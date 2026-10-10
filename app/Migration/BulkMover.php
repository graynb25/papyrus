<?php

namespace App\Migration;

use App\Models\BulkMigration;
use App\Models\BulkMigrationItem;
use App\Models\SeriesMigration;
use Illuminate\Support\Collection;

/**
 * Carries out the moves a reader ticked, one series at a time.
 *
 * ## Why each move is its own transaction
 *
 * {@see SeriesMigrator::migrate()} already wraps one series in a transaction, and that is the
 * right granularity here too. A run of eighty moves is not one atomic operation and should not
 * pretend to be: a reader whose read state moved for forty series and not the other forty has
 * no way to tell, and cannot undo the forty. One at a time means a failure at the fortieth
 * leaves thirty-nine good moves that stand and are reported, which is a state a reader can
 * reason about and act on.
 *
 * ## Why approval is re-verified here and not trusted from the browser
 *
 * For the same reason the wizard re-checks it. The list arrives from the browser, so it says
 * which series to move and where to; it does not get to say that a destination was legitimately
 * offered. {@see SeriesMigrator} looks that up itself, so a value tampered with in the request
 * is refused there rather than trusted here. This class adds a second check on top, that the
 * pair came from a run this reader owns, which is a different question from whether the
 * destination was offered.
 *
 * ## Why nothing is capped
 *
 * Because the reader ticked each one. A cap would silently move a subset of what was asked
 * for, and the page would report a number the reader never chose. The work is bounded by how
 * many series are in the library, which the reader can see.
 */
class BulkMover
{
    public function __construct(private readonly SeriesMigrator $migrator) {}

    /**
     * Move the ticked series, reporting each outcome rather than the first failure.
     *
     * @param  Collection<int, int>  $approvals  from_manga_id to to_manga_id
     * @return array{moved: int, refused: int, chapters_carried: int, reasons: array<int, string>}
     */
    public function move(BulkMigration $run, Collection $approvals): array
    {
        $tally = ['moved' => 0, 'refused' => 0, 'chapters_carried' => 0, 'reasons' => []];

        foreach ($approvals as $fromMangaId => $toMangaId) {
            $fromMangaId = (int) $fromMangaId;
            $toMangaId = (int) $toMangaId;

            /*
             * The item has to belong to this run, and this run has to belong to this reader.
             *
             * Two lookups rather than trusting the pair: the second is what stops a request
             * naming another reader's series, and the first stops it moving a series through a
             * run whose comparison the reader never saw.
             */
            $item = BulkMigrationItem::query()
                ->forUser($run->user_id)
                ->where('bulk_migration_id', $run->id)
                ->where('from_manga_id', $fromMangaId)
                ->first();

            if ($item === null || (int) $item->to_manga_id !== $toMangaId) {
                $tally['refused']++;

                continue;
            }

            if (! $item->isOfferable()) {
                // Already moved, or already decided, by an earlier press of the same button.
                $tally['refused']++;

                continue;
            }

            $outcome = $this->migrator->migrate($run->user_id, $fromMangaId, $toMangaId);

            if (! $outcome['ok']) {
                $item->update([
                    'status' => BulkMigrationItem::REFUSED,
                    'reason' => (string) $outcome['reason'],
                ]);

                $tally['refused']++;
                $tally['reasons'][$fromMangaId] = (string) $outcome['reason'];

                continue;
            }

            /** @var SeriesMigration $migration */
            $migration = $outcome['migration'];

            $item->update([
                'status' => BulkMigrationItem::MOVED,
                'reason' => null,
            ]);

            $tally['moved']++;
            $tally['chapters_carried'] += (int) $migration->chapters_carried;
        }

        return $tally;
    }
}
