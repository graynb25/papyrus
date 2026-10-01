<?php

namespace App\Migration;

use App\Models\MigrationSearch;
use App\Models\SeriesMigration;
use App\Models\Source;
use App\Models\User;
use App\Support\ReadingState;
use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SuwayomiException;
use Illuminate\Support\Facades\DB;

/**
 * Moves a series from one source to another, carrying the reader's state with it.
 *
 * ## The destination is checked against what was offered
 *
 * The action takes a manga id from the browser, so it takes the reader's word for
 * which source to move to unless something verifies it. This class verifies it: the
 * destination must be one of the candidates in a search this reader ran for this
 * series. That is the same rule the extension trust decision follows, enforced in the
 * action rather than only hidden in the template, because a hidden control is not a
 * boundary.
 *
 * ## The whole thing is one transaction
 *
 * A migration touches the series row, every chapter row, and the shelves, and a
 * half-applied one is worse than a refused one: a reader whose read state moved for
 * half their chapters and not the rest has no way to tell. So the state work is
 * delegated to {@see ReadingState::moveSeries()}, which wraps it, and the record of
 * what happened is written inside the same transaction as the state it describes.
 *
 * ## Downloads are left where they are
 *
 * A deliberate decision rather than an oversight. Pages on disk are keyed to the old
 * chapter ids, and the reader's page URL carries the new manga id, so those pages
 * become unreachable. They are still on disk, still holding the reader's place, and
 * the download centre offers to delete them. A reader who downloaded chapters to
 * read on a train should not lose that copy because they moved the series.
 *
 * Nothing here touches the download rows. They keep the old manga id, which is what
 * lets the centre find them with one lookup and label them as stranded.
 */
class SeriesMigrator
{
    public function __construct(
        private readonly SeriesGateway $series,
        private readonly ChapterMapper $mapper,
        private readonly ReadingState $state,
    ) {}

    /**
     * Attempt a migration.
     *
     * @return array{ok: bool, reason: string|null, migration: SeriesMigration|null}
     */
    public function migrate(User|int $user, int $fromMangaId, int $toMangaId): array
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        if ($fromMangaId === $toMangaId) {
            return $this->refuse(__('That is the series it is already on.'));
        }

        $candidate = $this->offeredCandidate($userId, $fromMangaId, $toMangaId);

        if ($candidate === null) {
            return $this->refuse(__('That source was not one of the options for this series.'));
        }

        try {
            $from = $this->series->find($fromMangaId);
            $to = $this->series->find($toMangaId);
        } catch (SuwayomiException $e) {
            return $this->refuse($e->getMessage());
        }

        if ($from === null) {
            return $this->refuse(__('The server no longer knows the series you are moving.'));
        }

        if ($to === null) {
            return $this->refuse(__('The server could not read the series you chose.'));
        }

        /*
         * The destination's chapter list has to exist before anything is moved. The
         * candidate search already fetched it, so this is normally the cheap read, but
         * a source can lose its listing between searching and confirming and a
         * migration onto an empty series would silently unfile the reader's history.
         */
        if (($to['chapters']['totalCount'] ?? 0) === 0) {
            try {
                $refreshed = $this->series->refresh($toMangaId);

                if ($refreshed['error'] !== null || $refreshed['series'] === null) {
                    return $this->refuse(__('That source lists no chapters for it, so there is nothing to move to.'));
                }

                $to = $refreshed['series'];
            } catch (SuwayomiException $e) {
                return $this->refuse($e->getMessage());
            }
        }

        $mapping = $this->mapper->map(
            $from['chapters']['nodes'] ?? [],
            $to['chapters']['nodes'] ?? [],
        );

        if ($mapping['map'] === []) {
            return $this->refuse(__('None of this reader\'s chapters could be matched to the new source, so nothing would carry over.'));
        }

        $result = DB::transaction(function () use ($userId, $fromMangaId, $toMangaId, $from, $candidate, $mapping): SeriesMigration {
            $moved = $this->state->moveSeries($userId, $fromMangaId, $toMangaId, $mapping['map']);

            unset($moved);

            return SeriesMigration::updateOrCreate(
                ['user_id' => $userId, 'from_manga_id' => $fromMangaId],
                [
                    'to_manga_id' => $toMangaId,
                    'from_source_id' => (string) $from['source']['id'],
                    'to_source_id' => (string) $candidate['source_id'],
                    'from_source_name' => (string) $from['source']['name'],
                    'to_source_name' => (string) $candidate['source_name'],
                    'chapters_total' => (int) $mapping['total'],
                    'chapters_carried' => count($mapping['map']),
                    'unmatched_chapters' => $mapping['unmatched'],
                ],
            );
        });

        return ['ok' => true, 'reason' => null, 'migration' => $result];
    }

    /**
     * The destination, but only if a search on this reader's behalf offered it.
     *
     * The newest search wins, because that is the one the page rendered. An older
     * search's candidates are not accepted, since a source that has since dropped the
     * series would still pass the check.
     *
     * @return array<string, mixed>|null
     */
    private function offeredCandidate(int $userId, int $fromMangaId, int $toMangaId): ?array
    {
        $search = MigrationSearch::forUser($userId)
            ->where('from_manga_id', $fromMangaId)
            ->where('state', MigrationSearch::READY)
            ->orderByDesc('id')
            ->first();

        if ($search === null) {
            return null;
        }

        foreach ($search->candidates as $candidate) {
            if ((int) ($candidate['manga_id'] ?? 0) === $toMangaId) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{ok: false, reason: string, migration: null}
     */
    private function refuse(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'migration' => null];
    }

    /**
     * Where a series came from, for the series page to show.
     */
    public function lastMigrationFor(User|int $user, int $toMangaId): ?SeriesMigration
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        return SeriesMigration::forUser($userId)
            ->where('to_manga_id', $toMangaId)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * The source a series' downloads came from, if it has been moved.
     *
     * @return array{manga_id: int, source_name: string, now_on: int}|null
     */
    public function strandedDownloads(User|int $user, int $mangaId): ?array
    {
        $migration = $this->lastMigrationFor($user, $mangaId);

        if ($migration === null) {
            return null;
        }

        return [
            'manga_id' => (int) $migration->from_manga_id,
            'source_name' => (string) $migration->from_source_name,
            'now_on' => (int) $migration->to_manga_id,
        ];
    }
}
