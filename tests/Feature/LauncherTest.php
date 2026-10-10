<?php

namespace Tests\Feature;

use Papyrus\Launcher\InstanceLock;
use Papyrus\Launcher\Prober;
use Papyrus\Launcher\ProcessIdentity;
use Tests\TestCase;

/**
 * The launcher's own logic, tested without starting anything.
 *
 * ## Why only these pieces
 *
 * Because the rest of the supervisor is process spawning, which cannot be tested by asserting
 * on itself. What can be tested, and what is where the silent failures live, is everything
 * that decides **whether a process is the one we started** and **how long to wait for one**.
 *
 * Both of those fail by returning a confident wrong answer. A lock that thinks a dead process
 * is alive refuses to start an install, and a reader's library is then unreachable with no
 * error to look at. A lock that thinks a live process is dead lets a second launcher start,
 * and two launchers start eight processes on two ports.
 *
 * ## Why these tests do not use the framework
 *
 * The launcher is required to work when the application does not, so it does not use the
 * application. These tests require its files directly for the same reason, and that also means
 * a failure here cannot be caused by a broken framework.
 */
class LauncherTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-launcher-'.getmypid();

        if (! is_dir($scratch)) {
            mkdir($scratch, 0o777, true);
        }

        $this->scratch = $scratch;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratch.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->scratch);

        parent::tearDown();
    }

    private function lockPath(string $name = 'launcher.lock'): string
    {
        return $this->scratch.DIRECTORY_SEPARATOR.$name;
    }

    /**
     * Two launchers, one install. The second must be told no rather than starting four more
     * processes.
     */
    public function test_a_second_launcher_cannot_take_a_held_lock(): void
    {
        $first = new InstanceLock($this->lockPath());

        $this->assertTrue($first->acquire(), 'the first launcher should get the lock');

        $second = new InstanceLock($this->lockPath());

        $this->assertFalse($second->acquire(), 'the second launcher must be refused');
        $this->assertTrue($second->isHeld(), 'and the lock must still report as held');
        $this->assertSame(getmypid(), $second->holderPid());
    }

    public function test_a_fresh_lock_is_reported_as_held_and_names_this_process(): void
    {
        $lock = new InstanceLock($this->lockPath());

        $this->assertFalse($lock->isHeld(), 'nothing has taken it yet');

        $lock->acquire();

        $this->assertTrue($lock->isHeld());
        $this->assertStringContainsString((string) getmypid(), $lock->describe());
    }

    /**
     * The case that matters most: a launcher crashed and left its lock behind.
     *
     * A pid on its own would eventually name a process that is alive and completely unrelated,
     * and the install would then refuse to start, forever, with no way for a reader to tell
     * why. Hence the start time in the lock.
     */
    public function test_a_lock_naming_a_dead_process_is_stale_and_reclaimable(): void
    {
        $path = $this->lockPath();

        file_put_contents($path, json_encode([
            'format' => 'json',
            'pid' => $this->deadPid(),
            'startedAt' => '1234.000000',
            'acquiredAt' => gmdate('c'),
        ]));

        $lock = new InstanceLock($path);

        $this->assertFalse($lock->isHeld(), 'a lock naming a dead process is not held');
        $this->assertNull($lock->holderPid());

        $this->assertTrue($lock->acquire(), 'and the install must not stay unstartable because of it');
        $this->assertTrue($lock->isHeld());
        $this->assertSame(getmypid(), $lock->holderPid());
    }

    /**
     * A live pid with the wrong start time is a recycled pid, not the original process.
     *
     * This is the identity check doing the work a pid cannot. Without it, any long lived
     * machine eventually produces this exact situation.
     */
    public function test_a_live_pid_with_the_wrong_start_time_is_not_the_same_process(): void
    {
        $path = $this->lockPath();

        // This process really is alive, but the lock claims it started at a different moment.
        file_put_contents($path, json_encode([
            'format' => 'json',
            'pid' => getmypid(),
            'startedAt' => '1.000000',
            'acquiredAt' => gmdate('c'),
        ]));

        $lock = new InstanceLock($path);

        $this->assertFalse(
            $lock->isHeld(),
            'a recycled pid must not be mistaken for the launcher that wrote the lock',
        );

        $this->assertTrue($lock->acquire(), 'so the stale lock is reclaimable');
    }

    /**
     * A pid that exists but with no recorded start time degrades to liveness alone.
     *
     * Better than refusing to start. The lock format can evolve, and an unreadable lock from
     * an older version should cost precision rather than the install.
     */
    public function test_a_lock_without_a_start_time_falls_back_to_liveness(): void
    {
        $path = $this->lockPath();

        file_put_contents($path, json_encode([
            'format' => 'json',
            'pid' => getmypid(),
            'startedAt' => '',
            'acquiredAt' => gmdate('c'),
        ]));

        $this->assertTrue((new InstanceLock($path))->isHeld());
    }

    /**
     * A lock this launcher did not write must not be interpreted by it.
     *
     * Guessing at a pid from an unknown layout is how a launcher ends up killing a process it
     * has never heard of.
     */
    public function test_a_lock_in_an_unknown_format_is_ignored_rather_than_guessed_at(): void
    {
        $path = $this->lockPath();

        file_put_contents($path, "9999\nsome other format\n");
        file_put_contents($this->lockPath('garbage.lock'), 'not json at all');

        $this->assertFalse((new InstanceLock($path))->isHeld());
        $this->assertFalse((new InstanceLock($this->lockPath('garbage.lock')))->isHeld());

        $this->assertTrue((new InstanceLock($path))->acquire(), 'and both are reclaimable');
    }

    /**
     * A `release` must only remove a lock this process actually holds.
     *
     * The scenario is narrow and worth pinning: this launcher's lock is removed, another
     * launcher takes the install, and then a late cleanup runs here. If `release` deleted the
     * file without checking, two launchers would both believe they own four processes.
     *
     * Written by naming a different pid rather than by making a second `InstanceLock`. Two
     * lock objects in one process *are* one process, so that arrangement would pass the guard
     * for the wrong reason and assert nothing. The guard is about the pid in the file, so the
     * file has to name a pid that is not this one.
     */
    public function test_releasing_a_lock_naming_another_process_leaves_it_in_place(): void
    {
        $path = $this->lockPath();

        file_put_contents($path, json_encode([
            'format' => 'json',
            'pid' => $this->deadPid(),
            'startedAt' => '1234.000000',
        ]));

        $lock = new InstanceLock($path);

        $lock->release();

        $this->assertFileExists(
            $path,
            'a launcher that does not hold the lock must not delete it on its way out',
        );
    }

    /**
     * The same guard, from the other side: a lock this process took is removed.
     *
     * Paired with the test above because either alone is half the behaviour, and the failure
     * mode of getting only one right is a lock that can never be released.
     */
    public function test_a_lock_naming_this_process_is_released(): void
    {
        $path = $this->lockPath();

        file_put_contents($path, json_encode([
            'format' => 'json',
            'pid' => getmypid(),
            'startedAt' => (string) ProcessIdentity::startTime(getmypid()),
        ]));

        (new InstanceLock($path))->release();

        $this->assertFileDoesNotExist($path);
    }

    public function test_releasing_our_own_lock_removes_it(): void
    {
        $path = $this->lockPath();
        $lock = new InstanceLock($path);

        $lock->acquire();
        $lock->release();

        $this->assertFileDoesNotExist($path);
        $this->assertFalse($lock->isHeld());
    }

    /**
     * A launcher killed hard leaves a temporary file next to the lock.
     *
     * It must not be mistaken for the lock itself, and it must not stop the lock being taken.
     */
    public function test_acquiring_leaves_no_temporary_file_behind(): void
    {
        $path = $this->lockPath();
        $lock = new InstanceLock($path);

        $lock->acquire();

        $this->assertSame(
            [$path],
            glob($this->scratch.DIRECTORY_SEPARATOR.'*') ?: [],
            'the lock should be the only file written',
        );
    }

    /**
     * The probe has to tolerate a throw, because "not ready yet" arrives as an exception.
     *
     * A Suwayomi that refuses connections for thirty seconds and then serves normally is the
     * normal cold start. Treating the refusal as fatal would fail every single start.
     */
    public function test_a_probe_that_throws_is_waited_on_rather_than_fatal(): void
    {
        $attempts = 0;

        $passed = (new Prober)->waitFor(function () use (&$attempts): bool {
            $attempts++;

            throw new \RuntimeException('connection refused');
        }, 2, 'a thing that throws');

        $this->assertFalse($passed, 'it never becomes true, so the wait times out');
        $this->assertGreaterThan(1, $attempts, 'and it must have retried rather than given up');
    }

    public function test_a_probe_that_passes_is_not_polled_again(): void
    {
        $attempts = 0;

        $passed = (new Prober)->waitFor(function () use (&$attempts): bool {
            $attempts++;

            return true;
        }, 30, 'a thing that is already true');

        $this->assertTrue($passed);
        $this->assertSame(1, $attempts);
    }

    /**
     * The interval backs off so a slow starter is not hammered.
     *
     * Measured by counting attempts inside a fixed window: constant polling would do far more
     * of them.
     */
    public function test_the_poll_interval_backs_off_rather_than_spinning(): void
    {
        $attempts = 0;

        (new Prober)->waitFor(function () use (&$attempts): bool {
            $attempts++;

            return false;
        }, 1, 'a thing that never is true');

        // Starting at 100ms and multiplying by 1.6 to a 1s ceiling, a second buys about seven
        // attempts. A flat 100ms would buy ten, and a spin loop would buy thousands.
        $this->assertLessThanOrEqual(9, $attempts, 'the interval must back off');
        $this->assertGreaterThanOrEqual(4, $attempts, 'but it must still poll promptly at first');
    }

    public function test_a_taken_port_is_reported_as_taken(): void
    {
        $prober = new Prober;
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        $this->assertIsResource($server, 'this test needs a socket to bind');

        $name = stream_socket_get_name($server, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $this->assertTrue($prober->isPortTaken($port), 'a bound port is taken');
        $this->assertFalse($prober->isPortTaken(1), 'port 1 is not');

        fclose($server);
    }

    public function test_identity_comparison_needs_a_pid_that_exists(): void
    {
        $this->assertFalse(ProcessIdentity::isSameProcess(0));
        $this->assertFalse(ProcessIdentity::isSameProcess(-1));
        $this->assertFalse(ProcessIdentity::isSameProcess($this->deadPid()));
    }

    public function test_this_process_is_identifiable(): void
    {
        $started = ProcessIdentity::startTime(getmypid());

        $this->assertNotNull($started, 'a launcher must be able to record its own start time');
        $this->assertTrue(ProcessIdentity::isSameProcess(getmypid(), $started));
        $this->assertFalse(ProcessIdentity::isSameProcess(getmypid(), '1.000000'));
    }

    /**
     * A pid that has certainly exited.
     *
     * Spawned and reaped rather than guessed at, because a hardcoded "large number" is
     * exactly the kind of value that becomes a live process one day and makes this test fail
     * for a reason nobody can see.
     *
     * `cmd /c exit` was the obvious choice and is wrong: `exit` is a cmd builtin rather than a
     * program, PHP quotes each array element, and the result is a shell error printed into the
     * test output while the process is spawned anyway. Running this same PHP binary with an
     * empty program is quieter, works on both platforms, and cannot print anything.
     */
    private function deadPid(): int
    {
        static $dead = null;

        if ($dead !== null) {
            return $dead;
        }

        $process = proc_open(
            [PHP_BINARY, '-r', 'exit;'],
            [1 => ['file', $this->scratch.DIRECTORY_SEPARATOR.'dead.out', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            // Falling back to a pid above any default maximum on Windows, where the address
            // space makes that safe in practice.
            return $dead = 4_294_000_000;
        }

        $status = proc_get_status($process);
        proc_close($process);

        $dead = (int) $status['pid'];

        // Verified, not assumed. A test built on a pid that is secretly alive asserts nothing,
        // and would do so while looking like it covered the stale lock case.
        $this->assertFalse(
            ProcessIdentity::isAlive($dead),
            "the helper returned pid {$dead}, which is still running, so it cannot test a stale lock",
        );

        return $dead;
    }
}
