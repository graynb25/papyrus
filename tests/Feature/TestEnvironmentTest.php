<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The suite must never point at the reader's library database.
 *
 * A run against the full database took 46 to 80 minutes instead of the normal
 * 8 to 13, and it read data that is not a fixture. `phpunit.xml` sets
 * `DB_DATABASE=:memory:`; this test fails if that setting drifts.
 */
final class TestEnvironmentTest extends TestCase
{
    public function test_the_suite_uses_an_in_memory_database(): void
    {
        $this->assertSame(
            ':memory:',
            config('database.connections.sqlite.database'),
            'The test suite must use DB_DATABASE=:memory: from phpunit.xml.'
        );
    }
}
