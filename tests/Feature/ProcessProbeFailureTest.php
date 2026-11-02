<?php

namespace Tests\Feature;

use Papyrus\Launcher\Prober;
use Papyrus\Launcher\ProcessFinder;
use Papyrus\Launcher\ProcessProbeFailed;
use Papyrus\Launcher\Supervisor;
use Tests\TestCase;

/**
 * A process probe that failed must not look like a machine with nothing running.
 *
 * The measured defect: a packaged install was up, `/ready` answered 200, and `papyrus stop` from a
 * second process reported that nothing belonging to the install was running while nine processes
 * were alive and both ports were held. `ProcessFinder::queryProcesses()` returned an empty array on
 * every failed PowerShell probe, which `find()` and `stopAll()` could not tell apart from a valid
 * process table with no matching row.
 */
class ProcessProbeFailureTest extends TestCase
{
    /**
     * @return list<array{pid: int, name: string, executable: string, command: string}>
     */
    private function rows(): array
    {
        return [
            [
                'pid' => 1234,
                'name' => 'php.exe',
                'executable' => 'C:/papyrus/runtime/bin/php.exe',
                'command' => 'php -c C:/papyrus/data/php/php.ini C:/papyrus/artisan queue:work',
            ],
            [
                'pid' => 5678,
                'name' => 'frankenphp.exe',
                'executable' => 'C:/papyrus/runtime/bin/frankenphp.exe',
                'command' => 'frankenphp run --config C:/papyrus/data/frankenphp/Caddyfile',
            ],
        ];
    }

    public function test_a_valid_probe_table_is_returned_as_process_rows(): void
    {
        $rows = $this->rows();

        $this->assertSame(
            $rows,
            ProcessFinder::parseProbeOutput((string) json_encode($rows), 0),
        );
    }

    public function test_a_single_json_object_is_accepted_as_a_one_row_table(): void
    {
        $row = $this->rows()[0];

        $this->assertSame(
            [$row],
            ProcessFinder::parseProbeOutput((string) json_encode($row), 0),
        );
    }

    public function test_empty_probe_output_is_a_failure(): void
    {
        $this->assertProbeFailure('', 0, ['exited with code 0', 'no output']);
    }

    public function test_the_powershell_banner_is_not_a_process_table(): void
    {
        $banner = "Windows PowerShell\nCopyright (C) Microsoft Corporation. All rights reserved.\n";

        $this->assertProbeFailure(
            $banner,
            0,
            ['exit code 0', 'not a JSON process table', 'Windows PowerShell'],
        );
    }

    public function test_malformed_json_is_a_failure(): void
    {
        $this->assertProbeFailure(
            '{"pid": 1234,',
            0,
            ['exit code 0', 'not a JSON process table', '{"pid": 1234,'],
        );
    }

    public function test_a_non_zero_exit_is_a_failure_even_when_the_output_is_json(): void
    {
        $this->assertProbeFailure(
            (string) json_encode($this->rows()),
            1,
            ['exited with code 1', 'It printed'],
        );
    }

    public function test_find_propagates_a_probe_failure_instead_of_returning_an_empty_list(): void
    {
        $finder = new ProcessFinder(function (array $spec): array {
            throw new ProcessProbeFailed(
                'The process list could not be read: PowerShell exited with code 9.',
            );
        });

        try {
            $pids = $finder->find(['C:/papyrus', 'queue:work']);

            $this->fail('find() returned '.json_encode($pids).' instead of propagating the probe failure');
        } catch (ProcessProbeFailed $e) {
            $this->assertStringContainsString('exited with code 9', $e->getMessage());
        }
    }

    public function test_stop_all_propagates_a_probe_failure_instead_of_returning_no_outcomes(): void
    {
        $supervisor = $this->supervisor();

        $supervisor->useProcessFinder(new ProcessFinder(function (array $spec): array {
            throw new ProcessProbeFailed(
                'The process list could not be read: PowerShell exited with code 9.',
            );
        }));

        $this->expectException(ProcessProbeFailed::class);
        $this->expectExceptionMessage('exited with code 9');

        $supervisor->stopAll();
    }

    public function test_a_valid_empty_process_table_still_stops_nothing_without_failing(): void
    {
        $finder = new ProcessFinder(static fn (array $spec): array => []);
        $supervisor = $this->supervisor();
        $supervisor->useProcessFinder($finder);

        $this->assertSame([], $finder->find(['C:/papyrus', 'queue:work']));
        $this->assertSame([], $supervisor->stopAll());
    }

    public function test_a_start_failure_keeps_its_own_message_when_the_stop_probe_also_fails(): void
    {
        $scratch = $this->scratchPath('papyrus-start-probe');
        $root = $scratch.DIRECTORY_SEPARATOR.'install';
        $data = $scratch.DIRECTORY_SEPARATOR.'data';

        mkdir($root.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'php', 0o777, true);
        mkdir($root.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'frankenphp', 0o777, true);

        file_put_contents($root.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'php'.DIRECTORY_SEPARATOR.'php.ini', "; test ini\n");
        file_put_contents($root.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'frankenphp'.DIRECTORY_SEPARATOR.'Caddyfile', "# test Caddyfile\n");

        $prober = new class extends Prober
        {
            public function isPortTaken(int $port): bool
            {
                return true;
            }

            /**
             * @return array{pid: int, name: string, command: string, addresses: list<string>}|null
             */
            public function portHolder(int $port): ?array
            {
                return null;
            }
        };

        $supervisor = new Supervisor(
            root: $root,
            dataDirectory: $data,
            logDirectory: $data.DIRECTORY_SEPARATOR.'logs',
            prober: $prober,
        );

        $supervisor->useProcessFinder(new ProcessFinder(function (array $spec): array {
            throw new ProcessProbeFailed(
                'The process list could not be read: PowerShell exited with code 9.',
            );
        }));

        try {
            $supervisor->start(static function (string $line): void {});

            $this->fail('start() did not report the failure that stopped it');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(
                'Something is listening on port 4567',
                $e->getMessage(),
                'the original start failure must not be replaced by the probe failure',
            );

            $this->assertStringContainsString(
                'exited with code 9',
                $e->getMessage(),
                'the probe failure must be appended rather than swallowed',
            );

            $this->assertStringContainsString(
                'Something is listening on port 4567',
                (string) $e->getPrevious()?->getMessage(),
                'the original failure stays attached as the previous exception',
            );
        }
    }

    private function supervisor(): Supervisor
    {
        return new Supervisor(
            root: 'C:\\Papyrus',
            dataDirectory: 'C:\\Papyrus Data',
            logDirectory: 'C:\\Papyrus Data\\logs',
        );
    }

    /**
     * @param  list<string>  $needles
     */
    private function assertProbeFailure(string $output, int $exit, array $needles): void
    {
        try {
            $rows = ProcessFinder::parseProbeOutput($output, $exit);

            $this->fail('the parser accepted a failed probe and returned '.json_encode($rows));
        } catch (ProcessProbeFailed $e) {
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        }
    }
}
