<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

/**
 * One log record after normalisation: the five fields, four of them filled by a parser.
 *
 * ## Why a value object rather than an array
 *
 * A record carries a flag that is not part of the five fields: whether the line continues the
 * record above it. Threading that through an array means every call site indexes a magic string,
 * and the one that gets it wrong silently reorders a stack trace. A named constructor makes the
 * two cases impossible to confuse, because `continuation()` cannot be mistaken for `record()`.
 *
 * ## Why a continuation carries no timestamp
 *
 * A stack frame does not know when it happened. Its parent does. Attaching a second timestamp to
 * a frame would invent precision, and inventing precision in a diagnostic is how two events get
 * correlated that never overlapped.
 */
final class ParsedLine
{
    /**
     * @param  string|null  $timestamp  ISO 8601 with offset, or null when the format carries none
     */
    private function __construct(
        public readonly ?string $timestamp,
        public readonly string $level,
        public readonly string $source,
        public readonly string $message,
        public readonly bool $isContinuation,
        public readonly ?string $process = null,
    ) {}

    /**
     * The start of a new record.
     *
     * A null timestamp is not an error. Laravel's console output genuinely has none, and the
     * collector supplies the file's modification time rather than having the parser guess.
     *
     * `process` overrides the process the record is written to when the source names one itself.
     * The summary log records `web PROBLEM nothing is listening` into `papyrus.log`, and the
     * reader is told which process is unhappy by that field, so the file's name cannot win.
     */
    public static function record(
        ?string $timestamp,
        string $level,
        string $source,
        string $message,
        ?string $process = null,
    ): self {
        return new self($timestamp, $level, $source, $message, false, $process);
    }

    /**
     * A line belonging to the record above it: a stack frame, or the exception header that
     * introduces one.
     *
     * The fragment is stored exactly as it was read. Nothing is trimmed, reordered or re-indented
     * here, because this is the text a Suwayomi maintainer would recognise, and the one rule this
     * whole layer runs on is that it is not ours to change.
     */
    public static function continuation(string $fragment): self
    {
        return new self(null, '', '', $fragment, true);
    }
}
