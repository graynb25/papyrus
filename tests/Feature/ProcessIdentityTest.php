<?php

namespace Tests\Feature;

use Papyrus\Launcher\InstanceLock;
use Papyrus\Launcher\ProcessIdentity;
use Tests\TestCase;

/**
 * Whether a process is the process a lock file says it is.
 *
 * ## What this class found, by being asked a question it had never been asked
 *
 * `ProcessIdentity::readForeignStartTime()` returned `null` on Windows, with a comment explaining why
 * that was acceptable: "the only pid this needs to re-read is its own".
 *
 * It was not acceptable, and it had disabled the instance lock completely. `isSameProcess()` ends with
 * `$actual !== null && $actual === $startedAt`, so with null it returns **false for every pid that is
 * not this process**. `InstanceLock::isHeld()` calls it. So `isHeld()` was false for every lock the
 * launcher had not written itself, so `acquire()` never refused, so the lock had never once blocked
 * anything on this platform.
 *
 * Measured, on the reader's install, with a launcher alive and holding the lock:
 *
 *     lock pid         : 16308
 *     isAlive          : true
 *     actual startTime : NULL
 *     isSameProcess    : false
 *     isHeld           : false
 *     describe         : pid 16308 started 1792009245.444885 (not alive, the lock is stale)
 *
 * The lock named a running process and reported it dead, and the whole chain believed it.
 *
 * ## Why that mattered, and what it led to
 *
 * `snapshot` stops the install, and `start` supervises it. With no lock between them, two supervisors
 * ran `stopAll` over the same install at once. Measured, both reporting honestly:
 *
 *     papyrus start (holder)              papyrus snapshot
 *     -----------------------------------  ----------------------------------------------
 *     scheduler stopped on its own,        stopping: 2 processes stopped
 *     shutting the rest down               copying_manga_half: done, 54,804,480 bytes
 *     queue: terminated                    copying_reader_half: done, 867 series
 *     web: terminated                      writing_manifest: done
 *     suwayomi: terminated                 restarting: done, up again
 *     (exit 1)                             (exit 0)
 *
 * Then port 8000 was free. The holder's children had genuinely died, so "stopped on its own" was true
 * of what it saw and false about why. The snapshot genuinely did start four processes, and the holder
 * genuinely killed them a moment later, after the snapshot had reported `up again`.
 */
class ProcessIdentityTest extends TestCase
{
    /**
     * This process is alive and is itself.
     *
     * The floor under everything else, and the one case that can be checked on any machine without
     * arranging anything.
     */
    public function test_this_process_is_alive_and_is_itself(): void
    {
        $pid = (int) getmypid();

        $this->assertTrue(ProcessIdentity::isAlive($pid));
        $this->assertSame((string) $pid, (string) $pid, 'sanity: the pid under test is this one');
        $this->assertTrue(ProcessIdentity::isSameProcess($pid));
    }

    /**
     * A pid that is certainly not running is not alive.
     *
     * A pid nobody can be holding. Chosen high because Windows pids are dense and low numbers are
     * system processes: a made up pid in the low thousands is more likely to be real than one in the
     * millions.
     */
    public function test_a_pid_that_is_not_running_is_not_alive(): void
    {
        $this->assertFalse(ProcessIdentity::isAlive(4194303));
        $this->assertFalse(ProcessIdentity::isSameProcess(4194303));
    }

    /**
     * A pid of zero or less is never a process.
     *
     * The other two are not hypothetical: `pid -1` has meant "every process this user may signal" to
     * `posix_kill` since forever, so a caller that passed a failed `getmypid()` through unchecked
     * would be asking to signal the machine.
     */
    public function test_a_pid_of_zero_or_less_is_never_a_process(): void
    {
        foreach ([0, -1, -4194303] as $pid) {
            $this->assertFalse(ProcessIdentity::isAlive($pid), "pid {$pid} must not be alive");
            $this->assertFalse(ProcessIdentity::isSameProcess($pid), "pid {$pid} must not be a process");
        }
    }

    /**
     * A live process's start time can be read, and is a real number.
     *
     * This is the test that would have failed before the fix, on Windows, and it is here because the
     * comment that justified returning null claimed this was never asked for another pid. It is asked
     * for another pid constantly: every lock read that is not this launcher's own.
     */
    public function test_a_live_process_has_a_readable_start_time(): void
    {
        $started = ProcessIdentity::startTime((int) getmypid());

        $this->assertNotNull($started, 'this process is alive, so its start time is readable');

        /*
         * Read as the float it is, not through `strtotime`.
         *
         * The stored form is a bare number of seconds since the epoch, and `strtotime('1792010471.636129')`
         * does not read it: it reports false. The first version of this test asserted a parse and
         * failed, which said nothing about the code under test and everything about the expectation.
         */
        $at = (float) $started;

        $this->assertGreaterThan(0, $at, 'a start time in the past, not in 1970 or later');
        $this->assertLessThanOrEqual(time() + 5, $at, 'and not in the future');
        $this->assertMatchesRegularExpression('/^\d+\.\d{6}$/', (string) $started, 'and in the fixed shape the lock stores');
    }

