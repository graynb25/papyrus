<?php

namespace App\Console\Commands;

use App\Suwayomi\Gateway;
use App\Suwayomi\SuwayomiException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Capture Suwayomi's live schema.
 *
 * The schema is generated at runtime from Kotlin classes and is unversioned -
 * there is no published .graphqls and no compatibility contract. Deprecated
 * fields are retained rather than deleted, which keeps clients alive across
 * upgrades, but two breaking families are already visible (versionCode
 * Int -> Long, boolean isNsfw -> ContentWarning). Snapshotting turns a silent
 * upgrade into a diff you can read.
 */
class SuwayomiSchemaDump extends Command
{
    protected $signature = 'suwayomi:schema:dump
                            {--dry-run : Report drift without writing the snapshot}';

    protected $description = 'Introspect the live Suwayomi schema and commit a snapshot for drift detection';

    /** The full introspection query. Deliberately exhaustive over types, not deep. */
    private const INTROSPECTION = <<<'GQL'
        query Introspection {
            __schema {
                queryType { name }
                mutationType { name }
                types {
                    kind
                    name
                    description
                    fields(includeDeprecated: true) {
                        name
                        description
                        isDeprecated
                        deprecationReason
                        args { name type { ...TypeRef } }
                        type { ...TypeRef }
                    }
                    inputFields { name type { ...TypeRef } }
                    enumValues(includeDeprecated: true) { name isDeprecated deprecationReason }
                    possibleTypes { name }
                }
            }
        }

        fragment TypeRef on __Type {
            kind name
            ofType { kind name ofType { kind name ofType { kind name } } }
        }
        GQL;

    public function handle(Gateway $gateway): int
    {
        try {
            $data = $gateway->execute(self::INTROSPECTION);
        } catch (SuwayomiException $e) {
            $this->components->error("Introspection failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $schema = $data['__schema'] ?? null;

        if (! is_array($schema)) {
            $this->components->error('Server returned no __schema. Is SUWAYOMI_GRAPHQL_PATH correct?');

            return self::FAILURE;
        }

        $types = $schema['types'] ?? [];
        $fingerprint = $this->fingerprint($types);

        $version = $this->serverVersion($gateway);

        $this->components->twoColumnDetail('Types captured', (string) count($types));
        $this->components->twoColumnDetail('Fields captured', (string) count($fingerprint));
        $this->components->twoColumnDetail('Server version', $version);

        $path = config('papyrus.schema_snapshot');
        $existing = is_file($path) ? json_decode((string) File::get($path), true) : null;

        if (is_array($existing) && ($existing['fingerprint'] ?? null) === $fingerprint) {
            $this->components->info('Schema is unchanged since the last snapshot.');

            return self::SUCCESS;
        }

        if (is_array($existing)) {
            $this->reportDrift($existing['fingerprint'] ?? [], $fingerprint);
        }

        if ($this->option('dry-run')) {
            $this->components->info('Dry run - snapshot not written.');

            return self::SUCCESS;
        }

        File::ensureDirectoryExists(dirname($path));

        $encoded = json_encode([
            'capturedAt' => now()->toIso8601String(),
            'serverVersion' => $version,
            'fingerprint' => $fingerprint,
            'schema' => $schema,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            $this->components->error('Could not encode the schema snapshot: '.json_last_error_msg());

            return self::FAILURE;
        }

        File::put($path, $encoded);

        $this->components->info("Snapshot written to {$path}");

        return self::SUCCESS;
    }

    /**
     * A flat, sorted list of "Type.field" and "Type.$input" identifiers.
     *
     * Shape-only on purpose: descriptions and deprecation reasons churn between
     * Suwayomi patches and would make the diff unreadable. What matters is
     * whether a field we call still exists.
     *
     * @param  array<int, array<string, mixed>>  $types
     * @return array<int, string>
     */
    private function fingerprint(array $types): array
    {
        $names = [];

        foreach ($types as $type) {
            $typeName = $type['name'] ?? null;

            if (! is_string($typeName) || str_starts_with($typeName, '__')) {
                continue;
            }

            foreach ($type['fields'] ?? [] as $field) {
                $names[] = $typeName.'.'.($field['name'] ?? '?');
            }

            foreach ($type['inputFields'] ?? [] as $field) {
                $names[] = $typeName.'$'.($field['name'] ?? '?');
            }

            foreach ($type['enumValues'] ?? [] as $value) {
                $names[] = $typeName.'!'.($value['name'] ?? '?');
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     */
    private function reportDrift(array $before, array $after): void
    {
        $removed = array_values(array_diff($before, $after));
        $added = array_values(array_diff($after, $before));

        if ($removed === [] && $added === []) {
            return;
        }

        $this->components->warn(sprintf(
            'Schema drifted: %d removed, %d added since the last snapshot.',
            count($removed),
            count($added),
        ));

        foreach (array_slice($removed, 0, 25) as $name) {
            $this->line("  <fg=red>-</> {$name}");
        }

        foreach (array_slice($added, 0, 25) as $name) {
            $this->line("  <fg=green>+</> {$name}");
        }

        if (count($removed) > 25 || count($added) > 25) {
            $this->line('  <fg=gray>… truncated</>');
        }
    }

    private function serverVersion(Gateway $gateway): string
    {
        try {
            $data = $gateway->execute('query { aboutServer { version } }');
        } catch (SuwayomiException) {
            return 'unknown';
        }

        return (string) ($data['aboutServer']['version'] ?? 'unknown');
    }
}
