<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

use DateTimeImmutable;
use RuntimeException;

/**
 * Layer 2: every process's log, normalised into one shape, written under `logs/collected/`.
 *
 * ## Why one file per process
 *
 * A single merged file would be easier to sort and impossible to attribute. Attribution is most of
 * what the layer is for: "which process said this" is the first question about any line, and once
 * it has been answered by a filename the five fields can be searched across all of them.
 *
 * ## The rule this class exists to keep
 *
 * **A defect here must never make a line disappear.**
 *
 * Three separate bugs this project has shipped came from a file that looked complete and was not.
 * A log rotated but never trimmed, a build that reported success over a missing file, a probe that
 * reported on what it read rather than what it measured. The collected files feed a bug report, so
 * a line lost here is a piece of evidence destroyed at the exact moment it is needed, and nothing
 * downstream can tell it happened. So:
 *
 *   - an unrecognised line continues the record above it rather than being dropped;
 *   - a continuation with no record above it is promoted to a record of its own;
 *   - a file that does not exist yields an empty output file, so its absence is visible;
 *   - every source is read even when an earlier one failed.
 *
 * ## Why the process name is a field as well as a filename
 *
 * Because the two are not redundant. The filename answers "which file did this come from" and the
 * field answers "which process said it", and those can disagree: a process can write into a log
 * another process nominally owns, and when they disagree the field is the one that is right.
 */
final class Collector
{
    /** Written before the reader collects it, so a half written bundle is never seen. */
    private const TEMPORARY_SUFFIX = '.writing';

    public function __construct(private readonly string $logDirectory) {}

    /**
     * Normalise every process log into `logs/collected/`.
     *
     * @return array<string, int> the number of records written per process
     *
     * @throws RuntimeException when `logs/collected/` cannot be created
     */
    public function collect(): array
    {
        $this->prepareDirectory();

        $written = [];

        foreach ($this->sources() as $process => $file) {
            $written[$process] = $this->collectOne($process, $file);
        }

        return $written;
    }

    /**
     * The process name and file for every log this layer reads.
     *
     * Exposed so the bundle does not keep its own copy of the list. Two lists would drift, and the
     * bundle's job is to report on the processes the collector actually read, so it asks rather
     * than remembering.
     *
     * @return array<string, string> process => file name
     */
    public function processes(): array
    {
        return $this->sources();
    }

    /**
     * The collected files that currently exist, for the bundle to include.
     *
     * @return array<int, string>
     */
    public function files(): array
    {
        $directory = $this->collectedDirectory();

        if (! is_dir($directory)) {
            return [];
        }

        $files = glob($directory.DIRECTORY_SEPARATOR.'*.txt');

        return $files === false ? [] : array_values(array_map('realpath', $files));
    }

    /**
     * Every log this layer knows how to read, by process name.
     *
     * The list is fixed rather than discovered because a missing file and an unreadable format
     * need different answers. A file that is not listed is a file nobody chose to normalise, and
     * that should be a deliberate omission rather than whatever happened to be in the directory.
     *
     * @return array<string, string> process => file name
     */
    private function sources(): array
    {
        return [
            'papyrus' => 'papyrus.log',
            'suwayomi' => 'suwayomi.log',
            'web' => 'web.log',
            'migrate' => 'migrate.log',
            'configure' => 'configure.log',
            'queue' => 'queue.log',
            'scheduler' => 'scheduler.log',
            'frankenphp' => 'frankenphp.log',
            'php' => 'php-error.log',
        ];
    }

    /**
     * @return array<int, array{level: string, source: string, message: string, timestamp: string}>
     */
    private function collectOne(string $process, string $file): int
    {
        $path = $this->logDirectory.DIRECTORY_SEPARATOR.$file;

        $records = $this->read($process, $path);

        $this->write($process, $records);

        return count($records);
    }

    /**
     * @return array<int, array{level: string, source: string, message: string, timestamp: string}>
     */
    private function read(string $process, string $path): array
    {
        if (! is_file($path)) {
            /*
             * An absent log still produces an empty collected file.
             *
             * `queue.log` and `scheduler.log` are empty whenever the workers are healthy, because
             * there is nothing to say, which is exactly why health asks the operating system
             * instead of reading them. Writing the empty file keeps "running and silent" and
             * "never started" distinguishable in the bundle without either having to be inferred
             * from what is missing.
             */
            return [];
        }

        $date = $this->dateOf($path);

        return $this->records($process, $path, $this->parserFor($path, $date), $date);
    }

