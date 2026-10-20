<?php

namespace App\Backup;

use App\Import\BackupReader;
use App\Import\ImportRefused;
use App\Import\Tachibk\TachibkReader;
use App\Import\Tachimanga\TachimangaReader;
use App\Support\Versions;
use App\Suwayomi\BackupGateway;
use App\Suwayomi\FailureKind;
use App\Suwayomi\Gateway;
use App\Suwayomi\SuwayomiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Papyrus\Launcher\BackupPair;
use Papyrus\Launcher\RestoreRefused;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * One live backup: both halves, each taken while its service keeps running, staged, verified,
 * and renamed into the backups folder only once the pair is one.
 *
 * ## The arrangement, in the order it must happen
 *
 * 1. The reader half is captured into staging by {@see ReaderHalfCapture}, the same one
 *    statement the daily half uses, and verified against the live database row by row.
 * 2. The manga half is Suwayomi's own fresh export through {@see BackupGateway}, restored later
 *    through Suwayomi's `restoreBackup` mutation and never through a file copy, and read back
 *    with {@see TachibkReader} before anything is believed.
 * 3. The manifest is written with {@see PairManifest} and read back through the launcher's own
 *    {@see BackupPair::read()}. Whichever of the two writers drifts, the single reader refuses,
 *    so the drift fails a run in staging instead of failing a restore years later.
 * 4. Only then are the files renamed into the backups folder, manifest last, because a manifest
 *    naming one half is exactly the shape {@see BackupPair} refuses. Staging sits inside the
 *    backups folder on the same drive, so every rename is atomic and the folder globs that list
 *    pairs on the page never see a staging file.
 *
 * ## Whole run, or nothing
 *
 * Any stage failure discards staging and rethrows. A left behind half file in a backups folder
 * is exactly the shape "looks like a backup, restores as an empty library" was about, so this
 * run keeps nothing half finished, not even on disk.
 *
 * The optional downloads folder is the reader's own choice, made on a page that names the size
 * before the press. It zips inside the same run, so it lives and dies with the run: if it
 * cannot be read at the moment of the press, the run is refused rather than recording a pair
 * whose manifest sits beside a downloads zip it cannot say anything about.
 */
final class LiveBackup
{
    /** The half names are the launcher pair's, right down to the extensions. */
    private const READER_PREFIX = 'papyrus-';

    private const MANGA_PREFIX = 'suwayomi-';

    private const MANIFEST_PREFIX = 'backup-';

    private const DOWNLOADS_PREFIX = 'downloads-';

    public function __construct(
        private readonly Gateway $gateway,
        private readonly BackupGateway $manga,
        private readonly Versions $versions,
        private readonly DownloadsFolder $downloads,
    ) {}

