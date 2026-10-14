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
