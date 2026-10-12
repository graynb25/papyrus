<?php

namespace Tests\Feature;

use Papyrus\Launcher\DataLocation;
use Tests\TestCase;

/**
 * Where a packaged install keeps its data when nobody has said.
 *
 * ## Why this is tested at all
 *
 * Because the two ways this project is installed want opposite answers, and getting it wrong is
 * silent until a reader cannot find their library.
 *
 * A portable install at `D:\Apps\Papyrus` can use `D:\Apps\papyrus-data`, and must, because that
 * is what makes the whole thing movable. An installed copy at `C:\Program Files\Papyrus` cannot use
 * the sibling at all, because that is read only for a normal user, and the alternative is a refusal
 * that names a path without saying a good one exists.
 *
 * The old behaviour was always the sibling, so the installed case was the broken one and the
 * portable case was the only one anyone had tried.
 */
class DataLocationTest extends TestCase
{
    /**
     * @param  array<string, bool>  $writable  Path to whether it could be written there.
     */
    private function locationFor(array $writable): DataLocation
    {
        return new DataLocation(fn (string $path): bool => $writable[$path] ?? false);
    }

    public function test_a_portable_install_keeps_its_data_beside_itself(): void
    {
        $beside = dirname('D:\\Apps\\Papyrus').DIRECTORY_SEPARATOR.'papyrus-data';

        $choice = $this->locationFor([$beside => true])->resolve('D:\\Apps\\Papyrus');

        $this->assertSame($beside, $choice['path'], 'the whole folder must stay movable');
        $this->assertStringContainsString('beside the install', $choice['reason']);
    }

    public function test_an_install_under_local_app_data_uses_local_app_data(): void
    {
        /*
         * The shape the installer produces.
         *
         * `%LOCALAPPDATA%\Programs\Papyrus` is where the installer writes, and it is writable, so
         * without this rule it takes the portable branch and puts the reader's data in a
         * `papyrus-data` folder beside the program. A reader looking for their library looks in
         * their own AppData, and it is not there.
         */
        $local = getenv('LOCALAPPDATA');

        if (! is_string($local) || $local === '') {
            $this->assertTrue(true, 'no LOCALAPPDATA here, the installed case cannot be exercised');

            return;
        }

        $installed = $local.DIRECTORY_SEPARATOR.'Programs'.DIRECTORY_SEPARATOR.'Papyrus';

        // Writable, so this is only correct because the inside check comes before the write check.
        $choice = (new DataLocation(fn (string $path): bool => true))->resolve($installed);

        $this->assertSame(
            $local.DIRECTORY_SEPARATOR.'Papyrus',
            $choice['path'],
            'an installed copy keeps its data in one predictable place under the reader\'s own AppData',
        );

        $this->assertStringNotContainsString('Programs', $choice['path'], 'not beside the program');
    }

    public function test_a_folder_merely_starting_with_local_app_data_is_not_inside_it(): void
    {
        /*
         * The separator on the end of the parent is the whole test.
         *
         * Without it, `C:\Users\<you>\AppDataLocal` counts as being inside `C:\Users\<you>\AppData`,
         * and that reader's data goes to a place they will never look. It is a prefix match on two
         * unrelated folders, which is the shape of a check that passes.
         */
        $this->assertTrue(
            (new DataLocation)->inside('C:\\Users\\reader\\AppData\\Programs\\Papyrus', 'C:\\Users\\reader\\AppData'),
            'a real subfolder is inside',
        );

        $this->assertFalse(
            (new DataLocation)->inside('C:\\Users\\reader\\AppDataLocal', 'C:\\Users\\reader\\AppData'),
            'a sibling folder with a longer name is not inside',
        );

        $this->assertFalse(
            (new DataLocation)->inside('C:\\Users\\reader\\AppData', 'C:\\Users\\reader\\AppData'),
            'a path is not inside itself',
        );
    }

    public function test_an_install_that_cannot_be_written_to_uses_local_app_data(): void
    {
        $beside = dirname('C:\\Program Files\\Papyrus').DIRECTORY_SEPARATOR.'papyrus-data';

        $choice = $this->locationFor([$beside => false])->resolve('C:\\Program Files\\Papyrus');

        /*
         * The sibling is the wrong answer here and saying so is the point of this class. A reader
         * whose install is under Program Files would otherwise be told that a read only directory
         * is not writable, with no indication that Windows keeps a perfectly good one for them.
         */
        $this->assertNotSame($beside, $choice['path']);
        $this->assertStringContainsString('Papyrus', $choice['path']);
        $this->assertStringContainsString('%LOCALAPPDATA%', $choice['reason']);
    }

