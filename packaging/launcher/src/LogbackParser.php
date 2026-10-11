<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

use DateTimeImmutable;

/**
 * Suwayomi's logback output, plus the raw JVM lines that land in the same file.
 *
 * ## The three kinds of line in one file
 *
 * ```
 * 20:14:38.025 [main] INFO com.zaxxer.hikari.HikariDataSource -- Suwayomi-DB-Pool - Starting...
 * WARNING: A restricted method in java.lang.System has been called
 * 	at suwayomi.tachidesk.server.user.UserTypeKt.requireUser(UserType.kt:23)
 * ```
 *
 * A logback record, a raw JVM line written to stderr, and a stack frame belonging to whichever
 * record precedes it. Treating the file as one format is what keeps them in order, and the order is
 * the point: a frame with no record above it is unreadable, and a JVM warning wedged between a
 * record and its frames would break the pairing.
 *
 * ## Why the JNA warnings are labelled rather than removed
 *
 * Two lines appear on every single start and mean nothing:
 *
 * ```
 * WARNING: A restricted method in java.lang.System has been called
 * WARNING: java.lang.System::load has been called by com.sun.jna.Native in an unnamed module ...
 * ```
 *
 * Removing them was considered and rejected. Suppressing loses evidence: if the JNA warning is
 * ever followed by something real, the connection is gone. Showing them unlabelled trains a reader
 * to ignore every `WARNING` in the file, which is the exact habit that makes a real one invisible.
 *
 * So they are kept, verbatim, with the level `NOISE`. That is a level we invented and the doc says
 * so; it is greppable in both directions, and a reader who sees ten NOISE lines and nothing else
 * has been told the truth, which is that there is nothing here yet.
 *
 * ## A real line in the shipped log has two records in it
 *
 * ```
 * WARNING: java.lang.System::load has been called by com.sun.jna.Native in an unnamed module (file:/...)20:14:37.171 [main] INFO suwayomi.tachidesk.server.ServerSetup -- Running Suwayomi-Server v2.4.2366
 * ```
 *
 * That is one physical line in the real `suwayomi.log`, and it is not a bug here. The JVM writes
 * its warning without a trailing newline, so logback's next record started on the same line. The
 * version of Suwayomi that is running, normally the single most useful line in the file, is
 * therefore buried at the end of a line labelled NOISE.
 *
 * It is deliberately not split. Splitting it would mean deciding where one record ends and another
 * begins inside a line we did not write, which is exactly the rewriting this layer exists to avoid,
 * and a split that guessed wrong would be worse than a long line. The line is reproduced exactly as
 * it was written, and this test pins that so nobody "fixes" it later and loses the raw text.
 */
final class LogbackParser implements LineParser
{
    /**
     * The record line.
     *
     * The separator between the logger and the message is `--` in logback's default pattern and a
     * plain space in the layout Suwayomi actually ships, and both were observed in one file:
     *
     * ```
     * 20:14:37.487 [main] INFO suwayomi.tachidesk.server.util.AppMutex -- Mutex status is clear.
     * 20:14:39.097 [main] INFO suwayomi.tachidesk.server.util.WebInterfaceManager setupWebUI(flavor= WebUI)
     * ```
     *
     * Requiring `--` was not a simplification, it was a bug: the three space separated lines in
     * a real log were rejected as records and fell through to the continuation path, which
     * appended them to the record above. Nothing looked wrong and no line was lost, which is
     * worse, because the text was still present and attached to the wrong event.
     *
     * The message group is optional because logback omits both separator and message when a
     * layout is configured to print only the prefix, and `io.javalin.Javalin` on its own is
     * exactly that.
     */
    private const RECORD = '/^(\d{2}):(\d{2}):(\d{2})\.(\d{3}) \[([^\]]*)\] ([A-Z]+) (\S+)(?:\s+--)?(?:\s+(.*))?$/';

    /**
     * The two JNA lines, identified by what they contain rather than by their exact wording.
     *
     * Both real lines mention `java.lang.System`, and both come from the JVM rather than from
     * logback. Matching on a substring rather than the full sentence means a future JNA release
     * that adds a clause still gets classified, and a genuine Suwayomi warning that merely says
     * `WARNING:` does not.
     */
    private const JNA_MARKER = 'java.lang.System';

