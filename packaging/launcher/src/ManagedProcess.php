<?php

namespace Papyrus\Launcher;

use RuntimeException;

/**
 * One child process, started from an absolute path, stopped deliberately.
 *
 * ## Why absolute paths and never PATH
 *
 * Because trap 217 is about exactly this. On this machine, `java` on PATH is Oracle's
 * `javapath` shim, which is not the JVM and does not stay alive, and on a clean Windows
 * machine PATH may contain no Java at all. A launcher that resolves an executable by name is
 * running whatever the user's shell happens to point at, which is the definition of an install
 * that works on the author's machine.
 *
 * Every path here is checked to exist before the process starts, so a missing runtime is a
 * message naming the file rather than an OS error naming a search path.
 *
 * ## Why output goes to files and not to pipes
 *
 * Because a pipe that nobody drains fills, and a filled pipe blocks the child forever. A
 * long import writing progress to a supervisor nobody is reading would wedge the one process
 * the reader is waiting on. Files also mean the log survives a supervisor crash, which is the
 * whole reason a reader can be asked to send one.
 *
 * ## Why stop is terminate and then kill
 *
 * Because a polite signal that a JVM or a PHP worker ignores leaves an orphan holding port 4567
 * or the database. `stop` waits, then kills, then waits again, and reports if either step had
 * to be used rather than pretending the first one worked.
 */
final class ManagedProcess
{
    /**
     * A process log above this size is rotated before the child opens it.
     *
     * Five megabytes is several runs of ordinary output and small enough that a debug loop
     * cannot fill a reader's disk between starts.
     */
    private const MAX_LOG_BYTES = 5 * 1024 * 1024;

    /** How many rotated process logs are kept. */
    private const KEPT_ROTATIONS = 3;

    /** @var resource|null */
    private $handle = null;

    private ?int $pid = null;

    private bool $started = false;

    public function __construct(
        public readonly string $name,
        private readonly string $executable,
        /** @var list<string> $arguments */
        private readonly array $arguments,
        private readonly string $workingDirectory,
        private readonly string $logFile,
        /** @var array<string, string> $environment */
        private readonly array $environment = [],
    ) {}