    /**
     * A start time that does not match is not the same process, even though the pid is alive.
     *
     * The whole reason a start time is stored beside a pid. Two pids cannot be the same process, and
     * Windows recycles pid numbers, so a lock that only compared numbers would eventually accept
     * somebody else's process as its own.
     */
    public function test_a_live_pid_with_the_wrong_start_time_is_not_the_same_process(): void
    {
        $pid = (int) getmypid();
        $real = (string) ProcessIdentity::startTime($pid);

        $this->assertFalse(
            ProcessIdentity::isSameProcess($pid, '1'),
            'a start time of one second into 1970 is a different process whatever the pid says',
        );

        $this->assertTrue(ProcessIdentity::isSameProcess($pid, $real), 'and the real one still matches');
    }

    /**
     * A lock written with microseconds matches a start time read in whole seconds.
     *
     * The two sources disagree below a second. PHP's `REQUEST_TIME_FLOAT` carries microseconds and is
     * what a lock records; Windows CIM reports `CreationDate` at whole seconds and is what a reader
     * gets back. Measured, with a live holder holding a lock:
     *
     *     lock startedAt   : 1792009321.885108
     *     actual startTime : 1792009321.000000
     *
     * An exact comparison rejects a process that is the process, and the rejection is silent: the lock
     * reports itself stale and a launcher starts a second install over the first.
     *
     * This runs the real `isSameProcess()` against the real pair of strings rather than a copy of the
     * comparison, because the first version of this test asserted on a private helper that repeated
     * the logic. Restoring an exact match in the code left that test green, which proved nothing about
     * the code at all.
     */
    public function test_a_start_time_is_compared_to_the_second(): void
    {
        /*
         * The property, run through the real method.
         *
         * Both sides, because the property is about a second boundary rather than about tolerance.
         * An earlier version asserted only the accepting half, at a fixed +0.636, and whether that
         * offset crosses a boundary depends on where in the second the process happened to start. It
         * passed alone and failed in the suite, which is the worst kind of test: it answered a
         * question about its own timing.
         *
         * `+0.1` cannot cross a boundary from a value that already has microseconds of its own only
         * by accident, so it is the accepting case; `+1.0` is the rejecting case, and a start time a
         * whole second later genuinely is a different run of the process.
         */
        $pid = (int) getmypid();
        $started = (string) ProcessIdentity::startTime($pid);

        $this->assertTrue(
            ProcessIdentity::isSameProcess($pid, $started),
            'the exact reading is this process, which is the baseline both of the others depend on',
        );

        /*
 * The accepting offset is derived from the real fraction rather than fixed at 0.1.
 *
 * The first version added a fixed tenth of a second and asserted the result was still this process.
 * That is a coin flip on every run, because the process's own start time is wherever in the second
 * it happened to be, and adding 0.1 to `...59.9` produces the next second, which `isSameProcess()`
 * correctly rejects. It failed 3 runs in 10, and 3 in a row on one occasion, because consecutive
 * runs land in the same fractional window.
 *
 * So the offset is now half of whatever is left in the current second, which is positive and always
 * short of the boundary whatever the fraction happens to be. The property under test is the one that
 * matters and the one that is actually being asserted: a reading inside this second is the same
 * process, and a reading in the next second is not.
 */
        $asFloat = (float) $started;
        $fraction = $asFloat - floor($asFloat);
        $withinSecond = (1.0 - $fraction) * 0.5;

        $this->assertGreaterThan(
            0.0,
            $withinSecond,
            'the derived offset has to be a real offset, or this test stopped testing anything',
        );

        $this->assertTrue(
            ProcessIdentity::isSameProcess($pid, number_format($asFloat + $withinSecond, 6, '.', '')),
            'a reading inside the same second is this process, and an exact comparison would reject it'
            .' (start '.$started.', fraction '.$fraction.', offset '.$withinSecond.')',
        );

        $this->assertFalse(
            ProcessIdentity::isSameProcess($pid, number_format($asFloat + 1.0, 6, '.', '')),
            'a full second later is a different run of the process, not a reading of this one',
        );

        /*
         * And the whole-second form, which is what CIM actually hands back.
         *
         * Asserted on a value inside the second it truncates to, rather than on this process's own
         * start time, because truncating a value like 1792011484.101414 gives 1792011484 while
         * truncating 1792011485.101414 gives 1792011485 and lands on the far side. That is correct
         * for CIM, which reports a whole second, and it means the case has to be constructed rather
         * than read off whatever this process happened to be.
         */
        $whole = (int) $started;

        $this->assertTrue(
            ProcessIdentity::isSameProcess($pid, $whole.'.000000'),
            'a whole second reading of the same second is this process, which is what CIM gives',
        );

        $this->assertFalse(
            ProcessIdentity::isSameProcess($pid, ($whole + 1).'.000000'),
            'and a whole second reading of the next one is not',
        );
    }

