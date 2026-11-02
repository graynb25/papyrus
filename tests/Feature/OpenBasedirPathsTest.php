<?php

namespace Tests\Feature;

use Papyrus\Launcher\OpenBasedirPaths;
use Tests\TestCase;

/**
 * The configured paths an upgraded install already uses.
 *
 * `open_basedir` names directories, but a 1.5.3 reader's `.env` can put a local source folder, the
 * database, the cache or the sendmail binary outside the install's data directory. Those paths have
 * to be appended or the upgrade starts and then cannot read the library it was reading yesterday.
 *
 * ## Why the parser is its own class
 *
 * The launcher has no Laravel and has to work when the application is broken, so this cannot use
 * the application's `EnvironmentFile`. It also cannot use `parse_ini_file`: a `.env` is not an ini
 * file, `#` starts a comment anywhere in an unquoted value, and quoting changes what the path is.
 */
class OpenBasedirPathsTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = $this->scratchPath('open-basedir-paths');
    }

    /**
     * The values an environment file with these lines produces.
     *
     * @return list<string>
     */
    private function paths(string $contents): array
    {
        return (new OpenBasedirPaths($this->environmentFile($contents)))->paths();
    }

    private function environmentFile(string $contents): string
    {
        $path = $this->scratch.DIRECTORY_SEPARATOR.'.env';

        file_put_contents($path, $contents);

        return $path;
    }

    public function test_a_single_quoted_path_keeps_its_spaces_and_a_hash(): void
    {
        $contents = <<<'ENV'
PAPYRUS_LOCAL_SOURCE_PATH='C:\Manga Library #1'
ENV;

        $this->assertSame(['C:/Manga Library #1'], $this->paths($contents));
    }

    public function test_a_double_quoted_path_keeps_its_spaces_and_a_hash(): void
    {
        $contents = <<<'ENV'
CACHE_STORAGE_PATH="C:\Papyrus Cache #2"
ENV;

        $this->assertSame(['C:/Papyrus Cache #2'], $this->paths($contents));
    }

    /**
     * The dotenv this project ships opens a comment at `#` anywhere in an unquoted value.
     */
    public function test_a_trailing_comment_on_an_unquoted_value_is_not_part_of_the_path(): void
    {
        $contents = <<<'ENV'
PAPYRUS_LOCAL_SOURCE_PATH=C:\Manga # the reader's library
ENV;

        $this->assertSame(['C:/Manga'], $this->paths($contents));
    }

    public function test_an_empty_value_adds_no_path(): void
    {
        $this->assertSame([], $this->paths("PAPYRUS_LOCAL_SOURCE_PATH=\nDB_DATABASE=''\n"));
    }

    public function test_a_missing_environment_file_adds_no_path_and_does_not_throw(): void
    {
        $missing = $this->scratch.DIRECTORY_SEPARATOR.'absent.env';

        $this->assertFileDoesNotExist($missing);

        $this->assertSame([], (new OpenBasedirPaths($missing))->paths());
        $this->assertFileDoesNotExist($missing, 'reading a missing .env must not create one');
    }

    public function test_a_relative_path_is_skipped(): void
    {
        $contents = <<<'ENV'
PAPYRUS_LOCAL_SOURCE_PATH=manga
DB_DATABASE=database/database.sqlite
CACHE_STORAGE_PATH=framework/cache/data
MAIL_SENDMAIL_PATH=sendmail.exe
ENV;

        $this->assertSame([], $this->paths($contents));
    }

    public function test_the_database_contributes_its_parent_directory(): void
    {
        $contents = <<<'ENV'
DB_DATABASE='C:\Papyrus Data\database.sqlite'
ENV;

        $this->assertSame(['C:/Papyrus Data'], $this->paths($contents));
    }

    public function test_paths_are_deduplicated_in_the_order_they_are_configured(): void
    {
        $contents = <<<'ENV'
PAPYRUS_LOCAL_SOURCE_PATH=C:\Manga
DB_DATABASE='C:\Papyrus\database.sqlite'
CACHE_STORAGE_PATH=C:\Papyrus
MAIL_SENDMAIL_PATH='C:\Papyrus\sendmail.exe'
ENV;

        $this->assertSame(['C:/Manga', 'C:/Papyrus'], $this->paths($contents));
    }

    /**
     * On Windows `;` is the separator, so an entry containing one becomes two unrelated roots.
     */
    public function test_a_path_containing_a_semicolon_is_skipped(): void
    {
        $contents = <<<'ENV'
PAPYRUS_LOCAL_SOURCE_PATH='C:\Manga;Archive'
ENV;

        $this->assertSame([], $this->paths($contents));
    }

    public function test_posix_absolute_paths_are_kept(): void
    {
        $contents = <<<'ENV'
PAPYRUS_LOCAL_SOURCE_PATH=/srv/manga
MAIL_SENDMAIL_PATH=/usr/sbin/sendmail
ENV;

        $this->assertSame(['/srv/manga', '/usr/sbin'], $this->paths($contents));
    }
}
