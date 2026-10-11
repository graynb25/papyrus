<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

use DateTimeImmutable;

/**
 * Papyrus's own summary log, read back so it can join the others.
 *
 * ## Why it is worth reading
 *
 * Layer 1 and layer 2 answer different questions and neither replaces the other, but the correlation
 * between them is most of the value: the summary line `papyrus: starting` followed by whatever
 * Suwayomi says next is the shape of a failed start, and that shape is invisible if the two files
 * are read separately.
 *
 * The source is `launcher`, which is the name of this process. Every other source in this layer is
 * the thing that emitted the line, and the launcher emitted these.
 *
 * ## Why the process in the line is carried through
 *
 * Because the summary log records lines against a process that is not always `papyrus`. Health
 * writes `web PROBLEM nothing is listening on port 8402` into `papyrus.log`, and a reader who is
 * told which process is unhappy by the process field has been told `papyrus` if the file's name
 * won. The filename answers "which file" and the field answers "which process", and when they
 * disagree the field is right.
 *
 * ## Why the level is left alone
 *
 * `SummaryLog::problem()` writes `PROBLEM`, and that is not a severity, it is a marker saying a
 * human should read this line. Mapping it onto WARN would be a guess about what the author meant,
 * and the author is us, three lines up.
 */
final class LauncherParser implements LineParser
{
    private const LINE = '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+(\S+)\s+(\S+)\s+(.*)$/';

    public function parse(string $line): ParsedLine
    {
        if (preg_match(self::LINE, $line, $matches) !== 1) {
            return ParsedLine::record(null, 'INFO', 'launcher', $line);
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $matches[1]);

        return ParsedLine::record(
            $date === false ? null : $date->format('Y-m-d\TH:i:s.vP'),
            $matches[3],
            'launcher',
            $matches[4],
            $matches[2],
        );
    }
}
