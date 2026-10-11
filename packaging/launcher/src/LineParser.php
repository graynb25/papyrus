<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

/**
 * Turns one log file's native line format into the five fields.
 *
 * ## The contract every parser keeps
 *
 * **No line is ever rejected.** A parser either recognises a line and fills the fields, or it
 * returns a continuation so the line stays attached to the record above it. There is no third
 * answer, and there is no path that drops a line.
 *
 * This is the property that matters most in this layer. The collected files exist for a reader
 * filing a bug, and the bug is often in the part of the log nobody understood well enough to write
 * a parser for. A parser that discards what it does not recognise destroys the evidence at exactly
 * the moment it becomes useful, and it does so silently: the bundle still looks complete.
 *
 * ## Why the parser is constructed with a date
 *
 * Logback writes `20:14:38.025`, a time with no date, and the five fields call for a full
 * timestamp. The collector knows the date because it can read the log file's modification time, so
 * it passes that in rather than letting each parser reach for `date_default_timezone_get` and
 * produce a slightly different answer.
 */
interface LineParser
{
    /**
     * Read one line.
     *
     * Implementations must not assume they are being called on every line of the file: a log can
     * be appended to while it is read, and the last line may be half written. A parser that
     * returns a continuation for a line it cannot read keeps that line instead of losing it.
     */
    public function parse(string $line): ParsedLine;
}