    /**
     * Take the pair.
     *
     * @param  bool  $includeDownloads  Zip Suwayomi's own downloads folder into the pair. The default is off.
     * @param  (callable(string $stage, string $words): void)|null  $note  One line per stage, written for the page the reader is watching.
     *
     * @throws SuwayomiException
     * @throws ReaderHalfRefused
     */
    public function run(bool $includeDownloads = false, ?callable $note = null): LiveBackupResult
    {
        $say = $note ?? static function (string $stage, string $words): void {};

        $token = gmdate('Ymd-His');
        $library = BackupLibrary::forThisInstall();
        $staging = $library->directory().DIRECTORY_SEPARATOR.'staging';

        /*
         * Stale staging is removed before anything starts, and on any stage failure, whole
         * run means whole run: a half file that survived a failed run would be a file that
         * looks like a backup and sits where a reader looks for one.
         */
        $this->removeStaging($staging);
        File::ensureDirectoryExists($staging);

        $staged = [
            'reader' => $staging.DIRECTORY_SEPARATOR.self::READER_PREFIX.$token.'.sqlite',
            'manga' => $staging.DIRECTORY_SEPARATOR.self::MANGA_PREFIX.$token.'.tachibk',
            'manifest' => $staging.DIRECTORY_SEPARATOR.self::MANIFEST_PREFIX.$token.'.json',
        ];

        if ($includeDownloads) {
            $staged['downloads'] = $staging.DIRECTORY_SEPARATOR.self::DOWNLOADS_PREFIX.$token.'.zip';
        }

        /*
         * The manifest renames last, so the pieces leave staging in a fixed order described
         * here once rather than spelled out again at every call site.
         */
        $renamedOrder = ['reader', 'manga'];

        if ($includeDownloads) {
            $renamedOrder[] = 'downloads';
        }

        $renamedOrder[] = 'manifest';

        try {
            /*
             * Asked first, because the manifest binds the manga half to the version and
             * BackupPair refuses a manifest whose server version cannot be read. Failing here
             * has captured nothing and so answers the reader moment by moment rather than
             * taking a reader half that then has to be thrown away.
             */
            $version = $this->versions->runningSuwayomi($this->gateway);

            if ($version === null) {
                throw new SuwayomiException(
                    'Suwayomi did not answer what version it is, so the manifest cannot be '
                    .'written and the run was refused with nothing taken.',
                    FailureKind::Unavailable,
                );
            }

            $say('version', 'Suwayomi is '.$version);

            $counts = $this->readerHalf($staged['reader'], $say);

            $downloadsBytes = null;

            if ($includeDownloads) {
                $downloadsBytes = $this->downloadsHalf($staged['downloads'], $say);
            }

            $summary = $this->mangaHalf($staged['manga'], $say);

            $this->manifestHalf($token, $staged, $counts, $version, $say);
            $this->rename($staged, $renamedOrder, $library->directory(), $say);

            /*
             * The renames emptied staging of files; the folder itself goes too, so a finished
             * run leaves the backups folder with the pair and nothing else. Renaming a file
             * out does not remove its directory, and an empty folder left behind would be
             * this run's residue sitting where the next run's staging would be named.
             */
            $this->removeStaging($staging);

            $say('done', sprintf(
                '%d series states and %d chapter states against Suwayomi %s, taken without stopping anything',
                $counts['series'],
                $counts['chapters'],
                $version,
            ));

            return new LiveBackupResult(
                token: $token,
                seriesStates: $counts['series'],
                chapterStates: $counts['chapters'],
                mangaSummary: $summary,
                suwayomiVersion: $version,
                downloadsBytes: $downloadsBytes,
                directory: $library->directory(),
            );
        } catch (Throwable $e) {
            $this->removeStaging($staging);

            throw $e;
        }
    }

    /**
     * Capture the reader half into staging and prove it is a copy of this moment.
     *
     * The counts come from the staged file and the live database through two different
     * readings, because a copy that opens is not a copy that matches: a file with the right
     * name and fewer rows than the database it claims to be is the empty library lie the
     * whole feature exists to prevent.
     *
     * @return array{series: int, chapters: int}
     *
     * @throws ReaderHalfRefused
     */
    private function readerHalf(string $target, callable $say): array
    {
        $say('reader_half', basename($target).', one VACUUM INTO statement against the live database, nothing stopped');

        (new ReaderHalfCapture)->capture($target);

        $say('reader_half', basename($target).' is staged, checking what it held against the live database');

        return $this->verifyReaderHalf($target, $say);
    }

    /**
     * Prove the staged reader half is a copy of the live database at this moment.
     *
     * The staged file is read through its own connection and the live database through the
     * app's, because a copy that opens is not a copy that matches: a file with the right
     * name and fewer rows than the database it claims to be is the empty library lie the
     * whole feature exists to prevent. Separate from the capture so a test can stage both
     * ends honestly and invoke exactly this comparison.
     *
     * @return array{series: int, chapters: int}
     */
    private function verifyReaderHalf(string $target, callable $say): array
    {
        $copy = new PDO('sqlite:'.$target);
        $copy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        /*
         * integrity_check reads every page of the copy. A `VACUUM INTO` output does not leave
         * a backup page short, but a disk that failed silently underneath the statement does,
         * and this is the moment to find that rather than a restore.
         */
        $integrity = (string) $this->query(
            $copy,
            'PRAGMA integrity_check',
            'its integrity check could not be run',
        )->fetchColumn();

        if ($integrity !== 'ok') {
            throw new RuntimeException(sprintf(
                'The staged reader half failed its own integrity check, reporting: %s.',
                $integrity,
            ));
        }

        $staged = [
            'series' => (int) $this->query(
                $copy,
                'select count(*) from series_states',
                'its series states could not be counted',
            )->fetchColumn(),
            'chapters' => (int) $this->query(
                $copy,
                'select count(*) from chapter_states',
                'its chapter states could not be counted',
            )->fetchColumn(),
        ];
        $live = [
            'series' => (int) DB::table('series_states')->count(),
            'chapters' => (int) DB::table('chapter_states')->count(),
        ];

        if ($staged !== $live) {
            throw new RuntimeException(sprintf(
                'The staged reader half was not verified: it carries %d series states and %d '
                .'chapter states, and the live database holds %d and %d, so it is not a copy '
                .'of this moment.',
                $staged['series'],
                $staged['chapters'],
                $live['series'],
                $live['chapters'],
            ));
        }

        $say('reader_half', sprintf(
            '%d series states and %d chapter states, the same counts the live database holds',
            $staged['series'],
            $staged['chapters'],
        ));

        return $staged;
    }

