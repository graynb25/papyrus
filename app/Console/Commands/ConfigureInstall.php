<?php

namespace App\Console\Commands;

use App\Support\EnvironmentFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Writes the `.env` a packaged install runs on.
 *
 * ## Why this is a command and not part of the launcher
 *
 * Because it has to happen before the web server starts. The app cannot answer `/ready`
 * without an app key, so the launcher has nothing to poll until this has run, and putting the
 * logic in the launcher would mean the same key generation exists in two languages. It runs as
 * an artisan command that the launcher calls, so there is one implementation and it is
 * testable.
 *
 * ## What it will not do
 *
 * **It will not overwrite an existing key.** `APP_KEY` is not a setting, it is the secret every
 * session cookie and every encrypted column is derived from. Regenerating it does not log
 * anybody out, it makes every session in every browser a forgery attempt and turns every
 * encrypted value in the database into garbage. So an existing key is left exactly as it is,
 * and that is the single most important behaviour in this class.
 *
 * The general shape: **idempotence on the destructive key and eagerness on everything else.**
 * A first run writes the file, a second run repairs it, and neither one can cost a reader their
 * sessions.
 */
class ConfigureInstall extends Command
{
    /**
     * The env vars whose value must be an absolute path, and why.
     *
     * Relative paths in a packaged install are resolved against whatever directory the process
     * happens to be started in. The launcher sets that deliberately to the application root,
     * but a reader double-clicking something, or a service manager with its own idea of a
     * working directory, does not have that guarantee, and the failure is a database that
     * silently appears empty.
     */
    private const PATH_KEYS = ['DB_DATABASE'];

