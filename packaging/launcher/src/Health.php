<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

use RuntimeException;

/**
 * Answers "is it working", which is a different question from "what happened".
 *
 * ## Why this is a command and not a log
 *
 * Because a reader asking whether Papyrus is broken should not have to open a file, and should not
 * have to know that there are four of them. The health command changes nothing, takes a second,
 * and answers in one screen. A reader who runs it and pastes the output has given us most of what
 * we need before they have written a word.
 *
 * ## Why the fields are what they are
 *
 * The last job and last run times look like decoration and are not. The queue worker and the
 * scheduler write nothing at all when healthy, which is correct behaviour and the worst possible
 * diagnostic. A worker whose database driver failed does not crash: it pops nothing and exits
 * quietly, and the install looks completely fine while every download, search and library check
 * silently never happens.
 *
 * Those two fields are the liveness signal for exactly the processes that cannot report their own
 * death. They are what would have turned a bug report into a diagnosis.
 */
final class Health
{
    /** @var list<array{name: string, detail: string, ok: bool}> */
    private array $lines = [];

    private bool $overall = true;

    public function __construct(
        private readonly Supervisor $supervisor,
        private readonly Prober $prober,
        private readonly SummaryLog $summary,
    ) {}

    /**
     * The report, as the reader sees it.
     */
    public function render(): string
    {
        $this->lines = [];
        $this->overall = true;

        $this->suwayomi();
        $this->web();
        $this->workers();
        $this->database();
        $this->logs();

        return $this->table();
    }

    private function table(): string
    {
        $width = 0;

        foreach ($this->lines as $line) {
            $width = max($width, strlen($line['name']));
        }

        $out = '';

        foreach ($this->lines as $line) {
            $out .= sprintf(
                "  %s  %-{$width}s  %s%s",
                $line['ok'] ? 'ok  ' : 'FAIL',
                $line['name'],
                $line['detail'],
                PHP_EOL,
            );
        }

        $out .= PHP_EOL;
        $out .= sprintf('  overall     %s%s', $this->overall ? 'ok' : 'something is wrong, see the FAIL lines', PHP_EOL);

        return $out;
    }

    private function add(string $name, string $detail, bool $ok): void
    {
        $this->lines[] = ['name' => $name, 'detail' => $detail, 'ok' => $ok];

        if (! $ok) {
            $this->overall = false;
        }
    }

    private function suwayomi(): void
    {
        $port = $this->supervisor->suwayomiPort();
        $answering = $this->prober->suwayomiAnswers($port);

        if (! $answering) {
            $this->add('suwayomi', 'not answering on port '.$port, false);

            return;
        }

        $version = $this->prober->suwayomiVersion($port);
        $this->add('suwayomi', 'running  '.($version !== '' ? $version : 'version unknown').'  port '.$port, true);
    }

    private function web(): void
    {
        $port = $this->supervisor->appPort();

        if (! $this->prober->isPortTaken($port)) {
            $this->add('web', 'not listening on port '.$port, false);

            return;
        }

        $readiness = $this->supervisor->readiness();

        if ($readiness === null) {
            $this->add('web', 'listening on '.$port.' but /ready did not answer', false);

            return;
        }

        $this->add(
            'web',
            sprintf(
                'listening  port %d  /ready %d  key %s  database %s',
                $port,
                $readiness['status'],
                $readiness['checks']['key'] ? 'ok' : 'FAILED',
                $readiness['checks']['database'] ? 'ok' : 'FAILED',
            ),
            $readiness['status'] === 200,
        );
    }

    /**
     * The two processes that cannot report their own death.
     *
     * A process being absent is the failure, and it is reported as such rather than skipped: a
     * missing worker is invisible everywhere else in the system.
     *
     * The consequence differs between them and is spelled out, because "not running" on its own
     * tells a reader nothing about whether they are losing anything. A dead queue silently drops
     * downloads and library checks; a dead scheduler silently stops refreshing series metadata.
     */
    private function workers(): void
    {
        $consequences = [
            'queue' => ['queue:work', 'downloads and library checks are never done'],
            'scheduler' => ['schedule:work', 'series are never refreshed'],
        ];

        foreach ($consequences as $name => [$command, $consequence]) {
            $running = $this->supervisor->isRunning($name);

            $this->add(
                $name,
                $running
                    ? 'running  '.$command
                    : 'NOT RUNNING, '.$command.' is not up, so '.$consequence,
                $running,
            );
        }
    }

    private function database(): void
    {
        $path = $this->supervisor->databaseFile();

        if (! is_file($path)) {
            $this->add('database', 'not created yet at '.$path, false);

            return;
        }

        $size = (int) filesize($path);
        $megabytes = round($size / 1048576, 1);

        $this->add(
            'database',
            sprintf('ok  %s  %.1f MB', basename($path), $megabytes),
            $size > 0,
        );
    }

    /**
     * Whether logs can be written, and whether that also means a bundle can be produced.
     *
     * One line rather than two, because they are the same question. They failed separately in the
     * first run of this command, both naming the same missing directory, which reads as two
     * problems when it is one.
     */
    private function logs(): void
    {
        $status = $this->summary->diagnose();

        if ($status === 'ok') {
            $this->add(
                'logging',
                'ok, and a diagnostics bundle can be written on request',
                true,
            );

            return;
        }

        // Named as one failure. A reader who cannot write logs cannot send us a bundle either, and
        // the action is the same for both: free up the directory.
        $this->add('logging', $status.' (so no bundle can be written either)', false);
    }

    /**
     * @throws RuntimeException when something is wrong, so a script can branch on it.
     */
    public function assertHealthy(): void
    {
        if (! str_contains($this->render(), 'FAIL')) {
            return;
        }

        throw new RuntimeException('This install is not healthy. Run papyrus health for the detail.');
    }
}
