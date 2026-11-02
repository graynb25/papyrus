<?php

namespace Papyrus\Launcher;

/**
 * Finding the processes of an install from a process that did not start them.
 *
 * ## Why this exists
 *
 * Because `stop` is documented as a command a reader types, and the tray icon is documented as
 * calling these same commands. Both arrive as their own process, and neither has a `proc_open`
 * handle to anything, because `ManagedProcess::stop()` needs a handle it did not create.
 *
 * So `stop` found an empty `$started` array, reported "Nothing was started by this session, so
 * nothing was stopped", and exited 0. The install stayed up and the reader was told, in a
 * confident and cheerful tone, that there was nothing to stop. The sandbox gate caught this: it
 * started the install, ran `stop` from a second process, and found the ports still held and the
 * JVM still alive.
 *
 * The honest version of a fix is to ask the operating system which processes are this install's,
 * the same way {@see Supervisor::isRunning()} already does for liveness.
 *
 * ## Why matching takes a name, a path and several tokens
 *
 * Because one substring is not enough to identify a process, and the evidence for that is measured
 * rather than assumed. On Windows, `proc_open` quotes every argument, so a worker started from
 * an install root containing a space has this command line:
 *
 *     "C:\...\php.exe" "-c" "D:\...\php.ini" "D:\Program Files\Papyrus\artisan" "queue:work"
 *
 * A single marker of `D:\Program Files\Papyrus\artisan queue:work` therefore never matches,
 * because a quote and a space sit between the two halves. Measured on this machine, not assumed.
 *
 * Worse, `queue:work` on its own matches *any* Laravel queue worker on the machine, including one
 * belonging to a developer's own checkout. And the web role marker was the literal `frankenphp`,
 * which also appears in this install's data path, so an editor with the data directory's
 * `Caddyfile` open matched the web server and was force killed with its children.
 *
 * So the query has exact anchors as well as tokens: the image name, the executable path, and the
 * command line markers that identify this install. A text editor is named `notepad.exe` and lives
 * in `C:\Windows`, so it cannot match either anchor even though its command line names the same
 * Caddyfile.
 *
 * ## Why the query is built in PHP rather than in the script
 *
 * The script used to receive the tokens on stdin and filter the process table itself. That made
 * the matcher untestable without a real process, and the matcher is the part that was wrong. The
 * script now lists name, executable path and command line for every process, and
 * {@see ProcessFinder::matches()} decides. A test hands it a table that includes the editor and
 * the real web server and asserts which pid survives.
 *
 * The script reads nothing from stdin at all, which also removes the quoting problems the old
 * channel had: `-Command` needed a nest of quotes that silently returned nothing, `-File` bound
 * only the first positional argument, and a JSON argv string arrived already split on spaces.
 *
 * ## Why the output is read from a file
 *
 * Because reading it from a pipe returns nothing, and the reason is worth writing down because
 * it cost the most of anything here and looks like a working probe.
 *
 * With the child's stdout attached to a PHP pipe, PowerShell writes its startup banner to that
 * pipe and the script's own output never arrives. `find()` therefore returned an empty list on a
 * machine where the identical script and flags found the process every time. Writing the
 * child's stdout to a file and reading it after the child exits works.
 *
 * The reason this survived several wrong diagnoses is that a matcher for one specific pid is
 * indistinguishable from a matcher that matched nothing when that pid is not running. An earlier
 * probe printed a diagnostic before it answered, so every run produced some output, it was just
 * the banner. Output that proves the probe *ran* is not output that proves it *answered*, and
 * only the second kind is worth asserting on.
 */
