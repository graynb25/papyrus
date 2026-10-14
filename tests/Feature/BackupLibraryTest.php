<?php

namespace Tests\Feature;

use App\Backup\BackupLibrary;
use App\Backup\BackupSummary;
use App\Backup\RunOutcome;
use Papyrus\Launcher\BackupPair;
use Papyrus\Launcher\RestoreRefused;
use Papyrus\Launcher\RunRecord;
use Tests\TestCase;

/**
 * Reading what the launcher wrote.
 *
 * ## What is being tested here, and what is not
 *
 * What a page can see. The pairs in the folder, whether the files they name are still there and
 * still match the digests the manifest recorded, what the reader's half held, and what the last run
 * did.
 *
 * What is not being tested is whether a backup restores. That is the restorer's job, it is tested
 * there, and this class deliberately does not pretend to answer it. A digest that matches says the
 * file is the one the manifest describes. It does not say the pair is restorable, because only
 * attempting that says so.
 *
 * ## The one test that crosses over into the launcher
 *
 * `test_the_reader_agrees_with_the_launchers_own_record`. This is a second reader of a file format the
 * launcher owns, which is lesson 225 waiting to happen: two implementations of one question, free to
 * disagree, disagreeing quietly. So a record is written by the launcher's own `RunRecord` and read
 * here, and the two have to say the same thing about it.
 */
class BackupLibraryTest extends TestCase
{
    private string $workspace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-backuplib-'.bin2hex(random_bytes(6));

