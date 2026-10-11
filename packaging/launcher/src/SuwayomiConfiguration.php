<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

use RuntimeException;

/**
 * Telling Suwayomi where to keep its data and which port to serve on.
 *
 * ## Why this class exists
 *
 * Because neither of those is an environment variable, and the launcher spent a long time
 * believing it was. It passed `TACHIDESK_DIR` to the JVM, which Suwayomi does not read, and it
 * waited on a configured port it had never communicated. The result was a clean install that could
 * never start on any port but Suwayomi's own default, and a reader's library silently written to
 * their Windows profile instead of beside the install.
 *
 * ## How Suwayomi actually decides, and how that was established
 *
 * Reading the jar's constant pool:
 *
 * ```
 * xyz/nulldev/ts/config/ApplicationRootDirKt
 *   System.getProperty("suwayomi.tachidesk.config.server.rootDir", AppDirs.getUserDataDir("Tachidesk"))
 * ```
 *
 * A **JVM system property**, falling back to `ca.gosyer.appdirs`, which on Windows is
 * `%LOCALAPPDATA%\<appName>`. A search of every class in the jar's `suwayomi/` packages found no
 * environment variable name at all.
 *
 * A string in a class file is evidence, not proof, so this was then run against a real JVM with
 * `TACHIDESK_DIR` deliberately still set. The root moved and the profile was untouched, which
 * separates the two mechanisms rather than assuming one.
 *
 * The port comes from `server.conf` in that root, which is HOCON with dotted keys:
 *
 * ```
 * server.ip = "0.0.0.0" # default: "0.0.0.0"
 * server.port = 4567 # default: 4567 ; range: [1, 65535]
 * ```
 *
 * ## Two things the run established that the code has to respect
 *
 * **The root must exist before the JVM starts.** Pointed at a directory that did not exist,
 * Suwayomi died inside `ApplicationRootDirKt.getApplicationRootDir` before reading anything, which
 * looks exactly like the property being ignored.
 *
 * **Suwayomi owns and expands `server.conf`.** Given a two line file it wrote an 8 KB one, filling
 * in every default and its own annotations. So writing the port is safe and writing the whole file
 * is not: the launcher changes the port line and leaves everything else alone.
 */
final class SuwayomiConfiguration
{
    /**
     * The JVM property that relocates Suwayomi's root directory.
     *
     * A property rather than an environment variable, which is the whole reason `TACHIDESK_DIR`
     * did nothing.
     */
    public const ROOT_PROPERTY = 'suwayomi.tachidesk.config.server.rootDir';

    /** The directory, inside the data directory, that holds everything Suwayomi owns. */
    public function rootDirectory(string $dataDirectory): string
    {
        return $dataDirectory.DIRECTORY_SEPARATOR.'suwayomi';
    }

    public function configurationFile(string $dataDirectory): string
    {
        return $this->rootDirectory($dataDirectory).DIRECTORY_SEPARATOR.'server.conf';
    }

    /**
     * The database Suwayomi keeps, which is how the layout is verified rather than assumed.
     */
    public function databaseFile(string $dataDirectory): string
    {
        return $this->rootDirectory($dataDirectory).DIRECTORY_SEPARATOR.'database.mv.db';
    }

    /**
     * Create the root and put the launcher's port into its configuration file.
     *
     * The port is written on every start rather than only when absent, and that is a decision
     * rather than an oversight. Papyrus chooses the port, waits on it, and reports on it; letting
     * Suwayomi's own settings take it would recreate exactly the class of bug this fixes, where the
     * launcher and the thing it supervises disagree about a port neither can see.
     *
     * Only the port line is touched. Suwayomi annotates that file with defaults and migrates it
     * itself, so a rewrite that dropped the annotations would be undone on the next start.
     *
     * @throws RuntimeException when the root cannot be created or the file cannot be written
     */
    public function prepare(string $dataDirectory, int $port): void
    {
        $root = $this->rootDirectory($dataDirectory);

        if (! is_dir($root) && ! @mkdir($root, 0o777, true) && ! is_dir($root)) {
            throw new RuntimeException(
                'Could not create '.$root.', which is where Suwayomi keeps the reader\'s library. '
                .'Without it Suwayomi falls back to the Windows profile and the install stops being '
                .'self contained.'
            );
        }

        $this->writePort($this->configurationFile($dataDirectory), $port);
    }

    /**
     * Put one number into an existing HOCON file, or make the file if it is not there.
     *
     * @throws RuntimeException when the file cannot be read or written
     */
    private function writePort(string $path, int $port): void
    {
        $existing = is_file($path) ? @file_get_contents($path) : false;

        if ($existing === false && is_file($path)) {
            throw new RuntimeException('Could not read '.$path.', so the port was not changed.');
        }

        $contents = is_string($existing) ? $existing : "# Written by Papyrus. Suwayomi expands this.\n";

        /*
         * Only the value is replaced, never the whole line.
         *
         * Suwayomi writes its defaults as trailing comments and uses them to decide whether a
         * user's file is out of date. Replacing `server.port = 4567 # default: 4567 ; range:
         * [1, 65535]` wholesale drops that metadata, which is the one thing this method must not
         * do. So the value is captured, replaced, and everything after it is put back.
         */
        $replaced = preg_replace(
            '/^(server\.port\s*=\s*)\S+(.*)$/m',
            '${1}'.$port.'${2}',
            $contents,
            1,
            $count,
        );

        if ($replaced === null || $count === 0) {
            $replaced = rtrim($contents, "\r\n")."\nserver.port = ".$port."\n";
        }

        if (@file_put_contents($path, $replaced) === false) {
            throw new RuntimeException('Could not write '.$path.', so Suwayomi would keep its own port.');
        }
    }
}
