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

    /**
     * The address Suwayomi is pinned to, overriding its own `0.0.0.0` default.
     *
     * A named constant rather than a literal in `prepare()`, because it is referenced by a test that
     * asserts the launcher writes this exact value, and a test that repeats the literal is a test
     * that passes when the value changes on both sides at once.
     */
    public const LOOPBACK = '127.0.0.1';

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

        $this->setValue($this->configurationFile($dataDirectory), 'server.port', (string) $port);

        /*
         * The address is pinned to loopback, and it is written on every start for the same reason the
         * port is: this is the only place that decides what the reader's library is reachable from.
         *
         * Suwayomi's own default is `server.ip = "0.0.0.0"`, every interface, which it writes into
         * `server.conf` itself with its own annotation:
         *
         *     server.ip = "0.0.0.0" # default: "0.0.0.0"
         *
         * So leaving it alone is not a neutral choice, it is the choice to serve on every interface.
         * Measured on a running install, with the default in place:
         *
         *     Get-NetTCPConnection -LocalPort 4567 -State Listen   LocalAddress :::4567   java
         *     127.0.0.1:4567      reachable
         *     192.0.2.1:4567    reachable
         *     192.0.2.2:4567     reachable
         *
         * The GraphQL API does require a login, so this was measured to the end rather than assumed
         * from the socket:
         *
         *     { sources { totalCount } } unauthenticated   Unauthorized
         *     login as the configured reader, from 192.0.2.1   succeeded
         *     { sources { totalCount } } with that token    223
         *
         * So a reader's entire library, 223 sources on the machine this was measured on, was
         * readable by anything that could route to the machine and guess a weak password. This is the
         * same defect `LauncherBindTest` records for FrankenPHP, where the listener was the IPv6
         * wildcard while every document in the project said nothing outside the machine was reachable.
         * FrankenPHP is now bound with an explicit `bind 127.0.0.1 ::1`. This is the other half.
         *
         * The launcher probes `127.0.0.1` throughout (`Supervisor::suwayomiAnswers()` and friends),
         * so loopback is the address this already expects and nothing else has to change.
         */
        $this->setValue(
            $this->configurationFile($dataDirectory),
            'server.ip',
            $this->escapeHocon(self::LOOPBACK),
        );

        /*
         * Only backupPath is set, and the other backup settings are left exactly as Suwayomi
         * wrote them.
         *
         * Suwayomi already defaults to a daily backup with 14 day retention, measured on the file
         * it writes:
         *
         *     server.backupInterval = 1  # default: 1 ; range: [0, +∞] ; 0 == disabled
         *     server.backupTTL      = 14 # default: 14 ; range: [0, +∞] ; Backup retention in days
         *     server.backupTime     = "00:00" # default: "00:00" ; Daily backup time (HH:MM)
         *
         * So writing them would change nothing and would rewrite lines carrying metadata Suwayomi
         * uses to judge whether its own file is out of date. Midnight and 03:17 are also different
         * minutes on purpose: this project's own snapshot runs at 03:17, and a backup that
         * collides with it competes for the same disk at the same moment.
         */
        $this->setValue(
            $this->configurationFile($dataDirectory),
            'server.backupPath',
            $this->escapeHocon($this->backupDirectory($dataDirectory)),
        );
    }

    /**
     * Where Suwayomi writes its own daily library backup.
     *
     * Left empty, Suwayomi keeps its backup inside its own root, which is inside the data
     * directory and therefore fine, so this is belt and braces rather than a repair. It is set
     * anyway so that a reader looking for their library backup finds every backup in one place,
     * next to Papyrus's own snapshots, which are the other half of the same story.
     *
     * The directory is created rather than left for Suwayomi to create at 03:17, because a backup
     * that has never run cannot tell a reader its path works.
     */
    private function backupDirectory(string $dataDirectory): string
    {
        $path = $this->rootDirectory($dataDirectory).DIRECTORY_SEPARATOR.'backups';

        if (! is_dir($path)) {
            @mkdir($path, 0o777, true);
        }

        return $path;
    }

    /**
     * Quote a HOCON value, since a Windows path is full of backslashes.
     *
     * HOCON reads a bare `C:\Users\<you>` as an escaped string with unquoted keys, and the install
     * then reads a path that does not exist. Measured on the file Suwayomi itself writes, every
     * path-valued setting is double quoted.
     */
    private function escapeHocon(string $value): string
    {
        return '"'.str_replace('\\', '\\\\', $value).'"';
    }

    /**
     * Put one setting into an existing HOCON file, or add it if it is not there.
     *
     * One method for every setting rather than one for the port, because the reasoning below about
     * trailing comments applies equally to `server.backupPath`, and a second copy of it would be a
     * second thing to get wrong.
     *
     * @throws RuntimeException when the file cannot be read or written
     */
    private function setValue(string $path, string $key, string $value): void
    {
        $existing = is_file($path) ? @file_get_contents($path) : false;

        if ($existing === false && is_file($path)) {
            throw new RuntimeException('Could not read '.$path.', so '.$key.' was not changed.');
        }

        $contents = is_string($existing) ? $existing : "# Written by Papyrus. Suwayomi expands this.\n";

        /*
         * Only the value is replaced, never the whole line.
         *
         * Suwayomi writes its defaults as trailing comments and uses them to decide whether a
         * user's file is out of date. Replacing `server.port = 4567 # default: 4567 ; range:
         * [1, 65535]` wholesale drops that metadata, which is the one thing this method must not
         * do. So the value is captured, replaced, and everything after it is put back.
         *
         * Through preg_replace_callback rather than preg_replace, because the replacement string
         * of preg_replace is itself a template: a backslash in it is an escape, so the doubled
         * separators of a Windows path came out single. HOCON reads `\` inside a quoted string as
         * the start of an escape sequence and rejects `"\s"`, so that wrote a path Suwayomi cannot
         * parse, and it did so quietly, into a backup setting nobody reads until a backup is
         * missing. A callback hands the value over as a string with no interpretation of it.
         */
        $replaced = preg_replace_callback(
            '/^('.preg_quote($key, '/').'\s*=\s*)(\S+|"[^"]*")(.*)$/m',
            static fn (array $matches): string => $matches[1].$value.$matches[3],
            $contents,
            1,
            $count,
        );

        if ($replaced === null || $count === 0) {
            $replaced = rtrim($contents, "\r\n")."\n".$key.' = '.$value."\n";
        }

        if (@file_put_contents($path, $replaced) === false) {
            throw new RuntimeException('Could not write '.$path.', so '.$key.' was not changed.');
        }
    }
}
