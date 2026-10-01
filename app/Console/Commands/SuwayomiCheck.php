<?php

namespace App\Console\Commands;

use App\Suwayomi\Gateway;
use App\Suwayomi\SuwayomiException;
use Illuminate\Console\Command;

class SuwayomiCheck extends Command
{
    protected $signature = 'suwayomi:check';

    protected $description = 'Verify connectivity, auth mode, and schema drift against the configured Suwayomi instance';

    public function handle(Gateway $gateway): int
    {
        $url = config('papyrus.suwayomi.url');

        $this->components->twoColumnDetail('Suwayomi URL', $url);

        try {
            $data = $gateway->execute(<<<'GQL'
                query Check {
                    aboutServer { version buildTime }
                    settings { authMode }
                }
                GQL);
        } catch (SuwayomiException $e) {
            $this->components->error("Could not query Suwayomi: {$e->getMessage()}");
            $this->line('');
            $this->line("  <fg=gray>kind:</> {$e->kind->value}");

            if ($e->isUnauthenticated()) {
                $this->line('  <fg=gray>hint: the server reached but rejected us. Set AuthMode to UI_LOGIN and check</fg=gray>');
                $this->line('  <fg=gray>      SUWAYOMI_USERNAME / SUWAYOMI_PASSWORD in .env, then clear config cache.</fg=gray>');
            } elseif ($e->isTimeout()) {
                $this->line('  <fg=gray>hint: raise SUWAYOMI_TIMEOUT, or check whether a Cloudflare challenge is</fg=gray>');
                $this->line('  <fg=gray>      blocking the connection. JCEF needs an interactive desktop session.</fg=gray>');
            }

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Server version', (string) ($data['aboutServer']['version'] ?? 'unknown'));
        $this->components->twoColumnDetail('Auth mode', (string) ($data['settings']['authMode'] ?? 'unknown'));
        $this->components->twoColumnDetail('Last call latency', round($gateway->lastLatencyMs() ?? 0).'ms');

        $this->line('');

        $this->reportExtensionStores();
        $this->reportSchemaSnapshot();

        return self::SUCCESS;
    }

    private function reportExtensionStores(): void
    {
        try {
            $data = app(Gateway::class)->execute(<<<'GQL'
                query Stores {
                    extensionStores { nodes { indexUrl name } }
                    extensions { totalCount }
                }
                GQL);
        } catch (SuwayomiException $e) {
            $this->components->warn("Could not read extension stores: {$e->getMessage()}");

            return;
        }

        $stores = $data['extensionStores']['nodes'] ?? [];
        $extensions = $data['extensions']['totalCount'] ?? 0;

        $this->components->twoColumnDetail('Extension stores', (string) count($stores));
        $this->components->twoColumnDetail('Extensions available', (string) $extensions);

        if ($stores === []) {
            $this->components->warn('No extension stores configured. Add one in the app - we ship no curated source list by design.');
        }
    }

    private function reportSchemaSnapshot(): void
    {
        $path = config('papyrus.schema_snapshot');

        if (! is_file($path)) {
            $this->components->warn('No schema snapshot committed. Run: php artisan suwayomi:schema:dump');

            return;
        }

        $snapshot = json_decode((string) file_get_contents($path), true);

        if (! is_array($snapshot)) {
            $this->components->warn('Schema snapshot is unreadable. Re-run: php artisan suwayomi:schema:dump');

            return;
        }

        $capturedAt = $snapshot['capturedAt'] ?? 'unknown';
        $this->components->twoColumnDetail('Schema snapshot', "captured {$capturedAt}");

        $version = (string) ($snapshot['serverVersion'] ?? '');

        if ($version === '' || $version === 'unknown') {
            $this->components->warn('Snapshot has no recorded server version. Re-run suwayomi:schema:dump.');

            return;
        }

        /*
         * Suwayomi reports versions like "v2.3.2243". A leading "v" makes the
         * first component non-numeric, and version_compare then sorts
         * "v2.3.2243" *below* "2.3.0" - so it has to be stripped or every
         * snapshot looks stale.
         */
        if (version_compare(ltrim($version, 'vV'), '2.3.0', '<')) {
            $this->components->warn("Snapshot is from {$version}, which predates the 2.3 line. Re-run suwayomi:schema:dump after upgrading Suwayomi.");
        }
    }
}
