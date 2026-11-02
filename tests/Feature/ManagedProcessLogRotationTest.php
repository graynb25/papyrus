<?php

namespace Tests\Feature;

use Papyrus\Launcher\ManagedProcess;
use Tests\TestCase;

/**
 * Process logs are opened append-only, so a long-lived install needs rotation.
 *
 * The launcher rotates before `proc_open`, which is what lets the child open the new file
 * rather than a handle to the old one.
 */
class ManagedProcessLogRotationTest extends TestCase
{
    public function test_start_rotates_a_log_above_five_megabytes_and_keeps_three_rotations(): void
    {
        $scratch = $this->scratchPath('papyrus-process-log');
        $log = $scratch.DIRECTORY_SEPARATOR.'web.log';
        $script = $scratch.DIRECTORY_SEPARATOR.'child.php';

        file_put_contents($script, "<?php echo 'child started';\n");

        $oversized = 5 * 1024 * 1024 + 1;

        file_put_contents($log, str_repeat('x', $oversized));

        $old = [];

        for ($i = 1; $i <= 3; $i++) {
            $old[$i] = $log.'.2020010'.$i.'-000000';

            file_put_contents($old[$i], 'old '.$i);
        }

        $process = new ManagedProcess('PHP', PHP_BINARY, [$script], $scratch, $log);

        $process->start();
        $process->stop(5);

        $rotations = glob($log.'.????????-??????*') ?: [];

        sort($rotations);

        $this->assertCount(3, $rotations, 'the new rotation and the two newest older ones');
        $this->assertFileDoesNotExist($old[1], 'the oldest rotation is the one pruned');
        $this->assertFileExists($old[2]);
        $this->assertFileExists($old[3]);
        $this->assertGreaterThan(
            $oversized - 1,
            (int) filesize($rotations[2]),
            'the oversized log became the newest rotation',
        );
        $this->assertLessThanOrEqual(
            5 * 1024 * 1024,
            (int) filesize($log),
            'the child opens a fresh log file after rotation',
        );
    }
}
