<?php

namespace Tests\Feature;

use Papyrus\Launcher\BackupPair;
use Papyrus\Launcher\FileMover;
use Papyrus\Launcher\LocalFileMover;
use Papyrus\Launcher\Restorer;
use Papyrus\Launcher\RestoreRefused;
use Tests\TestCase;

/**
 * Restoring both halves of an install as one operation, or neither.
 *
 * ## Why this is the severe one
 *
 * Because it is the only operation in this project where a reader can lose work they cannot
 * reconstruct. Suwayomi already takes a daily `.tachibk` with 14 day retention and this project can
 * read those, so the manga half has a backup. Nothing else backs up this side: measured on the
 * reader's install, 867 `series_states` and 21,447 `chapter_states` rows exist only in this
 * project's SQLite file.
 *
 * So there are two files holding different facts, and they reference each other:
 * `series_states.suwayomi_manga_id` points into the H2 database. Restoring one without the other, or
 * restoring two halves taken at different moments, leaves rows pointing at manga that are no longer
 * there. That is not a crash, it is a library that quietly reads as empty.
 *
 * ## What is being ruled out
 *
 * The failure this refuses is specifically **one half landing while the other fails**. Not corruption,
 * not a bad password, not a missing file: the case where the first database is replaced, the second
 * raises, and the install is left holding a new reader state against an old library with nothing
 * saying so.
 *
 * Every test below drives a real filesystem in a temporary directory, because the guarantee is about
 * what is on disk when something goes wrong halfway. The only thing injected is the file mover, so a
 * test can make the second copy fail on demand.
 */
class RestoreOrchestrationTest extends TestCase
{
    private string $workspace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-restore-'.bin2hex(random_bytes(6));