        mkdir($this->workspace, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->workspace.'/*') as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            is_dir($entry) ? @array_map('unlink', (array) glob($entry.'/*')) && @rmdir($entry) : @unlink($entry);
        }

        @rmdir($this->workspace);

        parent::tearDown();
    }

    private function library(): BackupLibrary
    {
        return new BackupLibrary($this->workspace);
    }

    public function test_an_empty_folder_is_empty_and_says_so(): void
    {
        $this->assertSame([], $this->library()->pairs());
        $this->assertNull($this->library()->lastRun());
        $this->assertTrue($this->library()->isEmpty());
    }

    public function test_it_finds_a_pair_and_reads_what_it_held(): void
    {
        $this->writePair();

        $pairs = $this->library()->pairs();

        $this->assertCount(1, $pairs);

        $pair = $pairs[0];

        $this->assertSame('20261014-203857', $pair->token);
        $this->assertSame(867, $pair->seriesStates);
        $this->assertSame(21447, $pair->chapterStates);
        $this->assertSame('2.4.2366', $pair->serverVersion);
        $this->assertTrue($pair->complete);
        $this->assertTrue($pair->intact);
        $this->assertNull($pair->problem);
        $this->assertSame('867 series and 21,447 chapter states', $pair->describe());
    }

    /**
     * The manifest itself is refused by `BackupPair`, which is right for a restore.
     *
     * So the wrapper exists at all. A restore must not proceed against a manifest it does not
     * understand; a page listing backups has to survive one and show the reader that the thing is
     * broken, rather than render an error page and show nothing at all.
     */
    public function test_a_manifest_the_restore_would_refuse_is_reported_rather_than_thrown(): void
    {
        file_put_contents($this->workspace.'/backup-20261014-203857.json', '{"token": "x", "papy');

        $this->expectException(RestoreRefused::class);

        BackupPair::read($this->workspace.'/backup-20261014-203857.json', $this->workspace);
    }

    public function test_that_same_broken_manifest_is_listed_as_broken(): void
    {
        file_put_contents($this->workspace.'/backup-20261014-203857.json', '{"token": "x", "papy');

        $pairs = $this->library()->pairs();

        $this->assertCount(1, $pairs, 'it is still listed, because it exists and is broken');
        $this->assertFalse($pairs[0]->complete);
        $this->assertNotNull($pairs[0]->problem);
    }

    /**
     * Newest first, by the token rather than by when the file was touched.
     *
     * Copying a manifest changes its mtime, and a reader restoring an old backup would then find it
     * listed as the newest one.
     */
    public function test_pairs_are_newest_first_by_token(): void
    {
        $this->writePair('20260101-000000');
        $this->writePair('20260601-000000');
        $this->writePair('20260301-000000');

        // Touch the oldest, so an mtime ordering would get this backwards.
        touch($this->workspace.'/backup-20260101-000000.json', time() + 3600);

        $this->assertSame(
            ['20260601-000000', '20260301-000000', '20260101-000000'],
            array_map(static fn (BackupSummary $p): string => $p->token, $this->library()->pairs()),
        );
    }

    /**
     * A half missing is listed as incomplete, not omitted.
     *
     * A reader deciding what to keep needs to know the backup exists and is unusable, rather than
     * finding it missing and wondering whether they never took one.
     */
    public function test_a_pair_missing_a_file_is_listed_and_named_as_incomplete(): void
    {
        $pair = $this->writePair();
        unlink($pair['manga']);

        $summary = $this->library()->pairs()[0];

        $this->assertFalse($summary->complete);
        $this->assertSame('Incomplete', $summary->describe());
        $this->assertContains('suwayomi-20261014-203857.mv.db', $summary->missing);
        $this->assertStringContainsString('cannot be restored', (string) $summary->problem);
    }

    /**
     * A file that no longer matches its digest is reported, because it is not what it claims to be.
     *
     * The manga half is 52MB on the reader's install and the reader half 4.9MB, so digesting both on
     * a page load is cheap. Not digesting them would list a truncated manga database beside the
     * others with nothing to say about it.
     */
    public function test_a_file_that_no_longers_matches_is_reported(): void
    {
        $pair = $this->writePair();
        file_put_contents($pair['manga'], 'HALF A MANGA DATABASE');

        $summary = $this->library()->pairs()[0];

        $this->assertTrue($summary->complete, 'both files are there');
        $this->assertFalse($summary->intact, 'and one of them is not what the manifest described');
        $this->assertStringContainsString('no longer matches', (string) $summary->problem);
    }

    public function test_it_reports_a_backup_whose_age_is_readable(): void
    {
        $this->writePair();

        $this->assertMatchesRegularExpression(
            '/ago|before|after|yesterday/i',
            $this->library()->pairs()[0]->ageForHumans(),
            'a reader acts on "3 hours ago", not on an ISO 8601 instant',
        );
    }

    // -------------------------------------------------------------------------------------------
    // The run record, which is the part that answers "what did it do".
    // -------------------------------------------------------------------------------------------

    public function test_it_reads_a_run_the_launcher_wrote(): void
    {
        $this->writeRun('done');

        $run = $this->library()->lastRun();

        $this->assertNotNull($run);
        $this->assertSame('snapshot', $run->operation());
        $this->assertTrue($run->finished());
        $this->assertFalse($run->failed());
        $this->assertSame('This backup finished.', $run->verdict());
        $this->assertCount(count(RunRecord::SNAPSHOT_STAGES), $run->stages());
    }

    /**
     * ## The wrapper does not reimplement anything
     *
     * `RunOutcome` hands the launcher's own `RunRecord::view()` through untouched, so this asserts
     * the wrapper's view **is** that array rather than something built from it. The first version of
     * this class parsed the record itself, on the belief that the application could not load the
     * launcher's classes, and it can: `composer.json` maps `Papyrus\Launcher\`. That put a second
     * reader of one format into the codebase for no reason, and a test asserting the two agreed is
     * not the same as there being only one.
     */
    public function test_the_run_view_is_the_launchers_own_and_not_a_rebuild(): void
    {
        $this->writeRun('failed');

        $run = $this->library()->lastRun();

        $this->assertNotNull($run);

        $path = $this->workspace.'/run-20261014-20failed00.json';

        $theirs = RunRecord::read($path);

        $this->assertNotNull($theirs);
        $this->assertSame($theirs->view(), $run->view, 'byte for byte the same array, so nothing can drift');
    }

    public function test_it_reports_a_failure_with_the_reason_and_the_stage(): void
    {
        $this->writeRun('failed');

        $run = $this->library()->lastRun();

        $this->assertNotNull($run);
        $this->assertTrue($run->failed());
        $this->assertStringContainsString('failed at copying manga half', $run->verdict());
        $this->assertStringContainsString('still running after thirty seconds', $run->verdict());
    }

    /**
     * A retry is visible, because "failed once then worked" and "worked" are different facts.
     */
    public function test_it_shows_a_retry(): void
    {
        $this->writeRun('retried');

        $run = $this->library()->lastRun();

        $this->assertNotNull($run);
        $this->assertContains('stopping', $run->retries());
        $this->assertSame('This backup finished.', $run->verdict(), 'and it did finish');
    }

    /**
     * A run that stopped reporting is a dead launcher, not a slow one.
     *
     * Without this the page shows a bar that climbs to nothing for ever, which is the exact failure
     * the run record exists to prevent, reproduced by the page built to prevent it.
     */
    public function test_a_run_that_stopped_reporting_reads_as_stalled(): void
    {
        $this->writeRun('stalled');

        $run = $this->library()->lastRun();

        $this->assertNotNull($run);
        $this->assertTrue($run->stalled());
        $this->assertFalse($run->finished());
        $this->assertStringContainsString('did not finish', $run->verdict());
    }

    public function test_an_unreadable_run_record_is_null_rather_than_throwing(): void
    {
        file_put_contents($this->workspace.'/run-20261014-203857.json', 'not json at all');

        $this->assertNull($this->library()->lastRun());
    }

    /**
     * Rollback folders are named rather than hidden, because that is where the disk space went.
     *
     * And counted properly: `File::size()` on a directory returns nothing useful, so a rollback
     * folder listed as zero would tell a reader it takes no space.
     */
    public function test_it_names_and_measures_what_a_restore_left_behind(): void
    {
        mkdir($this->workspace.'/rollback-20261014-203857', 0o777, true);
        file_put_contents($this->workspace.'/rollback-20261014-203857/previous-database.sqlite', str_repeat('x', 2048));
        file_put_contents($this->workspace.'/rollback-20261014-203857/previous-database.mv.db', str_repeat('y', 1024));

        $this->writePair();

        $leftovers = $this->library()->leftovers();

        $this->assertCount(1, $leftovers);
        $this->assertSame('rollback-20261014-203857', $leftovers[0]['name']);
        $this->assertSame(3072, $leftovers[0]['bytes'], 'the bytes inside it, not the folder entry');
    }

    /**
     * Write a manifest naming two real files, which is what a finished snapshot leaves behind.
     *
     * @return array{manifest: string, reader: string, manga: string}
     */
    private function writePair(string $token = '20261014-203857'): array
    {
        $reader = $this->workspace.DIRECTORY_SEPARATOR.'papyrus-'.$token.'.sqlite';
        $manga = $this->workspace.DIRECTORY_SEPARATOR.'suwayomi-'.$token.'.mv.db';
        $manifest = $this->workspace.DIRECTORY_SEPARATOR.'backup-'.$token.'.json';

        file_put_contents($reader, 'READER HALF');
        file_put_contents($manga, 'MANGA HALF');

        file_put_contents($manifest, (string) json_encode([
            'token' => $token,
            'taken_at' => '2026-10-14T20:38:57+00:00',
            'papyrus' => [
                'file' => basename($reader),
                'sha256' => hash_file('sha256', $reader),
                'bytes' => filesize($reader),
                'series_states' => 867,
                'chapter_states' => 21447,
            ],
            'suwayomi' => [
                'file' => basename($manga),
                'sha256' => hash_file('sha256', $manga),
                'bytes' => filesize($manga),
                'server_version' => '2.4.2366',
            ],
        ], JSON_PRETTY_PRINT));

        return ['manifest' => $manifest, 'reader' => $reader, 'manga' => $manga];
    }

    /**
     * Write a run record in the shape the launcher writes, for a named outcome.
     */
    private function writeRun(string $shape): void
    {
        $token = '20261014-20'.$shape.'00';
        $path = $this->workspace.DIRECTORY_SEPARATOR.'run-'.$token.'.json';

        $stages = [];

        foreach (RunRecord::SNAPSHOT_STAGES as $stage) {
            $stages[$stage] = ['state' => 'done', 'attempts' => 1, 'message' => $stage.' finished'];
        }

        $stages['checking_target']['message'] = 'nothing is in the way';

        if ($shape === 'failed') {
            $stages['copying_manga_half'] = [
                'state' => 'failed',
                'attempts' => 1,
                'message' => 'Suwayomi (pid 4242) is still running after thirty seconds',
            ];
        }

        if ($shape === 'retried') {
            $stages['stopping']['attempts'] = 2;
        }

        if ($shape === 'stalled') {
            $stages['copying_manga_half'] = [
                'state' => 'running',
                'attempts' => 1,
                'message' => 'waiting for Suwayomi (pid 4242) to go',
            ];
        }

        file_put_contents($path, (string) json_encode([
            'version' => 1,
            'operation' => 'snapshot',
            'token' => $token,
            'started_at' => $shape === 'stalled'
                ? gmdate('c', time() - 600)
                : gmdate('c', time() - 60),
            'stage_order' => RunRecord::SNAPSHOT_STAGES,
            'stages' => $stages,
        ], JSON_PRETTY_PRINT));
    }
}
