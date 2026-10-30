<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The storage path a packaged install uses before its first `.env` exists.
 *
 * ## The measured failure
 *
 * The 1.5.1 portable archive carries no `storage` tree, because a zip cannot hold an empty
 * directory and every directory the install needs is recreated at first start. The launcher
 * creates the data directory's `storage/framework/views` before it runs `papyrus:configure`,
 * but this bootstrap switched to that tree only when the `.env` already existed. On a first
 * run it therefore booted against the install's `storage`, whose `framework/views` was absent,
 * and the view compiler refused to boot with "Please provide a valid cache path" before the
 * command could write the `.env`. The console only said the database could not be migrated,
 * because the configure step's output went to `configure.log`.
 *
 * ## Why this spawns a process
 *
 * The test suite boots the application once, against the checkout's own storage, before any
 * test runs. A test inside that process cannot ask the bootstrap a first-run question. A child
 * process that requires `bootstrap/app.php` and prints `storagePath()` asks exactly the question
 * the portable launcher asks, without booting the framework or touching the checkout's storage.
 */
class PackagedBootstrapTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-bootstrap-'.getmypid().'-'.uniqid();

        File::ensureDirectoryExists(
            $this->scratch.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'storage'
            .DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views',
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    public function test_the_data_storage_is_used_before_the_env_file_exists(): void
    {
        $envFile = $this->scratch.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'.env';

        $this->assertFileDoesNotExist(
            $envFile,
            'this test is about the first run, before papyrus:configure has written the file',
        );

        $probe = $this->scratch.DIRECTORY_SEPARATOR.'storage-path.php';

        File::put($probe, <<<'PHP'
<?php

require $argv[1].'/vendor/autoload.php';

$app = require $argv[1].'/bootstrap/app.php';

echo $app->storagePath();
PHP);

        $environment = getenv() ?: [];
        $environment['PAPYRUS_ENV'] = $envFile;

        $process = proc_open(
            [PHP_BINARY, $probe, base_path()],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $environment,
        );

        $this->assertIsResource($process, 'the bootstrap probe could not be started');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        $this->assertSame(0, $exit, 'the bootstrap probe failed: '.$stderr);

        $this->assertSame(
            $this->scratch.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'storage',
            trim($stdout),
            'the first run must use the data storage the launcher created, not the install '
                .'storage the portable archive does not carry',
        );
    }
}
