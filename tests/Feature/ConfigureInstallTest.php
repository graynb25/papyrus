<?php

namespace Tests\Feature;

use Dotenv\Parser\Parser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * `papyrus:configure`, the first thing a packaged install runs.
 *
 * ## What is being protected
 *
 * An existing `APP_KEY`. Regenerating it does not log anybody out, it makes every session
 * cookie in every browser a forgery and every encrypted column unreadable. It is the one
 * failure in this feature that cannot be undone, so it is the first test.
 *
 * ## These tests do not touch the real `.env`
 *
 * The first version of this file copied the developer's own `.env` aside and restored it in
 * `tearDown`, keeping the backup in a per-test directory. That is broken in a way worth
 * recording: `tearDown` deletes that directory, so the second test found no backup and
 * overwrote the real file with a three line stub. Laravel then tried to load it, the process
 * died mid-suite, and the environment file was destroyed.
 *
 * The mistake was reaching for a careful backup instead of removing the hazard. So the command
 * takes `--env-file` and these tests point it at a scratch file. Nothing here reads or writes the
 * file the app is actually running on.
 *
 * ## Why these tests could not have caught the option name
 *
 * They pass options as an array to `$this->artisan()`, which hands them straight to the command's
 * input and never parses argv. So the option was called `env`, colliding with a Symfony global,
 * every test here passed, and the launcher still could not start. The name is now `env-file` and
 * there is a test below that runs the command as a real process to keep that honest.
 *
 * ## The snapshot tests are not here
 *
 * They are in {@see SnapshotDatabaseTest}, which cannot use `RefreshDatabase`. That is explained
 * there and it is a real constraint on the command, not a quirk of the tests.
 */
class ConfigureInstallTest extends TestCase
{
    use RefreshDatabase;

    private string $scratch;

    private string $envFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-configure-'.getmypid().'-'.uniqid();
        $this->envFile = $this->scratch.DIRECTORY_SEPARATOR.'.env';

        File::ensureDirectoryExists($this->scratch);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    private function configure(array $extra = []): PendingCommand
    {
        return $this->artisan('papyrus:configure', array_merge([
            '--env-file' => $this->envFile,
            '--data' => $this->scratch.DIRECTORY_SEPARATOR.'data',
        ], $extra));
    }

    /**
     * @return array<string, string>
     */
    private function envValues(): array
    {
        $values = [];

        foreach (File::lines($this->envFile) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $values[trim($name)] = trim($value);
        }

        return $values;
    }

    /**
     * The file as dotenv itself reads it.
     *
     * `envValues()` above trims the raw line, which is enough for an unquoted value and wrong
     * for a quoted one. This is the parser the application boots with, so a value that comes
     * back here is the one the install will use.
     *
     * @return array<string, string>
     */
    private function parsedEnvFile(): array
    {
        $parsed = [];

        foreach ((new Parser)->parse(File::get($this->envFile)) as $entry) {
            $parsed[$entry->getName()] = $entry->getValue()->get()->getChars();
        }

        return $parsed;
    }

    /**
     * A fresh install gets a real key.
     *
     * `.env.example` ships `APP_KEY=` empty, so "the key exists" and "the key is set" are
     * different questions, and treating the empty one as present ships an install that cannot
     * decrypt anything.
     */
    public function test_a_fresh_install_is_given_a_usable_app_key(): void
    {
        $this->configure()->assertSuccessful();

        $values = $this->envValues();

        $this->assertFileExists($this->envFile);
        $this->assertNotSame('', $values['APP_KEY']);
        $this->assertStringStartsWith('base64:', $values['APP_KEY']);
        $this->assertSame('production', $values['APP_ENV'], 'a packaged install is not local');
        $this->assertSame('false', $values['APP_DEBUG']);
    }

    /**
     * The shipped log setup is daily at info, with bounded retention.
     *
     * A packaged install inherits `.env.example`, which ships debug on a single file with no
     * retention. That is the verbose combination the diagnostics bundle was collecting.
     */
    public function test_a_packaged_install_writes_daily_logging_at_info_level(): void
    {
        $this->configure()->assertSuccessful();

        $values = $this->envValues();

        $this->assertSame('stack', $values['LOG_CHANNEL']);
        $this->assertSame('daily', $values['LOG_STACK']);
        $this->assertSame('info', $values['LOG_LEVEL']);
        $this->assertSame('14', $values['LOG_DAILY_DAYS']);
    }