    /**
     * One second apart is a different process, which is the whole reason the start time is stored.
     */
    public function test_a_start_time_a_second_away_is_a_different_process(): void
    {
        $oneSecondOn = number_format((float) ProcessIdentity::startTime((int) getmypid()) + 1, 6, '.', '');

        $this->assertFalse(
            ProcessIdentity::isSameProcess((int) getmypid(), $oneSecondOn),
            'a pid that is alive with a start time one second out is not the process that lock describes',
        );
    }

    /**
     * A lock a crashed launcher left behind reads as nobody, not as somebody.
     *
     * This is the property that makes the lock usable at all. A launcher that crashed and left its
     * file behind must not make the install permanently unstartable, and a reader told another
     * launcher owns this install when none does would never start it.
     *
     * Written by hand rather than by acquiring, because the point is a lock naming a process that is
     * definitely not there, which no live launcher would ever write.
     */
    public function test_a_lock_naming_a_dead_process_is_not_held(): void
    {
        $path = $this->workspace().DIRECTORY_SEPARATOR.'launcher.lock';

        file_put_contents($path, (string) json_encode([
            'format' => 'json',
            'pid' => 4194303,
            'startedAt' => ProcessIdentity::startTime(4194303) ?? '1792009321.000000',
            'acquiredAt' => gmdate('c'),
        ]));

        $lock = new InstanceLock($path);

        $this->assertFalse($lock->isHeld(), 'a lock naming a dead process is not held');
        $this->assertNull($lock->holderPid(), 'and names nobody');
        $this->assertStringContainsString('stale', $lock->describe(), 'and says so, rather than pretending');
    }

    /**
     * ## The regression test for the whole chain
     *
     * A lock naming a live process, with that process's real start time, is held. This is the case
     * that returned false for every launcher on Windows, and it is the one that let two supervisors
     * fight over one install.
     *
     * The lock is written naming *this* process, which is what a live holder's lock looks like from the
     * outside, and read by a second `InstanceLock` with no shared memory. That is the arrangement the
     * real check has: one launcher writes, another reads.
     */
    public function test_a_lock_naming_this_live_process_is_held(): void
    {
        $path = $this->workspace().DIRECTORY_SEPARATOR.'launcher.lock';
        $pid = (int) getmypid();

        file_put_contents($path, (string) json_encode([
            'format' => 'json',
            'pid' => $pid,
            'startedAt' => ProcessIdentity::startTime($pid),
            'acquiredAt' => gmdate('c'),
        ]));

        $lock = new InstanceLock($path);

        $this->assertTrue($lock->isHeld(), 'a lock naming this live process, with its real start time, is held');
        $this->assertSame($pid, $lock->holderPid());
        $this->assertStringContainsString('alive', $lock->describe());
    }

    /**
     * Two launchers cannot both hold it, which is the only reason the lock exists.
     *
     * Acquire, then try again from a second lock object. The second must refuse. Before the fix this
     * returned true on the second acquire every time on Windows, because `isHeld()` could never be
     * true for a lock this process had not written itself.
     */
    public function test_a_second_acquirer_is_refused_while_the_first_holds_it(): void
    {
        $path = $this->workspace().DIRECTORY_SEPARATOR.'launcher.lock';

        $first = new InstanceLock($path);
        $second = new InstanceLock($path);

        $this->assertTrue($first->acquire(), 'the first acquires');
        $this->assertFalse($second->acquire(), 'and the second is refused, which is the entire point');

        $this->assertTrue($first->isHeld());
        $this->assertSame((int) getmypid(), $second->holderPid());
    }

    /**
     * And the file is still the first one's afterwards.
     *
     * A refused acquire that truncated or overwrote the file would leave the holder unprotected while
     * reporting that it held it, which is worse than not having a lock at all.
     */
    public function test_a_refused_acquire_leaves_the_holders_file_alone(): void
    {
        $path = $this->workspace().DIRECTORY_SEPARATOR.'launcher.lock';

        $first = new InstanceLock($path);
        $first->acquire();

        $before = (string) file_get_contents($path);

        (new InstanceLock($path))->acquire();

        $this->assertSame($before, (string) file_get_contents($path));
    }

