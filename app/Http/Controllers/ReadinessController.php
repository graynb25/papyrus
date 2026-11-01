<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Answers whether this installation is ready to be used, for the launcher that supervises it.
 *
 * ## Why this exists when Laravel already registers `/up`
 *
 * Because `/up` answers one question, "is PHP serving", and a packaged install needs three.
 * A launcher has to know when to start the queue worker, when to run migrations, and whether
 * the install it just wrote is the one the reader is about to use. `/up` returns 200 with an
 * empty database and no app key, and both of those make every subsequent page fail.
 *
 * So the two answer different questions and both are kept:
 *
 * | Route | Answers | Registered by |
 * | --- | --- | --- |
 * | `/up` | is PHP serving at all | Laravel, `bootstrap/app.php` |
 * | `/ready` | can this installation actually be used | here |
 *
 * ## Why it is deliberately not in the web middleware group
 *
 * Because `SESSION_DRIVER=database`, which is what `.env.example` ships. Every request through
 * the web stack starts a session, and a database session is a write. A launcher polling
 * readiness once a second would put a session row into SQLite every second, forever, on a
 * process that is also trying to hold a write lock for an import.
 *
 * That is not a performance concern in the abstract. This app is on SQLite precisely because
 * one reader on one machine does not need a database server, and SQLite's single writer is the
 * thing the import work has already had to work around. Adding an idle poll that writes on a
 * timer would undo that, in a way that only shows up as "the app gets slow after it has been
 * open a while".
 *
 * So the route is registered through `withRouting(then: ...)`, which attaches no group.
 *
 * ## Why it does not check Suwayomi
 *
 * Because Suwayomi being down is not this process being unready, and the difference matters.
 * The launcher owns the Suwayomi lifecycle and polls its GraphQL `aboutServer` for that half.
 * If this route waited on Suwayomi too then Suwayomi being unreachable would make Papyrus look
 * broken to anything watching it, including a health check in an installer, and the failure
 * would be reported against the wrong process.
 *
 * ## Why it only answers on loopback
 *
 * Because this server supports a public deployment mode, and an unauthenticated endpoint that
 * reports whether the app key is set and whether migrations are pending is information worth
 * withholding from the internet. The launcher is always on the same machine, so loopback is
 * never a restriction in practice.
 *
 * The check reads `REMOTE_ADDR` rather than `$request->ip()`, deliberately. `ip()` honours
 * `X-Forwarded-For` for trusted proxies, which means a remote client behind a misconfigured
 * proxy could make itself look local. `REMOTE_ADDR` is the transport's own answer and cannot
 * be set by the client, though a proxy on this machine supplies its own loopback address.
 *
 * That is the caveat: `REMOTE_ADDR` is the transport's answer, not the client's identity. A
 * same-host reverse proxy makes every remote request arrive from loopback, so every remote
 * client gets the readiness body. The check is correct for the direct connection the launcher
 * makes and wrong for that proxy shape. Do not forward `/ready` through one. A launcher
 * secret or a separate loopback-only listener is the later fix; `docs/SECURITY.md` carries
 * the operator-facing version.
 *
 * A non-loopback request gets 404 rather than 403, so the endpoint does not confirm its own
 * existence to someone probing for it.
 */
final class ReadinessController extends Controller
{
    /**
     * 200 when the installation can be used, 503 when it cannot.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->isLoopback($request)) {
            return response()->json(['status' => 'not found'], 404);
        }

        $checks = [
            'key' => $this->hasAppKey(),
            'database' => $this->databaseAnswers(),
            'pending_migrations' => $this->pendingMigrations(),
            'schema_ahead' => $this->schemaAhead(),
        ];

        $ready = $checks['key']
            && $checks['database']
            && $checks['pending_migrations'] === 0
            && $checks['schema_ahead'] === 0;

        return response()->json([
            'status' => $ready ? 'ready' : 'not ready',
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }

    /**
     * Whether an encryption key is present, because without one sessions cannot be read.
     *
     * Not merely whether the config key exists: a freshly cloned `.env.example` copied to
     * `.env` has the key present and empty, and that install answers every request with a
     * session decode failure that reads like a corrupt database.
     */
    private function hasAppKey(): bool
    {
        $key = (string) config('app.key');

        return $key !== '' && $key !== 'base64:';
    }

    /**
     * Whether the database answers a read.
     *
     * `select 1` and not a schema query, because the point is whether the file opens and is not
     * locked. On a fresh install there are no tables yet and that is the answer the launcher
     * needs: not ready, and ready to be migrated.
     */
    private function databaseAnswers(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * How many migrations exist that have not run.
     *
     * Read from the files and the table rather than through `migrate:status`, which boots the
     * migrator and prints a table. This runs on every poll and has to stay cheap.
     *
     * A missing `migrations` table means nothing has run, which on a fresh install is every
     * file and is exactly the state the launcher acts on.
     */
    private function pendingMigrations(): int
    {
        $expected = $this->migrationNames();

        if ($expected === []) {
            return 0;
        }

        $ran = $this->ranMigrationNames();

        return $ran === null ? count($expected) : count(array_diff($expected, $ran));
    }

    /**
     * How many migrations have run that this build does not know.
     *
     * This is the downgrade direction. A database carried forward by a newer build has migration
     * rows whose files are gone from this one. `array_diff($expected, $ran)` sees none of them and
     * reports ready, so the older build starts against columns it does not understand and fails
     * page by page. The launcher refuses to start when this is greater than zero.
     */
    private function schemaAhead(): int
    {
        $ran = $this->ranMigrationNames();

        if ($ran === null) {
            return 0;
        }

        return count(array_diff($ran, $this->migrationNames()));
    }

    /**
     * The migration names this build carries.
     *
     * @return list<string>
     */
    private function migrationNames(): array
    {
        $files = glob(database_path('migrations/*.php')) ?: [];

        return array_map(
            static fn (string $file): string => basename($file, '.php'),
            $files,
        );
    }

    /**
     * The migration names the database says have run, or null when the table is missing.
     *
     * @return array<int, string>|null
     */
    private function ranMigrationNames(): ?array
    {
        try {
            $ran = DB::table('migrations')->pluck('migration')->all();
        } catch (Throwable) {
            return null;
        }

        return array_filter($ran, 'is_string');
    }

    /**
     * Whether the request arrived over loopback.
     *
     * Both families, because a Windows host listening on `::1` and one on `127.0.0.1` are
     * different configurations and a launcher should not care which it got.
     */
    private function isLoopback(Request $request): bool
    {
        $address = $request->server('REMOTE_ADDR');

        // Not cast. A server bag value is typed as anything, including a list, because that is
        // what the CGI variables can hold, and casting one to a string produces "Array" plus a
        // notice rather than an answer. An address that is not a string is not loopback.
        if (! is_string($address)) {
            return false;
        }

        return $address === '127.0.0.1'
            || $address === '::1'
            || str_starts_with($address, '127.');
    }
}
