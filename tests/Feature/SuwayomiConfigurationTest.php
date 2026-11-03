<?php

declare(strict_types=1);

namespace Tests\Feature;

use Papyrus\Launcher\SuwayomiConfiguration;
use RuntimeException;
use Tests\TestCase;

/**
 * Telling Suwayomi where to keep its data and which port to serve on.
 *
 * ## Why this is pinned by tests at all
 *
 * Because the mechanism was wrong for a long time in a way no test could see. The launcher passed
 * `TACHIDESK_DIR`, which Suwayomi does not read, and waited on a port it had never communicated,
 * so a clean install could only ever start on 4567. Every probe that ever worked passed 4567, so
 * the defect was invisible at the default and total anywhere else.
 *
 * These tests exist so that a change to the property name, or to how the port is written, fails
 * here rather than in a reader's install three months from now.
 */
class SuwayomiConfigurationTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The space in `papyrus suwayomi` is the whole point of this line.
         *
         * This used to be `papyrus-suwayomi-<pid>-<uniqid>` under `sys_get_temp_dir()`, which on the
         * machine this was found on is `%LOCALAPPDATA%\Temp`. No space, so every value
         * this class writes had no space in it, so the capture that stopped at the first whitespace
         * matched the whole value and behaved.
         *
         * Two tests in this file exist to catch exactly that and both passed against the broken
         * method, because the fixture could not reproduce it. The defect only appears when the path
         * has a space, which it does for any reader who puts an install in a folder called
         * `My Books` or `Paper Comics`, which is most of them.
         *
         * So the fixture has a space in it now, deliberately, and changing this line back to a
         * hyphen is how fifteen tests here go back to being unable to fail.
         */
        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus suwayomi '.getmypid().'-'.uniqid();

        mkdir($this->scratch, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->scratch);

        parent::tearDown();
    }

    private function configuration(): SuwayomiConfiguration
    {
        return new SuwayomiConfiguration;
    }

    // ---------------------------------------------------------------- the mechanism itself

    /**
     * The property name is the whole of the fix, so it is asserted literally.
     *
     * A typo here is invisible until an install cannot start, and it is a string taken from another
     * project's class file, so it is worth one test that cannot be satisfied by anything else.
     */
    public function test_the_root_property_is_the_one_suwayomi_actually_reads(): void
    {
        $this->assertSame(
            'suwayomi.tachidesk.config.server.rootDir',
            SuwayomiConfiguration::ROOT_PROPERTY,
            'Suwayomi reads this JVM property and nothing else. TACHIDESK_DIR is not read at all.',
        );
    }

    public function test_the_root_and_everything_in_it_lives_inside_the_data_directory(): void
    {
        $configuration = $this->configuration();

        $root = $configuration->rootDirectory($this->scratch);

        $this->assertSame($this->scratch.DIRECTORY_SEPARATOR.'suwayomi', $root);
        $this->assertStringStartsWith($root, $configuration->configurationFile($this->scratch));
        $this->assertStringStartsWith($root, $configuration->databaseFile($this->scratch));
    }

    // ---------------------------------------------------------------- preparing

    public function test_preparing_creates_the_root_that_suwayomi_will_die_without(): void
    {
        // Established by running Suwayomi: pointed at a root that does not exist it throws inside
        // ApplicationRootDirKt.getApplicationRootDir before reading anything, which looks exactly
        // like the property being ignored.
        $root = $this->configuration()->rootDirectory($this->scratch);

        $this->assertDirectoryDoesNotExist($root);

        $this->configuration()->prepare($this->scratch, 4567);

        $this->assertDirectoryExists($root);
    }

    public function test_a_first_prepare_writes_the_port_the_launcher_is_waiting_on(): void
    {
        $configuration = $this->configuration();

        $configuration->prepare($this->scratch, 4999);

        $this->assertStringContainsString(
            'server.port = 4999',
            (string) file_get_contents($configuration->configurationFile($this->scratch)),
        );
    }

    // ---------------------------------------------------------------- the address the library answers on

    /**
     * Suwayomi is pinned to loopback, and it is the listener, not the firewall, that does it.
     *
     * Suwayomi's own default is every interface, and it writes that default into `server.conf` itself
     * with its own annotation. So a launcher that writes only the port is not choosing to leave the
     * address alone, it is choosing to serve the reader's library on the network. Measured on a
     * running install with the default in place, before this test existed:
     *
     *     Get-NetTCPConnection -LocalPort 4567 -State Listen   LocalAddress :::4567   java
     *     192.0.2.1:4567  reachable
     *
     * and the GraphQL API does require a login, which is why this went to the end rather than
     * stopping at the socket:
     *
     *     { sources { totalCount } } unauthenticated                 Unauthorized
     *     login as the configured reader, from 192.0.2.1            succeeded
     *     { sources { totalCount } } carrying that token             223
     *
     * So the whole library was readable from the local network by anything that could route here and
     * guess the password. This is the defect `LauncherBindTest` records for FrankenPHP, where the
     * listener was the IPv6 wildcard while every document in this project said nothing outside the
     * machine was reachable. FrankenPHP is bound with an explicit `bind 127.0.0.1 ::1`. This is the
     * other half, and it is asserted here rather than left to a firewall the reader may have closed,
     * or may have answered differently to on the day.
     */
    public function test_suwayomi_is_pinned_to_loopback_rather_than_suwayomis_own_default(): void
    {
        $configuration = $this->configuration();
        $path = $configuration->configurationFile($this->scratch);

        // The file Suwayomi writes for itself, abbreviated, carrying the default the fix overrides.
        // The root is made first because `prepare()` is what creates it, and a fixture written into
        // a directory that does not exist yet fails on setup and says nothing about the assertion.
        @mkdir(dirname($path), 0o777, true);
        file_put_contents($path, "# Network\n"."server.ip = \"0.0.0.0\" # default: \"0.0.0.0\"\n"
            ."server.port = 4567 # default: 4567 ; range: [1, 65535]\n");

        $configuration->prepare($this->scratch, 4999);

        $contents = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression(
            '/^server\.ip = "'.preg_quote(SuwayomiConfiguration::LOOPBACK, '/').'" # default: "0\.0\.0\.0"$/m',
            $contents,
            'the address must become loopback and Suwayomi\'s own annotation must survive it',
        );

        $this->assertStringNotContainsString(
            'server.ip = "0.0.0.0"',
            $contents,
            'the wildcard must be gone, not merely shadowed by a second ip line',
        );

        $this->assertSame(
            1,
            preg_match_all('/^server\.ip\s*=/m', $contents),
            'exactly one ip line, so repeated starts cannot stack them',
        );
    }

    /**
     * Suwayomi is told not to open a browser of its own.
     *
     * Its default is `server.initialOpenInBrowserEnabled = true` and it fires on every start, putting
     * `http://127.0.0.1:4567` in the reader's face: Suwayomi's own administration UI, which this app
     * drives through GraphQL and which no reader has a reason to open. The app meanwhile opens nothing
     * unless `--open` was passed, and only `Papyrus.cmd` passes it.
     *
     * Together those are a reader looking at a working install, seeing one confident window open, and
     * it being the wrong program. Measured in the clean-machine gate, where the guest sat showing
     * Suwayomi's Library page while Papyrus served correctly on 8402 throughout.
     */
    public function test_suwayomi_does_not_open_a_browser_over_the_readers(): void
    {
        $configuration = $this->configuration();
        $path = $configuration->configurationFile($this->scratch);

        @mkdir(dirname($path), 0o777, true);
        file_put_contents($path, "# WebUI\nserver.initialOpenInBrowserEnabled = true # default: true ; Open client on startup\n");

        $configuration->prepare($this->scratch, 4999);

        $this->assertMatchesRegularExpression(
            '/^server\.initialOpenInBrowserEnabled = false # default: true/m',
            (string) file_get_contents($path),
            'it must be turned off, and Suwayomi\'s own annotation must survive the rewrite',
        );
    }

    /**
     * The pin has to survive into a file that has no `server.ip` line at all.
     *
     * On a first run the launcher writes `server.conf` before Suwayomi has ever seen it, so there is
     * no default line to replace and the key has to be added. A fix that only rewrote an existing
     * line would pass the test above and leave every new reader serving on the network.
     */
    public function test_a_first_run_writes_the_address_when_suwayomi_has_written_none(): void
    {
        $configuration = $this->configuration();

        $configuration->prepare($this->scratch, 4999);

        // Quoted, because the value goes through `escapeHocon()` like every other written value. An
        // assertion that repeated the bare literal would fail against correct code and tempt somebody
        // into dropping the quotes, so it asserts the quoted shape deliberately.
        $this->assertStringContainsString(
            'server.ip = "'.SuwayomiConfiguration::LOOPBACK.'"',
            (string) file_get_contents($configuration->configurationFile($this->scratch)),
        );
    }

    // ---------------------------------------------------------------- the case most likely to break something

    /**
     * Changing the port must not disturb the rest of Suwayomi's own configuration.
     *
     * Suwayomi writes its defaults as trailing comments and uses them to decide whether a user's
     * file is out of date, rewriting it when it decides so. A launcher that replaced the whole
     * `server.port` line would drop that metadata on every start, and the first reader to change
     * the port would find Suwayomi rewriting their settings.
     *
     * The file below is one Suwayomi actually wrote, abbreviated.
     */
    public function test_changing_the_port_leaves_suwayomis_own_annotations_alone(): void
    {
        $configuration = $this->configuration();
        $path = $configuration->configurationFile($this->scratch);

        $original = "# Network\n"
            ."server.ip = \"0.0.0.0\" # default: \"0.0.0.0\"\n"
            ."server.port = 4567 # default: 4567 ; range: [1, 65535]\n"
            ."\n"
            ."# WebUI\n"
            ."server.webUIFlavor = WEBUI # default: WEBUI ; options: WEBUI, VUI, CUSTOM\n"
            ."server.initialOpenInBrowserEnabled = true # default: true\n";

        @mkdir(dirname($path), 0o777, true);
        file_put_contents($path, $original);

        $configuration->prepare($this->scratch, 4999);

        $after = (string) file_get_contents($path);

        $this->assertStringContainsString(
            'server.port = 4999 # default: 4567 ; range: [1, 65535]',
            $after,
            'the port value changes and its annotation stays',
        );

        // Every other line, byte for byte.
        //
        // Except the ones this launcher owns. That set was the port alone when this test was written,
        // and it is the port, the address, the browser setting and the backup path now, because
        // `prepare()` writes all of them on every start. Treating one of those as an unrelated line
        // would demand that a value the launcher deliberately sets survives untouched, which is the
        // exact defect each of those settings exists to remove. So the loop skips what the launcher
        // sets and holds everything else to byte for byte.
        foreach (['server.port', 'server.ip', 'server.initialOpenInBrowserEnabled', 'server.backupPath'] as $owned) {
            $this->assertStringContainsString(
                $owned.' =',
                $after,
                'the launcher is supposed to set '.$owned.' and did not',
            );
        }

        foreach (explode("\n", $original) as $line) {
            $skip = false;

            foreach (['server.port', 'server.ip', 'server.initialOpenInBrowserEnabled'] as $owned) {
                if (str_starts_with($line, $owned)) {
                    $skip = true;
                }
            }

            if ($line === '' || $skip) {
                continue;
            }

            $this->assertStringContainsString($line, $after, 'an unrelated line was lost: '.$line);
        }
    }

    public function test_the_port_is_corrected_when_suwayomi_is_on_another_one(): void
    {
        $configuration = $this->configuration();

        $configuration->prepare($this->scratch, 4999);
        $configuration->prepare($this->scratch, 5000);

        $contents = (string) file_get_contents($configuration->configurationFile($this->scratch));

        $this->assertStringContainsString('server.port = 5000', $contents);
        $this->assertStringNotContainsString('server.port = 4999', $contents);
    }

    public function test_preparing_twice_does_not_grow_the_file(): void
    {
        $configuration = $this->configuration();

        $configuration->prepare($this->scratch, 4999);
        $first = filesize($configuration->configurationFile($this->scratch));

        $configuration->prepare($this->scratch, 4999);
        $configuration->prepare($this->scratch, 4999);

        $this->assertSame($first, filesize($configuration->configurationFile($this->scratch)));
    }

    // ---------------------------------------------------------------- the backup half

    /**
     * Suwayomi is told where to put its own daily library backup.
     *
     * Suwayomi already takes one daily with 14 day retention, and this project can read the
     * `.tachibk` files it writes, so the library half of the backup story needs no work from us
     * beyond pointing it at a folder. Without this it writes into its own root, which happens to be
     * inside the data directory anyway, so nothing is lost: what changes is that a reader looking
     * for their backups finds them in one place rather than two.
     */
    public function test_the_backup_path_is_written_and_the_directory_exists(): void
    {
        $configuration = $this->configuration();

        $configuration->prepare($this->scratch, 4567);

        $contents = (string) file_get_contents($configuration->configurationFile($this->scratch));

        $this->assertMatchesRegularExpression(
            '/^server\.backupPath = ".+"$/m',
            $contents,
            'the path has to be quoted, because a bare Windows path is not a HOCON string',
        );

        $expected = $configuration->rootDirectory($this->scratch).DIRECTORY_SEPARATOR.'backups';

        $this->assertDirectoryExists(
            $expected,
            'created rather than left for 03:17, because a backup that has never run cannot tell a '
                .'reader its path works',
        );
    }

    /**
     * The separators are doubled, which is the difference between a path Suwayomi can read and
     * one it cannot.
     *
     * HOCON treats a backslash inside a quoted string as the start of an escape sequence, so
     * `"C:\Users\<you>"` is rejected and `"C:\\Users\\me"` is the path. Getting this wrong fails
     * silently and late: the setting is written, the file parses, and the backup simply never
     * appears where it was pointed.
     *
     * This was a real defect in the first version of this change, caught by a probe rather than a
     * test: the value is doubled by `escapeHocon()` and then handed to `preg_replace()`, whose
     * replacement string is itself a template, so a backslash in it is an escape and the doubling
     * was undone on the way out.
     */
    public function test_windows_separators_in_a_written_path_are_doubled(): void
    {
        $configuration = $this->configuration();

        $configuration->prepare($this->scratch, 4567);

        $contents = (string) file_get_contents($configuration->configurationFile($this->scratch));

        /*
         * The match result is checked rather than asserted afterwards, because `assertNotEmpty` on
         * the array does not tell PHPStan that offset 1 is there, and this file is not in
         * `phpstan.neon`'s paths so nothing else would have caught it. `fail()` is what narrows.
         */
        if (preg_match('/^server\.backupPath = "(.*)"$/m', $contents, $matches) !== 1) {
            $this->fail('the path has to be written, quoted, on its own line');
        }

        $value = $matches[1];

        $this->assertMatchesRegularExpression(
            '/^[A-Za-z]:[\\\\\/]/',
            $value,
            'sanity: the value read back is an absolute Windows path, not something else',
        );

        /*
         * Every backslash appears in a pair, checked by walking the string rather than with a
         * pattern. The first version of this used a regex that got the character class backwards
         * and rejected the correct value, which is a test that fails on right and passes on wrong.
         */
        $length = strlen($value);
        $single = 0;
        $pairs = 0;

        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] !== '\\') {
                continue;
            }

            if (($value[$i + 1] ?? '') !== '\\') {
                $single++;
                $i++;

                continue;
            }

            $pairs++;
            $i++;
        }

        $this->assertSame(
            0,
            $single,
            'a single backslash inside the quotes is an unterminated escape sequence to HOCON, and '
                .'it would be rejected when Suwayomi reads the file',
        );

        $this->assertGreaterThan(
            0,
            $pairs,
            'the path does contain separators, so this test is measuring something',
        );
    }

    /**
     * Everything the backup settings already say for themselves is left alone.
     *
     * Suwayomi writes `backupInterval = 1`, `backupTTL = 14` and `backupTime = "00:00"` with
     * comments describing their ranges, and uses those comments to decide whether its own file is
     * out of date. Rewriting them would change nothing and would discard the metadata, which is the
     * one thing this class must not do.
     */
    public function test_the_backup_settings_suwayomi_already_set_are_untouched(): void
    {
        $configuration = $this->configuration();
        $path = $configuration->configurationFile($this->scratch);

        @mkdir(dirname($path), 0o777, true);

        $original = <<<'HOCON'
        # Written by Suwayomi.
        server {
          port = 4567
        }
        server.port = 4567 # default: 4567 ; range: [1, 65535]
        server.backupInterval = 1 # default: 1 ; range: [0, +oo] ; 0 == disabled
        server.backupTTL = 14 # default: 14 ; range: [0, +oo] ; Backup retention in days
        server.backupTime = "00:00" # default: "00:00" ; Daily backup time (HH:MM)
        server.address = "0.0.0.0"
        HOCON;

        file_put_contents($path, $original);

        $configuration->prepare($this->scratch, 4999);

        $after = (string) file_get_contents($path);

        $this->assertStringContainsString(
            'server.backupInterval = 1 # default: 1 ; range: [0, +oo] ; 0 == disabled',
            $after,
        );

        $this->assertStringContainsString('server.backupTTL = 14 # default: 14', $after);
        $this->assertStringContainsString(
            'server.backupTime = "00:00" # default: "00:00" ; Daily backup time (HH:MM)',
            $after,
        );
        $this->assertStringContainsString('server.address = "0.0.0.0"', $after);
    }

    /**
     * Preparing repeatedly settles, rather than escaping further each time.
     *
     * The escaping is applied to the value being written, so an already escaped file must be
     * matched and replaced with the same escaped string rather than with a doubly escaped one. A
     * start that rewrites this file every time would leave a reader with `C:\\\\Users` after the
     * third launch, and nothing would say so.
     */
    public function test_preparing_repeatedly_settles_on_one_value(): void
    {
        $configuration = $this->configuration();
        $path = $configuration->configurationFile($this->scratch);

        $configuration->prepare($this->scratch, 4999);
        $first = (string) file_get_contents($path);

        for ($i = 0; $i < 4; $i++) {
            $configuration->prepare($this->scratch, 4999);
        }

        $this->assertSame(
            $first,
            (string) file_get_contents($path),
            'four starts must produce the same file as one, or every start rewrites it',
        );
    }

    /**
     * A line already corrupted by the old capture is repaired, not carried forward.
     *
     * This is the state `build/papyrus-data` was actually found in after one `papyrus snapshot`, on
     * an install whose path contains a space:
     *
     *     server.backupPath = "C:\\My Projects\\papyrus\\...\\backups" Projects\\...\\backups"
     *
     * The old capture took the value up to the first whitespace, so the rest of the line was carried
     * into every later run and the line grew. HOCON then refused the whole file with `Expecting end
     * of input or a comma, got '\'`, and the JVM would not start.
     *
     * A reader who already has this file has to be able to start the app, so the repair has to be
     * part of the fix rather than something a reader is asked to do by hand. Without this, every one
     * of them is stuck until they edit a file in a folder they may not know they have.
     */
    public function test_a_line_a_previous_version_duplicated_is_repaired(): void
    {
        $configuration = $this->configuration();
        $path = $configuration->configurationFile($this->scratch);

        $configuration->prepare($this->scratch, 4567);

        // Written the way the old capture left it: the correct value, then the tail it cut off.
        $correct = $this->backupPathLine($path);

        $this->assertNotNull($correct, 'the path has to be written before it can be corrupted');

        $value = substr($correct, strlen('server.backupPath = '));

        file_put_contents(
            $path,
            str_replace(
                $correct,
                'server.backupPath = '.$value.' '.$value.' # default: ""',
                (string) file_get_contents($path),
            ),
        );

        $broken = (string) $this->backupPathLine($path);

        $this->assertStringContainsString(
            $value.' '.$value,
            $broken,
            'the fixture has to be genuinely broken or this test proves nothing',
        );

        $configuration->prepare($this->scratch, 4567);

        $repaired = (string) $this->backupPathLine($path);
        $repairedValue = substr($repaired, strlen('server.backupPath = '));

        $this->assertSame(
            $value,
            $this->valueOf($repairedValue),
            'preparing has to put the line back to one value, or the next start reads a file '
                .'Suwayomi cannot parse',
        );

        $this->assertSame(
            1,
            substr_count((string) file_get_contents($path), 'server.backupPath = '),
            'and exactly one assignment, because a second one is a second key of the same name',
        );
    }

    /**
     * The value part of a `key = value # comment` line, with the comment taken off.
     *
     * The repair keeps whatever trailing comment Suwayomi had written, because that comment is the
     * metadata it uses to judge whether its own file is out of date, so an assertion on the whole
     * line would be asserting that the repair throws information away.
     */
    private function valueOf(string $rest): string
    {
        if (preg_match('/^("[^"]*"|[^#]*?)(\s+#.*)?$/', $rest, $m) !== 1) {
            return $rest;
        }

        return trim($m[1]);
    }

    /**
     * The `server.backupPath` line, trimmed, or null when it is not there.
     *
     * Read by walking the lines rather than by a pattern over the whole file, so a key that appears
     * inside a comment cannot be mistaken for the key itself.
     */
    private function backupPathLine(string $path): ?string
    {
        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            if (str_starts_with(trim($line), 'server.backupPath')) {
                return trim($line);
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- failure has to be loud

    /**
     * A root that cannot be created is refused, not fallen past.
     *
     * Suwayomi's fallback for a missing root is the Windows profile, which is how a reader's
     * library ends up outside the install. Saying so is better than carrying on.
     *
     * The blocker is a file where the directory has to go. `C:\no-such-drive-for-tests\...` looks
     * unwritable and is not: Windows creates the whole chain under `C:\`.
     */
    public function test_a_root_that_cannot_be_created_is_refused(): void
    {
        $blocker = $this->scratch.DIRECTORY_SEPARATOR.'a-file-not-a-directory';

        file_put_contents($blocker, 'the directory below cannot be created');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not create/');

        $this->configuration()->prepare($blocker, 4567);
    }

    public function test_a_configuration_file_that_cannot_be_written_is_refused(): void
    {
        $configuration = $this->configuration();
        $root = $configuration->rootDirectory($this->scratch);

        mkdir($root, 0o777, true);

        // server.conf as a directory: the path exists, is not a file, and cannot be written.
        mkdir($configuration->configurationFile($this->scratch), 0o777, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not write/');

        $configuration->prepare($this->scratch, 4567);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$entry;

            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
