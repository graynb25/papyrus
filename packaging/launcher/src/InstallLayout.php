<?php

namespace Papyrus\Launcher;

/**
 * Where a packaged install puts its files, decided once and checked before anything starts.
 *
 * ## The three findings that shaped this
 *
 * **Nothing needs `public/storage`.** The usual Windows symlink problem for a Laravel install
 * is that `storage:link` needs privileges the reader does not have. It does not apply here,
 * because nothing in any view references a `storage/` URL: covers are served by
 * `CoverController`, CBZ exports by `CbzExportController`, and Suwayomi's images by the
 * proxy. The `links` entry in `filesystems.php` is inherited from the framework and unused.
 * Measured rather than assumed, and it removes the largest single Windows path hazard.
 *
 * **Path length is not the problem it is usually blamed for.** The longest path in this
 * repository including `vendor/` is **179 characters**, the deepest being Livewire's test
 * fixtures. Installed at `C:\Program Files\Papyrus\` that becomes about 205, inside the 260
 * limit. This machine additionally has `LongPathsEnabled=1`, which is not the Windows default,
 * so the margin is worth having but the risk is not close.
 *
 * **Writing into the install directory is the hazard that is real.** It is read-only for a
 * normal user under Program Files, so every database write fails; and when it is writable, an
 * uninstall that removes the folder removes the reader's library with it. Both are prevented
 * by the same rule, which is why {@see assertNotInsideInstall()} exists.
 */
final class InstallLayout
{
    /**
     * Directories the install needs, relative to the data directory.
     *
     * `tmp` and `logs` are named by `php.ini` rather than by Laravel, and they are here
     * because of trap 219: the ini pointing `sys_temp_dir` at a directory nobody created made
     * the first command that shelled out fail with a Symfony Process complaint about a
     * temporary file, which reads as a bug in that command and is not one.
     */
    private const DATA_SUBDIRECTORIES = [
        'storage',
        'storage/app',
        'storage/app/private',
        'storage/app/public',
        'storage/framework',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
        'database',
        'exports',
        'tmp',
        'logs',
    ];

    /**
     * Windows refuses ordinary writes under this, for anyone who is not an administrator.
     */
    private const READ_ONLY_PREFIXES = [
        'c:\\program files',
        'c:\\program files (x86)',
        'c:\\windows',
    ];

    private const MAX_PATH = 260;

    /**
     * How close to the limit the data directory may get before it is called out.
     *
     * The deepest path under the data directory is a Livewire temporary upload, which is the
     * data directory plus `storage/app/private/livewire-tmp/` plus a 32 character name and an
     * extension. Ninety characters of headroom covers it with room to spare.
     */
    private const COMFORTABLE_LENGTH = 90;

    public function __construct(
        private readonly string $installRoot,
        private readonly string $dataDirectory,
    ) {}

    /**
     * The data directory a reader on this platform should use.
     *
     * Per platform rather than one rule for all three, because they disagree about where
     * application data belongs and getting it wrong means either a folder in the user's home
     * or a permissions prompt on first run.
     *
     * - Windows: `%LOCALAPPDATA%`, which is per user and never synced. Not Program Files, and
     *   not roaming, because a library that syncs to a corporate file server is a surprise.
     * - macOS: `~/Library/Application Support`, which is the documented location.
     * - Linux: `$XDG_DATA_HOME`, honouring the spec before falling back to `~/.local/share`.
     */
    public static function defaultDataDirectory(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $local = getenv('LOCALAPPDATA');

            if (is_string($local) && $local !== '') {
                return rtrim($local, '\\/').'\\Papyrus';
            }

            return rtrim((string) getenv('USERPROFILE'), '\\/').'\\AppData\\Local\\Papyrus';
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            return rtrim((string) getenv('HOME'), '/').'/Library/Application Support/Papyrus';
        }

        $xdg = getenv('XDG_DATA_HOME');

        if (is_string($xdg) && $xdg !== '') {
            return rtrim($xdg, '/').'/papyrus';
        }