    /**
     * @return array<int, array{level: string, source: string, message: string, timestamp: string}>
     */
    private function records(string $process, string $path, LineParser $parser, string $date): array
    {
        $fallback = (new DateTimeImmutable($date))->format('Y-m-d\TH:i:s.vP');

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $records = [];
        $current = null;

        try {
            while (($raw = fgets($handle)) !== false) {
                $line = rtrim($raw, "\r\n");

                /*
                 * A blank line carries nothing, so it is not a record and not a continuation.
                 * Anything else is treated as evidence, including a line that looks like nothing.
                 */
                if (trim($line) === '') {
                    continue;
                }

                $parsed = $parser->parse($line);

                if ($parsed->isContinuation) {
                    if ($current !== null) {
                        $current['message'] .= PHP_EOL.$parsed->message;

                        continue;
                    }

                    // An orphan would otherwise vanish. It gets the process as its source,
                    // because the logger that wrote it is not knowable from here.
                    $current = [
                        'timestamp' => $fallback,
                        'level' => 'INFO',
                        'source' => $process,
                        'message' => $parsed->message,
                        'process' => null,
                    ];

                    continue;
                }

                if ($current !== null) {
                    $records[] = $current;
                }

                $current = [
                    'timestamp' => $parsed->timestamp ?? $fallback,
                    'level' => $parsed->level,
                    'source' => $parsed->source,
                    'message' => $parsed->message,
                    'process' => $parsed->process,
                ];
            }
        } finally {
            fclose($handle);
        }

        if ($current !== null) {
            $records[] = $current;
        }

        return $records;
    }

    /**
     * @param  array<int, array{level: string, source: string, message: string, timestamp: string, process: ?string}>  $records
     */
    private function write(string $process, array $records): void
    {
        $destination = $this->collectedDirectory().DIRECTORY_SEPARATOR.$process.'.txt';
        $temporary = $destination.self::TEMPORARY_SUFFIX;

        $lines = [];

        foreach ($records as $record) {
            $lines[] = $this->render($process, $record);
        }

        file_put_contents($temporary, implode(PHP_EOL, $lines).(count($lines) > 0 ? PHP_EOL : ''));

        // Written to a temporary name and moved into place, because a bundle assembled from a
        // half written file is worse than one assembled from a slightly older file: it looks
        // complete and stops halfway through.
        rename($temporary, $destination);
    }

    /**
     * @param  array{level: string, source: string, message: string, timestamp: string, process: ?string}  $record
     */
    private function render(string $process, array $record): string
    {
        $message = str_replace(PHP_EOL, PHP_EOL.'  ', $record['message']);

        /*
         * The process named in the record wins over the file's name.
         *
         * `papyrus.log` holds lines recorded against `web` as well as `papyrus`, because health
         * reports on the whole install from the one place. Labelling those `papyrus` would tell a
         * reader the launcher was unhappy when it was the web server that was not answering, which
         * is the difference between looking in the right directory and looking in the wrong one.
         */
        return sprintf(
            '%s  %-9s %-6s %-24s %s',
            $record['timestamp'],
            $record['process'] ?? $process,
            $record['level'],
            $record['source'],
            $message,
        );
    }

    /**
     * The parser for a log file, chosen by its name.
     *
     * Matched on the base name rather than the full path so the call site can pass either, and so
     * the list reads as one table: the file name and the format it holds.
     */
    private function parserFor(string $path, string $date): LineParser
    {
        return match (basename($path)) {
            'suwayomi.log' => new LogbackParser($date),
            'web.log' => new CaddyJsonParser,
            'papyrus.log' => new LauncherParser,
            'php-error.log' => new PhpErrorParser,
            default => new ConsoleParser,
        };
    }

    /**
     * The date to give a format that has a time but no date.
     *
     * Read from the file itself rather than from the clock, so a log collected next week still
     * carries the day it was written. This is an assumption and the doc says which one, but it is
     * the only defensible one: logback emits `20:14:38.025` with nothing to attach a date to.
     */
    private function dateOf(string $path): string
    {
        $modified = filemtime($path);

        return $modified === false
            ? date('Y-m-d')
            : date('Y-m-d', $modified);
    }

    private function collectedDirectory(): string
    {
        return $this->logDirectory.DIRECTORY_SEPARATOR.'collected';
    }

    /**
     * @throws RuntimeException
     */
    private function prepareDirectory(): void
    {
        $directory = $this->collectedDirectory();

        if (is_dir($directory)) {
            return;
        }

        if (! @mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            /*
             * Unlike the summary log, this throws rather than failing quietly.
             *
             * A summary line that cannot be written costs one line. A bundle that cannot be
             * assembled at all is the one thing a reader was asked to produce, and returning an
             * empty bundle with no explanation is the failure mode this whole layer exists to
             * prevent.
             */
            throw new RuntimeException('cannot write to '.$directory);
        }
    }
}