final class ProcessFinder
{
    /**
     * @param  (callable(array<string, mixed>): list<array{pid: int, name: string, executable: string, command: string}>)|null  $probe
     *                                                                                                                                  Overrides the OS query, for tests. It is handed the query so a test can record what was
     *                                                                                                                                  asked, and returns the process table rather than a filtered answer so the matching
     *                                                                                                                                  itself is exercised.
     * @param  (callable(string): array{status: int})|null  $killer  Overrides running `taskkill`, for
     *                                                               tests. It exists because the defect this class was measured having is a defect in the
     *                                                               *command*, and a command nobody can assert on is a command nobody can catch being wrong.
     *                                                               Only the Windows branch uses it; the Unix branch signals with `posix_kill`.
     */
    public function __construct(private $probe = null, private $killer = null) {}

    /**
     * The pids of every running process that matches the query.
     *
     * A query is a name, an executable path, and any number of command line tokens. Every part
     * that is given must match, and a query with nothing in it matches nothing rather than
     * everything.
     *
     * ## Why the name and the executable are separate from the tokens
     *
     * Because a substring alone cannot tell this install's web server from an editor that has the
     * same file open. Measured: the web role token was the literal `frankenphp`, and the data
     * directory contains `...\Papyrus\frankenphp\Caddyfile`, so a text editor, a diff tool or a
     * backup tool whose command line named that file matched `stop` and was force killed with its
     * children. The name and the executable path are exact anchors, and the tokens are what
     * distinguish this install from another one running the same program.
     *
     * A list of bare strings is accepted as a token-only query, because that is the shape every
     * existing caller used and removing it would be a second change hidden inside this one.
     *
     * @param  array<string, mixed>|list<string>  $query
     * @return list<int>
     *
     * @throws ProcessProbeFailed When the operating system process list could not be read.
     */
    public function find(array $query): array
    {
        $spec = $this->normalise($query);

        if (! $this->hasAnchor($spec)) {
            return [];
        }

        $processes = $this->probe !== null ? ($this->probe)($spec) : $this->queryProcesses();

        $pids = [];

        foreach ($processes as $process) {
            if (self::matches($process, $spec)) {
                $pids[] = $process['pid'];
            }
        }

        // array_unique keeps the original keys, so it can leave gaps. This returns a list.
        return array_values(array_unique($pids));
    }

