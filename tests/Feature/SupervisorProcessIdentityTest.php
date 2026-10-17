<?php

namespace Tests\Feature;

use Papyrus\Launcher\ProcessFinder;
use Papyrus\Launcher\Supervisor;
use Tests\TestCase;

/**
 * Which processes this launcher is allowed to believe are its own.
 *
 * ## What was broken
 *
 * `stop` matched processes on `[data directory, role]`, and `health` matched them on a bare role
 * name: `queue:work`, `schedule:work`, `frankenphp`, the jar's basename. Two answers to one question,
 * in the same class, one screen apart.
 *
 * The consequence was measured, not argued. With a second copy of the install in another folder, its
 * `health` reported **the installed app's four processes as its own and every line green**, while
 * failing on a database that was not there. A reader running a portable copy alongside an installed one
 * would have been told everything was fine.
 *
 * `stop` was always right, because it had been fixed for a different defect once already. Lesson 223
 * again, one level up, and lesson 225 in the specific form that decides it: the fix landed on one
 * occurrence of the pattern and the other occurrence was three lines away.
 *
 * ## What is asserted
 *
 * Not that the markers are particular strings, because that would be a test of the implementation. The
 * property is that **every process this launcher looks for carries this install's own data
 * directory**, which is the one string that cannot be shared with another install because it is where
 * the instance lock lives. `dev-workers.ps1` uses the same idea for the same reason.
 */
class SupervisorProcessIdentityTest extends TestCase
{
    private string $root;

    private string $data;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-identity-'.getmypid().'-'.uniqid();
        $this->data = $this->root.DIRECTORY_SEPARATOR.'papyrus-data';

        mkdir($this->data, 0o777, true);

