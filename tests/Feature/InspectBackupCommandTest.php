<?php

namespace Tests\Feature;

use App\Import\BackupReaders;
use App\Import\Tachibk\TachibkReader;
use App\Import\Tachimanga\TachimangaReader;
use App\Support\Suwayomi\Timestamps;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\BuildsTachibkBackups;
use Tests\Support\BuildsTachimangaBackups;
use Tests\TestCase;

/**
 * `import:inspect`, the command that reports what a backup holds and writes nothing.
 *
 * ## Why the wording is the test
 *
 * Because this is the only thing a reader sees before they commit to an import, and a report
 * that is right about the counts and wrong about what they mean is worse than no report.
 *
 * ## The two sentences that broke
 *
 * Both of these were wrong at once and both were found by running the command on the real
 * file rather than by reading it:
 *
 * 1. **"format version 0."** The version used to be a plain `%d`. A `.tachibk` states no
 *    version anywhere, so a null printed as 0, which reads as a version this server
 *    recognised. An intermediate attempt at the fix dropped the ternary's branches, which is
 *    still valid PHP because `sprintf($a, $b, $bool)` is a complete call, and printed "1".
 *    So both the wrong answer and a new wrong answer were reachable from passing tests.
 * 2. **"4 of 4 series are on the shelf. The rest is what the app cached while browsing."**
 *    There is no rest. A `.tachibk` only holds what the reader chose to keep, so the sentence
 *    described a thing that does not exist in that reader's file, at the exact moment they
 *    were deciding whether the file looked right.
 */
class InspectBackupCommandTest extends TestCase
{
    use BuildsTachibkBackups;
    use BuildsTachimangaBackups;

    private ?string $built = null;

    protected function tearDown(): void
    {
        foreach ([$this->built, $this->builtTachimanga] as $path) {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }

        $this->built = null;
        $this->builtTachimanga = null;

        parent::tearDown();
    }

    private ?string $builtTachimanga = null;

    /**
     * @return array{0: int, 1: string}
     */
    private function inspect(string $path): array
    {
        $code = $this->app->make(Kernel::class)->call('import:inspect', ['path' => $path]);

        return [$code, $this->app->make(Kernel::class)->output()];
    }

    /**
     * The registry has to actually hold the new reader.
     *
     * Registering it is one line in a provider and easy to leave out, and every other test in
     * this file constructs the reader directly and would not notice. This goes through the
     * container the way the command does.
     */
    private function readers(): BackupReaders
    {
        return new BackupReaders([
            new TachimangaReader(new Timestamps),
            $this->app->make(TachibkReader::class),
        ]);
    }

    public function test_the_container_knows_about_the_tachibk_reader(): void
    {
        $this->assertStringContainsString('tachibk', $this->readers()->supported());
        $this->assertStringContainsString('tachimanga', $this->readers()->supported());
    }

    public function test_a_format_with_no_version_says_so_and_never_prints_a_number(): void
    {
        $this->built = $this->buildTachibkBackup();

        [$code, $output] = $this->inspect($this->built);

        $this->assertSame(0, $code, 'inspecting a readable backup is a success');

        $this->assertStringContainsString('states no format version', $output);

        // The two wrong answers this sentence has produced. Neither may come back.
        $this->assertStringNotContainsString('format version 0', $output);
        $this->assertStringNotContainsString('backup1', $output);
    }

    public function test_a_format_with_a_version_still_reports_it(): void
    {
        $this->builtTachimanga = $this->buildTachimangaBackup();

        [$code, $output] = $this->inspect($this->builtTachimanga);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('format version 520', $output);
        $this->assertStringNotContainsString('states no format version', $output);
    }

    /**
     * A browse cache is only mentioned when the file has one.
     *
     * The measured Tachimanga file holds 132,185 series of which 1,277 are the library, and
     * that difference is the single most important thing a reader is told before importing, so
     * the sentence has to stay. It just must not be said about a file that has no rest.
     */
    public function test_a_browse_cache_is_reported_only_where_there_is_one(): void
    {
        $this->built = $this->buildTachibkBackup();

        [, $output] = $this->inspect($this->built);

        $this->assertStringContainsString('All 2 series in the file are on the shelf', $output);
        $this->assertStringNotContainsString('cached while browsing', $output);

        $this->builtTachimanga = $this->buildTachimangaBackup();

        [, $tachimanga] = $this->inspect($this->builtTachimanga);

        $this->assertStringContainsString('cached while browsing', $tachimanga);
        $this->assertStringContainsString('are on the shelf', $tachimanga);
    }

    public function test_a_file_no_reader_claims_says_what_is_supported(): void
    {
        $this->built = $this->buildTachibkBackup();

        [$code, $output] = $this->inspect(__FILE__);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('tachibk', $output);
        $this->assertStringContainsString('tachimanga', $output);
    }
}