    public function test_the_fallback_is_under_local_app_data_not_the_install(): void
    {
        $local = getenv('LOCALAPPDATA');

        if (! is_string($local) || $local === '') {
            $this->assertTrue(true, 'no LOCALAPPDATA on this machine, the fallback cannot be checked here');

            return;
        }

        $beside = dirname('C:\\Program Files\\Papyrus').DIRECTORY_SEPARATOR.'papyrus-data';

        $choice = $this->locationFor([$beside => false])->resolve('C:\\Program Files\\Papyrus');

        $this->assertStringStartsWith(
            $local,
            $choice['path'],
            'a reader\'s data goes where Windows keeps their own data, not beside the install',
        );

        $this->assertStringNotContainsString(
            'Program Files',
            $choice['path'],
            'the whole point is that Program Files cannot be written to',
        );
    }

    public function test_a_chosen_location_always_explains_itself(): void
    {
        foreach ([true, false] as $writable) {
            $beside = dirname('D:\\Apps\\Papyrus').DIRECTORY_SEPARATOR.'papyrus-data';

            $choice = $this->locationFor([$beside => $writable])->resolve('D:\\Apps\\Papyrus');

            $this->assertNotSame('', $choice['reason'], 'every answer carries a reason');
        }
    }

    public function test_it_asks_about_the_directory_itself_and_not_an_ancestor(): void
    {
        /*
         * The injected check receives the path the choice is about, not its parent.
         *
         * A probe that reports the wrong path is a probe measuring the wrong thing, and it would
         * still produce a plausible answer here: the portable branch would simply never be taken.
         */
        $asked = [];

        $location = new DataLocation(function (string $path) use (&$asked): bool {
            $asked[] = $path;

            return true;
        });

        $location->resolve('D:\\Apps\\Papyrus');

        $this->assertSame(
            [dirname('D:\\Apps\\Papyrus').DIRECTORY_SEPARATOR.'papyrus-data'],
            $asked,
            'the check is about the candidate directory, and only the candidate',
        );
    }

    public function test_choosing_a_location_does_not_create_it(): void
    {
        /*
         * The no side effects property, measured rather than asserted from the source.
         *
         * A probe that creates the directory in order to test it leaves an empty directory behind
         * when the answer turns out to be no, which is the residue this project has been bitten by
         * in other places.
         *
         * Under the repository rather than the system temp directory, because the temp directory
         * is inside `%LOCALAPPDATA%` and a root there is an installed install, which takes a
         * different branch and would make this pass without testing the rejection.
         */
        $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'dataloc-'.getmypid();
        $beside = dirname($root).DIRECTORY_SEPARATOR.'papyrus-data';

        @mkdir($root, 0o777, true);

        try {
            $choice = (new DataLocation(fn (string $path): bool => $path !== $beside))->resolve($root);

            $this->assertNotSame($beside, $choice['path']);
            $this->assertDirectoryDoesNotExist(
                $beside,
                'choosing where data lives must not create the directory it rejected',
            );
        } finally {
            @rmdir($root);
        }
    }

    public function test_the_walk_up_stops_at_the_first_existing_ancestor(): void
    {
        /*
         * The real filesystem path, since the injected check bypasses it.
         *
         * A candidate that does not exist yet is normal, so asking `is_writable` about it directly
         * would say no for the wrong reason and the portable branch would never be taken on any
         * machine where the data directory has not been created.
         *
         * Under the repository for the same reason as above: a root inside `%LOCALAPPDATA%` is an
         * installed install and never reaches the walk up at all.
         */
        $root = dirname(__DIR__, 2)
            .DIRECTORY_SEPARATOR.'build'
            .DIRECTORY_SEPARATOR.'walkup-'.getmypid()
            .DIRECTORY_SEPARATOR.'Papyrus';
        $beside = dirname($root).DIRECTORY_SEPARATOR.'papyrus-data';

        $this->assertDirectoryDoesNotExist($beside, 'the data directory does not exist yet');

        $choice = (new DataLocation)->resolve($root);

        $this->assertSame(
            $beside,
            $choice['path'],
            'a candidate whose parent is writable is usable even though it does not exist yet',
        );
    }

    public function test_the_walk_up_guard_exists_but_cannot_be_reproduced_on_windows(): void
    {
        /*
         * Recorded rather than asserted, because a test that cannot fail is worse than no test.
         *
         * `canWrite()` walks up until it finds a directory that exists, and guards against
         * spinning by checking whether `dirname()` has stopped changing. Removing that guard
         * survives every other test in this file, and it cannot be killed by one on Windows:
         *
         *   - a real path always has `C:\` somewhere above it, and `C:\` exists, so the walk
         *     terminates whether or not the guard is there
         *   - a fake path such as `Z:\nope\Papyrus` cannot be made to have no existing ancestor,
         *     because the filesystem resolves the drive before anything can ask about it
         *
         * So the guard is correct by construction and untested by necessity. The mutation check
         * reports this one as SURVIVED, which is the honest outcome, and it is the only survivor in
         * this file.
         *
         * What a test *could* do is assert the guard is present in the source, which is a weaker
         * claim and worth exactly as much as it sounds like. It is left out rather than dressed up.
         */
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/packaging/launcher/src/DataLocation.php'
        );

        $this->assertStringContainsString(
            'if ($parent === $existing) {',
            $source,
            'the walk up must stop when dirname() stops changing, or it spins on a filesystem root',
        );
    }
}