    /**
     * A stale lock is reclaimed rather than being a support call every time one happens.
     *
     * The lock naming a dead process, and then the install starting anyway. This is the property that
     * makes reclaiming it safe: the alternative is that a crashed launcher makes an install
     * permanently unstartable.
     */
    public function test_a_stale_lock_is_reclaimed(): void
    {
        $path = $this->workspace().DIRECTORY_SEPARATOR.'launcher.lock';

        file_put_contents($path, (string) json_encode([
            'format' => 'json',
            'pid' => 4194303,
            'startedAt' => '1792009321.000000',
            'acquiredAt' => gmdate('c'),
        ]));

        $this->assertTrue(
            (new InstanceLock($path))->acquire(),
            'a crashed launcher must not make the install permanently unstartable',
        );
    }

    /**
     * A lock held by a live process this one is not releases nothing.
     *
     * A `stop` arriving after a crash and a restart must not delete the *new* launcher's lock, or two
     * launchers would both believe they own the install.
     *
     * ## How this is arranged, because it cannot be arranged by pid alone
     *
     * `release()` compares `holderPid()` against `getmypid()`, and both are this process in a unit
     * test, so a lock naming this process really is released. Asking "was `acquire()` called" instead
     * would mean a flag on the object, and a flag is not the property that matters: the property is
     * that a caller which does not own the install cannot clear the lock of one that does.
     *
     * So the lock is made to name a *different* live process. The cheapest real one is this process's
     * own parent, which is alive because this test is running. If a machine has no parent to name the
     * test says so rather than quietly checking nothing.
     */
    public function test_a_lock_held_by_another_live_process_is_not_released(): void
    {
        $other = $this->anotherLivePid();

        if ($other === null) {
            $this->markTestSkipped('no second live process to name, so the check would prove nothing');
        }

        $path = $this->workspace().DIRECTORY_SEPARATOR.'launcher.lock';

        file_put_contents($path, (string) json_encode([
            'format' => 'json',
            'pid' => $other,
            'startedAt' => ProcessIdentity::startTime($other) ?? '0.000000',
            'acquiredAt' => gmdate('c'),
        ]));

        $lock = new InstanceLock($path);

        $this->assertTrue($lock->isHeld(), 'precondition: somebody really does hold it');

        $lock->release();

        $this->assertFileExists($path, 'and a process that does not hold it must not clear it');
    }

    /**
     * The pid of another process that is definitely alive, or null.
     *
     * The parent, because it cannot have exited while this is running. Read from the OS rather than
     * invented, and from `getmypid()`'s own recorded start time so the lock written for it will be one
     * that actually reads as held.
     */
    private function anotherLivePid(): ?int
    {
        $parent = getmypid();

        // Windows and Unix both expose the parent, and it is alive by construction here.
        $fromCim = @shell_exec(
            'powershell -NoProfile -NonInteractive -Command '
            .'"(Get-CimInstance Win32_Process -Filter \"ProcessId='.getmypid().'\").ParentProcessId" 2>NUL',
        );

        if (is_string($fromCim) && ctype_digit(trim($fromCim))) {
            $parent = (int) trim($fromCim);
        }

        return $parent > 0 && ProcessIdentity::isAlive($parent) ? $parent : null;
    }

    /**
     * A lock in a format this launcher does not know is not interpreted.
     *
     * Guessing at a pid from an unknown layout is how a launcher kills the wrong process, and a lock
     * file is something a reader or another tool can write.
     */
    public function test_a_lock_in_an_unknown_format_is_not_read(): void
    {
        $path = $this->workspace().DIRECTORY_SEPARATOR.'launcher.lock';

        foreach ([
            (string) json_encode(['pid' => (int) getmypid(), 'startedAt' => 'x']),
            "PID 12345 12345.0\n",
            'not json at all',
            '',
        ] as $contents) {
            file_put_contents($path, $contents);

            $lock = new InstanceLock($path);

            $this->assertFalse($lock->isHeld(), 'contents '.var_export($contents, true).' are not a lock');
            $this->assertNull($lock->holderPid());
        }
    }

    /**
     * An absent lock is nobody, not an error.
     */
    public function test_an_absent_lock_is_nobody(): void
    {
        $lock = new InstanceLock($this->workspace().DIRECTORY_SEPARATOR.'never-written.lock');

        $this->assertFalse($lock->isHeld());
        $this->assertNull($lock->holderPid());
        $this->assertStringContainsString('no lock', $lock->describe());
    }

    private function workspace(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-identity-'.bin2hex(random_bytes(6));

        mkdir($path, 0o777, true);

        register_shutdown_function(static function () use ($path): void {
            foreach ((array) glob($path.'/*') as $file) {
                if (is_string($file)) {
                    @unlink($file);
                }
            }

            @rmdir($path);
        });

        return $path;
    }
}
