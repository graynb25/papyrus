<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

use RuntimeException;
use Throwable;

/**
 * Step 5: the bundle a reader attaches to their report.
 *
 * ## One command, one thing to send
 *
 * A reader who has to work out which of nine log files to attach will attach one file, and the one
 * they attach will be the wrong one. So the bundle gathers everything and says what it is.
 *
 * ## The rule, again
 *
 * **A defect here must never make a file disappear.** An absent file is indistinguishable from a
 * file with nothing in it, and a bundle that quietly drops `health.txt` reads as a reader with no
 * problems. So every file the bundle is supposed to contain is always written: when a step fails,
 * the file says so and says why, which is the difference between a reportable failure and a
 * mystery.
 *
 * ## Why health is asked again rather than copied
 *
 * Because a report that describes the wrong moment is worse than no report. A reader who restarted
 * a worker and then collected the bundle would otherwise send us the state before the restart, and
 * we would chase a bug that was already fixed. So the bundle renders health itself, at collection
 * time, and a failed render is written into the file rather than skipped.
 */
final class Bundle
{
    /**
     * The largest collected file this release loads into the zip writer.
     *
     * The writer still holds the whole archive in memory, so this is a release sized bound
     * rather than a streaming rewrite.
     */
    private const MAX_COLLECTED_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly Supervisor $supervisor,
        private readonly Prober $prober,
    ) {}

    /**
     * Collect everything into the bundle directory.
     *
     * @return string the directory written
     *
     * @throws RuntimeException when the bundle directory itself cannot be created
     */
    public function write(): string
    {
        $directory = $this->directory();

        if (! is_dir($directory) && ! @mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            throw new RuntimeException('cannot write to '.$directory);
        }

        // Layer 2 is rebuilt first, because the bundle should carry what the logs say now rather
        // than whatever the last health command happened to leave behind.
        $collector = new Collector($this->supervisor->logDirectory());
        $collected = $this->records(fn () => $collector->collect());

        foreach ($collector->files() as $file) {
            $this->copy($file, $directory.DIRECTORY_SEPARATOR.'collected'.DIRECTORY_SEPARATOR.basename($file));
        }

        $this->writeFile($directory.DIRECTORY_SEPARATOR.'README.txt', $this->readme());
        $this->writeFile($directory.DIRECTORY_SEPARATOR.'summary.txt', $this->summary());
        $this->writeFile($directory.DIRECTORY_SEPARATOR.'health.txt', $this->health());
        $this->writeFile($directory.DIRECTORY_SEPARATOR.'processes.txt', $this->processes($collector, $collected));
        $this->writeFile($directory.DIRECTORY_SEPARATOR.'environment.txt', $this->environment());

        return $directory;
    }

    /**
     * The bundle as one zip, so attaching it to an issue is a single drag.
     *
     * Takes the directory rather than rebuilding it, so a caller that writes once and zips does
     * not collect the logs twice and produce two bundles whose collected files could disagree if
     * a process logged in between.
     *
     * Written with this project's own zip writer rather than `ZipArchive`, because the packaged
     * runtime cannot be assumed to have ext-zip and a bundle that only works on a developer's
     * machine is the one thing that must not happen to the file a reader is told to send.
     */
    public function zip(string $directory): string
    {
        $destination = dirname($directory).DIRECTORY_SEPARATOR.'papyrus-diagnostics.zip';

        $writer = new ZipWriter;

        foreach ($this->allFiles($directory) as $name => $path) {
            $size = @filesize($path);

            if ($size !== false && $size > self::MAX_COLLECTED_BYTES) {
                $writer->add(
                    'papyrus-diagnostics/'.$name.'.truncated.txt',
                    sprintf('This file was %d bytes, above the bundle limit, and was not included.', $size),
                );

                continue;
            }

            $writer->addFile('papyrus-diagnostics/'.$name, $path);
        }

        file_put_contents($destination, $writer->finish());

        return $destination;
    }

    /**
     * The bundle's files, relative to its directory, so a reader sees what they are sending.
     *
     * A reader who can see the contents before attaching is much less likely to send four of the
     * nine by accident, which is the outcome this command exists to prevent.
     *
     * @return array<int, string>
     */
    public function listing(string $directory): array
    {
        $names = array_keys($this->allFiles($directory));

        sort($names);

        return $names;
    }

    /**
     * @return array<string, string>
     */
    private function allFiles(string $directory): array
    {
        $files = [];

        $entries = scandir($directory) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;

            if (is_file($path)) {
                $files[$entry] = $path;

                continue;
            }

            foreach (scandir($path) ?: [] as $nested) {
                if ($nested === '.' || $nested === '..') {
                    continue;
                }

                $files[$entry.'/'.$nested] = $path.DIRECTORY_SEPARATOR.$nested;
            }
        }

        return $files;
    }

    /**
     * The summary log, or an explanation of why it is not there.
     */
    private function summary(): string
    {
        return $this->text(
            function (): string {
                $path = $this->supervisor->summary()->path();

                if (! is_file($path)) {
                    return $this->missing('summary log', $path, 'the launcher has not written one yet, which means it has not started');
                }

                return (string) file_get_contents($path);
            },
            '',
        );
    }

    private function health(): string
    {
        return $this->text(
            function (): string {
                $health = new Health(
                    $this->supervisor,
                    $this->prober,
                    $this->supervisor->summary(),
                );

                return $health->render();
            },
            'the health report',
        );
    }

    /**
     * What each process is doing, and why its log looks the way it does.
     *
     * ## Why this file exists
     *
     * Because `queue.log` and `scheduler.log` are empty whenever the workers are healthy. There is
     * nothing to say when nothing is wrong, which is correct behaviour and useless evidence: an
     * empty file and a process that never started look identical in a bundle.
     *
     * So the bundle says which it is, per process, at the moment it was collected. A reader no
     * longer has to infer liveness from a file size, which is exactly the kind of inference that
     * produces a confident report of a bug that does not exist.
     *
     * ## Why a missing log is not called silent
     *
     * Because the first version of this did exactly that, and reading the output caught it. It
     * printed "running and silent, which is what a healthy worker looks like" next to a log file
     * that did not exist, which is a false reassurance written by the one file whose job is to
     * stop false reassurance. Silence is evidence. Absence is not, and conflating them is the
     * mistake this file is here to prevent, so the two are now separate sentences.
     *
     * @param  array<string, int>  $collected
     */
    private function processes(Collector $collector, array $collected): string
    {
        $lines = ['Processes at the moment this bundle was collected', ''];

        foreach ($collector->processes() as $process => $file) {
            $running = $this->supervisor->isRunning($process);
            $records = $collected[$process] ?? null;
            $source = $this->supervisor->logDirectory().DIRECTORY_SEPARATOR.$file;

            $lines[] = sprintf('  %-11s %s', $process, $running ? 'running' : 'not running');
            $lines[] = sprintf('    source log  %s', $this->describeLog($source, $file));
            $lines[] = sprintf('    collected   %s', $records === null ? 'unknown' : $records.' record(s)');

            foreach ($this->explain($running, is_file($source), $records) as $note) {
                $lines[] = '    note        '.$note;
            }

            $lines[] = '';
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * The sentence that stops a reader drawing the wrong conclusion, or none at all.
     *
     * Returning a list rather than a string because two of these can be true at once: a process
     * that never started and wrote no log is both not running and missing its file, and saying
     * only the first would leave the second unexplained.
     *
     * @return array<int, string>
     */
    private function explain(bool $running, bool $logExists, ?int $records): array
    {
        $notes = [];

        if (! $logExists) {
            $notes[] = 'no log file exists for this process, which is not the same as a quiet one';

            return $notes;
        }

        if (! $running) {
            $notes[] = 'not running, so anything in its log is from an earlier run';

            return $notes;
        }

        if ($records === 0) {
            $notes[] = 'running and silent, which is what a healthy worker looks like';
        }

        return $notes;
    }

    private function describeLog(string $path, string $file): string
    {
        if (! is_file($path)) {
            return $file.', which does not exist';
        }

        return $file.', '.$this->countLines($path).' non blank line(s)';
    }

    private function countLines(string $path): int
    {
        $lines = 0;

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (trim($line) !== '') {
                $lines++;
            }
        }

        return $lines;
    }

    private function environment(): string
    {
        return $this->text(
            fn (): string => (new EnvironmentReport($this->supervisor, $this->prober))->render(),
            'the environment report',
        );
    }

    private function readme(): string
    {
        return <<<'TEXT'
        Papyrus diagnostics
        ====================

        This is everything Papyrus knows about this install at the moment it was collected. Attach
        the whole bundle to your report. There is nothing in it you need to edit first.

        What is here
        ------------

          README.txt       this file
          summary.txt      the short log meant to be read by a person
          health.txt       whether each process is up, and what follows if it is not
          processes.txt    what each process was doing, and why its log is the size it is
          environment.txt  what this machine is, collected as a fixed list of named probes
          collected/       every process log, normalised into one five field shape

        What is not here, and will not be
        ----------------------------------

          Your library, your sources, your reading positions or your downloads.
          Your database. It is reported by name and size, never opened.
          Any credential, token, cookie or password.
          Any environment variable at all. environment.txt asks the operating system for a fixed
          list of named values and never reads the environment, so there is no list to forget to
          filter.

        One thing to know before you attach it
        ---------------------------------------

          The paths in environment.txt contain your Windows account name. That is deliberate:
          the data directory sitting on a different drive from the install is a real cause, and a
          redacted path cannot answer it. If you would rather not, delete the file before
          attaching. Nothing else in the bundle depends on it.

        How to use it
        -------------

          Please keep it as a whole. Four files taken at random is a worse report than the whole
          bundle, because we cannot tell what is missing from what was never there.
        TEXT.PHP_EOL;
    }

    /**
     * Run a step that produces text, and write the reason it failed rather than leaving the file
     * out.
     *
     * An absent file is indistinguishable from a file with nothing in it, and a bundle that
     * silently omits `health.txt` reads as a reader with no problems. The label is passed in
     * rather than derived from the closure because a Closure stringified is `Closure::__invoke`,
     * which tells a reader nothing about what went wrong.
     */
    private function text(callable $step, string $label): string
    {
        try {
            return (string) $step();
        } catch (Throwable $e) {
            return $this->missing($label, $this->directory(), $e->getMessage());
        }
    }

    /**
     * Run a step that produces counts, and return nothing rather than a string.
     *
     * ## Why this is not the same method as `text`
     *
     * Because they used to be one, and the difference only shows when the step fails. A single
     * method returned the explanation for a failed step whatever the caller had asked for, so a
     * failure while counting records handed a string to a parameter typed `array` and the bundle
     * died with a `TypeError`.
     *
     * That is the worst outcome this class can produce. The reader asked for a diagnostics bundle
     * precisely because something was broken, and the act of collecting it produced a fatal error
     * and no bundle at all, while the explanation of what went wrong was in hand and discarded.
     *
     * An empty array is the honest result, because it makes every record count read `unknown`
     * rather than `0`. Zero is a claim that the log was read and had nothing in it.
     *
     * @return array<string, int>
     */
    private function records(callable $step): array
    {
        try {
            return $step();
        } catch (Throwable) {
            return [];
        }
    }

    private function missing(string $what, string $path, string $why): string
    {
        return PHP_EOL
            .'This file could not be produced.'.PHP_EOL.PHP_EOL
            .'  what   '.$what.PHP_EOL
            .'  where  '.$path.PHP_EOL
            .'  why    '.$why.PHP_EOL.PHP_EOL
            .'It is written here rather than left out, because a bundle that silently omits a file'
            .PHP_EOL.'is indistinguishable from a bundle in which everything was fine.'.PHP_EOL;
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0o777, true);
        }

        file_put_contents($path, $contents);
    }

    private function copy(string $from, string $to): void
    {
        $directory = dirname($to);

        if (! is_dir($directory)) {
            @mkdir($directory, 0o777, true);
        }

        copy($from, $to);
    }

    private function directory(): string
    {
        return $this->supervisor->dataDirectory().DIRECTORY_SEPARATOR.'diagnostics';
    }
}
