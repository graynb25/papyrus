<?php

namespace App\Jobs;

use App\Library\CheckLedger;
use App\Models\SeriesState;
use App\Support\ReadingState;
use App\Suwayomi\SeriesGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Asks the source for a series' chapter list, in the background, so a series page is never
 * blank until a reader presses Refresh.
 *
 * ## Why this exists
 *
 * `SeriesGateway::find()` reads Suwayomi's cache. `fetchMangaAndChapters` is what fills it. So a
 * series reached from search or browse, which has never been fetched, arrived with no chapters
 * and status UNKNOWN, and the only way out was the Refresh chapters button. Measured, 19 of 20
 * titles on a source were in that state, so it was the common path and not an edge case.
 *
 * ## Why it cannot run inside the request
 *
 * Because a cold fetch is slow enough to be worth measuring. Asura Scans came in at 1.6s,
 * Weeb Central 4.6s, Manga Demon 1.9s, and MangaFire at **7.7s for 2,440 chapters**. Blocking
 * the page on that is a white screen for eight seconds on some titles, so the page renders with
 * a loading state and this fills it in.
 *
 * ## Why it fetches through {@see CheckLedger}
 *
 * Because that is the one code path that re-reads a series, and a second one would be a second
 * set of rules about chapter counts, fingerprints and the ledger row. It also writes the
 * chapter count the library page reads, so a series opened from browse ends up in the library
 * correctly rather than needing a second pass later.
 *
 * ## Why the claim is per reader and not per series
 *
 * This is the part that was argued out. Prefetching the whole grid sounds like it would make
 * things feel faster, and it does the opposite: there is **one** queue worker, so a grid of 20
 * covers prefetched at the measured median puts about 30 seconds, and at MangaFire's measured
 * worst case about 150 seconds, of somebody else's scraping in front of the one series the
 * reader actually clicked. A reader browsing twenty covers opens about two, which means
 * eighteen upstream requests spent to make the wanted one slower.
 *
 * So the claim is deliberately narrow: one chapter fetch in flight per reader, whatever the
 * series. Clicking a card *is* the intent signal, so the fetch is booked the moment the series
 * page mounts, and opening a second series while one is in flight does not stack behind it.
 * `Cache::add` makes the claim atomic, so two tabs racing do not both book.
 */
class FetchSeriesChapters implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt.
     *
     * A retry repeats the same upstream call and probably fails the same way, and the page is
     * waiting on it. The reader is offered the Refresh button instead, which is the retry they
     * can see and choose.
     */
    public int $tries = 1;

    /**
     * How long the claim survives a worker that dies mid-fetch.
     *
     * Not a timeout for the fetch, which the gateway's own budget governs. This is only so a
     * crashed worker cannot leave a reader staring at a loading state for ever: the claim
     * expires, the page stops claiming to be loading, and the Refresh button is there.
     */
    public const CLAIM_TTL_SECONDS = 180;

    /**
     * Whether the chapter list is missing, as opposed to only the metadata.
     *
     * `true` runs the full `fetchMangaAndChapters`, which is what a series with no chapters needs
     * and costs 1.6s to 7.1s measured. `false` runs `fetchManga` alone, which costs a flat 186ms
     * to 555ms measured and is what a series with chapters but an unknown status needs.
     *
     * The default is `true`, so a caller that does not think about it books the thorough one.
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $mangaId,
        public readonly bool $chapters = true,
    ) {}

    /**
     * The claim key for one reader's single in-flight chapter fetch.
     *
     * Public because the page reads it to decide whether to show a loading state, and the job
     * writes it, so the two must agree on the name.
     */
    public static function claimKey(int $userId): string
    {
        return "series:chapters:claim:{$userId}";
    }

    /**
     * Whether this reader can book a chapter fetch right now.
     *
     * The claim is taken by the caller rather than here, because a job that discovers its own
     * claim is taken has already cost a queue slot and a worker handover to find out.
     */
    public static function claim(int $userId): bool
    {
        return Cache::add(self::claimKey($userId), now()->timestamp, self::CLAIM_TTL_SECONDS);
    }

    public static function isClaimed(int $userId): bool
    {
        return Cache::has(self::claimKey($userId));
    }

    public static function release(int $userId): void
    {
        Cache::forget(self::claimKey($userId));
    }

    public function handle(CheckLedger $ledger): void
    {
        try {
            /*
             * The reader may have reached a series they have never touched, so there is no row
             * for it, and `CheckLedger::check()` throws on exactly that.
             *
             * The row is made here rather than on the page, because `ReadingState::series()`
             * returns an **unsaved** model and `loadOrCreate()` is a misnomer that does not save
             * either. Both read or build, neither persists. A page that called either one and
             * assumed a row now existed booked a job that threw on arrival, which the catch
             * below swallowed into a silent "no chapters" for every never-opened series.
             */
            $state = SeriesState::forUser($this->userId)
                ->where('suwayomi_manga_id', $this->mangaId)
                ->first();

            if ($state === null) {
                app(ReadingState::class)->series($this->userId, $this->mangaId)->save();
            }

            if ($this->chapters) {
                $ledger->check($this->userId, $this->mangaId, force: true);

                return;
            }

            /*
             * Metadata only, and straight past the ledger.
             *
             * The ledger's row is a chapter count and a chapter-list fingerprint. This call does
             * not read chapters, so routing through the ledger would mark the series as re-checked
             * on the strength of a call that never looked at the list, and the fingerprint would
             * keep agreeing with itself while saying nothing about staleness.
             */
            app(SeriesGateway::class)->refreshMetadata($this->mangaId);
        } catch (\Throwable $e) {
            /*
             * Swallowed on purpose, and this is not the usual reason to swallow something.
             *
             * `CheckLedger::check()` already records a Suwayomi failure as an ERROR row, which
             * is where the diagnosis belongs. What must not happen is the exception leaving
             * `handle()`: under a sync queue driver a dispatch is a direct call, so a rethrow
             * 500s the series page the reader is looking at, turning a missing chapter list
             * into a broken app. Under any other queue the same throw would mark the job
             * failed, which is equally useless because there is nobody watching a queue log
             * for a page they are already staring at.
             *
             * The page renders its loading state, this clears the claim, and the reader finds
             * the retry button. That is the outcome worth having.
             */
            report($e);
        } finally {
            // In a finally, because a claim left behind by a throw is a reader who can never
            // load a chapter list again without the TTL expiring.
            self::release($this->userId);
        }
    }

    /**
     * Nothing here is worth retrying, and the reader can see the Refresh button instead.
     */
    public function failed(?\Throwable $e): void
    {
        self::release($this->userId);
    }
}
