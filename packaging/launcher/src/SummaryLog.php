<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

use RuntimeException;

/**
 * The one log a reader is meant to read.
 *
 * ## Why this file exists separately from everything else
 *
 * Because the other logs answer questions for us and this one answers a question for the reader.
 * Eight files in three formats with no shared timeline is the right shape for diagnosis and the
 * wrong shape for a person deciding whether to file a bug. Nothing in this file is needed to fix
 * anything, and everything in it is safe to screenshot.
 *
 * The two rules that keep it that way:
 *
 *   - **Only state changes.** Startup milestones, errors, and stop. Not every request, not every
 *     poll, not every chapter. A reader who opens this after two years of daily use should be
 *     able to read the last run.
 *   - **Nothing is written here that is not explained.** No stack traces, no SQL, no tokens, no
 *     series names. When something needs detail, it goes to the collected diagnostics and this
 *     file says where.
 *
 * ## Appended, never truncated
 *
 * The obvious design is to rewrite the file on every start so it describes the current run. That
 * loses the one thing a reader most often needs, which is what happened on the run *before* the
 * one that failed. A crash is exactly when there is no opportunity to tidy up, so the file is
 * appended to and rotation happens on size only. A reader whose install will not start still has
 * every line up to the point it stopped.
 *
 * ## Rotation is on size rather than on date
 *
 * A reader who opens Papyrus once a month should not accumulate a year of logs, and a reader in a
 * debug loop should not be able to fill a disk. Both are handled by the same rule.
 */
final class SummaryLog
{
    /** Rotated at this size, so a reader always has the previous run to compare against. */
    private const ROTATE_BYTES = 1048576;

    /** How many previous files to keep. One is enough: the run before the one that failed. */
    private const KEEP_GENERATIONS = 1;

    public function __construct(
        private readonly string $logDirectory,
        ?Translator $translator = null,
    ) {
        $this->translator = $translator ?? new Translator;
    }

    private readonly Translator $translator;

    public function path(): string
    {
        return $this->logDirectory.DIRECTORY_SEPARATOR.'papyrus.log';
    }

    /**
     * Record one state change.
     *
     * `$process` is one of `papyrus`, `suwayomi`, `web`, `queue` or `scheduler`, and it is the
     * only reason a reader can tell which of four processes said something.
     */
    public function record(string $process, string $message): void
    {
        $this->append($process, 'INFO', $message);
    }

    /**
     * Record something that went wrong, in terms a reader can act on.
     *
     * The message must say what failed and where to look, because the reader has no other context.
     * "Migration failed" is useless to them; "migration failed, see migrate.log" is not.
     */
    public function problem(string $process, string $message): void
    {
        $this->append($process, 'PROBLEM', $message);
    }

    /**
     * Record where the detail lives, so the summary never has to contain any.
     */
    public function pointAt(string $process, string $what, string $message): void
    {
        $this->append($process, 'INFO', $message.' ('.$what.')');
    }

    private function append(string $process, string $level, string $message): void
    {
        $directory = $this->logDirectory;

        if (! is_dir($directory) && ! @mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            // Deliberately silent. A log that cannot be written must not stop an install from
            // starting, and a launcher that dies because a log directory is locked is a far worse
            // outcome than a missing line. The health command reports the absence.
            return;
        }

        $path = $this->path();

        $this->rotateIfNeeded($path);

        $line = sprintf(
            '%s  %-8s  %-8s  %s%s',
            date('Y-m-d H:i:s'),
            $this->pad($process, 8),
            $level,
            $this->oneLine($message),
            PHP_EOL,
        );

        // Appended with LOCK_EX because the queue worker and the scheduler may write here too, and
        // an interleaved half line in the one file a reader reads would be its own defect.
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Collapse a message to one line.
     *
     * A summary line that wraps is a summary line that gets misread when it is quoted into a bug
     * report, so newlines become a space rather than being left to the terminal's width.
     */
    private function oneLine(string $message): string
    {
        return trim((string) preg_replace('#\s+#', ' ', $message));
    }

    private function pad(string $value, int $length): string
    {
        return strlen($value) >= $length ? $value : $value.str_repeat(' ', $length - strlen($value));
    }

    /**
     * Move the current file aside once it is too big to be read comfortably.
     *
     * Fails quietly by design. A rotation that cannot happen leaves a large log, which is a
     * nuisance; a rotation that throws stops an install from starting, which is a defect.
     */
    private function rotateIfNeeded(string $path): void
    {
        if (! is_file($path)) {
            return;
        }

        $size = @filesize($path);

        if ($size === false || $size < self::ROTATE_BYTES) {
            return;
        }

        // Generations are shifted rather than deleted one at a time, so there is never a window
        // where neither file exists and a start loses its own beginning.
        //
        // The shift loop that used to be here was dead code. `KEEP_GENERATIONS` is 1, so it began
        // at 0 and its `>= 1` test was false before it ran. It was written for a value this
        // install does not use, which is the opposite of how anything else in this project is
        // written: code for a hypothetical future sits here looking like it works.
        //
        // Keeping more than one generation means changing this method and its tests together, not
        // a constant, because the two are no longer independent. A generation beyond the kept one
        // is discarded below rather than left to survive every future rotation.
        @rename($path, $path.'.1');

        $this->discardExcessGenerations($path);
    }

    /**
     * Remove anything beyond the generations this install keeps.
     *
     * Without this, a stale `.2` or `.3` survives every rotation forever. It costs nothing to
     * keep one generation and everything to keep an unknown number, because `files()` sweeps
     * every match into the diagnostics bundle, so one leftover from a build that once kept more
     * would be attached to every support request that reader ever sends.
     */
    private function discardExcessGenerations(string $path): void
    {
        foreach (glob($path.'.*') ?: [] as $candidate) {
            $suffix = substr($candidate, strlen($path) + 1);

            if (ctype_digit($suffix) && (int) $suffix > self::KEEP_GENERATIONS) {
                @unlink($candidate);
            }
        }
    }

    /**
     * Every log file belonging to this install, newest first, for the diagnostics bundle.
     *
     * @return list<string>
     */
    public function files(): array
    {
        $found = [];

        foreach (glob($this->logDirectory.DIRECTORY_SEPARATOR.'papyrus.log*') ?: [] as $file) {
            $found[] = $file;
        }

        return $found;
    }

    /**
     * Whether this log is usable at all, for the health command.
     *
     * Reports the specific failure rather than a boolean, because "logging is broken" is not
     * something a reader can act on and "the log directory is not writable" is.
     */
    public function diagnose(): string
    {
        $directory = $this->logDirectory;

        if (! is_dir($directory)) {
            return $this->translator->get('summary.log_dir_missing', ['path' => $directory]);
        }

        if (! is_writable($directory)) {
            return $this->translator->get('summary.log_dir_unwritable', ['path' => $directory]);
        }

        $path = $this->path();

        if (is_file($path) && ! is_writable($path)) {
            return $this->translator->get('summary.log_file_unwritable', ['path' => $path]);
        }

        return 'ok';
    }

    /**
     * @throws RuntimeException only when the directory is unusable, which the caller should see.
     */
    public function assertUsable(): void
    {
        $status = $this->diagnose();

        if ($status !== 'ok') {
            throw new RuntimeException($this->translator->get('summary.assert', ['status' => $status]));
        }
    }
}