    /**
     * The platform's null device, for a child that needs a stdin and must not consume one.
     *
     * Pointing stdin at the log is wrong twice over. It opens the file for reading, which
     * fails outright when the file does not exist yet, which is the usual case on a first run,
     * and it lets the child read back bytes it is concurrently writing. A process that needs
     * no stdin gets this instead.
     */
    public static function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }

    /**
     * Start the process, or explain precisely why it cannot be started.
     *
     * @throws RuntimeException
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        if (! is_file($this->executable)) {
            throw new RuntimeException(sprintf(
                'The %s runtime is not where this install expects it. Looking for %s, which is not a file. '
                .'The install is incomplete.',
                $this->name,
                $this->executable,
            ));
        }

        if (! is_dir($this->workingDirectory)) {
            throw new RuntimeException(sprintf(
                'Cannot start %s: the directory %s does not exist.',
                $this->name,
                $this->workingDirectory,
            ));
        }

        // The log path is whatever the caller passed, per process, and is not rewritten here.
        // An earlier version normalised it to "<name>.log" inside this method and tried to
        // assign to the promoted readonly property, which PHP refuses at runtime. The callers
        // already name each file, so the line bought nothing.
        @mkdir(dirname($this->logFile), 0o777, true);

        /*
         * Rotation runs before `proc_open`, so the child opens the new file rather than a
         * handle to the oversized one. A rename cannot replace an existing name on Windows,
         * so a timestamp is the base and a counter resolves a collision in the same second.
         */
        $this->rotateLog();

        $command = array_merge([$this->executable], $this->arguments);

        $descriptors = [
            0 => ['file', self::nullDevice(), 'r'],
            1 => ['file', $this->logFile, 'a'],
            2 => ['file', $this->logFile, 'a'],
        ];

        /*
         * The command is passed as an array on every platform, never joined into a string.
         *
         * On Windows, a string command is handed to `cmd /c`, and cmd applies its own quoting
         * rules to the whole line. Quoting each argument correctly and then having cmd
         * reinterpret the result is how `frankenphp.exe run --config "<path>"` started, logged
         * two lines about GOMAXPROCS, and died without a word. The same thing, run by hand,
         * worked, which is what made it look like a runtime problem rather than a launcher one.
         *
         * The array form has escaped each argument correctly since PHP 7.4 and does not go
         * through a shell at all.
         */
        $handle = @proc_open(
            $command,
            $descriptors,
            $pipes,
            $this->workingDirectory,
            $this->inheritedEnvironment(),
        );

        if (! is_resource($handle)) {
            throw new RuntimeException(sprintf('Could not start %s at all.', $this->name));
        }

        $status = proc_get_status($handle);

        $this->handle = $handle;
        $this->pid = $status['pid'];
        $this->started = true;
    }

    /**
     * Whether the child is still running.
     *
     * Read from the handle rather than from a poll, because `proc_get_status` only knows for
     * the process this launcher started. A handle that has been released reports nothing, so
     * it is treated as not running.
     */
    public function isRunning(): bool
    {
        if (! $this->handle || ! is_resource($this->handle)) {
            return false;
        }

        $status = proc_get_status($this->handle);

        return $status['running'];
    }

    public function pid(): ?int
    {
        return $this->pid;
    }

    public function logFile(): string
    {
        return $this->logFile;
    }

    /**
     * Stop the child, politely then not, and say which one it took.
     *
     * Returns a word rather than a bool because the caller has a reason to log the difference:
     * "stopped cleanly" and "had to be killed" are the same outcome to a reader and very
     * different signals about whether something is wrong.
     *
     * @return string One of `already gone`, `terminated`, `killed`, `still running`.
     */
    public function stop(int $graceSeconds = 10): string
    {
        if (! $this->handle || ! is_resource($this->handle)) {
            return 'already gone';
        }

        if (! $this->isRunning()) {
            $this->close();

            return 'already gone';
        }

        // proc_terminate on Windows is TerminateProcess, so "polite" means nothing there and
        // this is honest about that rather than pretending otherwise.
        @proc_terminate($this->handle, defined('SIGTERM') ? SIGTERM : 15);

        if ($this->waitFor($graceSeconds)) {
            $this->close();

            return 'terminated';
        }

        @proc_terminate($this->handle, defined('SIGKILL') ? SIGKILL : 9);

        if ($this->waitFor(5)) {
            $this->close();

            return 'killed';
        }

        return 'still running';
    }

    /**
     * Release the handle without stopping anything.
     *
     * Needed after a `stop` that already ended the process, so that the destructor does not
     * try again and so a second `stop` reports honestly rather than repeating the kill.
     */
    public function close(): void
    {
        if ($this->handle && is_resource($this->handle)) {
            @proc_close($this->handle);
        }

        $this->handle = null;
        $this->started = false;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Rotate an oversized process log and keep only the newest three rotations.
     */
    private function rotateLog(): void
    {
        clearstatcache(true, $this->logFile);

        $size = @filesize($this->logFile);

        if ($size === false || $size <= self::MAX_LOG_BYTES) {
            return;
        }

        $stamp = gmdate('Ymd-His');
        $rotated = $this->logFile.'.'.$stamp;
        $suffix = 0;

        while (file_exists($rotated)) {
            $suffix++;
            $rotated = $this->logFile.'.'.$stamp.'-'.$suffix;
        }

        if (! @rename($this->logFile, $rotated)) {
            return;
        }

        $rotations = glob($this->logFile.'.????????-??????*') ?: [];

        sort($rotations, SORT_STRING);

        $excess = count($rotations) - self::KEPT_ROTATIONS;

        foreach ($excess > 0 ? array_slice($rotations, 0, $excess) : [] as $old) {
            @unlink($old);
        }
    }

    private function waitFor(int $seconds): bool
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if (! $this->isRunning()) {
                return true;
            }

            usleep(100_000);
        }

        return ! $this->isRunning();
    }

    /**
     * The environment this process runs with: everything inherited, plus what we add.
     *
     * Merged rather than replaced, and that is not a detail. `proc_open`'s fifth argument
     * *replaces* the child's entire environment, it does not add to it. Passing only
     * `['PHPRC' => ...]` therefore hands a Windows process a world with no `SystemRoot`, no
     * `PATH` and no `TEMP`, and it dies before it can log anything at all.
     *
     * The failure is silent and misleading in exactly the wrong direction: adding one
     * environment variable is supposed to be the most conservative change available, and it
     * looks like the least.
     *
     * @return array<string, string>|null Null only when there is nothing to change, which
     *                                    leaves proc_open to inherit as it normally would.
     */
    private function inheritedEnvironment(): ?array
    {
        if ($this->environment === []) {
            return null;
        }

        $inherited = getenv();

        /** @var array<string, string> $merged */
        $merged = [];

        foreach ($inherited as $name => $value) {
            $merged[(string) $name] = (string) $value;
        }

        foreach ($this->environment as $name => $value) {
            $merged[$name] = $value;
        }

        return $merged;
    }
}
