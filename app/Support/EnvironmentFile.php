<?php

namespace App\Support;

use Illuminate\Filesystem\Filesystem;

/**
 * The one owner of reading and rewriting an environment file.
 *
 * ## Why this exists rather than a second copy inside the caller that needed it
 *
 * Because the logic here is not obvious and two copies of it would disagree. Measured against the
 * dotenv this project ships, a `#` opens a comment anywhere in an unquoted value with no whitespace
 * required, so `a#b` parses as `a`. An unquoted value containing whitespace is a parse error rather
 * than a literal, so a Windows path under `C:\Program Files` written unquoted produces an install
 * whose environment file the framework refuses to load, and the application does not boot at all.
 * Both of those rules have to agree with the parser exactly, and a guard that is stricter or looser
 * corrupts a value without erroring.
 *
 * Those rules were written once, inside `papyrus:configure`, and they are correct there. The second
 * caller is the one that makes a copy dangerous: lesson 227 in `docs/ENGINEERING-NOTES.md` is two
 * implementations of one question in this project disagreeing for the life of a feature while every
 * test stayed green. So the logic moved here and `ConfigureInstall` now delegates to it, rather than
 * being rewritten or shadowed.
 *
 * ## Why writing is done by line and not by re-serialising a parsed set
 *
 * Because a `.env` is a file a human wrote, with comments and their own alignment in it. Re-serialising
 * a parse of it produces a file that is equivalent and completely different, and the diff against
 * somebody's own environment then shows what was tidied rather than what changed. Every line this
 * class does not own is copied through byte for byte.
 */
final class EnvironmentFile
{
    /**
     * @param  string|null  $path  Overrides where the file is. Settable rather than hardcoded so a
     *                             test can point this at a temporary file, which is a mistake worth
     *                             designing out rather than backing up carefully: an earlier caller
     *                             here took the path as an option for exactly this reason.
     */
    public function __construct(
        private readonly Filesystem $files,
        private readonly ?string $path = null,
    ) {}

    /**
     * Where the environment file is.
     *
     * `PAPYRUS_ENV` first, because that is how a packaged install is pointed at the `.env` inside
     * its data directory rather than beside the code. A development checkout has no such variable and
     * gets the conventional path.
     */
    public function path(): string
    {
        if ($this->path !== null) {
            return $this->path;
        }

        $given = getenv('PAPYRUS_ENV');

        if (is_string($given) && $given !== '' && is_file($given)) {
            return $given;
        }

        return base_path('.env');
    }

    /**
     * Is this a packaged install's own environment file, rather than a checkout's?
     *
     * Asked before anything is written. A development checkout has a `.env` beside the code, and it
     * belongs to whoever is developing: a feature that remembers something must not reach into it
     * because a test happened to create a row. Only the file the launcher named is ever written.
     */
    public function isPackaged(): bool
    {
        if ($this->path !== null) {
            return true;
        }

        $given = getenv('PAPYRUS_ENV');

        return is_string($given) && $given !== '' && is_file($given);
    }

    public function exists(): bool
    {
        return $this->files->isFile($this->path());
    }

    /**
     * Every setting in the file, as name to raw value. Comments and blank lines are not settings.
     *
     * @return array<string, string>
     */
    public function read(?string $path = null): array
    {
        $path ??= $this->path();

        if (! $this->files->isFile($path)) {
            return [];
        }

        $values = [];

        foreach ($this->files->lines($path) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);

            $values[trim($name)] = trim($value);
        }

