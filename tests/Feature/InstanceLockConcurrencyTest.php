<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Two launchers starting at once, against the real lock.
 *
 * ## What was broken
 *
 * `acquire()` read and validated the lock, then wrote a temporary file and renamed it over the
 * lock path. On Windows PHP's `rename()` replaces an existing file, so two launchers could both
 * pass the check and both rename. Both then believed they owned the install, the second failed on
 * a port or on Suwayomi, and its catch block called `stopAll()`, which stopped the processes the
 * first launcher had just started.
 *
 * ## Why this runs real child processes
 *
 * Because the defect is an interleaving between processes, and no assertion inside one process
 * can produce it. Each child waits for a barrier file and then calls `acquire()` within
 * microseconds of the others. With the old check-then-rename every child would win; with the
 * exclusive create exactly one does.
 *
 * The winner holds its lock open until the parent says every child has reported, because a child
 * that exited straight after acquiring would leave a lock naming a dead process and the others
 * would legitimately reclaim it. That would test stale recovery rather than the race.
 */
final class InstanceLockConcurrencyTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-lock-race-'.getmypid().'-'.uniqid();

        mkdir($this->scratch, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->scratch);

        parent::tearDown();
    }

    public function test_only_one_of_several_simultaneous_launchers_acquires(): void
    {
        // More than once, because a timing test that passes once has not measured a race.
        for ($round = 0; $round < 3; $round++) {
            $this->race($round);
        }
    }

    private function race(int $round): void
    {
        $script = $this->scratch.DIRECTORY_SEPARATOR.'acquire.php';
        $lock = $this->scratch.DIRECTORY_SEPARATOR.'launcher-'.$round.'.lock';
        $barrier = $this->scratch.DIRECTORY_SEPARATOR.'start-'.$round;
        $release = $this->scratch.DIRECTORY_SEPARATOR.'release-'.$round;

        file_put_contents($script, <<<'PHP'
<?php
require $argv[1];
require $argv[2];
require $argv[3];

$lock = new \Papyrus\Launcher\InstanceLock($argv[4]);

file_put_contents($argv[5], 'ready');

$deadline = microtime(true) + 15;

while (! is_file($argv[6]) && microtime(true) < $deadline) {
    usleep(1000);
}

$acquired = $lock->acquire();

file_put_contents($argv[7], $acquired ? '1' : '0');

$deadline = microtime(true) + 15;

while (! is_file($argv[8]) && microtime(true) < $deadline) {
    usleep(1000);
}

exit($acquired ? 0 : 1);
PHP);

        $launcher = dirname(__DIR__, 2).'/packaging/launcher/src';
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $children = [];
        $ready = [];
        $results = [];

        for ($index = 0; $index < 4; $index++) {
            $readyFile = $this->scratch.DIRECTORY_SEPARATOR.'ready-'.$round.'-'.$index;
            $resultFile = $this->scratch.DIRECTORY_SEPARATOR.'result-'.$round.'-'.$index;

            $ready[] = $readyFile;
            $results[] = $resultFile;

            $process = proc_open(
                [
                    PHP_BINARY,
                    $script,
                    $launcher.'/SystemTools.php',
                    $launcher.'/ProcessIdentity.php',
                    $launcher.'/InstanceLock.php',
                    $lock,
                    $readyFile,
                    $barrier,
                    $resultFile,
                    $release,
                ],
                [
                    0 => ['pipe', 'r'],
                    1 => ['file', $null, 'w'],
                    2 => ['file', $null, 'a'],
                ],
                $pipes,
            );

            $this->assertIsResource($process, 'a child launcher could not be started');
            fclose($pipes[0]);

            $children[] = $process;
        }

        try {
            $this->waitForFiles($ready, 'every child to reach acquire()');

            // All four are waiting on this one file, so the acquire calls land together.
            file_put_contents($barrier, 'go');

            $this->waitForFiles($results, 'every child to report its acquire result');

            $acquired = 0;

            foreach ($results as $result) {
                $value = (string) file_get_contents($result);

                $this->assertContains($value, ['0', '1'], 'each child must report an answer');

                $acquired += $value === '1' ? 1 : 0;
            }

            $this->assertSame(
                1,
                $acquired,
                'exactly one launcher may win the lock; '.$acquired.' did in round '.$round,
            );
        } finally {
            file_put_contents($release, 'go');

            foreach ($children as $process) {
                proc_close($process);
            }
        }
    }

    /**
     * @param  list<string>  $files
     */
    private function waitForFiles(array $files, string $what): void
    {
        $deadline = microtime(true) + 20;

        while (microtime(true) < $deadline) {
            $missing = array_filter($files, static fn (string $file): bool => ! is_file($file));

            if ($missing === []) {
                return;
            }

            usleep(10000);
        }

        $this->fail('timed out waiting for '.$what);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($path);
    }
}
