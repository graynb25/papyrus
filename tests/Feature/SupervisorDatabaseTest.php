<?php

namespace Tests\Feature;

use Papyrus\Launcher\Supervisor;
use Tests\TestCase;

/**
 * The launcher must not ask for a pre-migration snapshot when there is no database yet.
 *
 * 1.5.2 called `papyrus:snapshot` unconditionally before the first migration. `VACUUM INTO`
 * refuses a source that does not exist, so a fresh install failed before creating its schema
 * and never started. This pins the predicate the launcher uses to skip that snapshot.
 */
final class SupervisorDatabaseTest extends TestCase
{
    private string $root;

    private string $data;

    private string $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = $this->scratchPath('supervisor-db/root');
        $this->data = $this->scratchPath('supervisor-db/data');
        $this->logs = $this->scratchPath('supervisor-db/logs');
    }

    public function test_a_missing_database_is_not_a_database(): void
    {
        $this->assertFalse($this->supervisor()->hasDatabase());
    }

    public function test_an_empty_database_file_is_not_a_database(): void
    {
        file_put_contents($this->data.DIRECTORY_SEPARATOR.'database.sqlite', '');

        $this->assertFalse($this->supervisor()->hasDatabase());
    }

    public function test_a_database_with_bytes_is_a_database(): void
    {
        file_put_contents(
            $this->data.DIRECTORY_SEPARATOR.'database.sqlite',
            "SQLite format 3\0",
        );

        $this->assertTrue($this->supervisor()->hasDatabase());
    }

    private function supervisor(): Supervisor
    {
        return new Supervisor($this->root, $this->data, $this->logs);
    }
}
