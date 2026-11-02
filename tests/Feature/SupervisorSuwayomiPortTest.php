<?php

namespace Tests\Feature;

use Papyrus\Launcher\Prober;
use Papyrus\Launcher\Supervisor;
use RuntimeException;
use Tests\TestCase;

/**
 * Which process the launcher is allowed to adopt on the Suwayomi port.
 *
 * ## What was broken
 *
 * `isPortTaken()` only opened a TCP connection and nothing asked who owned the listener. A second
 * Windows account, or any process in another user's session, could bind 4567 first and receive the
 * reader's Suwayomi username, password and token, then answer library, search and cover requests
 * with arbitrary content. The launcher said it would use the listener and the application sent the
 * login mutation to it.
 *
 * ## What is asserted
 *
 * The port is taken in every test here. The only variable is the owner: a squatter, a Java process
 * from somewhere else, and this install's own Java process. The refusal is the security property,
 * and an owner that cannot be identified must also be refused rather than assumed friendly.
 */
final class SupervisorSuwayomiPortTest extends TestCase
{
    private string $root;

    private string $data;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-adopt-'.getmypid().'-'.uniqid();
        $this->data = $this->root.DIRECTORY_SEPARATOR.'papyrus-data';

        mkdir($this->root.DIRECTORY_SEPARATOR.'suwayomi', 0o777, true);
        mkdir($this->data, 0o777, true);

        file_put_contents(
            $this->root.DIRECTORY_SEPARATOR.'suwayomi'.DIRECTORY_SEPARATOR.'Suwayomi-Server-v2.4.2366.jar',
            'not a real jar',
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function test_a_squatter_on_the_port_is_refused_and_named(): void
    {
        $prober = new class extends Prober
        {
            public function isPortTaken(int $port): bool
            {
                return true;
            }

            public function portHolder(int $port): ?array
            {
                return [
                    'pid' => 4242,
                    'name' => 'squatter.exe',
                    'command' => '"C:\\somewhere\\squatter.exe" --listen 4567',
                    'addresses' => ['127.0.0.1'],
                ];
            }
        };

        $said = [];

        try {
            $this->startSuwayomi($prober, $said);
            $this->fail('a listener that is not this install\'s Suwayomi must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('port 4567', $e->getMessage());
            $this->assertStringContainsString('squatter.exe', $e->getMessage(), 'the owner is named');
            $this->assertStringContainsString('not the Suwayomi this install started', $e->getMessage());
        }

        $this->assertNotContains(
            'using the Suwayomi already running from this install',
            $said,
            'a refused listener must not be announced as adopted',
        );
    }

    /**
     * An owner that cannot be identified fails closed.
     *
     * `portHolder()` returns null when the machine will not answer. A launcher that treated that as
     * "probably fine" would send the credentials to exactly the process it failed to identify.
     */
    public function test_an_unidentified_owner_fails_closed(): void
    {
        $prober = new class extends Prober
        {
            public function isPortTaken(int $port): bool
            {
                return true;
            }

            public function portHolder(int $port): ?array
            {
                return null;
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not the Suwayomi this install started');

        $said = [];

        $this->startSuwayomi($prober, $said);
    }

    /**
     * A Java process with another install's data directory is still a squatter.
     */
    public function test_a_java_process_from_another_install_is_not_adopted(): void
    {
        $prober = new class extends Prober
        {
            public function isPortTaken(int $port): bool
            {
                return true;
            }

            public function portHolder(int $port): ?array
            {
                return [
                    'pid' => 5151,
                    'name' => 'java.exe',
                    'command' => '"java.exe" "-Dpapyrus.rootDir=D:\\other\\data\\suwayomi" -jar "D:\\other\\suwayomi\\Suwayomi-Server-v2.4.2366.jar"',
                    'addresses' => ['127.0.0.1'],
                ];
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not the Suwayomi this install started');

        $said = [];

        $this->startSuwayomi($prober, $said);
    }

    public function test_this_installs_own_suwayomi_is_adopted_with_a_message(): void
    {
        $jar = $this->root.DIRECTORY_SEPARATOR.'suwayomi'.DIRECTORY_SEPARATOR.'Suwayomi-Server-v2.4.2366.jar';
        $command = sprintf(
            '"java.exe" "-Dpapyrus.rootDir=%s" -jar "%s"',
            $this->data.DIRECTORY_SEPARATOR.'suwayomi',
            $jar,
        );

        $prober = new class($command) extends Prober
        {
            public function __construct(private string $command) {}

            public function isPortTaken(int $port): bool
            {
                return true;
            }

            public function portHolder(int $port): ?array
            {
                return [
                    'pid' => 6161,
                    'name' => 'java.exe',
                    'command' => $this->command,
                    'addresses' => ['127.0.0.1'],
                ];
            }
        };

        $said = [];

        $this->startSuwayomi($prober, $said);

        $this->assertContains(
            'using the Suwayomi already running from this install',
            $said,
            'the reader is told the listener was checked, not merely that the port was busy',
        );
    }

    private function startSuwayomi(Prober $prober, array &$said): void
    {
        $supervisor = new Supervisor(
            root: $this->root,
            dataDirectory: $this->data,
            logDirectory: $this->data.DIRECTORY_SEPARATOR.'logs',
            suwayomiPort: 4567,
            appPort: 8402,
            prober: $prober,
        );

        $said = [];

        (new \ReflectionMethod($supervisor, 'startSuwayomi'))->invoke(
            $supervisor,
            function (string $line) use (&$said): void {
                $said[] = $line;
            },
        );
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($path);
    }
}
