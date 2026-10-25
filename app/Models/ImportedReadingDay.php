<?php

namespace App\Models;

use App\Import\ReadingDay;
use App\Models\Concerns\OwnedModel;
use App\Support\ReadingInsights;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One series' reading time on one day, carried in from another app's backup.
 *
 * ## The row is keyed by the file it came from
 *
 * A reader who imports the same backup twice asked for the same reading time, not
 * twice as much of it. {@see replaceFor()} deletes the file's rows for one series and
 * writes the days it now describes, inside the writer's per-series transaction, so a
 * re-import is a replacement and an interrupted one cannot leave half a series behind.
 *
 * Two different exports can hold the same day, because a second export contains the
 * first one's history. The rows are not merged at write time: the backup that made a
 * newer claim should not silently rewrite the older one. {@see ReadingInsights}
 * answers at read time with the largest value for each series and day, which is the one
 * a reader sees whether they imported the newer file before or after the older one.
 *
 * @property int $id
 * @property int $user_id
 * @property string $backup_key
 * @property int $suwayomi_manga_id
 * @property string $day A plain `Y-m-d`, deliberately not a datetime.
 * @property int $seconds
 */
#[Fillable([
    'backup_key',
    'suwayomi_manga_id',
    'day',
    'seconds',
])]
class ImportedReadingDay extends OwnedModel
{
    protected function casts(): array
    {
        return [
            'suwayomi_manga_id' => 'integer',
            /*
             * `day` is not cast to a date on purpose. Laravel writes a date cast as
             * `Y-m-d H:i:s`, and this column is a calendar day: a value carrying a
             * midnight timestamp compares equal to `Y-m-d` in SQLite but is not equal
             * in the unique index, so two writes of one day could both land. The
             * ledger stores the day exactly as `Y-m-d` and the value object owns the
             * date arithmetic.
             */
            'seconds' => 'integer',
        ];
    }

    /**
     * Replace one series' imported days for one backup file.
     *
     * A series that resolved but whose file carries no reading time still calls this
     * with an empty collection: the delete is the point, because a file that used to
     * carry days for the series and no longer does must not leave the old ones behind.
     *
     * @param  Collection<int, ReadingDay>  $days
     */
    public static function replaceFor(User|int $user, string $backupKey, int $mangaId, Collection $days): void
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        DB::transaction(function () use ($userId, $backupKey, $mangaId, $days): void {
            static::query()
                ->forUser($userId)
                ->where('backup_key', $backupKey)
                ->where('suwayomi_manga_id', $mangaId)
                ->delete();

            if ($days->isEmpty()) {
                return;
            }

            $now = now();

            $rows = $days
                ->map(fn (ReadingDay $day): array => [
                    'user_id' => $userId,
                    'backup_key' => $backupKey,
                    'suwayomi_manga_id' => $mangaId,
                    'day' => $day->day->toDateString(),
                    'seconds' => $day->seconds,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->values()
                ->all();

            // A backup can carry years of daily rows for one series, and SQLite's
            // bound-parameter ceiling is 999 by default. Chunking keeps a long
            // history one insert statement per few hundred days.
            foreach (array_chunk($rows, 500) as $chunk) {
                static::query()->insert($chunk);
            }
        });
    }
}