    protected $signature = 'papyrus:configure
        {--data= : The data directory. Created if missing, and made absolute}
        {--suwayomi=http://127.0.0.1:4567 : Where Suwayomi answers}
        {--app-url= : The URL the app is served on}
        {--env-file= : The .env to write. Defaults to the one in the application root}
        {--report : Print what changed, for the launcher to show or log}';

    protected $description = 'Write the .env a packaged install runs on, without ever replacing an existing APP_KEY';

    /**
     * The settings a packaged install wants that differ from the development defaults.
     *
     * Deliberately a short list. Anything not here is inherited from `.env.example`, so this
     * cannot drift away from what the development environment actually uses, which is the
     * failure a hand-maintained list of overrides invites.
     */
    private const OVERRIDES = [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'SESSION_DRIVER' => 'database',
        'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'database',
        'LOG_CHANNEL' => 'stack',
    ];

    public function handle(): int
    {
        $path = $this->envPath();

        /*
         * Nothing below may run when the example could not be copied. The first version printed
         * the clear sentence and carried on, so `EnvironmentFile::write()` read a `.env` that
         * had never been created and the install died with a parser stack trace instead.
         */
        if (! is_file($path) && ! $this->copyExample($path)) {
            return self::FAILURE;
        }

        /*
         * The file mechanics live in `EnvironmentFile`, because a second implementation of them is
         * lesson 227 waiting to happen and this command's copy is the one with the measured dotenv
         * rules in it. What stays here is the part that is about *this* command: which settings it
         * owns, and what "the app key is present" means.
         */
        $env = app(EnvironmentFile::class);

        $contents = $env->read($path);

        $hadKey = isset($contents['APP_KEY']) && $this->hasKey($contents['APP_KEY']);

        $changes = $this->apply($contents, $hadKey);

        if ($changes !== []) {
            $env->set($contents, $path);
        }

        $this->report($path, $changes, $hadKey);

        return self::SUCCESS;
    }

    /**
     * Which `.env` this run writes.
     *
     * Settable rather than hardcoded to `base_path('.env')`, for two reasons. An installer can
     * stage the file somewhere and move it into place, which is how an upgrade avoids a window
     * where the app is running with no key. And it means the command can be tested without a
     * test touching the developer's own environment file, which is a mistake worth designing
     * out rather than backing up carefully.
     *
     * ## Why the option is called `env-file` and not `env`
     *
     * Because `--env` is already taken. Symfony Console defines it as a global application
     * option meaning the environment name, and an application option wins over a command option
     * of the same name. So a launcher passing `--env <path>` gets the flag consumed by the
     * application and the path falls through as a stray argument, which the command then rejects
     * with "No arguments expected". The launcher refused to start and the trace pointed at the
     * command rather than at the name collision.
     *
     * It was invisible to the tests because `$this->artisan()` hands options straight to the
     * command's input and never parses argv. The command worked under test and failed as a
     * spawned process, which is the shape of bug that only a real invocation finds.
     */
    private function envPath(): string
    {
        $given = $this->option('env-file');

        if (is_string($given) && $given !== '') {
            return $given;
        }

        return base_path('.env');
    }

    /**
     * Start from the shipped example rather than from nothing.
     *
     * `.env.example` is committed and is the only description of what this app needs. A file
     * written from scratch here would be a second list to keep in step with it, and the two
     * would diverge the first time a dependency was added.
     *
     * Returns whether the file is now in place. A caller that ignores this is the crash this
     * method exists to prevent, so the answer is part of the signature rather than a printed
     * message the caller has to remember to act on.
     */
    private function copyExample(string $path): bool
    {
        $example = base_path('.env.example');

        if (! is_file($example)) {
            $this->components->error("No .env.example to copy from at {$example}. The install is incomplete.");

            return false;
        }

        if (! File::copy($example, $path)) {
            $this->components->error("Could not copy {$example} to {$path}. The install is incomplete.");

            return false;
        }

        $this->components->info('Created .env from .env.example');

        return true;
    }

    /**
     * Whether a value is an actual key rather than a placeholder.
     *
     * `.env.example` ships `APP_KEY=` empty, and a freshly copied file therefore "has" the key
     * while having nothing in it. Treating that as present is how a packaged install ships with
     * no key and answers every request with a session decode error.
     */
    private function hasKey(string $value): bool
    {
        $value = trim($value, "\"' ");

        return $value !== '' && $value !== 'base64:';
    }

    /**
     * Decide every value, returning the names that actually changed.
     *
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function apply(array &$values, bool $hadKey): array
    {
        $changed = [];

        foreach (self::OVERRIDES as $name => $value) {
            if (($values[$name] ?? null) !== $value) {
                $values[$name] = $value;
                $changed[] = $name;
            }
        }

        if (! $hadKey) {
            $values['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
            $changed[] = 'APP_KEY';
        }

        foreach (self::PATH_KEYS as $name) {
            $absolute = $this->absolute($values[$name] ?? '');

            if ($absolute !== null && ($values[$name] ?? null) !== $absolute) {
                $values[$name] = $absolute;
                $changed[] = $name;
            }
        }

        $url = (string) ($this->option('suwayomi') ?: 'http://127.0.0.1:4567');

        if (($values['SUWAYOMI_URL'] ?? null) !== $url) {
            $values['SUWAYOMI_URL'] = $url;
            $changed[] = 'SUWAYOMI_URL';
        }

        $appUrl = $this->option('app-url');

        if (is_string($appUrl) && $appUrl !== '' && ($values['APP_URL'] ?? null) !== $appUrl) {
            $values['APP_URL'] = $appUrl;
            $changed[] = 'APP_URL';
        }

        return $changed;
    }

    /**
     * Where the database goes, which is the data directory and not the application root.
     *
     * Not `base_path()` of whatever the example happened to say. `.env.example` ships
     * `DB_DATABASE=laravel`, which means `database/laravel` relative to the application, and
     * putting the install's database inside the install is the one thing that makes uninstall
     * dangerous: a reader who removes the folder removes their library with it. So the data
     * directory given on the command line wins, and the example's value is only a fallback for
     * a developer running without one.
     */
    private function absolute(string $value): ?string
    {
        $data = $this->option('data');

        if (! is_string($data) || $data === '') {
            return null;
        }

        $root = $this->normalise($data);
        $path = $root.DIRECTORY_SEPARATOR.'database.sqlite';

        File::ensureDirectoryExists(dirname($path));

        return $path;
    }

    /**
     * Say what happened, in a form the launcher can log or a reader can read.
     *
     * The app key is never printed. A log file or a console transcript is the sort of thing
     * that gets pasted into a support thread.
     *
     * @param  list<string>  $changed
     */
    private function report(string $path, array $changed, bool $hadKey): void
    {
        if (! $this->option('report')) {
            return;
        }

        $this->line($path);
        $this->line($changed === []
            ? 'nothing to change'
            : 'changed: '.implode(', ', $changed));

        $this->line($hadKey
            ? 'APP_KEY was already set and has been left exactly as it was'
            : 'APP_KEY was generated for this install');
    }

    /**
     * Collapse separators so a path written with the other platform's slash still compares.
     *
     * No trailing separator is added, because this is applied to file paths such as
     * `database/database.sqlite`, and appending one turns that into a directory name ending in
     * a backslash, which SQLite will happily create and Laravel will then fail to open.
     */
    private function normalise(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
