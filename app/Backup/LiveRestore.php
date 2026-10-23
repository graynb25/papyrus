<?php

namespace App\Backup;

use App\Import\ImportRefused;
use App\Import\Tachibk\TachibkReader;
use App\Suwayomi\BackupGateway;
use App\Suwayomi\FailureKind;
use App\Suwayomi\SuwayomiException;
use Papyrus\Launcher\BackupPair;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * One live restore: both halves of a verified pair put back through their own doors, each
 * while the service keeps answering.
 *
 * ## The arrangement, in the order it must happen
 *
 * 1. Both halves are verified again, inside the run: the reader half's file against its own
 *    integrity check and its own manifest counts, the manga half through
 *    {@see TachibkReader} so a file that is not the Mihon format is refused before a single
 *    series moves. The pair's manifest is the only authority for what its halves are: counts
 *    read from Suwayomi while a restore runs would count its cached imports, not this pair.
 * 2. The manga half goes to the server first, along {@see BackupGateway::importBackup()}.
 *    The route is synchronous, so when it answers the server side has already settled, and
 *    an answer refusing the file leaves the reader half alone: a server side refusal is the
 *    shape where both halves come out of the run untouched.
 * 3. The reader half is swapped in second, through {@see ReaderHalfSwap}'s one brief
 *    committed point. A refusal there, after the manga half has already been restored, is
 *    the one in-flight state this run can be in, and the job that calls this records it the
 *    way {@see RunLiveRestore} documents: the restore run's own retry completes it.
 *
 * ## Why the counts come from the pair, and only from the pair
 *
 * The honest-language rule this feature was written under: a restore confirm names numbers
 * the pair itself can prove, from its manifest and its files. The server's own
 * `totalManga` reads from its library cache and counts browse entries the file carries
 * without series the app tracks, so it is the restore progress bar's vocabulary and never
 * the diff's.
 */
final class LiveRestore
{
    public function __construct(
        private readonly BackupGateway $manga,
        private readonly ReaderHalfSwap $reader,
    ) {}

    /**
     * Put a verified pair back.
     *
     * @param  (callable(string $stage, string $words): void)|null  $note  One line per stage, written for the page the reader is watching.
     *
     * @throws SuwayomiException
     * @throws ReaderHalfRefused
     */
    public function run(BackupPair $pair, ?callable $note = null): LiveRestoreResult
    {
        $say = $note ?? static function (string $stage, string $words): void {};

        $counts = $this->verifyReaderHalf($pair->pathOf('papyrus'), $pair, $say);
        $summary = $this->verifyMangaHalf($pair->pathOf('suwayomi'), $say);

        /*
         * Manga first, so a refusal from the server answers while the reader half is still
         * exactly what it was. The route holds the whole flight, so this call returns once
         * the server has already restored, whatever it made of the file.
         */
        $say('manga', 'Sending your manga data to the manga server.');

        $restoreSummary = $this->manga->importBackup($pair->pathOf('suwayomi'));

        $say('manga', sprintf(
            'Manga data restored. %d sources, %d trackers and %d series were not found.',
            count($restoreSummary['missingSources']),
            count($restoreSummary['missingTrackers']),
            count($restoreSummary['mangasMissingSources']),
        ));

        $say('reader_swap', 'Restoring your library.');

        $this->reader->swap($pair->pathOf('papyrus'), static function (string $words) use ($say): void {
            $say('reader_swap', $words);
        });

        $say('done', sprintf(
            'Restore complete: %d series and %d %s are back, along with your manga data.',
            $counts['series'],
            $counts['chapters'],
            $counts['chapters'] === 1 ? 'chapter' : 'chapters',
        ));

        return new LiveRestoreResult(
            token: $pair->token,
            seriesStates: $counts['series'],
            chapterStates: $counts['chapters'],
            mangaSummary: $summary,
            suwayomiVersion: $pair->suwayomiVersion,
            restoreSummary: $restoreSummary,
        );
    }

    /**
     * Prove the pair's reader half is a file that can be put back, before anything moves.
     *
     * The authority is the pair's own manifest count from {@see BackupPair}: a file whose
     * rows disagree with the numbers its own manifest records is either not the file that
     * was backed up or is broken, and either is a refusal here rather than a surprise
     * during the few milliseconds the run cannot take back.
     *
     * @return array{series: int, chapters: int}
     *
     * @throws ReaderHalfRefused
     */
    private function verifyReaderHalf(string $target, BackupPair $pair, callable $say): array
    {
        if (! is_file($target)) {
            throw new ReaderHalfRefused("Your library is missing from this backup: {$target}");
        }

        $say('reader_half', 'Checking your library before restoring it.');

        $copy = new PDO('sqlite:'.$target);
        $copy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        /*
         * integrity_check reads every page. The same check the backup's stager ran against
         * its own staged half, for the same reason: a disk that failed silently under a
         * write leaves a short file, and this is the moment to refuse rather than a swap
         * that puts a half-readable database into the live seat.
         */
        $integrity = (string) $this->query($copy, 'PRAGMA integrity_check', 'its integrity check could not be run')->fetchColumn();

        if ($integrity !== 'ok') {
            throw new ReaderHalfRefused(sprintf(
                'Your library copy failed its integrity check: %s.',
                $integrity,
            ));
        }

        $staged = [
            'series' => (int) $this->query($copy, 'select count(*) from series_states', 'its series states could not be counted')->fetchColumn(),
            'chapters' => (int) $this->query($copy, 'select count(*) from chapter_states', 'its chapter states could not be counted')->fetchColumn(),
        ];

        if ($staged['series'] !== $pair->seriesStates
            || $staged['chapters'] !== $pair->chapterStates
        ) {
            throw new ReaderHalfRefused(sprintf(
                'The library copy does not match the backup details: it has '
                .'%d series and %d chapters, but the backup says %d and %d.',
                $staged['series'],
                $staged['chapters'],
                $pair->seriesStates,
                $pair->chapterStates,
            ));
        }

        $say('reader_half', sprintf(
            'Your library has %d series and %d chapters, and the backup checks out.',
            $staged['series'],
            $staged['chapters'],
        ));

        return $staged;
    }

    /**
     * Read the manga half back through the format's own reader before asking the server.
     *
     * @return array<string, int|string|null> {@see ImportedBackup::summary()} for the pair's file.
     *
     * @throws SuwayomiException
     */
    private function verifyMangaHalf(string $target, callable $say): array
    {
        if (! is_file($target)) {
            throw new SuwayomiException(
                "Your manga data is missing from this backup: {$target}",
                FailureKind::Unavailable,
            );
        }

        try {
            $summary = (new TachibkReader)->read($target)->summary();
        } catch (ImportRefused $e) {
            throw new SuwayomiException(
                'This backup does not contain manga data Papyrus can restore: '.$e->getMessage(),
                FailureKind::Unavailable,
                previous: $e,
            );
        }

        $say('manga_checked', sprintf(
            'The backup contains %d series and %d chapters, with %d already read.',
            $summary['library_series'],
            $summary['chapters'],
            $summary['chapters_read'],
        ));

        return $summary;
    }

    /**
     * Run a query that takes no parameters, refusing a driver failure rather than letting it
     * surface. The same seam as {@see LiveBackup::query()}, because there is no second
     * honest way to answer a driver that refused.
     *
     * @throws RuntimeException
     */
    private function query(PDO $pdo, string $sql, string $what): PDOStatement
    {
        $statement = $pdo->query($sql);

        if ($statement === false) {
            throw new RuntimeException("The pair's reader half refused a query, so {$what}: {$sql}");
        }

        return $statement;
    }
}
