<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class ReleaseRecordTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        require_once base_path('packaging/installer/ReleaseRecord.php');

        $this->dir = $this->scratchPath('release-record');
    }

    public function test_it_merges_both_artifacts_and_preserves_the_installer_signing_state(): void
    {
        $installer = $this->manifest('Papyrus-1.5.2-setup.exe', str_repeat('a', 64), 10, 'a1', true, 'thumbprint');
        $portable = $this->manifest('Papyrus-1.5.2-portable.zip', str_repeat('b', 64), 20, 'b2');

        $path = \ReleaseRecord::merge($this->dir, $installer);
        \ReleaseRecord::merge($this->dir, $portable);

        $record = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('1.5.2', $record['version']);
        $this->assertSame('a1', $record['commit']);
        $this->assertTrue($record['signed']);
        $this->assertSame('thumbprint', $record['signature']);
        $this->assertCount(2, $record['artifacts']);
        $this->assertSame('Papyrus-1.5.2-portable.zip', $record['artifacts'][0]['file']);
        $this->assertSame('b2', $record['artifacts'][0]['commit']);
        $this->assertSame('Papyrus-1.5.2-setup.exe', $record['artifacts'][1]['file']);
        $this->assertSame('a1', $record['artifacts'][1]['commit']);
    }

    public function test_it_keeps_the_installer_toolchain_when_the_portable_merges(): void
    {
        $installer = $this->manifest('Papyrus-1.5.2-setup.exe', str_repeat('a', 64), 10, 'a1');
        $installer['toolchain'] = ['inno_setup' => '6.7.3'];

        $portable = $this->manifest('Papyrus-1.5.2-portable.zip', str_repeat('b', 64), 20, 'b2');

        $path = \ReleaseRecord::merge($this->dir, $installer);
        \ReleaseRecord::merge($this->dir, $portable);

        $record = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            ['inno_setup' => '6.7.3'],
            $record['toolchain'] ?? null,
            'the compiler that built the installer must survive the portable merge',
        );
    }

    public function test_the_same_artifact_merge_is_idempotent(): void
    {
        $installer = $this->manifest('Papyrus-1.5.2-setup.exe', str_repeat('a', 64), 10, 'a1');

        $path = \ReleaseRecord::merge($this->dir, $installer);
        $first = (string) file_get_contents($path);

        \ReleaseRecord::merge($this->dir, $installer);
        $second = (string) file_get_contents($path);

        $this->assertSame($first, $second);
    }

    public function test_a_different_digest_for_the_same_file_fails(): void
    {
        \ReleaseRecord::merge($this->dir, $this->manifest('Papyrus-1.5.2-setup.exe', str_repeat('a', 64), 10, 'a1'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the same artifact name already has a different digest');

        \ReleaseRecord::merge($this->dir, $this->manifest('Papyrus-1.5.2-setup.exe', str_repeat('b', 64), 10, 'a1'));
    }

    public function test_a_missing_commit_fails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('a release artifact needs a file, a sha256 and a commit');

        \ReleaseRecord::merge($this->dir, $this->manifest('Papyrus-1.5.2-setup.exe', str_repeat('a', 64), 10, null));
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(
        string $file,
        string $sha256,
        int $size,
        ?string $commit,
        bool $signed = false,
        ?string $signature = null,
    ): array {
        return [
            'version' => '1.5.2',
            'released' => '2026-11-01T00:00:00+00:00',
            'commit' => $commit,
            'requires' => [
                'suwayomi' => '>=2.4.0',
                'jvm' => '>=25.0.5',
            ],
            'signed' => $signed,
            'signature' => $signature,
            'artifacts' => [[
                'file' => $file,
                'sha256' => $sha256,
                'size' => $size,
                'commit' => $commit,
            ]],
        ];
    }
}