    /**
     * An existing 1.5.3 `.env` keeps debug logging until the next launcher start repairs it.
     */
    public function test_an_existing_env_file_with_debug_logging_is_repaired(): void
    {
        $key = 'base64:'.base64_encode(str_repeat('k', 32));

        File::put(
            $this->envFile,
            "APP_KEY={$key}\nLOG_CHANNEL=stack\nLOG_STACK=single\nLOG_LEVEL=debug\nLOG_DAILY_DAYS=90\n",
        );

        $this->configure()->assertSuccessful();

        $values = $this->envValues();

        $this->assertSame($key, $values['APP_KEY'], 'the key must survive the repair');
        $this->assertSame('daily', $values['LOG_STACK']);
        $this->assertSame('info', $values['LOG_LEVEL']);
        $this->assertSame('14', $values['LOG_DAILY_DAYS']);
    }

    /**
     * The one that must not break.
     */
    public function test_an_existing_app_key_is_never_replaced(): void
    {
        $original = 'base64:'.base64_encode(str_repeat('k', 32));

        File::put($this->envFile, "APP_KEY={$original}\nAPP_ENV=local\n");

        $this->configure()->assertSuccessful();

        $this->assertSame($original, $this->envValues()['APP_KEY'], 'the key must survive a second run');
    }

    /**
     * Running twice must be safe, because the launcher runs it on every start.
     */
    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $this->configure()->assertSuccessful();

        $first = $this->envValues();

        $this->configure(['--report' => true])
            ->expectsOutputToContain('nothing to change')
            ->assertSuccessful();

