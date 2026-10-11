<?php

namespace Tests\Feature;

use Papyrus\Launcher\InstallLayout;
use Papyrus\Launcher\LayoutRefused;
use Tests\TestCase;

/**
 * Where a packaged install puts its files.
 *
 * ## Why these are refusals and not warnings
 *
 * All three failure modes here produce the same symptom much later: a SQLite error naming a
 * file, from a command the reader did not think involved a disk. A data directory inside the
 * install is unwritable under Program Files, so every write fails. A path Windows will not let
 * an ordinary user write is the same. And a path that is writable but inside the install means
 * an uninstall removes the library with the application, which is the worst outcome this
 * project could ship.
 *
 * Catching them before four processes start turns an unauthenticatable failure into a sentence
 * that says what to do instead.
 */
class InstallLayoutTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-layout-'.getmypid().'-'.uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->scratch);

        parent::tearDown();
    }

    private function layout(string $install, ?string $data = null): InstallLayout
    {
        return new InstallLayout($install, $data ?? $this->scratch.DIRECTORY_SEPARATOR.'data');
    }

    /**
     * The directory every directory the app and the ini name.
     *
     * `tmp` and `logs` are in this list because trap 219: the ini points `sys_temp_dir` at a
     * directory nobody created, and the first casualty is whatever command shelled out first.
     */
    public function test_it_creates_every_directory_the_install_needs(): void
    {
        $made = $this->layout('C:/Program Files/Papyrus')->prepare(static fn (string $line): null => null);

        $data = $this->scratch.DIRECTORY_SEPARATOR.'data';

        foreach ([
            'storage/app/private',
            'storage/app/public',
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/logs',
            'suwayomi',
            'tmp',
            'logs',
        ] as $relative) {
            $this->assertDirectoryExists(
                $data.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative),
                "{$relative} should have been created",
            );
        }

        $this->assertNotEmpty($made);
    }

    /**
     * Two directories that are gone, and why.
     *
     * An empty directory named after data is the same shape of problem as an empty log named after
     * something that does not exist: it looks like evidence of a fault and is not one. Both of
     * these stayed empty through complete runs.
     *
     * `database/` was never the SQLite file, which is `<data>/database.sqlite`. `exports/` belongs
     * to Suwayomi, whose root is now `suwayomi/`.
     */
    public function test_no_directory_is_created_that_looks_like_data_and_is_not(): void
    {
        $made = $this->layout('C:/Program Files/Papyrus')->prepare(static fn (string $line): null => null);

        $this->assertDirectoryDoesNotExist($this->scratch.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'database');
        $this->assertDirectoryDoesNotExist($this->scratch.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'exports');

        foreach ($made as $relative) {
            $this->assertStringNotContainsString('database', $relative);
            $this->assertStringNotContainsString('exports', $relative);
        }
    }

    public function test_preparing_twice_is_safe_and_creates_nothing_new(): void
    {
        $layout = $this->layout('C:/Program Files/Papyrus');

        $layout->prepare(static fn (string $line): null => null);
        $second = $layout->prepare(static fn (string $line): null => null);

        $this->assertSame([], $second, 'a second run must find everything already in place');
    }

    /**
     * The one that must refuse.
     *
     * Under Program Files it is unwritable and every database write fails. Anywhere else it is
     * writable, and then uninstalling takes the reader's library with the application.
     */
    public function test_a_data_directory_inside_the_install_is_refused(): void
    {
        $inside = $this->scratch.DIRECTORY_SEPARATOR.'Papyrus'.DIRECTORY_SEPARATOR.'data';

        $this->expectException(LayoutRefused::class);
        $this->expectExceptionMessageMatches('/inside the install/');

        $this->layout($this->scratch.DIRECTORY_SEPARATOR.'Papyrus', $inside)
            ->prepare(static fn (string $line): null => null);
    }

    public function test_the_install_directory_itself_is_refused_as_a_data_directory(): void
    {
        $root = $this->scratch.DIRECTORY_SEPARATOR.'Papyrus';

        $this->expectException(LayoutRefused::class);

        $this->layout($root, $root)->assertNotInsideInstall();
    }

    /**
     * A sibling directory whose name merely starts the same is not inside the install.
     *
     * Worth pinning because the naive check is a plain `str_starts_with` on a path that has not
     * had its separators normalised, which refuses `C:\app-data` because it begins with
     * `C:\app`.
     */
    public function test_a_sibling_with_a_shared_prefix_is_not_inside_the_install(): void
    {
        $root = $this->scratch.DIRECTORY_SEPARATOR.'Papyrus';

        $this->layout($root, $this->scratch.DIRECTORY_SEPARATOR.'Papyrus-data')
            ->assertNotInsideInstall();

        $this->addToAssertionCount(1);
    }

    /**
     * Separator style must not change the answer, because one side of this comparison comes
     * from an environment variable and the other from PHP.
     */
    public function test_the_inside_check_ignores_separator_style(): void
    {
        $root = $this->scratch.DIRECTORY_SEPARATOR.'Papyrus';

        $forward = str_replace('\\', '/', $root);
        $backward = str_replace('/', '\\', $root.DIRECTORY_SEPARATOR.'data');

        $this->expectException(LayoutRefused::class);

        $this->layout($forward, $backward)->assertNotInsideInstall();
    }

    /**
     * Program Files is refused by name even when the path does not exist yet, because the
     * question is where Windows will let an ordinary user write, not whether the folder is there.
     */
    public function test_program_files_is_refused_on_windows(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('the refusal is specific to Windows paths');
        }

        $this->expectException(LayoutRefused::class);
        $this->expectExceptionMessageMatches('/not writable by an ordinary user/');

        (new InstallLayout('C:/Somewhere/Papyrus', 'C:/Program Files/Papyrus/data'))->assertNotReadOnly();
    }

    public function test_a_normal_local_app_data_path_is_accepted(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('the refusal is specific to Windows paths');
        }

        (new InstallLayout('C:/Program Files/Papyrus', 'C:/Users/<you>/AppData/Local/Papyrus'))
            ->assertNotReadOnly();

        $this->addToAssertionCount(1);
    }

    /**
     * Writability is proven by writing, not asked about.
     *
     * `is_writable` lies on Windows network shares, and a launcher that trusts it brings four
     * processes up against a disk it cannot write to.
     */
    public function test_an_unwritable_directory_is_refused(): void
    {
        $data = $this->scratch.DIRECTORY_SEPARATOR.'data';

        // A path that cannot exist because its parent is a file.
        $blocker = $this->scratch.DIRECTORY_SEPARATOR.'blocker';
        @mkdir($this->scratch, 0o777, true);
        file_put_contents($blocker, 'not a directory');

        $this->expectException(LayoutRefused::class);

        (new InstallLayout('C:/Program Files/Papyrus', $blocker.DIRECTORY_SEPARATOR.'data'))
            ->assertWritable();
    }

    /**
     * The probe file must not survive, or a data directory slowly fills with litter.
     */
    public function test_the_write_probe_leaves_nothing_behind(): void
    {
        $data = $this->scratch.DIRECTORY_SEPARATOR.'data';

        (new InstallLayout('C:/Program Files/Papyrus', $data))->assertWritable();

        $this->assertSame([], glob($data.DIRECTORY_SEPARATOR.'.write-probe') ?: []);
    }

    /**
     * A short data path says nothing, a long one says so once while it can still be acted on.
     */
    public function test_a_short_path_produces_no_warning_and_a_long_one_does(): void
    {
        $short = new InstallLayout('C:/Program Files/Papyrus', 'C:/PapyrusData');
        $this->assertNull($short->lengthWarning());

        $long = new InstallLayout('C:/Program Files/Papyrus', str_repeat('C:/a-long-directory-name', 6));
        $warning = $long->lengthWarning();

        $this->assertNotNull($warning, 'a path near the limit must be called out');
        $this->assertStringContainsString('260', $warning);
    }

    /**
     * The platform default has to be per platform, and the three disagree on purpose.
     */
    public function test_the_default_data_directory_is_per_platform(): void
    {
        $default = InstallLayout::defaultDataDirectory();

        $this->assertNotSame('', $default);

        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertStringContainsStringIgnoringCase('Papyrus', $default);
            $this->assertStringNotContainsStringIgnoringCase('Program Files', $default);
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $this->assertStringContainsString('Application Support', $default);
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $this->assertMatchesRegularExpression('#(\.local/share|/share)/#', $default);
        }
    }

    /**
     * The default must not be inside an install, on any platform, or the whole rule is moot.
     */
    public function test_the_platform_default_is_outside_any_install(): void
    {
        (new InstallLayout('C:/Program Files/Papyrus', InstallLayout::defaultDataDirectory()))
            ->assertNotInsideInstall();

        $this->addToAssertionCount(1);
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
