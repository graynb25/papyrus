<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $database = $this->envValues()['DB_DATABASE'];

        $this->assertStringStartsWith(
            str_replace('/', '\\', $this->scratch).'\\data',
            str_replace('/', '\\', $database),
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

        $database = $this->envValues()['DB_DATABASE'];

        $this->assertTrue(
            str_starts_with($database, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $database) === 1,
            "expected an absolute path, got {$database}",
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
     * Comments in `.env` are the documentation, and a rewrite that drops them is a regression a
     * reader only notices months later.
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
        $this->assertStringContainsString('CUSTOM_SETTING=keep me', $text);
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