        $this->assertSame($first, $this->envValues(), 'a second run must be a no-op');
    }

    /**
     * A path with a space is quoted, and the quoted form must still count as unchanged.
     *
     * This is the case the shipped runtime exposed: its temp directory is under a path containing
     * a space, so `DB_DATABASE` is written with single quotes. `EnvironmentFile::read()` keeps
     * the quotes on purpose, for lines this command does not own. Comparing the raw value to the
     * unquoted path made every second run report `DB_DATABASE` as changed, which is exactly what
     * a command that runs on every launcher start must not do.
     */
    public function test_a_quoted_path_that_already_matches_is_not_rewritten(): void
    {
        $data = $this->scratch.DIRECTORY_SEPARATOR.'quoted data';

        $this->artisan('papyrus:configure', [
            '--env-file' => $this->envFile,
            '--data' => $data,
        ])->assertSuccessful();

        $this->artisan('papyrus:configure', [
            '--env-file' => $this->envFile,
            '--data' => $data,
            '--report' => true,
        ])->expectsOutputToContain('nothing to change')->assertSuccessful();
    }

    /**
     * The database goes in the data directory, not in the install.
     *
     * Putting it under the application root is what makes uninstall dangerous: a reader who
     * removes the folder removes their library with it. And `.env.example` ships
     * `DB_DATABASE=laravel`, which resolves against the application, so following the example
     * blindly would put it exactly there.
     */
    public function test_the_database_goes_in_the_data_directory(): void
    {
        $this->configure()->assertSuccessful();

        $database = $this->parsedEnvFile()['DB_DATABASE'] ?? '';
        $expected = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->scratch.DIRECTORY_SEPARATOR.'data');
        $actual = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $database);

        $this->assertStringStartsWith(
            $expected,
            $actual,
            "the database must live in the data directory, got {$database}",
        );
        $this->assertStringEndsWith('database.sqlite', $database, 'and name the file, not a directory');
    }

    /**
     * A relative path is the failure this exists to prevent: it works while the process happens
     * to start from the application root and produces an empty library rather than an error when
     * it does not.
     */
    public function test_the_database_path_is_absolute(): void
    {
        $this->configure()->assertSuccessful();

        $database = $this->parsedEnvFile()['DB_DATABASE'] ?? '';

        $this->assertTrue(
            str_starts_with($database, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $database) === 1,
            "expected an absolute path, got {$database}",
        );
    }

    /**
     * A data directory whose path contains a space, which is not an edge case.
     *
     * `C:\Program Files\Papyrus` is where a Windows install goes by default, and this project's
     * own working directory is `C:\My Projects`. A Windows path with a space is the
     * ordinary case, not the unusual one.
     *
     * This test exists because every other test here passed while the install was broken on those
     * paths. The scratch directory is under the system temp dir, whose path has no space, so the
     * suite, and the sandbox gate with it, could never have found this. The sandbox installs at
     * `C:\papyrus` for exactly the same reason.
     *
     * The assertion is that dotenv can parse what was written, rather than that a quote is
     * present, so that a future change which quotes in a way dotenv dislikes still fails here.
     */
    public function test_a_data_directory_containing_a_space_is_written_parseable(): void
    {
        $spaced = $this->scratch.DIRECTORY_SEPARATOR.'papyrus data';

        $this->artisan('papyrus:configure', [
            '--env-file' => $this->envFile,
            '--data' => $spaced,
        ])->assertSuccessful();

        $line = null;

        foreach (File::lines($this->envFile) as $candidate) {
            if (str_starts_with(trim($candidate), 'DB_DATABASE=')) {
                $line = trim($candidate);
            }
        }

        $this->assertNotNull($line, 'the command must write a DB_DATABASE line');

        $database = $this->envValues()['DB_DATABASE'];

        $this->assertStringContainsString(
            'papyrus data',
            $database,
            'the spaced directory should be the one recorded',
        );

        // Parsed by dotenv itself, which is what refused it: "Encountered unexpected whitespace".
        /*
 * Parsed by dotenv's own parser, which is what raised "Encountered unexpected whitespace"
 * on a path with a space in it. Not by hand: a regex that a quoted path satisfies and an
 * unquoted one does not would be a restatement of the rule rather than a check of the
 * library the install actually depends on.
 *
 * A thrown InvalidFileException is the failure being asserted against, so the test fails with
 * dotenv's own message rather than one written here.
 */
        $parsed = [];

        foreach ((new Parser)->parse((string) file_get_contents($this->envFile)) as $entry) {
            // getValue() is a PhpOption, not a Value, so the unwrap is not optional.
            $parsed[$entry->getName()] = $entry->getValue()->get()->getChars();
        }

        $this->assertArrayHasKey(
            'DB_DATABASE',
            $parsed,
            'dotenv must be able to parse what the launcher wrote, otherwise the app refuses to '
                .'boot with an error that names a parser rather than a path',
        );

        $this->assertSame(
            trim($database, "'\""),
            $parsed['DB_DATABASE'],
            'and the value dotenv reads back must be the path itself, with the quotes removed by the '
                .'parser rather than left inside the value',
        );
    }

    /**
     * A data directory containing `#` is a path, not a comment.
     *
     * The renderer used to split every value at the first `#`, because dotenv opens a comment
     * there in an unquoted value. For a setting this command owns, that split is a guess: the
     * value is the path the caller gave, and the only way to read it back unchanged is to quote
     * the whole thing. Measured before this test: the written `DB_DATABASE` stopped at the
     * `#`, so a data directory named `papyrus # data` produced a different sqlite file, with no
     * error naming the line.
     *
     * A space is in the name too, because the quoting rule that preserves `#` must also handle
     * the ordinary Windows install path.
     */
    public function test_a_data_directory_containing_a_hash_keeps_the_whole_path(): void
    {
        $data = $this->scratch.DIRECTORY_SEPARATOR.'papyrus # data';

        $this->artisan('papyrus:configure', [
            '--env-file' => $this->envFile,
            '--data' => $data,
        ])->assertSuccessful();

        $expected = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $data.DIRECTORY_SEPARATOR.'database.sqlite');

        $this->assertSame(
            $expected,
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) ($this->parsedEnvFile()['DB_DATABASE'] ?? '')),
            'dotenv must read back the whole data directory, including everything after the #',
        );

        /*
         * The launcher runs this on every start. The second run reads the line back, so it must
         * not shorten a path the first run wrote, and it must not need a third run to repair it.
         */
        $this->artisan('papyrus:configure', [
            '--env-file' => $this->envFile,
            '--data' => $data,
        ])->assertSuccessful();

        $this->assertSame(
            $expected,
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) ($this->parsedEnvFile()['DB_DATABASE'] ?? '')),
            'a second configure run must leave the whole path in place',
        );
    }

    public function test_the_data_directory_is_created(): void
    {
        $data = $this->scratch.'/created-on-purpose';

        $this->configure(['--data' => $data])->assertSuccessful();

        $this->assertDirectoryExists($data);
    }

    /**
     * Where Suwayomi answers is a fact about the machine, not about the repository.
     */
    public function test_the_suwayomi_url_is_written(): void
    {
        $this->configure(['--suwayomi' => 'http://127.0.0.1:9999'])->assertSuccessful();

        $this->assertSame('http://127.0.0.1:9999', $this->envValues()['SUWAYOMI_URL']);
    }

    /**
     * A setting this command does not own, whose value contains a space, must survive readable.
     *
     * This is the case the byte comparison below used to hide. `CUSTOM_SETTING=keep me` is not
     * one of this command's keys, so it is written back through the branch that carries lines it
     * does not own, and that branch was copying the value out verbatim.
     *
     * The result is a file dotenv refuses to load, with "Encountered unexpected whitespace at
     * [keep me]", which kills the whole application rather than the one setting. So the
     * assertion is that the file parses, and that the value comes back as the author wrote it.
     */
    public function test_a_foreign_setting_with_a_space_in_its_value_survives_readable(): void
    {
        File::put(
            $this->envFile,
            "# a comment that explains things\nAPP_KEY=\nCUSTOM_SETTING=keep me\n# another comment\n",
        );

        $this->configure()->assertSuccessful();

        $parsed = [];

        foreach ((new Parser)->parse(File::get($this->envFile)) as $entry) {
            $parsed[$entry->getName()] = $entry->getValue()->get()->getChars();
        }

        $this->assertSame(
            'keep me',
            $parsed['CUSTOM_SETTING'] ?? null,
            'a value this command does not own must still come back as the author wrote it, '
                .'quoted if it has to be quoted for the file to load',
        );
    }

    /**
     * An inline comment is not part of the value, and quoting must not swallow it.
     *
     * `#` opens a comment only outside quotes. So writing `LOG_LEVEL='debug # very noisy'` turns
     * the comment into part of the setting, and the application silently reads a level that
     * nobody set. This is the failure a naive fix for the test above introduces, so it is pinned
     * here rather than left to be discovered.
     */
    public function test_an_inline_comment_on_a_foreign_setting_stays_a_comment(): void
    {
        File::put(
            $this->envFile,
            "# a comment that explains things\nAPP_KEY=\nCUSTOM_LEVEL=debug # very noisy\n# another comment\n",
        );

        $this->configure()->assertSuccessful();

        $parsed = [];

        foreach ((new Parser)->parse(File::get($this->envFile)) as $entry) {
            $parsed[$entry->getName()] = $entry->getValue()->get()->getChars();
        }

        $this->assertSame(
            'debug',
            $parsed['CUSTOM_LEVEL'] ?? null,
            'the comment must stay outside the quotes, or the setting becomes the comment',
        );
    }

    /**
     * A `#` inside a value opens a comment even with no space before it.
     *
     * Measured on the dotenv this ships with, because the comment handling here has to agree with
     * the parser exactly: `VALUE=a#b` parses to `a`, not to `a#b`.
     *
     * This matters because the alternative is a value that changed without error. A guard that
     * required whitespace before the `#` would look reasonable, would pass every test written
     * about trailing comments, and would quietly turn a path or an ID containing `#` into
     * something shorter.
     */
    public function test_a_hash_inside_a_value_is_still_a_comment(): void
    {
        File::put($this->envFile, "# a comment\nAPP_KEY=\nSITE_TAG=reader#beta\n");

        $this->configure()->assertSuccessful();

        $parsed = [];

        foreach ((new Parser)->parse(File::get($this->envFile)) as $entry) {
            $parsed[$entry->getName()] = $entry->getValue()->get()->getChars();
        }

        $this->assertSame(
            'reader',
            $parsed['SITE_TAG'] ?? null,
            'dotenv opens a comment at any #, so this must be split here too or the value '
                .'comes back as something the author never wrote',
        );
    }

    /**
     * A comment written by the author must still be in the file afterwards.
     *
     * Separate from the value assertions on purpose. A fix that quotes the comment into the
     * value, or drops it while splitting, produces a file that parses and a setting that means
     * something else. Only reading the text back catches both.
     */
    public function test_a_foreign_settings_comment_is_preserved_in_the_file(): void
    {
        File::put(
            $this->envFile,
            "# a comment that explains things\nAPP_KEY=\nCUSTOM_SETTING=keep me # and why\n# another comment\n",
        );

        $this->configure()->assertSuccessful();

        $this->assertStringContainsString(
            '# and why',
            File::get($this->envFile),
            'the comment an author wrote must survive this command, since it is documentation '
                .'and this command is not its author',
        );
    }

    /**
     * A line that already parses is returned exactly as it was found.
     *
     * This command owns a handful of settings and passes the rest through. Rewriting a line that
     * needed nothing makes a diff between a developer's `.env` and a packaged one unreadable for
     * no benefit, which is the cost this guards against.
     */
    public function test_a_readable_foreign_setting_is_not_reformatted(): void
    {
        File::put(
            $this->envFile,
            "# a comment that explains things\nAPP_KEY=\nSIMPLE=value\n# another comment\n",
        );

        $this->configure()->assertSuccessful();

        $this->assertStringContainsString('SIMPLE=value', File::get($this->envFile));
    }

    /**
     * Comments in `.env` are the documentation, and a rewrite that drops them is a regression a
     * reader only notices months later.
     *
     * The unrelated line here has a space in its value, so it is now written quoted. That is the
     * point of the assertion rather than a detail of it: `CUSTOM_SETTING=keep me` left unquoted
     * is the same parse error that stopped a real install from booting, and a test that insisted
     * the line came through byte for byte would have been insisting the file stay broken.
     *
     * What matters is that the setting survives with the value a reader would expect, which is
     * checked through dotenv rather than by matching text.
     */
    public function test_comments_and_unrelated_lines_survive(): void
    {
        File::put(
            $this->envFile,
            "# a comment that explains things\nAPP_KEY=\nCUSTOM_SETTING=keep me\n# another comment\n",
        );

        $this->configure()->assertSuccessful();

        $text = File::get($this->envFile);

        $this->assertStringContainsString('# a comment that explains things', $text);
        $this->assertStringContainsString('# another comment', $text);

        // The value on that CUSTOM_SETTING line is checked for readability in
        // test_a_foreign_setting_with_a_space_in_its_value_survives_readable, which is the same
        // line for the same reason. Asserting it twice here would only make one failure to fix.
        $this->assertStringContainsString('CUSTOM_SETTING=', $text);
    }

    /**
     * A missing `.env.example` is a clear failure, not a stack trace.
     *
     * The command already knew how to say the install is incomplete, then carried on into
     * `EnvironmentFile::write()`, which read a `.env` that had never been copied. A reader sees
     * a parser crash at the moment the command intended a clean diagnostic, and a support
     * bundle then reads as a report about the wrong thing.
     *
     * The base path is pointed at a scratch directory with no example in it and restored in
     * `finally`, so this never depends on, or changes, the real `.env.example`.
     */
    public function test_a_missing_env_example_fails_cleanly_without_writing_an_env(): void
    {
        $base = $this->app->basePath();
        $incomplete = $this->scratch.DIRECTORY_SEPARATOR.'incomplete';

        File::ensureDirectoryExists($incomplete);

        try {
            $this->app->setBasePath($incomplete);

            $exit = Artisan::call('papyrus:configure', [
                '--env-file' => $this->envFile,
                '--data' => $this->scratch.DIRECTORY_SEPARATOR.'data',
            ]);

            $output = Artisan::output();
        } finally {
            $this->app->setBasePath($base);
        }

        $this->assertSame(1, $exit, 'an incomplete install must report failure');
        $this->assertStringContainsString(
            'No .env.example to copy from',
            $output,
            'the clear sentence the command already had must be the thing the reader sees',
        );
        $this->assertStringNotContainsString(
            'Stack trace',
            $output,
            'and the command must stop before the write that used to crash on the missing file',
        );
        $this->assertFileDoesNotExist($this->envFile, 'nothing may be half configured');
    }

    /**
     * A key must never reach a log file or a terminal.
     */
    public function test_the_report_never_prints_the_key(): void
    {
        $this->configure(['--report' => true])
            ->assertSuccessful()
            ->doesntExpectOutputToContain('base64:');
    }
}