        return rtrim((string) getenv('HOME'), '/').'/.local/share/papyrus';
    }

    /**
     * Create everything and prove it is usable, or say precisely what is wrong.
     *
     * @param  callable(string): void  $say
     * @return list<string> What was created or changed.
     */
    public function prepare(callable $say): array
    {
        $this->assertNotInsideInstall();
        $this->assertNotReadOnly();

        $made = [];

        foreach (self::DATA_SUBDIRECTORIES as $relative) {
            $path = $this->pathIn($relative);

            if (! is_dir($path) && @mkdir($path, 0o777, true)) {
                $made[] = $relative;
            }
        }

        $this->assertWritable();

        $say('data directory ready at '.$this->dataDirectory);

        $warning = $this->lengthWarning();

        if ($warning !== null) {
            $say('WARNING: '.$warning);
        }

        return $made;
    }

    /**
     * Refuse a data directory inside the install.
     *
     * Under Program Files this is already unwritable, and the failure would surface much later
     * as a SQLite error nobody can trace. Anywhere else it is writable, and then uninstalling
     * removes the reader's library with the application.
     *
     * @throws LayoutRefused
     */
    public function assertNotInsideInstall(): void
    {
        $data = $this->comparable($this->dataDirectory);
        $root = $this->comparable($this->installRoot);

        if ($data === $root || str_starts_with($data, $root.DIRECTORY_SEPARATOR)) {
            throw new LayoutRefused(sprintf(
                'The data directory is inside the install (%s is under %s). SQLite cannot write '
                .'there for an ordinary user, and an uninstall would take the reader\'s library '
                .'with it. Choose somewhere outside, such as %s.',
                $this->normalise($this->dataDirectory),
                $this->normalise($this->installRoot),
                self::defaultDataDirectory(),
            ));
        }
    }

    /**
     * Refuse a directory Windows will not let an ordinary user write to.
     *
     * @throws LayoutRefused
     */
    public function assertNotReadOnly(): void
    {
        $path = $this->comparable($this->dataDirectory);

        foreach (self::READ_ONLY_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                throw new LayoutRefused(sprintf(
                    '%s is not writable by an ordinary user on Windows. Use %s instead.',
                    $this->normalise($this->dataDirectory),
                    self::defaultDataDirectory(),
                ));
            }
        }
    }

    /**
     * Prove the directory is writable rather than assuming it.
     *
     * The check writes a real file and removes it, because `is_writable` lies on Windows
     * network shares and on some container filesystems, and the alternative is discovering at
     * 3am that the first database write fails.
     *
     * @throws LayoutRefused
     */
    public function assertWritable(): void
    {
        if (! is_dir($this->dataDirectory) && ! @mkdir($this->dataDirectory, 0o777, true)) {
            throw new LayoutRefused("Could not create the data directory {$this->dataDirectory}.");
        }

        $probe = $this->dataDirectory.DIRECTORY_SEPARATOR.'.write-probe';

        if (@file_put_contents($probe, 'x') === false) {
            throw new LayoutRefused(sprintf(
                'The data directory %s is not writable. Nothing has been started.',
                $this->dataDirectory,
            ));
        }

        @unlink($probe);
    }

    /**
     * A warning about a path that is legal but close to the limit, or null.
     *
     * Not a refusal. A long path still works, and refusing to run would be worse than running.
     * The point is to say it out loud once, while there is still time to move the install,
     * rather than at the moment a write fails.
     */
    public function lengthWarning(): ?string
    {
        $worst = $this->dataDirectory.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'
            .DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'livewire-tmp'
            .DIRECTORY_SEPARATOR.str_repeat('a', 32).'.jpg';

        $length = strlen($worst);

        if ($length >= self::MAX_PATH) {
            return sprintf(
                'Temporary uploads will reach %d characters, which is past the %d that Windows '
                .'reliably handles unless long paths are enabled. Install somewhere shorter.',
                $length,
                self::MAX_PATH,
            );
        }

        if ($length > self::COMFORTABLE_LENGTH) {
            return sprintf(
                'Temporary uploads will reach %d characters, which works but is close to the %d '
                .'limit. A shorter install path would be safer.',
                $length,
                self::MAX_PATH,
            );
        }

        return null;
    }

    public function pathIn(string $relative): string
    {
        return rtrim($this->dataDirectory, '\\/').DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public function dataDirectory(): string
    {
        return $this->dataDirectory;
    }

    private function normalise(string $path): string
    {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    /**
     * The same path, folded for comparison.
     *
     * Windows paths are case insensitive, so `C:\Program Files` and `c:\program files` are the
     * same directory and a case sensitive `str_starts_with` refuses neither of them correctly.
     * It refuses the first and waves the second through, which is exactly backwards: the
     * prefixed path is the one that must be caught.
     *
     * Folded unconditionally rather than only on Windows, because these checks exist for
     * Windows paths and a case insensitive comparison is correct for them everywhere.
     *
     * ## Why `strtolower` and not `mb_strtolower`
     *
     * Because the launcher runs under a bare `php.exe` with no ini, so `mbstring` is not loaded and
     * `mb_strtolower` is an undefined function. That is not a theoretical concern: the sandbox gate
     * reached this line and died with "Call to undefined function
     * Papyrus\Launcher\mb_strtolower()", on the very first line of the layout step, in an install
     * where every other file was correct.
     *
     * `strtolower` is ASCII only, which is the right trade here anyway. The comparison is between
     * two filesystem paths on the same machine, so the only characters that can differ in case are
     * ones the filesystem itself treats case insensitively. Folding non-ASCII would change paths
     * that were never ambiguous, and a wrong refusal is worse than a missed one: this method
     * exists to stop a reader's library being written inside an install directory, and it should
     * never refuse a legitimate layout.
     */
    private function comparable(string $path): string
    {
        return strtolower($this->normalise($path));
    }
}
