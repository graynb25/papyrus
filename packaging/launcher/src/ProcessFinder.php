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
 * ## Why matching takes several tokens rather than one
 *
 * Because one token is not enough to identify a process, and the evidence for that is measured
 * rather than assumed. On Windows, `proc_open` quotes every argument, so a worker started from
 * an install root containing a space has this command line:
 *
 *     "C:\...\php.exe" "-c" "D:\...\php.ini" "D:\Program Files\Papyrus\artisan" "queue:work"
 *
 * A single marker of `D:\Program Files\Papyrus\artisan queue:work` therefore never matches,
 * because a quote and a space sit between the two halves. Measured on this machine, not assumed.
 *
 * Worse, and this is the part that decides the design: `queue:work` on its own matches *any*
 * Laravel queue worker on the machine, including one belonging to a developer's own checkout.
 * Killing that would be the worst outcome this class could produce, so no single token is ever
 * sufficient. A process is this install's only when the install root **and** the role marker are
 * both present.
 *
 * ## Why the tokens travel as JSON on stdin
 *
 * Because the three obvious channels were each measured and each is broken:
 *
 * - argv with `-Command`: needs double quotes wrapped around source that itself contains double
 *   quotes. Escaping that nest correctly was tried, and it silently returned nothing on a
 *   machine where the identical command worked pasted into a shell. That failure mode is the
 *   worst one available, because health reports every process broken while its own query tool is
 *   the problem.
 * - argv with `-File`: binds only the **first** positional argument into a `[string[]]`. Measured:
 *   three tokens in, `COUNT 1`. The rest are dropped without complaint.
 * - argv as a JSON string: arrives already split on spaces, because the shell parsed it before
 *   PowerShell did.
 *
 * stdin has no shell and no argument parser anywhere in the path, so a path containing a space
 * is simply a path containing a space. JSON rather than a delimiter because a delimiter is one
 * more character that could appear inside a path, and the tokens are Windows paths.
 *
 * The payload must come from `json_encode`, never from a hand written string: `\D` and `\P` are
 * not valid JSON escapes, and a probe built with them fails in a way that looks exactly like the
 * approach being wrong.
 *
 * ## Why the output is read from a file
 *
 * Because reading it from a pipe returns nothing, and the reason is worth writing down because
 * it cost the most of anything here and looks like a working probe.
 *
 * With the child's stdout attached to a PHP pipe, PowerShell writes its startup banner to that
 * pipe and the script's own output never arrives. `find()` therefore returned an empty list on a
 * machine where the identical script, flags and tokens found the process every time. Writing the
 * child's stdout to a file and reading it after the child exits works.
 *
 * The reason this survived several wrong diagnoses is that a matcher for one specific pid is
 * indistinguishable from a matcher that matched nothing when that pid is not running. An earlier
 * probe printed a diagnostic before reading its tokens, so every run produced some output, it was
 * just the banner. Output that proves the probe *ran* is not output that proves it *answered*, and
 * only the second kind is worth asserting on.
 */
final class ProcessFinder
{
    /**
     * @param  (callable(list<string>): list<int>)|null  $probe  Overrides the OS query, for tests.
     * @param  (callable(string): array{status: int})|null  $killer  Overrides running `taskkill`, for
     *                                                               tests. It exists because the defect this class was measured having is a defect in the
     *                                                               *command*, and a command nobody can assert on is a command nobody can catch being wrong.
     *                                                               Only the Windows branch uses it; the Unix branch signals with `posix_kill`.
     */
    public function __construct(private $probe = null, private $killer = null) {}

    /**
     * The pids of every running process whose command line contains all of these tokens.
     *
     * @param  list<string>  $tokens
     * @return list<int>
     */
    public function find(array $tokens): array
    {
        if ($tokens === []) {
            return [];
        }

        if ($this->probe !== null) {
            return ($this->probe)($tokens);
        }

        $script = tempnam(sys_get_temp_dir(), 'papyrus-find-');

        if ($script === false) {
            return [];
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

        file_put_contents($script, self::probeScript());

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

            return [];
        }

        $process = @proc_open(
            [
                'powershell',
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

            return [];
        }

        // json_encode, for the reason in the class docblock.
        fwrite($pipes[0], (string) json_encode($tokens));
        fclose($pipes[0]);

        proc_close($process);

        $output = (string) file_get_contents($out);

        @unlink($script);
        @unlink($out);

        $pids = [];

        foreach (preg_split('#\R#', $output) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && ctype_digit($line)) {
                $pids[] = (int) $line;
            }
        }

        // array_unique keeps the original keys, so it can leave gaps. This returns a list.
        return array_values(array_unique($pids));
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
                sprintf('taskkill /PID %d /T /F 2>NUL', $pid),
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
     * The filter is written out longhand. A `Where-Object` with a nested `Where-Object` reads as
     * though it filters on all tokens and is not the same thing at all, which is how a matcher
     * quietly becomes a matcher for any one token.
     */
    private static function probeScript(): string
    {
        return <<<'POWERSHELL'
$ErrorActionPreference = 'SilentlyContinue'

$raw = [Console]::In.ReadToEnd()

try {
    $tokens = ConvertFrom-Json -InputObject $raw
}
catch {
    exit 1
}

if ($null -eq $tokens) {
    exit 1
}

foreach ($proc in (Get-CimInstance Win32_Process)) {
    if (-not $proc.CommandLine) { continue }

    $matched = $true

    foreach ($token in $tokens) {
        if (-not $proc.CommandLine.Contains($token)) {
            $matched = $false
            break
        }
    }

    if ($matched) {
        Write-Output $proc.ProcessId
    }
}
POWERSHELL;
    }
}