        return $values;
    }

    /**
     * Apply settings, returning the names that actually changed.
     *
     * A name already in the file is rewritten in place, keeping its position and any comment its
     * author wrote. A name that is not in the file is appended.
     *
     * @param  array<string, string>  $settings
     * @return list<string>
     */
    public function set(array $settings, ?string $path = null): array
    {
        $path ??= $this->path();

        $current = $this->read($path);

        $changed = [];

        foreach ($settings as $name => $value) {
            if (($current[$name] ?? null) !== $value) {
                $changed[] = $name;
            }
        }

        if ($changed === []) {
            return [];
        }

        $this->write($settings, $path, $changed);

        return $changed;
    }

    /**
     * @param  array<string, string>  $settings
     * @param  list<string>  $changed  Names this run chose a new value for. Those values are
     *                                 data; every other setting is a line being carried.
     */
    private function write(array $settings, string $path, array $changed): void
    {
        $remaining = $settings;
        $written = [];

        foreach ($this->files->lines($path) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
                $written[] = $line;

                continue;
            }

            $name = trim(explode('=', $trimmed, 2)[0]);

            if (array_key_exists($name, $remaining)) {
                $written[] = $this->renderSetting($name, $remaining[$name], in_array($name, $changed, true));
                unset($remaining[$name]);

                continue;
            }

            /*
             * Copied through verbatim, which is correct, and worth saying why.
             *
             * It looks like the branch a foreign setting should be quoted in, and it is not.
             * `read()` parses every line into a set, so a setting this caller does not own still
             * arrives as a key and takes the branch above. The ownership flag passed there is
             * what keeps its `#` meaning a comment rather than data. Anything reaching this one
             * is not a setting at all, and writing it back exactly as it was found is the only
             * safe thing to do with a line whose value nobody here has interpreted.
             */
            $written[] = $line;
        }

        foreach ($remaining as $name => $value) {
            $written[] = $name.'='.$this->quoteIfNeeded($value);
        }

        $this->files->put($path, implode(PHP_EOL, array_map(
            static fn (string $line): string => rtrim($line),
            $written,
        )).PHP_EOL);
    }

    /**
     * Render `name=value` for one line this class is writing.
     *
     * ## Why an owned value is never split at `#`
     *
     * Because the value was chosen by the caller, not written by a person as an environment
     * line. `DB_DATABASE` for a data directory containing `#` is the measured case: splitting
     * it writes a shorter path, and the application then opens a different sqlite file with no
     * error naming the line. The whole value goes to `quoteIfNeeded()`, which already quotes a
     * `#`, so dotenv reads it back unchanged.
     *
     * ## Why a carried value still splits
     *
     * Because dotenv does, and this class is not the author of that line. When a `#` opens a
     * comment, measured against the dotenv this ships with:
     *
     *     VALUE=a#b                  parses to  a
     *     VALUE=a #b                 parses to  a
     *     VALUE=a# b                 parses to  a
     *     VALUE=path\to\file # tail  parses to  path\to\file
     *     VALUE=two words            refuses to parse
     *
     * `SITE_TAG=reader#beta` is a setting this command does not own, and it must keep parsing
     * as `reader`; quoting it whole would turn the author's comment into data.
     */
    private function renderSetting(string $name, string $value, bool $owned): string
    {
        if ($owned) {
            return $name.'='.$this->quoteIfNeeded($value);
        }

        $comment = '';

        if (preg_match('~^(.*?)#(.*)$~', trim($value), $matches) === 1) {
            $value = rtrim($matches[1]);

            /*
             * The comment is kept as its author wrote it, including the space after the `#`.
             *
             * `trim()` on that part looks tidier and quietly rewrites the line: `# and why` becomes
             * `#and why`. This class is not the author of these lines, and a diff against a
             * developer's own `.env` should show what changed, not what was tidied.
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
     * An unquoted value containing whitespace is a parse error, not a literal, and
     * `C:\Program Files\Papyrus\data\database.sqlite` is where a Windows install puts its database
     * by default. Unquoted, the framework refuses the file and the application does not boot, at the
     * worst possible moment: the web server is up and the app is dead with an error naming a parser
     * rather than a path.
     *
     * Quoting is applied only where needed. Always quoting would mean every line looks different from
     * the author's own `.env`, which makes a diff between the two unreadable for no benefit.
     */
    private function quoteIfNeeded(string $value): string
    {
        if (str_starts_with($value, '"') || str_starts_with($value, "'")) {
            return $value;
        }

        $needsQuoting = str_contains($value, ' ')
            || str_contains($value, "\t")
            // A `#` starts a comment in an unquoted value, so a path containing one loses everything
            // after it. Rare on Windows but free to handle here.
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
         * exist. Single quotes are literal in dotenv, which is what a Windows path needs. They cannot
         * contain a single quote, so a value with one falls back to double quotes with only the quote
         * escaped, which dotenv does understand.
         */
        if (str_contains($value, "'")) {
            return '"'.str_replace('"', '\\"', $value).'"';
        }

        return "'".$value."'";
    }
}