        mkdir($this->backupDir(), 0o777, true);
        mkdir($this->liveDir(), 0o777, true);
    }

    /**
     * Where the backup pair is written.
     *
     * A method rather than `$this->workspace.'/backup'` at each of the twelve call sites, because
     * spelled with a forward slash it produced a path carrying both separators, and one of the tests
     * asserts on a rollback path the implementation built with `DIRECTORY_SEPARATOR`. The assertion
     * failed on Windows for a reason that had nothing to do with the restore.
     *
     * The obvious wrong fix is to loosen the assertion to match whatever comes out. That would hide
     * a real mixed-separator bug in the code instead of removing it from the test.
     */
    private function backupDir(): string
    {
        return $this->workspace.DIRECTORY_SEPARATOR.'backup';
    }

    private function liveDir(): string
    {
        return $this->workspace.DIRECTORY_SEPARATOR.'live';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);

        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach ((array) glob($path.'/*') as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            is_dir($entry) ? $this->removeTree($entry) : @unlink($entry);
        }

        @rmdir($path);
    }

    /**
     * Write a believable pair into the backup directory.
     *
     * A pair is two files plus a manifest binding them. The row counts are not decoration: they are
     * what a restore reports afterwards, and the test asserts against them so a restore that reports
     * "done" while having restored nothing is visible.
     */
    private function writePair(string $token = '20261013-031701', int $seriesStates = 867, int $chapterStates = 21447): BackupPair
    {
        $papyrus = $this->backupDir().DIRECTORY_SEPARATOR.'papyrus-'.$token.'.sqlite';
        $suwayomi = $this->backupDir().DIRECTORY_SEPARATOR.'suwayomi-'.$token.'.mv.db';

        file_put_contents($papyrus, 'PAPYRUS-SIDECAR-'.$seriesStates.'-'.$chapterStates);
        file_put_contents($suwayomi, 'SUWAYOMI-H2-'.$token);

        $manifest = $this->backupDir().DIRECTORY_SEPARATOR.'papyrus-'.$token.'.json';

        file_put_contents($manifest, json_encode([
            'version' => 1,
            'token' => $token,
            'taken_at' => '2026-10-13T03:17:01+00:00',
            'papyrus' => [
                'file' => basename($papyrus),
                'sha256' => hash_file('sha256', $papyrus),
                'bytes' => filesize($papyrus),
                'series_states' => $seriesStates,
                'chapter_states' => $chapterStates,
            ],
            'suwayomi' => [
                'file' => basename($suwayomi),
                'sha256' => hash_file('sha256', $suwayomi),
                'bytes' => filesize($suwayomi),
                'server_version' => '2.4.2366',
            ],
        ], JSON_PRETTY_PRINT));

        return BackupPair::read($manifest, $this->backupDir());
    }

    /**
     * A live install whose two files carry recognisable content.
     *
     * Each half is a target plus the sidecars that belong to it, because a WAL without its database
     * and a database without its WAL are both states nothing can be reasoned about. None are listed
     * here: this suite writes plain files, and a write-ahead log cannot be produced by writing a
     * file, so it has its own suite in {@see RestoreSidecarTest}.
     *
     * @return array{papyrus: array{target: string, companions: list<string>}, suwayomi: array{target: string, companions: list<string>}}
     */
    private function writeLive(string $papyrusBody = 'LIVE-PAPYRUS', string $suwayomiBody = 'LIVE-SUWAYOMI'): array
    {
        $papyrus = $this->liveDir().DIRECTORY_SEPARATOR.'database.sqlite';
        $suwayomi = $this->liveDir().DIRECTORY_SEPARATOR.'database.mv.db';

        file_put_contents($papyrus, $papyrusBody);
        file_put_contents($suwayomi, $suwayomiBody);

        return [
            'papyrus' => ['target' => $papyrus, 'companions' => []],
            'suwayomi' => ['target' => $suwayomi, 'companions' => []],
        ];
    }

    private function restorer(?FileMover $mover = null): Restorer
    {
        return new Restorer($mover ?? new LocalFileMover);
    }

    // ---------------------------------------------------------------------------------------------
    // The severe case.
    // ---------------------------------------------------------------------------------------------

    /**
     * The second half failing must put the first half back.
     *
     * This is the whole reason the class exists. The reader state is copied in first, the manga file
     * then fails, and the install is left holding a new SQLite against an old H2 with nothing saying
     * so: every `series_states.suwayomi_manga_id` now points at a row that means something else, and
     * the symptom is a library that reads as empty rather than an error.
     *
     * Asserted on the bytes on disk after the failure, not on a return value, because the return value
     * is the thing a careless implementation gets right.
     */
    public function test_the_second_half_failing_puts_the_first_half_back(): void
    {
        $live = $this->writeLive();
        $pair = $this->writePair();

        $mover = new class(new LocalFileMover) implements FileMover
        {
            public function __construct(private readonly FileMover $inner) {}

            public function move(string $from, string $to): void
            {
                $this->inner->move($from, $to);
            }

            public function copy(string $from, string $to): void
            {
                if (str_contains($to, '.mv.db')) {
                    throw new RestoreRefused('the manga half failed to copy');
                }

                $this->inner->copy($from, $to);
            }

            public function digest(string $path): string
            {
                return $this->inner->digest($path);
            }

            public function exists(string $path): bool
            {
                return $this->inner->exists($path);
            }
        };

        $failed = false;

        try {
            $this->restorer($mover)->restore($pair, $live);
        } catch (RestoreRefused) {
            $failed = true;
        }

        $this->assertTrue($failed, 'the restore must refuse rather than report success');

        // Both live files are byte for byte what they were before the attempt.
        $this->assertSame('LIVE-PAPYRUS', (string) file_get_contents($live['papyrus']['target']), 'the reader half was left replaced');
        $this->assertSame('LIVE-SUWAYOMI', (string) file_get_contents($live['suwayomi']['target']), 'the manga half was left replaced');
    }

    /**
     * A rollback that itself half fails must still say the install is not trustworthy.
     *
     * A restore that cannot put things back has to say so in a way a reader cannot misread, because
     * the alternative is somebody opening Papyrus, seeing a library, and believing it.
     */
    public function test_a_failed_rollback_is_reported_rather_than_swallowed(): void
    {
        $live = $this->writeLive();
        $pair = $this->writePair();

        /*
         * Two failures at once, and both are needed.
         *
         * Refusing only the rollback leaves nothing to roll back from: the copies land, the digests
         * match, and the restore succeeds, so the rollback code never runs. Refusing only the second
         * copy triggers the rollback but lets it succeed, so the message under test never appears.
         *
         * The version that threw on every move failed at the *first* step, which is a different
         * situation again: nothing had been moved aside, so there was nothing to put back and the
         * code correctly reported a plain refusal.
         */
        $refusesRollback = new class(new LocalFileMover, array_values(array_column($live, 'target'))) implements FileMover
        {
            public function __construct(private readonly FileMover $inner, private readonly array $livePaths) {}

            public function move(string $from, string $to): void
            {
                if (in_array($to, $this->livePaths, true)) {
                    throw new RestoreRefused('the rollback could not move anything back');
                }

                $this->inner->move($from, $to);
            }

            public function copy(string $from, string $to): void
            {
                // The manga half fails to arrive, which is what sends the restore into its rollback.
                if (str_contains($to, '.mv.db')) {
                    throw new RestoreRefused('the manga half failed to copy');
                }

                $this->inner->copy($from, $to);
            }

            public function digest(string $path): string
            {
                return $this->inner->digest($path);
            }

            public function exists(string $path): bool
            {
                return $this->inner->exists($path);
            }
        };

        $message = '';

        try {
            $this->restorer($refusesRollback)->restore($pair, $live);
        } catch (RestoreRefused $e) {
            $message = $e->getMessage();
        }

        $this->assertNotSame('', $message);
        $this->assertStringContainsString(
            'could not be put back',
            $message,
            'a restore that cannot undo itself has to say the install is not in a known state, '
            .'because the alternative is a reader trusting a library that is half of two backups',
        );
        $this->assertStringContainsString(
            'not in a known state',
            $message,
            'and it has to say so in words a reader will read, not only in an exception a caller '
            .'may render as "the restore failed, try again". Asserting the claim rather than one '
            .'spelling of the sentence, so rewording it does not need this test changed.',
        );
        $this->assertStringContainsString(
            'papyrus stop',
            $message,
            'and it has to name the command that stops everything, because a reader who does not know '
            .'that cannot act on being told the install is in a state they should not open it in',
        );
        /*
         * Built with DIRECTORY_SEPARATOR rather than a literal slash.
         *
         * The first version spelled the expected path with forward slashes and failed on Windows,
         * where the message carries backslashes. A test that pins the separator is a test that fails
         * on one platform for a reason that has nothing to do with the restore.
         */
        $expectedRollback = $this->workspace
            .DIRECTORY_SEPARATOR.'backup'
            .DIRECTORY_SEPARATOR.'rollback-'.$pair->token;

        $this->assertStringContainsString(
            $expectedRollback,
            $message,
            'and where the previous files are, which is the only copy of their library that exists',
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Refusing before touching anything.
    // ---------------------------------------------------------------------------------------------

    /**
     * One half missing is refused before the install is touched at all.
     *
     * The order matters more than the refusal. A pre-flight that stops Suwayomi and then discovers
     * the manga file is missing has already cost the reader their running application.
     */
    public function test_a_pair_missing_its_manga_half_is_refused_before_anything_is_touched(): void
    {
        $live = $this->writeLive();
        $pair = $this->writePair();

        unlink($this->backupDir().DIRECTORY_SEPARATOR.'suwayomi-'.$pair->token.'.mv.db');

        $mover = new class(new LocalFileMover) implements FileMover
        {
            /** @var list<string> */
            public array $wroteTo = [];

            public function __construct(private readonly FileMover $inner) {}

            public function move(string $from, string $to): void
            {
                $this->wroteTo[] = $to;
                $this->inner->move($from, $to);
            }

            public function copy(string $from, string $to): void
            {
                $this->wroteTo[] = $to;
                $this->inner->copy($from, $to);
            }

            public function digest(string $path): string
            {
                return $this->inner->digest($path);
            }

            public function exists(string $path): bool
            {
                return $this->inner->exists($path);
            }
        };

        try {
            $this->restorer($mover)->restore($pair, $live);
        } catch (RestoreRefused) {
            $touched = true;
        }

        $this->assertTrue($touched, 'a half pair must be refused');

        /*
         * Nothing WRITTEN, rather than nothing touched.
         *
         * The first version of this asserted that the mover had not been used at all, and it failed,
         * correctly: a pre-flight has to read the files to hash them, and reading is the whole point
         * of checking before touching anything. The invariant worth holding is that nothing was
         * *changed*, so reads are excluded and writes are counted.
         */
        $this->assertSame(
            [],
            $mover->wroteTo,
            'nothing may be written before the pair is known good. It wrote to: '.implode(', ', $mover->wroteTo),
        );

        $this->assertSame('LIVE-PAPYRUS', (string) file_get_contents($live['papyrus']['target']));
        $this->assertSame('LIVE-SUWAYOMI', (string) file_get_contents($live['suwayomi']['target']));
    }

    /**
     * A file whose digest does not match the manifest is refused.
     *
     * A snapshot that was truncated in transit, or written by a process that died partway, is the
     * case `papyrus:snapshot` tries to prevent by deleting a partial file. This is the second line of
     * defence, and it is the one that still works if the first was skipped.
     */
    public function test_a_file_whose_digest_does_not_match_is_refused(): void
    {
        $live = $this->writeLive();
        $pair = $this->writePair();

        // Corrupt the manga half after the manifest was written.
        file_put_contents($this->backupDir().DIRECTORY_SEPARATOR.'suwayomi-'.$pair->token.'.mv.db', 'TRUNCATED');

        $refused = false;

        try {
            $this->restorer()->restore($pair, $live);
        } catch (RestoreRefused) {
            $refused = true;
        }

        $this->assertTrue($refused, 'a digest mismatch must be refused');
        $this->assertSame('LIVE-PAPYRUS', (string) file_get_contents($live['papyrus']['target']));
        $this->assertSame('LIVE-SUWAYOMI', (string) file_get_contents($live['suwayomi']['target']));

        /*
         * Nothing was written, which is the property that makes this a pre-flight rather than a
         * rollback.
         *
         * The first version of this test asserted only the end state, and removing the pre-flight
         * digest check changed nothing: the copy landed, `checkLandedMatchesManifest` caught it, and
         * the rollback put both files back to exactly the bytes they started as. The end state was
         * right and the reader still paid for it: two files were moved aside, a 4.9MB copy was made
         * and thrown away, and a rollback directory was left behind for no reason.
         *
         * "The files are as they were" and "nothing happened" are different guarantees, and only one
         * of them is worth having before a restore that may be about to be interrupted.
         */
        $this->assertDirectoryDoesNotExist(
            $this->backupDir().DIRECTORY_SEPARATOR.'rollback-'.$pair->token,
            'a bad backup must be refused before anything is moved aside, so no rollback directory '
            .'is created and no live file is ever out of place',
        );
    }

    /**
     * A copy that arrives short is deleted rather than left where a database is expected.
     *
     * Found by mutation: removing the check that compares a copy against its source changed nothing.
     * The copy is verified again against the manifest afterwards, so the end state was right, but a
     * truncated file had been written over a working database first and then taken away again.
     *
     * The gap between those two is a reader who opens Papyrus at exactly the wrong moment, and it is
     * the same shape as the portable zip: the tool reported success and the artefact was wrong.
     */
    public function test_a_copy_that_arrives_short_is_deleted_rather_than_left_in_place(): void
    {
        $live = $this->writeLive();
        $pair = $this->writePair();

        // Copies faithfully, then loses the tail. What a filter or a full disk does.
        $truncating = new class(new LocalFileMover) implements FileMover
        {
            public function __construct(private readonly FileMover $inner) {}

            public function move(string $from, string $to): void
            {
                $this->inner->move($from, $to);
            }

            public function copy(string $from, string $to): void
            {
                $this->inner->copy($from, $to);

                file_put_contents($to, substr((string) file_get_contents($to), 0, 4));
            }

            public function digest(string $path): string
            {
                return $this->inner->digest($path);
            }

            public function exists(string $path): bool
            {
                return $this->inner->exists($path);
            }
        };

        $refused = false;

        try {
            $this->restorer($truncating)->restore($pair, $live);
        } catch (RestoreRefused) {
            $refused = true;
        }

        $this->assertTrue($refused, 'a copy that did not arrive intact must be refused');

        // Nothing short is sitting where a database is expected.
        foreach (array_column($live, 'target') as $path) {
            $body = (string) @file_get_contents($path);

            $this->assertNotSame(
                'SUWA',
                $body,
                'a truncated copy was left in place at '.$path,
            );
        }

        $this->assertSame('LIVE-PAPYRUS', (string) file_get_contents($live['papyrus']['target']));
        $this->assertSame('LIVE-SUWAYOMI', (string) file_get_contents($live['suwayomi']['target']));
    }

    /**
     * `copy()` actually calls the check.
     *
     * A static assertion, and it needs the same justification as the ancestor guard's.
     *
     * `assertCopyArrivedIntact` is tested directly and thoroughly, so removing its body is caught.
     * Removing the *call* is not: `checkLandedMatchesManifest` re-hashes both files after they land
     * and catches the same damage, one step later. The redundancy is deliberate, so the call site is
     * genuinely uncovered by any runtime test.
     *
     * It cannot be covered at runtime either. Making `copy()` genuinely lose bytes needs a filter or
     * a full disk, and a test that reaches for one works on the machine it was written on and nowhere
     * else. What can be asserted is that the two lines are connected, which is the part that can
     * silently come apart during a refactor.
     *
     * The test above it (`test_a_faithful_copy_is_accepted`) is what stops this from being the
     * assertion-only test in the file: a real `copy()` runs, and the check is proved not to fire on a
     * good copy, so the static assertion below is not pinning a call to a method that always throws.
     */
    public function test_copy_calls_the_intact_check(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/packaging/launcher/src/LocalFileMover.php',
        );

        $this->assertMatchesRegularExpression(
            '/function copy\(.*?\$this->assertCopyArrivedIntact\(\$from, \$to\);/s',
            $source,
            'copy() must verify the copy it just made. Without the call the check is correct code that '
            .'nothing reaches, which is the same as not having it.',
        );
    }

    /**
     * A short destination is refused and deleted, called directly.
     *
     * The end-to-end version of this asserts that a restore leaves nothing short in place, and it
     * passes whether or not `LocalFileMover` checks its own copies, because `checkLandedMatchesManifest`
     * catches the same damage a moment later. Mutating the copy check alone therefore survived, which
     * is correct: the two checks are redundant by design.
     *
     * So this calls the check directly with a deliberately mismatched pair. The situation it guards
     * cannot be produced through a real `copy()` without a filter or a full disk, and a test that
     * reached for one would work on the machine it was written on and nowhere else. Exposing the
     * check as a method is what makes it assertable at all.
     */
    public function test_a_short_destination_is_refused_and_deleted(): void
    {
        $mover = new LocalFileMover;

        $source = $this->liveDir().DIRECTORY_SEPARATOR.'source.bin';
        $short = $this->liveDir().DIRECTORY_SEPARATOR.'short.bin';

        file_put_contents($source, 'the whole of it');
        file_put_contents($short, 'the');

        $refused = false;

        try {
            $mover->assertCopyArrivedIntact($source, $short);
        } catch (RestoreRefused) {
            $refused = true;
        }

        $this->assertTrue($refused, 'a short destination must be refused');
        $this->assertFileDoesNotExist(
            $short,
            'and it must be deleted, because the one thing a reader should never find where their '
            .'database was is a file that is not one',
        );
    }

    /**
     * A destination of the right length but the wrong bytes is refused too.
     *
     * Because the size check alone is the obvious half, and a copy can be the right length and still
     * be the wrong file. Pinned separately so that shortening the check to `filesize()` alone is
     * caught here rather than looking like a simplification.
     */
    public function test_a_destination_of_the_right_size_but_the_wrong_bytes_is_refused(): void
    {
        $mover = new LocalFileMover;

        $source = $this->liveDir().DIRECTORY_SEPARATOR.'source.bin';
        $wrong = $this->liveDir().DIRECTORY_SEPARATOR.'wrong.bin';

        file_put_contents($source, 'AAAAAAAAAAAAAAAA');
        file_put_contents($wrong, 'BBBBBBBBBBBBBBBB');

        $this->assertSame(
            filesize($source),
            filesize($wrong),
            'the sizes match, which is the point: only hashing can tell these apart',
        );

        $refused = false;

        try {
            $mover->assertCopyArrivedIntact($source, $wrong);
        } catch (RestoreRefused) {
            $refused = true;
        }

        $this->assertTrue($refused, 'the right length with the wrong bytes must be refused');
        $this->assertFileDoesNotExist($wrong);
    }

    /**
     * A faithful copy is accepted and left alone.
     *
     * Without this the refusal could be passing because the check always throws, which is the shape
     * of a check that cannot succeed and therefore cannot fail either.
     */
    public function test_a_faithful_copy_is_accepted(): void
    {
        $mover = new LocalFileMover;

        $source = $this->liveDir().DIRECTORY_SEPARATOR.'source.bin';
        $copy = $this->liveDir().DIRECTORY_SEPARATOR.'copy.bin';

        file_put_contents($source, str_repeat('a valid database would be longer than this ', 500));

        $mover->copy($source, $copy);

        $this->assertFileExists($copy, 'a good copy must survive');

        $mover->assertCopyArrivedIntact($source, $copy);

        $this->assertFileExists(
            $copy,
            'and asserting on a good copy must not delete it, or the check is refusing indiscriminately',
        );
    }

    /**
     * A manifest cannot name a file outside the directory it sits in.
     *
     * A manifest is a file a reader can open in a text editor, so `../../something` is not a
     * hypothetical. Refused rather than tidied up, because silently stripping the traversal would
     * mean the manifest says one thing and the restore reads another, and the reader has no way to
     * see which.
     */
    public function test_a_manifest_cannot_name_a_file_outside_its_own_directory(): void
    {
        foreach (['../escaped.sqlite', 'sub/dir.sqlite', '..'] as $sneaky) {
            $manifest = $this->backupDir().DIRECTORY_SEPARATOR.'sneaky-'.md5($sneaky).'.json';

            file_put_contents($manifest, json_encode([
                'version' => 1,
                'token' => 'sneaky',
                'taken_at' => '2026-10-13T03:17:01+00:00',
                'papyrus' => [
                    'file' => $sneaky,
                    'sha256' => str_repeat('a', 64),
                    'bytes' => 1,
                    'series_states' => 0,
                    'chapter_states' => 0,
                ],
                'suwayomi' => [
                    'file' => 'suwayomi.mv.db',
                    'sha256' => str_repeat('b', 64),
                    'bytes' => 1,
                    'server_version' => '2.4.2366',
                ],
            ]));

            $refused = false;

            try {
                BackupPair::read($manifest, $this->backupDir());
            } catch (RestoreRefused) {
                $refused = true;
            }

            $this->assertTrue($refused, sprintf('"%s" must be refused, not tidied up', $sneaky));
        }
    }

    /**
     * A manifest with no usable digest is refused, because there is nothing to check against.
     *
     * Without this the restore would either skip the integrity check or compare against something
     * that is not a digest, and both of those read as "the backup is fine" to whoever reads the log.
     */
    public function test_a_manifest_with_no_usable_digest_is_refused(): void
    {
        foreach (['', 'not-a-digest', str_repeat('z', 64), str_repeat('a', 63)] as $bad) {
            $manifest = $this->backupDir().DIRECTORY_SEPARATOR.'nodigest-'.md5($bad).'.json';

            file_put_contents($manifest, json_encode([
                'version' => 1,
                'token' => 'nodigest',
                'taken_at' => '2026-10-13T03:17:01+00:00',
                'papyrus' => [
                    'file' => 'a.sqlite',
                    'sha256' => $bad,
                    'bytes' => 1,
                    'series_states' => 0,
                    'chapter_states' => 0,
                ],
                'suwayomi' => [
                    'file' => 'b.mv.db',
                    'sha256' => str_repeat('b', 64),
                    'bytes' => 1,
                    'server_version' => '2.4.2366',
                ],
            ]));

            $refused = false;

            try {
                BackupPair::read($manifest, $this->backupDir());
            } catch (RestoreRefused) {
                $refused = true;
            }

            $this->assertTrue($refused, sprintf('the digest "%s" must be refused', $bad));
        }
    }

    /**
     * A manifest that names only one half is not a pair.
     *
     * Someone hand-editing a manifest, or a snapshot written by a version that only knew about one
     * database, both produce this. Restoring it would replace one half and leave the other alone,
     * which is precisely the outcome this class exists to prevent, reached by a different road.
     */
    public function test_a_manifest_naming_only_one_half_is_refused(): void
    {
        $manifest = $this->backupDir().DIRECTORY_SEPARATOR.'lopsided.json';

        file_put_contents($manifest, json_encode([
            'version' => 1,
            'token' => 'lopsided',
            'taken_at' => '2026-10-13T03:17:01+00:00',
            'papyrus' => [
                'file' => 'whatever.sqlite',
                'sha256' => str_repeat('0', 64),
                'bytes' => 10,
                'series_states' => 0,
                'chapter_states' => 0,
            ],
        ]));

        $refused = false;

        try {
            BackupPair::read($manifest, $this->backupDir());
        } catch (RestoreRefused) {
            $refused = true;
        }

        $this->assertTrue($refused, 'a manifest with no manga half is not a restorable pair');
    }

    // ---------------------------------------------------------------------------------------------
    // The path that works.
    // ---------------------------------------------------------------------------------------------

    /**
     * The whole thing, both halves, with the old ones kept rather than deleted.
     *
     * The rollback directory is not an implementation detail. It is the reason a reader who
     * restores the wrong pair can get back to where they were, and it is why nothing is deleted
     * during a restore.
     */
    public function test_it_restores_both_halves_and_keeps_the_old_ones(): void
    {
        $live = $this->writeLive();
        $pair = $this->writePair();

        $result = $this->restorer()->restore($pair, $live);

        $this->assertSame(
            'PAPYRUS-SIDECAR-867-21447',
            (string) file_get_contents($live['papyrus']['target']),
            'the reader half was not restored',
        );
        $this->assertSame(
            'SUWAYOMI-H2-20261013-031701',
            (string) file_get_contents($live['suwayomi']['target']),
            'the manga half was not restored',
        );

        $this->assertNotSame([], $result->rollback, 'the previous files must be kept, not deleted');
        $this->assertSame(
            'LIVE-PAPYRUS',
            (string) file_get_contents($result->rollback['papyrus']['database.sqlite'] ?? ''),
            'the rollback copy is not the file that was there before',
        );
        $this->assertSame(867, $result->seriesStates);
        $this->assertSame(21447, $result->chapterStates);

        /*
         * BOTH, not "at least one".
         *
         * Found by mutation: moving only the first half aside, and not the second, changed nothing at
         * all in the suite. `prepareTarget()` unlinks whatever is at the destination, so the half that
         * was never moved aside still got overwritten and the rollback still put the first one back.
         * The end state was correct and the guarantee was not: there was no kept copy of the manga
         * database, so a reader who restored the wrong pair had only the reader half to recover.
         *
         * This is the same shape as lesson 253, where a cleanup deleted something that had been there
         * all along because the baseline was narrower than the thing it protected.
         */
        $this->assertArrayHasKey(
            'suwayomi',
            $result->rollback,
            'the manga half has to be kept too, or a reader who restores the wrong backup has only '
            .'one of their two databases back',
        );
        $this->assertSame(
            'LIVE-SUWAYOMI',
            (string) file_get_contents($result->rollback['suwayomi']['database.mv.db'] ?? ''),
            'the kept manga half is not the file that was there before',
        );
    }

    /**
     * Restoring the same pair twice is the same answer both times.
     *
     * Not because idempotence is interesting, but because a restore that only works on a clean
     * install is a restore that cannot be retried after a failure, and retrying is what a reader
     * does.
     */
    public function test_restoring_twice_is_the_same_answer_both_times(): void
    {
        $live = $this->writeLive();
        $pair = $this->writePair();

        $this->restorer()->restore($pair, $live);
        $first = (string) file_get_contents($live['papyrus']['target']);

        $this->restorer()->restore($pair, $live);
        $second = (string) file_get_contents($live['papyrus']['target']);

        $this->assertSame($first, $second);
        $this->assertSame('PAPYRUS-SIDECAR-867-21447', $second);
    }
}
