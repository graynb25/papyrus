<?php

namespace Tests\Unit\Suwayomi;

use App\Suwayomi\FailureKind;
use App\Suwayomi\SuwayomiException;
use PHPUnit\Framework\TestCase;

/**
 * The server appends full Kotlin stack traces to GraphQL error messages, so
 * sanitising before the message reaches a log or a view is not cosmetic.
 */
class SuwayomiExceptionTest extends TestCase
{
    public function test_it_collapses_a_stack_trace_into_one_line(): void
    {
        $exception = new SuwayomiException("boom\n\tat eu.kanade.Foo.bar(Foo.kt:42)\n\tat baz()");

        $this->assertStringNotContainsString("\n", $exception->getMessage());
        $this->assertStringContainsString('at eu.kanade.Foo.bar', $exception->getMessage());
    }

    public function test_it_truncates_very_long_messages(): void
    {
        $exception = new SuwayomiException(str_repeat('x', 900));

        $this->assertLessThanOrEqual(SuwayomiException::MAX_MESSAGE_LENGTH, mb_strlen($exception->getMessage()));
        $this->assertStringEndsWith('…', $exception->getMessage());
    }

    public function test_it_preserves_the_raw_error_payload_for_diagnostics(): void
    {
        $errors = [
            ['message' => 'Source not installed', 'path' => ['fetchSourceManga'], 'extensions' => ['code' => 'NOT_FOUND']],
        ];

        $exception = SuwayomiException::query($errors, 12.5);

        $this->assertSame(FailureKind::Query, $exception->kind);
        $this->assertSame($errors, $exception->errors);
        $this->assertSame(12.5, $exception->elapsedMs);
    }

    public function test_query_failures_are_not_transient(): void
    {
        $exception = SuwayomiException::query([['message' => 'bad']], 1.0);

        $this->assertFalse($exception->isTransient());
    }

    public function test_timeouts_and_auth_failures_are_transient(): void
    {
        $this->assertTrue(SuwayomiException::timeout(30000.0)->isTransient());
        $this->assertTrue(SuwayomiException::unauthenticated()->isTransient());
    }
}
