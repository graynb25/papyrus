<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

/**
 * The paths an existing reader's `.env` already uses, for `open_basedir` to allow.
 *
 * ## Why this exists
 *
 * `open_basedir` can only name directories, and a 1.5.3 install can legitimately have its local
 * source folder, its database, its cache or its sendmail binary somewhere else. A fresh install
 * keeps all four inside the data directory, but an upgrade must not turn a working library into an
 * install that cannot read the folder and database it read yesterday.
 *
 * ## Why it parses the file itself
 *
 * The launcher has no Laravel and has to work when the application is broken, so the application's
 * `EnvironmentFile` is not available. `parse_ini_file` is also wrong: a `.env` is not an ini file,
 * a `#` opens a comment anywhere in an unquoted value, and quotes change where the path ends. The
 * rules here match the dotenv this project ships, because a value read differently by the two is
 * either a missing root or a directory that does not exist.
 *
 * It reads the one `.env` it is given and nothing else. The reader database is not opened, which
 * matters because this runs while the database may be the thing that is broken.
 */
final class OpenBasedirPaths
{
    /**
     * The keys that can point outside the install's own roots.
     *
     * `true` means the value names a file and its parent directory is what `open_basedir` needs.
     * SQLite writes `-wal` and `-shm` files beside a database, so allowing the file itself would
     * still fail on the first write.
     *
     * @var array<string, bool>
     */
    private const PATHS = [
        'PAPYRUS_LOCAL_SOURCE_PATH' => false,
        'DB_DATABASE' => true,
        'CACHE_STORAGE_PATH' => false,
        'MAIL_SENDMAIL_PATH' => true,
    ];

    public function __construct(private readonly string $environmentFile) {}

    /**
     * The directories to append to `open_basedir`, in the order the keys are listed above.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $values = $this->values();
        $paths = [];
        $seen = [];

        foreach (self::PATHS as $key => $valueIsFile) {
            $value = $values[$key] ?? null;

            if (! is_string($value) || $value === '') {
                continue;
            }

            $path = $valueIsFile ? $this->parentOf($value) : $this->directory($value);

            if ($path === null || isset($seen[$path])) {
                continue;
            }

            $seen[$path] = true;
            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * Every setting in the environment file, with its value decoded.
     *
     * @return array<string, string>
     */
    private function values(): array
    {
        $contents = @file_get_contents($this->environmentFile);

        if (! is_string($contents)) {
            return [];
        }

        $values = [];

        foreach (preg_split('#\R#', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$name, $raw] = explode('=', $line, 2);

            $values[trim($name)] = $this->value($raw);
        }

        return $values;
    }

    /**
     * One raw value, as dotenv reads it.
     *
     * A quoted value keeps spaces and a `#`, because both are ordinary in a folder name and neither
     * is a comment inside quotes. An unquoted value ends at `#`, which is how this project's dotenv
     * treats a trailing comment.
     */
    private function value(string $raw): string
    {
        $raw = ltrim($raw);

        if ($raw === '') {
            return '';
        }

        $quote = $raw[0];

        if ($quote === "'" || $quote === '"') {
            $end = strpos($raw, $quote, 1);

            // An unterminated quote is not a path this can safely guess at.
            if ($end === false) {
                return '';
            }

            return substr($raw, 1, $end - 1);
        }

        $comment = strpos($raw, '#');

        if ($comment !== false) {
            $raw = substr($raw, 0, $comment);
        }

        return trim($raw);
    }

    private function directory(string $value): ?string
    {
        $path = $this->normalise($value);

        if ($path === null) {
            return null;
        }

        if ($path === '/' || preg_match('#^[A-Za-z]:/$#', $path) === 1) {
            return $path;
        }

        return rtrim($path, '/');
    }

    private function parentOf(string $value): ?string
    {
        $path = $this->normalise($value);

        if ($path === null) {
            return null;
        }

        $path = rtrim($path, '/');

        if ($path === '') {
            return '/';
        }

        $separator = strrpos($path, '/');

        if ($separator === false) {
            return null;
        }

        if ($separator === 0) {
            return '/';
        }

        $parent = substr($path, 0, $separator);

        // `C:/database.sqlite` would otherwise become `C:`, which is drive relative rather than
        // the drive root.
        if (preg_match('#^[A-Za-z]:$#', $parent) === 1) {
            return $parent.'/';
        }

        return $parent;
    }

    /**
     * An absolute path with forward slashes, or null when it cannot be an `open_basedir` entry.
     */
    private function normalise(string $value): ?string
    {
        $value = trim($value);

        /*
         * On Windows `;` separates entries. A path containing one would silently become two
         * unrelated roots, neither of them the directory the reader configured.
         */
        if ($value === '' || str_contains($value, ';') || ! $this->isAbsolute($value)) {
            return null;
        }

        return str_replace('\\', '/', $value);
    }

    private function isAbsolute(string $value): bool
    {
        if (str_starts_with($value, '/') || str_starts_with($value, '\\\\')) {
            return true;
        }

        return preg_match('#^[A-Za-z]:[\\\\/]#', $value) === 1;
    }
}
