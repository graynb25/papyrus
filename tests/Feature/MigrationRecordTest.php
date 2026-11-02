<?php

namespace Tests\Feature;

use Papyrus\Launcher\MigrationRecord;
use Tests\TestCase;

final class MigrationRecordTest extends TestCase
{
    private string $data;

    private string $install;

    protected function setUp(): void
    {
        parent::setUp();

        $this->data = $this->scratchPath('migration-record/data');
        $this->install = $this->scratchPath('migration-record/install');

        file_put_contents(
            $this->install.DIRECTORY_SEPARATOR.'version.txt',
            "version 1.5.2\ncommit 090c096\nbuilt 2026-11-01 00:00:00+00:00\n",
        );
    }

    public function test_it_records_the_snapshot_path_digest_and_install_stamp(): void
    {
        $snapshot = $this->data.DIRECTORY_SEPARATOR.'reader-half-20261101-000000.sqlite';
        file_put_contents($snapshot, 'snapshot bytes');

        $record = new MigrationRecord($this->data, $this->install);
        $path = $record->write($snapshot);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);
        $this->assertSame($snapshot, $decoded['snapshot']);
        $this->assertSame(hash_file('sha256', $snapshot), $decoded['snapshot_sha256']);
        $this->assertSame('1.5.2', $decoded['from_version']);
        $this->assertSame('090c096', $decoded['from_commit']);
        $this->assertArrayHasKey('at', $decoded);
    }

    public function test_it_reads_what_it_wrote(): void
    {
        $snapshot = $this->data.DIRECTORY_SEPARATOR.'reader-half-20261101-000000.sqlite';
        file_put_contents($snapshot, 'snapshot bytes');

        $record = new MigrationRecord($this->data, $this->install);
        $record->write($snapshot);

        $decoded = $record->read();

        $this->assertIsArray($decoded);
        $this->assertSame($snapshot, $decoded['snapshot']);
    }

    public function test_it_returns_null_when_no_record_exists(): void
    {
        $this->assertNull((new MigrationRecord($this->data, $this->install))->read());
    }

    public function test_it_returns_null_for_a_record_with_no_snapshot(): void
    {
        file_put_contents(
            $this->data.DIRECTORY_SEPARATOR.'last-migration.json',
            json_encode(['from_version' => '1.5.2']),
        );

        $this->assertNull((new MigrationRecord($this->data, $this->install))->read());
    }

    public function test_it_refuses_to_record_a_missing_snapshot(): void
    {
        $this->expectException(\RuntimeException::class);

        (new MigrationRecord($this->data, $this->install))->write(
            $this->data.DIRECTORY_SEPARATOR.'missing.sqlite',
        );
    }
}
