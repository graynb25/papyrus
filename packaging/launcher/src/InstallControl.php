<?php

namespace Papyrus\Launcher;

/**
 * Stopping and starting the install, behind an interface.
 *
 * ## Why this exists rather than `RestoreCommand` holding a `Supervisor`
 *
 * Because `Supervisor::start()` launches a web server, a JVM and workers, and a test that calls it
 * needs a whole install on disk: a Caddyfile, a PHP binary, a jar. The first version of
 * {@see RestoreCommand} took the `Supervisor` directly and five of its tests died on
 *
 *     The web server configuration is not where this install expects it
 *
 * which is the Supervisor working correctly and the test reaching too far.
 *
 * There were two ways out and only one of them is honest. Stubbing the Supervisor is impossible
 * because it is final. Restructuring the test to build a real install would be testing the launcher
 * rather than the restore, and would make the restore tests slow and fragile.
 *
 * So the dependency is named. `RestoreCommand` asks for "stop" and "start" and does not care what
 * they are, which is also the truth: it is choreography, and choreography should not know what it is
 * choreographing.
 *
 * ## Why stop and start have different return shapes
 *
 * Because the Supervisor's two methods genuinely differ, and unifying them was wrong.
 *
 * | | Returns | Why |
 * | --- | --- | --- |
 * | `stop()` | `array<string, string>` | name to outcome: `stopped`, `killed`, `still running` |
 * | `start()` | `list<string>` | one line per process, in the order they were started |
 *
 * A stop reports what happened to each thing, because "which process had to be killed" is the single
 * most useful line in the file when a reader says it crashed. A start reports the order it did them
 * in, because a web server that is up before the workers are means requests arriving at a queue
 * nobody is draining.
 *
 * Declaring both as one shape was an attempt to tidy that, and PHPStan rejected it against the real
 * implementation twice. The shapes are not an inconsistency to be smoothed over; they are two
 * different facts.
 */
interface InstallControl
{
    /**
     * Bring everything down.
     *
     * ## Why a caller of this has to ask permission first
     *
     * Because `stop` does not care whether another launcher is supervising this install, and two
     * supervisors over one install is a race neither can win. Measured, on the reader's own install,
     * running `papyrus snapshot` from a second process while `papyrus start` was holding the terminal
     * open:
     *
     *     start (holder)                  snapshot
     *     ------------------------------  ---------------------------------------------
     *     scheduler stopped on its own,    stopping: 2 processes stopped
     *     shutting the rest down           copying_manga_half: done, 49,709,056 bytes
     *     scheduler: already gone          copying_reader_half: done, 867 series, 21,447 states
     *     queue: terminated                writing_manifest: done
     *     web: terminated                  restarting: done, up again
     *     suwayomi: terminated             (exit 0)
     *     kcef: could not be stopped
     *     (exit 1)
     *
     * Both reported what they did and the install was down afterwards. The holder saw its children
     * die, concluded they had died on their own, and shut down and exited. The snapshot then started
     * a fresh set of processes that nobody was supervising, and its own record said `up again`.
     *
     * The install was not running when I looked. Neither command had lied, and together they had
     * produced a stopped install that both of them claimed to have left running.
     *
     * So a caller asks {@see InstallFacts::supervisorPid()} whether a live launcher owns this install
     * before stopping anything, and refuses with an instruction rather than racing. The lock file
     * already answers that question and names the process; it was simply only ever consulted by
     * `start`.
     *
     * @param  callable(string): void  $say
     * @return array<string, string> Process name to outcome, empty when nothing belonging to this
     *                               install was running.
     */
    public function stop(callable $say): array;

    /**
     * Bring everything up.
     *
     * @param  callable(string): void  $say
     * @return list<string> One line per process, in start order.
     */
    public function start(callable $say): array;
}