    /**
     * Whether one process row is the process a query names.
     *
     * Public because this is the decision that was wrong, and a decision that cannot be handed a
     * process table in a test is a decision that has to be read rather than exercised.
     *
     * @param  array{pid?: int, name?: string, executable?: string, command?: string}  $process
     * @param  array{name: string|null, executable: string|null, tokens: list<string>}  $spec
     */
    public static function matches(array $process, array $spec): bool
    {
        if ($spec['name'] !== null
            && strcasecmp((string) ($process['name'] ?? ''), $spec['name']) !== 0) {
            return false;
        }

        if ($spec['executable'] !== null
            && ! self::samePath((string) ($process['executable'] ?? ''), $spec['executable'])) {
            return false;
        }

        $command = (string) ($process['command'] ?? '');

        foreach ($spec['tokens'] as $token) {
            if ($token === '' || ! str_contains($command, $token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Two paths naming the same file, on a platform whose paths are case insensitive.
     *
     * Separators are normalised because the same path reaches a process command line with
     * backslashes on Windows and forward slashes when a build stage wrote it.
     */
    private static function samePath(string $left, string $right): bool
    {
        $normalise = static fn (string $path): string => strtolower(
            rtrim(str_replace('/', '\\', $path), '\\'),
        );

        return $left !== '' && $right !== '' && $normalise($left) === $normalise($right);
    }

    /**
     * @param  array<string, mixed>|list<string>  $query
     * @return array{name: string|null, executable: string|null, tokens: list<string>}
     */
    private function normalise(array $query): array
    {
        if (array_is_list($query)) {
            return [
                'name' => null,
                'executable' => null,
                'tokens' => array_map('strval', $query),
            ];
        }

        $tokens = $query['tokens'] ?? [];

        return [
            'name' => isset($query['name']) && is_string($query['name']) && $query['name'] !== ''
                ? $query['name']
                : null,
            'executable' => isset($query['executable']) && is_string($query['executable']) && $query['executable'] !== ''
                ? $query['executable']
                : null,
            'tokens' => is_array($tokens) ? array_values(array_map('strval', $tokens)) : [],
        ];
    }

    /**
     * @param  array{name: string|null, executable: string|null, tokens: list<string>}  $spec
     */
    private function hasAnchor(array $spec): bool
    {
        return $spec['name'] !== null || $spec['executable'] !== null || $spec['tokens'] !== [];
    }

    /**
     * The whole process table, read through PowerShell.
     *
     * The filtering happens here rather than in the script, so the matcher is a PHP method a test
     * can hand a process table to. A PowerShell `Where-Object` would be a second implementation
     * of the rule, and the rule is the part that was wrong.
     *
     * @return list<array{pid: int, name: string, executable: string, command: string}>
     */
    private function queryProcesses(): array
    {
        $script = tempnam(sys_get_temp_dir(), 'papyrus-find-');

        if ($script === false) {
            throw new ProcessProbeFailed(
                'The process list could not be read: a temporary PowerShell script could not be created.'
            );
        }

        /*
         * PowerShell will not run a file passed to -File unless it ends in .ps1.
         *
         * Measured, and it is the reason this class found nothing while looking like it worked.
         * `tempnam()` produces a name with no extension at all, so `-File C:\...\papyrus-find-abc123`
         * is refused outright with "the file does not have a '.ps1' extension". The script never
         * ran, and what came back on stdout was PowerShell printing its banner next to that
         * complaint, which reads exactly like a script that ran and printed nothing useful.
         *
         * That is the whole shape of the mistake, and it is the third time this session a probe
         * reported a plausible looking result that was really its own failure being echoed back:
         * an unreadable stderr presented as a missing log, a parse error presented as a quiet
         * install, and now a refusal presented as an empty machine.
         */
        $script .= '.ps1';

        if (file_put_contents($script, self::probeScript()) === false) {
            @unlink($script);

            throw new ProcessProbeFailed(sprintf(
                'The process list could not be read: the PowerShell probe script could not be written to %s.',
                $script,
            ));
        }

        /*
         * The child's output goes to a file, and is read after it exits. Not read through a pipe.
         *
         * Measured, and the measurement is the whole reason this class exists in this shape. With
         * the child's stdout attached to a PHP pipe, PowerShell prints its startup banner and the
         * script's own output never arrives, so this method returned an empty list on a machine
         * where the identical script, flags and tokens found the process every time when its
         * output was read from a file.
         *
         * What made this expensive to find is that the probe appeared to work. An earlier version
         * printed a diagnostic line before reading its tokens, so every run produced *some* output,
         * it was just the banner rather than the answer, and a matcher for a specific pid can look
         * identical to a matcher that found nothing when the pid you are looking for is absent.
         *
         * A file also removes a deadlock that a pipe invites: writing tokens to stdin and then
         * reading stdout back can block forever if the child ever fills its output buffer while
         * this process is still writing. Here nothing reads until the child has exited.
         */
        $out = tempnam(sys_get_temp_dir(), 'papyrus-find-out-');

        if ($out === false) {
            @unlink($script);

            throw new ProcessProbeFailed(
                'The process list could not be read: a temporary PowerShell output file could not be created.'
            );
        }

        $process = @proc_open(
            [
                SystemTools::powershell(),
                '-NoProfile',
                '-NonInteractive',
                '-ExecutionPolicy',
                'Bypass',
                '-File',
                $script,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $out, 'w'],
                2 => ['file', $out, 'a'],
            ],
            $pipes,
            null,
            // PowerShell exits nonzero on a parse error, and inheriting this process's exit code
            // would make a failed probe look like a successful one that found nothing.
            ['PAPYRUS_FINDER' => '1'] + (getenv() ?: []),
        );

        if (! is_resource($process)) {
            @unlink($script);
            @unlink($out);

            throw new ProcessProbeFailed(
                'The process list could not be read: the PowerShell probe could not be started.'
            );
        }

        // The script reads nothing from stdin. Closing it lets the child exit promptly.
        fclose($pipes[0]);

        $exit = proc_close($process);
        $output = @file_get_contents($out);

        @unlink($script);
        @unlink($out);

        if ($output === false) {
            throw new ProcessProbeFailed(sprintf(
                'The process list could not be read: the PowerShell output file could not be read after PowerShell exited with code %d.',
                $exit,
            ));
        }

        return self::parseProbeOutput($output, $exit);
    }

    /**
     * Turn raw PowerShell output into process rows, or refuse it.
     *
     * Public so the failure cases can be exercised without PowerShell, which is the only way to
     * assert that empty output, a banner, malformed JSON and a non-zero exit are all named as
     * failures rather than returned as a plausible empty process table.
     *
     * @return list<array{pid: int, name: string, executable: string, command: string}>
     *
     * @throws ProcessProbeFailed
     */
    public static function parseProbeOutput(string $output, int $exit): array
    {
        if ($exit !== 0) {
            throw new ProcessProbeFailed(sprintf(
                'The process list could not be read: PowerShell exited with code %d.%s',
                $exit,
                self::printed($output),
            ));
        }

        if (trim($output) === '') {
            throw new ProcessProbeFailed(
                'The process list could not be read: PowerShell exited with code 0 but printed no output.'
            );
        }

        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            throw self::notAProcessTable($output, $exit);
        }

        // ConvertTo-Json gives a bare object for a one-row table. Accept both shapes.
        if (isset($decoded['pid'])) {
            $decoded = [$decoded];
        } elseif (! array_is_list($decoded)) {
            throw self::notAProcessTable($output, $exit);
        }

        $processes = [];

        foreach ($decoded as $row) {
            if (! is_array($row) || ! isset($row['pid'])) {
                continue;
            }

            $processes[] = [
                'pid' => (int) $row['pid'],
                'name' => (string) ($row['name'] ?? ''),
                'executable' => (string) ($row['executable'] ?? ''),
                'command' => (string) ($row['command'] ?? ''),
            ];
        }

        return $processes;
    }

    private static function notAProcessTable(string $output, int $exit): ProcessProbeFailed
    {
        return new ProcessProbeFailed(sprintf(
            'The process list could not be read: PowerShell output was not a JSON process table (exit code %d).%s',
            $exit,
            self::printed($output),
        ));
    }

    /**
     * A short, single-line excerpt of what the probe printed, for a failure message.
     *
     * Bounded because a PowerShell banner or a stack trace can be long, and the point is to name
     * the failure rather than to put the whole output into a terminal message.
     */
    private static function printed(string $output): string
    {
        $snippet = trim((string) preg_replace('/\s+/', ' ', $output));

        if ($snippet === '') {
            return '';
        }

        if (strlen($snippet) > 300) {
            $snippet = substr($snippet, 0, 300).'...';
        }

        return ' It printed: '.$snippet;
    }

    /**
     * Terminate a pid this process did not start.
     *
     * ## Why there is no graceful attempt on Windows, and it was measured
     *
     * Because `taskkill` without `/F` **cannot terminate a console process on Windows at all**. It
     * sends a close request to windows and console applications have none. Measured on the reader's
     * own install, against a `php artisan queue:work` this launcher did not start:
     *
     *     taskkill /PID 31548            exit 1
     *       ERROR: The process with PID 31548 could not be terminated.
     *       Reason: This process can only be terminated forcefully (with /F option).
     *
     * So the first version, which tried `taskkill /PID <pid> /T` and fell back to `/F`, was
     * attempting a graceful stop that cannot exist on this platform.
     *
     * Worse than useless, because it reports success it did not achieve. Against the JVM tree:
     *
     *     taskkill /PID 8396 /T          exit 128
     *       ERROR:   The process with PID 4964  could not be terminated. Reason: ... forcefully
     *       SUCCESS: Sent termination signal to process with PID 30280, child of PID 8396.
     *       ERROR:   The process with PID 30912 could not be terminated. Reason: ... forcefully
     *       ERROR:   The process with PID 8396  could not be terminated. Reason: child processes ...
     *
     * 30280 was still running afterwards. `SUCCESS: Sent termination signal` means a signal was sent,
     * not that anything died, and reading it as a stop is the exact failure this project's notes call
     * a probe reporting a plausible result that is really its own failure echoed back.
     *
     * And for comparison, the one call that does work, whose every SUCCESS line is true:
     *
     *     taskkill /PID 8396 /T /F       exit 0
     *       SUCCESS: The process with PID 4964 ... has been terminated.
     *       SUCCESS: The process with PID 30280 ... has been terminated.
     *       SUCCESS: The process with PID 30912 ... has been terminated.
     *       SUCCESS: The process with PID 8396  ... has been terminated.
     *
     * So one call, and the outcome is named for what it is. `/T` is kept because Suwayomi's
     * `jcef_helper` children hold files open inside the reader's data directory, and a JVM stopped
     * without its tree leaves that directory undeletable.
     *
     * ## Why the Unix branch still tries politely
     *
     * Because `SIGTERM` is real there, and a process that handles it closes its database and flushes
     * what it is holding. Same order as {@see ManagedProcess::stop()}, which is the polite path for
     * processes this process *did* start and which has a handle to ask.
     *
     * @return string What happened, in the words the caller reports to a reader.
     */
    public function terminate(int $pid): string
    {
        if ($pid <= 0 || $pid === getmypid()) {
            // Never this process. `stop` is itself a PHP process whose command line may contain
            // the install root, and a matcher loose enough to find it would otherwise make the
            // launcher kill the reader mid-command.
            return 'refused, that is this process';
        }

        if (! ProcessIdentity::isAlive($pid)) {
            return 'already gone';
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $status = ($this->killer ?? [self::class, 'taskkill'])(
                sprintf('%s /PID %d /T /F 2>NUL', escapeshellarg(SystemTools::taskkill()), $pid),
            )['status'];

            return $status === 0 && $this->waitForExit($pid, 10) ? 'killed' : 'could not be stopped';
        }

        // SIGTERM first, SIGKILL second, which is the same order ManagedProcess::stop uses.
        @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);

        if ($this->waitForExit($pid, 10)) {
            return 'terminated';
        }

        @posix_kill($pid, defined('SIGKILL') ? SIGKILL : 9);

        return $this->waitForExit($pid, 5) ? 'killed' : 'still running';
    }

    /**
     * Run one command and report only its exit status.
     *
     * @return array{status: int}
     */
    private static function taskkill(string $command): array
    {
        @exec($command, $output, $status);

        return ['status' => $status];
    }

    /** Whether the pid has gone within the grace period. */
    private function waitForExit(int $pid, int $seconds): bool
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if (! ProcessIdentity::isAlive($pid)) {
                return true;
            }

            usleep(200000);
        }

        return ! ProcessIdentity::isAlive($pid);
    }

    /**
     * The PowerShell probe, as a heredoc rather than a resource.
     *
     * Written inline so the launcher keeps its rule of depending on nothing but PHP itself: a
     * `.ps1` shipped beside the code is one more file to lose in packaging, and this is the only
     * thing in the launcher that needs a shell.
     *
     * The script lists the process table and does no filtering. The matcher lives in PHP for the
     * reason the class docblock gives: the rule is the part that was wrong, and a PowerShell
     * `Where-Object` would be a second implementation no test could hand a process table to.
     */
    private static function probeScript(): string
    {
        return <<<'POWERSHELL'
$ErrorActionPreference = 'SilentlyContinue'

$rows = @(Get-CimInstance Win32_Process | ForEach-Object {
    [pscustomobject]@{
        pid = [int]$_.ProcessId
        name = [string]$_.Name
        executable = [string]$_.ExecutablePath
        command = [string]$_.CommandLine
    }
})

[Console]::Out.Write((ConvertTo-Json -InputObject @($rows) -Compress))
POWERSHELL;
    }
}