    private string $date;

    /** The most recent time of day seen, used to notice the clock passing midnight. */
    private ?int $lastTimeOfDay = null;

    private int $dayOffset = 0;

    public function __construct(string $date)
    {
        $this->date = $date;
    }

    public function parse(string $line): ParsedLine
    {
        if (preg_match(self::RECORD, $line, $matches) === 1) {
            return $this->record($matches);
        }

        return $this->rawJvmLine($line);
    }

    /**
     * @param  array<int, string>  $matches
     */
    private function record(array $matches): ParsedLine
    {
        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        $second = (int) $matches[3];
        $millisecond = (int) $matches[4];
        $level = $matches[6];
        $logger = $matches[7];

        // The optional group does not participate when a layout omits the separator, so the
        // message index is only present when the group matched.
        $message = $matches[8] ?? '';

        $timeOfDay = ($hour * 3600 + $minute * 60 + $second) * 1000 + $millisecond;

        /*
         * Midnight rollover.
         *
         * Logback writes a time with no date, so a log that ran past midnight would otherwise be
         * folded back onto its own first hour, which makes two unrelated events look simultaneous
         * and, worse, sorts them incorrectly. A clock that goes backwards by more than an hour is
         * only ever a date change here: logback writes in order, so nothing else produces it.
         */
        if ($this->lastTimeOfDay !== null && $timeOfDay < $this->lastTimeOfDay - 3_600_000) {
            $this->dayOffset++;
        }

        $this->lastTimeOfDay = $timeOfDay;

        return ParsedLine::record(
            $this->timestamp($hour, $minute, $second, $millisecond),
            $level === 'WARNING' ? 'WARN' : $level,
            $logger,
            $message,
        );
    }

    /**
     * A line the JVM wrote to stderr rather than through logback.
     *
     * Anything not recognised here continues the record above it, which is what catches the
     * exception header line and every stack frame.
     */
    private function rawJvmLine(string $line): ParsedLine
    {
        if (str_starts_with($line, 'WARNING: ') && str_contains($line, self::JNA_MARKER)) {
            return ParsedLine::record(
                $this->timestampFromDate(),
                'NOISE',
                'jna',
                substr($line, strlen('WARNING: ')),
            );
        }

        if (str_starts_with($line, 'SEVERE: ') || str_starts_with($line, 'Exception in thread')) {
            return ParsedLine::record($this->timestampFromDate(), 'ERROR', 'jvm', $line);
        }

        if (str_starts_with($line, 'WARNING: ')) {
            return ParsedLine::record($this->timestampFromDate(), 'WARN', 'jvm', $line);
        }

        return ParsedLine::continuation($line);
    }

    private function timestamp(int $hour, int $minute, int $second, int $millisecond): string
    {
        $date = (new DateTimeImmutable($this->date))
            ->modify(sprintf('+%d day', $this->dayOffset))
            ->setTime($hour, $minute, $second, $millisecond * 1000);

        return $date->format('Y-m-d\TH:i:s.vP');
    }

    /**
     * A time for a JVM line, which carries none of its own.
     *
     * The first logback time already seen is used, so a raw line sits next to the record it was
     * printed with. When the JVM speaks before logback has, the log file's own date at midnight is
     * used, which is the JNA warning case. Midnight is a weaker claim than the other lines carry
     * and it is deliberate: "some time on this date" is true, and inventing a time that reads like
     * the others would be a lie dressed as precision.
     */
    private function timestampFromDate(): string
    {
        if ($this->lastTimeOfDay === null) {
            return (new DateTimeImmutable($this->date))->format('Y-m-d\TH:i:s.vP');
        }

        $milliseconds = $this->lastTimeOfDay;

        return $this->timestamp(
            intdiv($milliseconds, 3_600_000),
            intdiv($milliseconds % 3_600_000, 60_000),
            intdiv($milliseconds % 60_000, 1000),
            $milliseconds % 1000,
        );
    }
}
