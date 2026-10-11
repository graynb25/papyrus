<?php

namespace App\Console\Commands;

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

        if (! is_file($path)) {
            $this->copyExample($path);
        }

        [$contents, $hadKey] = $this->readEnv($path);
        $changes = $this->apply($contents, $hadKey);

        if ($changes !== []) {
            $this->putEnv($path, $contents);
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
     */
    private function copyExample(string $path): void
    {
        $example = base_path('.env.example');

        if (! is_file($example)) {
            $this->components->error("No .env.example to copy from at {$example}. The install is incomplete.");

            return;
        }

        File::copy($example, $path);
        $this->components->info('Created .env from .env.example');
    }

    /**
     * Read the file into a set and the lines, plus whether a usable key was already there.
     *
     * @return array{0: array<string, string>, 1: bool}
     */
    private function readEnv(string $path): array
    {
        $values = [];
        $hadKey = false;

        foreach (File::lines($path) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);

            $name = trim($name);
            $values[$name] = trim($value);

            if ($name === 'APP_KEY' && $this->hasKey($values[$name])) {
                $hadKey = true;
            }
        }

        return [$values, $hadKey];
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
     * Put the values back into the file, preserving what it does not own.
     *
     * Rewriting the whole file from the parsed set would discard comments, which is where
     * `.env.example` explains what each setting is for, and the second run of this command
     * would strip every one of them. So only the lines being changed are replaced.
     *
     * @param  array<string, string>  $values  Consumed by reference, so the leftovers can be appended.
     */
    private function putEnv(string $path, array &$values): void
    {
        $lines = File::lines($path);
        $written = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
                $written[] = $line;

                continue;
            }

            $name = trim(explode('=', $trimmed, 2)[0]);

            if (array_key_exists($name, $values)) {
                $written[] = $this->renderSetting($name, $values[$name]);
                unset($values[$name]);

                continue;
            }

            /*
             * Copied through verbatim, which is correct, and worth saying why.
             *
             * It looks like the branch a foreign setting should be quoted in, and it is not.
             * `readEnv()` parses every line into `$values`, so a setting this command does not
             * own still arrives here as a key of `$values` and takes the branch above. Anything
             * reaching this one is a name `$values` no longer holds, and writing it back exactly
             * as it was found is the only safe thing to do with a line whose value this command
             * has not interpreted.
             *
             * The bug this replaces was in the branch above, not this one, and the comments
             * there say so. Two fixes were written before that was established: quoting here
             * changed no observable behaviour, and the tests could not kill a mutation of it,
             * which is how a test that pins nothing gets left behind.
             */
            $written[] = $line;
        }

        // Anything the example gained since the file was written.
        foreach ($values as $name => $value) {
            $written[] = $name.'='.$this->quoteIfNeeded($value);
        }

        File::put($path, implode(PHP_EOL, array_map(
            static fn (string $line): string => rtrim($line),
            $written,
        )).PHP_EOL);
    }

    /**
     * Render `name=value`, quoting only the value and only when it has to be quoted.
     *
     * ## Why the comment is split off first
     *
     * Because quoting a value that has a comment on it turns that comment into part of the
     * setting, and the application then reads `debug` where the author wrote
     * `debug # very noisy`, or the other way round.
     *
     * ## Where a `#` opens a comment, measured rather than remembered
     *
     * Anywhere in the value, with no requirement of whitespace before it. Measured against the
     * dotenv this ships with, because the guard here has to agree with the parser exactly and a
     * guard that is stricter or looser corrupts a value without erroring:
     *
     *     VALUE=a#b                  parses to  a
     *     VALUE=a #b                 parses to  a
     *     VALUE=a# b                 parses to  a
     *     VALUE=path\to\file # tail  parses to  path\to\file
     *     VALUE=two words            refuses to parse
     *
     * An earlier version of this required whitespace before the `#`, which is wrong by that
     * table: it would have kept `#b` inside the quotes and changed `a#b` into a two character
     * value. The test that pins it is test_a_hash_inside_a_value_is_still_a_comment.
     */
    private function renderSetting(string $name, string $value): string
    {
        $comment = '';

        if (preg_match('~^(.*?)#(.*)$~', trim($value), $matches) === 1) {
            $value = rtrim($matches[1]);

            /*
             * The comment is kept as its author wrote it, including the space after the `#`.
             *
             * `trim()` on that part looks tidier and quietly rewrites the line: `# and why`
             * becomes `#and why`. This command is not the author of these lines, and a diff
             * against a developer's own `.env` should show what changed, not what was tidied.
             * Only trailing whitespace goes, because `trim($value)` above has already removed
             * the leading whitespace of the whole value.
             */
            $comment = ' #'.rtrim($matches[2]);
        }

        return $name.'='.$this->quoteIfNeeded($value).$comment;
    }

    /**
     * Quote a value that dotenv would otherwise refuse to parse.
     *
     * ## Why this is not optional
     *
     * An unquoted value containing whitespace is a parse error, not a literal. Writing
     * `DB_DATABASE=C:\Program Files\Papyrus\data\database.sqlite` produces an install whose
     * environment file the framework refuses to load: "Encountered unexpected whitespace", and the
     * application does not boot at all.
     *
     * `C:\Program Files` is where a Windows install goes by default, so this is not an edge case
     * found on an unusual machine. It is the common case, and it fails at the worst possible
     * moment: the launcher has written the file, the web server has started, and the app is dead
     * with an error that names a parser rather than a path.
     *
     * Quoting is applied only where it is needed. Always quoting would be tidier and would mean
     * every value in the file looks different from the developer's own `.env`, which makes a diff
     * between the two unreadable for no benefit.
     */
    private function quoteIfNeeded(string $value): string
    {
        // Already quoted by the caller or by the example file. Left alone rather than double
        // quoted, which would make the value include the quotes.
        if (str_starts_with($value, '"') || str_starts_with($value, "'")) {
            return $value;
        }

        $needsQuoting = str_contains($value, ' ')
            || str_contains($value, "\t")
            // A `#` starts a comment in an unquoted value, so a path containing one loses
            // everything after it. Rare on Windows but free to handle here.
            || str_contains($value, '#')
            || $value === '';

        if (! $needsQuoting) {
            return $value;
        }

        /*
         * Single quotes, and no backslash escaping.
         *
         * Dotenv does not treat a backslash as an escape inside double quotes, so escaping them
         * produces a path with doubled separators and the application opens a file that does not
         * exist. That is worse than an unquoted value, because the quoted form parses cleanly and
         * so nothing complains: the install builds, the launcher writes the file, and the app
         * reports `database: false` with a path that looks almost right.
         *
         * Single quotes are literal in dotenv, which is what a Windows path needs. They cannot
         * contain a single quote, so a value with one falls back to double quotes with only the
         * quote itself escaped, which dotenv does understand.
         */
        if (str_contains($value, "'")) {
            return '"'.str_replace('"', '\\"', $value).'"';
        }

        return "'".$value."'";
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
