<?php

declare(strict_types=1);

namespace Tests\Feature;

use Papyrus\Launcher\StopRequest;
use PHPUnit\Framework\TestCase;

/**
 * The marker that tells the start window a disappearing child was stopped on purpose.
 *
 * The launcher cannot use the framework, so this class is exercised directly. The scripts that
 * call it are checked for the three calls, because a correct marker nobody writes or consumes
 * changes nothing.
 */
final class StopRequestTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'papyrus-stop-request-'.getmypid().'-'.uniqid();

        mkdir($this->directory, 0o777, true);
    }

    protected function tearDown(): void
    {
        StopRequest::clear($this->directory);
        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_a_request_is_pending_until_it_is_consumed_once(): void
    {
        $this->assertFalse(StopRequest::consume($this->directory));

        StopRequest::request($this->directory);

        $this->assertFileExists(StopRequest::path($this->directory));
        $this->assertTrue(StopRequest::consume($this->directory));
        $this->assertFileDoesNotExist(StopRequest::path($this->directory));
        $this->assertFalse(StopRequest::consume($this->directory));
    }

    public function test_clear_removes_a_request_left_by_a_stop_with_no_start_watching(): void
    {
        StopRequest::request($this->directory);

        StopRequest::clear($this->directory);

        $this->assertFileDoesNotExist(StopRequest::path($this->directory));
        $this->assertFalse(StopRequest::consume($this->directory));
    }

    /**
     * The wiring is the part a unit test of the marker cannot see.
     */
    public function test_the_entry_point_requests_clears_and_consumes_the_marker(): void
    {
        $script = (string) file_get_contents(
            dirname(__DIR__, 2).'/packaging/launcher/papyrus.php',
        );

        $this->assertStringContainsString('StopRequest::clear($data)', $script);
        $this->assertStringContainsString('StopRequest::request($data)', $script);
        $this->assertStringContainsString('StopRequest::consume($data)', $script);
    }
}