        /*
         * A jar, because without one `suwayomi` has no tokens and is never looked up at all.
         *
         * That is correct behaviour and it is worth having the test say so rather than quietly
         * asserting three lookups: a suite that expected four would be asserting an assumption about
         * the scratch install rather than about the code, and it would pass for the wrong reason on a
         * machine where the jar happened to be found.
         */
        mkdir($this->root.DIRECTORY_SEPARATOR.'suwayomi', 0o777, true);
        file_put_contents(
            $this->root.DIRECTORY_SEPARATOR.'suwayomi'.DIRECTORY_SEPARATOR.'Suwayomi-Server-v2.4.2366.jar',
            'not a real jar',
        );
    }

    protected function tearDown(): void
    {
        foreach (['papyrus-data' => true, 'suwayomi' => true] as $dir => $unused) {
            $path = $this->root.DIRECTORY_SEPARATOR.$dir;

            foreach ((array) glob($path.DIRECTORY_SEPARATOR.'*') as $file) {
                if (is_string($file)) {
                    @unlink($file);
                }
            }

            @rmdir($path);
        }

        @rmdir($this->root);

        /*
         * The app boot in setUp left its error and exception handlers on the stack, and the
         * parent teardown is what takes them off. Every test in this file ended flagged as
         * risky with a message about handlers the class never registered, which reads like a
         * production fault and was a missing parent call.
         */
        parent::tearDown();
    }

    /**
     * A supervisor whose finder records what it was asked for and finds nothing.
     *
     * Records rather than answers, because the question being tested is *what was asked*, and a
     * finder that returned a fixed list would let a test pass while the tokens were wrong.
     *
     * @param  list<list<string>>  $asked
     */
    private function supervisorRecording(array &$asked): Supervisor
    {
        $supervisor = new Supervisor(
            root: $this->root,
            dataDirectory: $this->data,
            logDirectory: $this->data.DIRECTORY_SEPARATOR.'logs',
            suwayomiPort: 4567,
            appPort: 8402,
        );

        $supervisor->useProcessFinder(new ProcessFinder(
            probe: function (array $tokens) use (&$asked): array {
                $asked[] = $tokens;

                return [];
            },
        ));

        return $supervisor;
    }

    /**
     * The load-bearing assertion: every lookup names this install's data directory.
     *
     * A bare `queue:work` matches the queue worker of any install on the machine, which is the whole
     * defect. The data directory cannot collide, because two installs sharing one would also share the
     * lock that stops the second starting at all.
     */
    public function test_every_process_lookup_names_this_install_data_directory(): void
    {
        $asked = [];
        $supervisor = $this->supervisorRecording($asked);

        foreach (['queue', 'scheduler', 'web', 'suwayomi'] as $name) {
            $supervisor->isRunning($name);
        }

        $this->assertCount(4, $asked, 'every process was looked up');

        foreach ($asked as $tokens) {
            $this->assertContains(
                $this->data,
                $tokens,
                'a lookup that does not name this install\'s data directory will match another install\'s '
                    .'processes. Tokens asked: '.implode(' | ', $tokens),
            );
        }
    }

    /**
     * And each lookup still carries the role, so asking for the queue does not find the web server.
     *
     * The data directory alone is not enough. Four processes share it, and a stop that found all four
     * for every name would report four kills where one happened.
     */
    public function test_each_lookup_still_distinguishes_the_role(): void
    {
        $asked = [];
        $supervisor = $this->supervisorRecording($asked);

        $expected = [
            'queue' => 'queue:work',
            'scheduler' => 'schedule:work',
            'web' => 'frankenphp',
        ];

        foreach ($expected as $name => $role) {
            $supervisor->isRunning($name);
        }

        foreach ($expected as $name => $role) {
            $this->assertContains($role, $asked[$name === 'queue' ? 0 : ($name === 'scheduler' ? 1 : 2)], $name.' must name its own role');
        }
    }

    /**
     * A process belonging to a different install matches nothing.
     *
     * The scenario as a test rather than as prose. Another install's worker has a real
     * `queue:work` on its command line and none of this install's data directory, so the matcher,
     * which requires every token, finds nothing. Before the fix the token list was the role alone and
     * this returned true.
     *
     * The matcher is not exercised against real processes, because that is what
     * {@see ProcessFinder} is and it is tested there. What is under test here is the token list.
     */
    public function test_another_installs_worker_is_not_mistaken_for_ours(): void
    {
        $otherData = 'C:\\somewhere\\else\\Papyrus';

        $asked = [];
        $supervisor = $this->supervisorRecording($asked);
        $supervisor->isRunning('queue');

        $tokens = $asked[0];

        $this->assertContains('queue:work', $tokens, 'the role must still be there');
        $this->assertNotContains($otherData, $tokens, 'sanity: this is a different install');
        $this->assertContains(
            $this->data,
            $tokens,
            'with the data directory required, a process carrying another install\'s cannot match, '
                .'because a command line holds one path and it would have to be both',
        );
    }

    /**
     * An unknown name is asked nothing rather than asked everything.
     *
     * A lookup for a name that is not one of the four has no tokens, and a finder handed an empty list
     * would either match nothing or, worse, match whatever an empty filter returns. `find()` already
     * returns nothing for an empty list, and the point here is that the supervisor does not reach it
     * with something accidental.
     */
    public function test_an_unknown_name_is_not_looked_up_at_all(): void
    {
        $asked = [];
        $supervisor = $this->supervisorRecording($asked);

        $this->assertFalse($supervisor->isRunning('not-a-process'));
        $this->assertSame([], $asked, 'nothing should have been searched for');
    }

    /**
     * The launcher's own liveness seam still short-circuits, so tests that stub it are unaffected.
     *
     * Stated as a test because it is the reason this change is safe against the twenty nine health
     * tests that stub liveness: they inject an answer before the finder is reached, so they exercise
     * their own assertions and not the token list.
     */
    public function test_an_injected_liveness_answer_wins_and_never_reaches_the_finder(): void
    {
        $asked = [];
        $supervisor = $this->supervisorRecording($asked);

        $supervisor->useLivenessProbe(fn (string $name): bool => true);

        $this->assertTrue($supervisor->isRunning('queue'));
        $this->assertSame([], $asked, 'the finder must not be consulted when an answer is injected');
    }
}
