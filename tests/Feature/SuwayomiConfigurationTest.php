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

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-suwayomi-'.getmypid().'-'.uniqid();

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
        foreach (explode("\n", $original) as $line) {
            if ($line === '' || str_starts_with($line, 'server.port')) {
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

        preg_match('/^server\.backupPath = "(.*)"$/m', $contents, $matches);

        $this->assertNotEmpty($matches, 'the path has to be written, quoted, on its own line');

        $value = $matches[1];

        $this->assertStringContainsString(
            'C:',
            $value,
            'sanity: the value read back is the path and not something else',
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