    /**
     * Zip Suwayomi's own downloads folder into the run, when the reader asked for it.
     *
     * The folder is the one {@see DownloadsFolder} names, so the path the page measured
     * for the reader before the press and the path this zips are one answer, not two.
     *
     * @throws SuwayomiException
     */
    private function downloadsHalf(string $target, callable $say): int
    {
        try {
            $path = $this->downloads->path();
        } catch (SuwayomiException $unanswerable) {
            throw new SuwayomiException(
                $unanswerable->getMessage().' The run asking for it was refused with nothing taken.',
                FailureKind::Unavailable,
                previous: $unanswerable,
            );
        }

        $files = [];
        $bytes = 0;

        /** @var \SplFileInfo $entry */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isFile()) {
                $files[] = $entry->getPathname();
                $bytes += $entry->getSize();
            }
        }

        $say('downloads', sprintf(
            '%d files holding %s bytes from the downloads folder, packing them',
            count($files),
            number_format($bytes),
        ));

        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("The downloads zip could not be written: {$target}");
        }

        /*
         * Entry names are relative to the downloads folder itself. A reader unstaging this
         * later gets their own folder back, and no install's absolute paths ride inside the
         * file, though they would say nothing about this machine they are still paths a
         * backup folder has no business carrying.
         */
        $root = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $prefixLength = strlen($root);

        foreach ($files as $from) {
            $in = str_replace('\\', '/', substr($from, $prefixLength));

            if ($zip->addFile($from, $in) !== true) {
                $zip->close();
                @unlink($target);

                throw new RuntimeException("A downloads file refused to be zipped: {$from}");
            }
        }

        if ($zip->close() !== true) {
            @unlink($target);

            throw new RuntimeException("The downloads zip could not be finished: {$target}");
        }

        $say('downloads', basename($target).' written, zipping finished.');

        return $bytes;
    }

    /**
     * Take Suwayomi on its fresh export and read it back to know what it holds.
     *
     * The download is streamed before anything is verified, because a refusal named against a
     * partial body would explain other failure and this one. Then the walk. {@see
     * TachibkReader::read()} reads through its own {@see BackupReader::recognises()}
     * gate, so an export that is not the Mihon format is refused in this reader's own words,
     * and what survives answers its real summary: series, chapters, read chapters, categories.
     *
     * @return array<string, int|string|null>
     *
     * @throws SuwayomiException
     */
    private function mangaHalf(string $target, callable $say): array
    {
        $say('manga_half', 'asking Suwayomi to write its own fresh export');

        $path = $this->manga->createBackup();

        $say('manga_half', sprintf('%s answered, fetching it into staging', basename($path)));

        $this->manga->download($path, $target);

        $say('manga_half', basename($target).' downloaded from the stream, walking it back');

        try {
            $summary = (new TachibkReader)->read($target)->summary();
        } catch (ImportRefused $e) {
            throw new SuwayomiException(
                'The downloaded manga half does not read as a Mihon or Suwayomi backup, so it '
                .'cannot be one half of a pair: '.$e->getMessage(),
                FailureKind::Unavailable,
                previous: $e,
            );
        }

        $say('manga_checked', sprintf(
            '%s holds %d series, %d chapters, %d of them read',
            basename($target),
            $summary['library_series'],
            $summary['chapters'],
            $summary['chapters_read'],
        ));

        return $summary;
    }

    /**
     * Write the manifest for a staged pair, and read it back through the launcher's reader.
     *
     * The readback is the anti drift seal: PairManifest writes the launcher's key shape, and
     * the same class the next restore uses reads it back before anything leaves staging. A
     * cross gone wrong, a digest mismatched to its file, a version that became an empty
     * string, any of those is refused here on the staged copy rather than at a restore.
     *
     * @param  array<string, string>  $staged
     * @param  array{series: int, chapters: int}  $counts
     *
     * @throws RestoreRefused
     */
    private function manifestHalf(string $token, array $staged, array $counts, string $version, callable $say): void
    {
        PairManifest::write(
            $staged['manifest'],
            $token,
            $staged['reader'],
            $counts['series'],
            $counts['chapters'],
            $staged['manga'],
            $version,
        );

        $pair = BackupPair::read($staged['manifest'], dirname($staged['manifest']));

        if ($pair->token !== $token
            || $pair->papyrusSha256 !== hash_file('sha256', $staged['reader'])
            || $pair->suwayomiSha256 !== hash_file('sha256', $staged['manga'])
            || $pair->seriesStates !== $counts['series']
            || $pair->chapterStates !== $counts['chapters']
            || $pair->suwayomiVersion !== $version
        ) {
            throw new RuntimeException(
                'The manifest just written did not read back as the pair just staged, so it '
                .'cannot be trusted to bind the two halves.',
            );
        }

        $say('manifest', basename($staged['manifest']).' written and read back through BackupPair, naming both halves');
    }

    /**
     * Move the staged pieces into the backups folder, manifest last.
     *
     * Manifest last, because a manifest that named one half is the shape a restore refuses
     * with a library that reads as empty its warning. A refusal here puts back everything
     * already renamed before the run fails, so a half-introduced pair is never what a reader
     * finds.
     *
     * @param  array<string, string>  $staged
     * @param  list<string>  $order
     */
    private function rename(array $staged, array $order, string $into, callable $say): void
    {
        File::ensureDirectoryExists($into);

        $moved = [];

        try {
            foreach ($order as $which) {
                $from = $staged[$which];
                $to = $into.DIRECTORY_SEPARATOR.basename($from);

                /*
                 * The token comes from the clock, so two runs inside one second name the same
                 * files. Refused rather than overwritten: the first pair is a backup worth
                 * keeping and the second is its own moment, and overwriting one with the
                 * other answers neither reader.
                 */
                if (is_file($to)) {
                    throw new RuntimeException("{$to} is already there, so a second run inside the same second would overwrite it. The run was refused, and the pair already recorded stands.");
                }

                if (! rename($from, $to)) {
                    throw new RuntimeException("The staged file refused its rename: {$from} to {$to}");
                }

                $moved[] = [$from, $to];
            }
        } catch (RuntimeException $putBack) {
            foreach ($moved as [$from, $to]) {
                if (! rename($to, $from)) {
                    throw new RuntimeException("A renamed piece could not be put back into staging after a later rename failed: {$to}");
                }
            }

            throw $putBack;
        }

        $say('renamed', implode(', ', array_map(
            static fn (string $which): string => basename($staged[$which]),
            $order,
        )).' renamed next to their manifest, staging emptied');
    }

    private function removeStaging(string $staging): void
    {
        if (is_dir($staging)) {
            File::deleteDirectory($staging);
        }
    }

    /**
     * Run a query that takes no parameters, refusing a driver failure rather than letting it
     * surface.
     *
     * `PDO::query()` is typed `PDOStatement|false`. With `ERRMODE_EXCEPTION` set it raises
     * rather than returning false, so this is not expected to fire, and that is exactly why
     * it is worth a sentence if it ever does: a false return would otherwise reach a
     * `->fetchColumn()` call on a bool and surface as a TypeError naming an internal method
     * rather than anything about the staged half.
     *
     * The same seam as {@see TachimangaReader::query()}, because there
     * is no second honest way to answer a driver that refused.
     *
     * @throws RuntimeException
     */
    private function query(PDO $pdo, string $sql, string $what): PDOStatement
    {
        $statement = $pdo->query($sql);

        if ($statement === false) {
            throw new RuntimeException("The staged reader half refused a query, so {$what}: {$sql}");
        }

        return $statement;
    }
}
