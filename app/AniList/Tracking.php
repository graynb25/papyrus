<?php

namespace App\AniList;

use App\Jobs\SyncAniListProgress;
use App\Models\AniListAccount;
use App\Models\AniListMedia;
use App\Models\ChapterState;
use App\Models\User;
use App\Suwayomi\SeriesGateway;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Reading progress, pushed to a reader's AniList account.
 *
 * ## The grant, stated plainly
 *
 * Connecting hands the app a token that acts as that reader on AniList. What
 * the app does with it is deliberately one thing: when a chapter becomes read,
 * the series' progress number on AniList moves to match. Nothing else is
 * written, and nothing is read back beyond who the token belongs to. A reader
 * who disconnects leaves no row behind; AniList publishes no revocation
 * endpoint, so the token itself lasts until its own expiry.
 *
 * ## The key the join runs on
 *
 * Which AniList media a series *is* is answered by the same cache the search
 * dedupe uses, keyed on the normalised title. A series nobody has looked up
 * gets one lookup, made by a job and not by a render path, and a title
 * AniList has never heard of is recorded as answered-no so it is never asked
 * again. A reader who connects has already consented to their titles going to
 * AniList; that consent is what makes the lookup lawful here when it needed an
 * opt-in switch on the search page.
 *
 * ## The progress number
 *
 * AniList's `progress` is a chapter number, not a count of rows. The number
 * pushed is the largest chapter number among the reader's read chapters,
 * floored to an integer, because sources number chapters 10.5 and AniList does
 * not. It is an approximation and the honest kind: the alternative, counting
 * read rows, would report 41 for a reader whose 41 read chapters are numbers
 * 100 to 140.
 */
class Tracking
{
    /** Marker in the cache while a sync for this pair is queued or running. */
    private function pendingKey(int $userId, int $mangaId): string
    {
        return "anilist:sync-pending:{$userId}:{$mangaId}";
    }

    public function __construct(
        private readonly SeriesGateway $series,
        private readonly TitleResolver $titles,
        private readonly Client $client,
    ) {}

    /**
     * This reader's AniList connection, or null when they have none.
     */
    public function accountFor(User|int $user): ?AniListAccount
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        return AniListAccount::forUser($userId)->first();
    }

    /**
     * Record a reader's connection, replacing any previous one.
     */
    public function connect(User $user, string $accessToken, int $anilistUserId, string $anilistUserName, ?CarbonInterface $expiresAt): AniListAccount
    {
        AniListAccount::forUser($user->getKey())->delete();

        return AniListAccount::createFor($user, [
            'access_token' => $accessToken,
            'anilist_user_id' => $anilistUserId,
            'anilist_user_name' => $anilistUserName,
            'token_expires_at' => $expiresAt,
        ]);
    }

    /**
     * Forget the connection. AniList publishes no revocation endpoint, so deleting
     * the row ends this app's use of the token but does not invalidate it on
     * AniList's side; it remains valid there until its own expiry.
     */
    public function disconnect(User|int $user): void
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        AniListAccount::forUser($userId)->delete();
    }

    /**
     * Queue a push for one series, once.
     *
     * The marker keeps a continuous reading session from stacking a job per
     * progress ping: while one is queued or running, later pings for the same
     * series are absorbed, and the push that eventually runs sees the final
     * state rather than an intermediate one.
     *
     * Answers whether a push is now on its way, because the migration summary
     * records what was asked and must not claim a push nobody booked: a reader
     * who disconnected between the dialog and the confirm gets `false` on the
     * row, not a queued truth.
     */
    public function scheduleSync(User|int $user, int $mangaId): bool
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        if (AniListAccount::forUser($userId)->exists() === false) {
            return false;
        }

        $key = $this->pendingKey($userId, $mangaId);

        if (Cache::has($key)) {
            // One is already on its way, and it is the same push by (user, manga).
            return true;
        }

        Cache::put($key, true, now()->addMinutes(5));

        SyncAniListProgress::dispatch($userId, $mangaId);

        return true;
    }

    /**
     * Push one series' progress now.
     *
     * Every return value is a normal state and not a failure, so the caller has
     * nothing to interpret: a reader with no token, a series the server does not
     * know, no chapters read, no chapter numbers to speak of, and a title
     * AniList has never heard of all leave AniList untouched.
     *
     * @return string one of not_connected, unknown_series, nothing_read,
     *                no_chapter_numbers, unmatched, pushed, completed, token_rejected
     */
    public function sync(User|int $user, int $mangaId): string
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        $account = $this->accountFor($userId);

        if ($account === null) {
            return 'not_connected';
        }

        $map = $this->series->chapterMap($mangaId);

        if ($map === null) {
            return 'unknown_series';
        }

        $readIds = ChapterState::forUser($userId)
            ->where('suwayomi_manga_id', $mangaId)
            ->where('is_read', true)
            ->pluck('suwayomi_chapter_id')
            ->all();

        if ($readIds === []) {
            return 'nothing_read';
        }

        $numbers = [];
        foreach ($readIds as $chapterId) {
            $number = $map['chapters'][$chapterId] ?? null;
            if ($number !== null) {
                $numbers[] = $number;
            }
        }

        if ($numbers === []) {
            return 'no_chapter_numbers';
        }

        $progress = (int) floor(max($numbers));

        $mediaId = $this->titles->idFor($map['title']);

        if ($mediaId === null) {
            $media = $this->titles->resolve($map['title']);
            $mediaId = $media !== null && (int) $media->anilist_id > 0 ? (int) $media->anilist_id : null;
        }

        if ($mediaId === null) {
            return 'unmatched';
        }

        /*
         * Completed only when AniList itself says how long the series is. Our
         * chapter list is the scanlation's, which over- and under-counts against
         * the original release by measurement, so it is never the authority on
         * "finished".
         */
        $total = AniListMedia::query()->where('anilist_id', $mediaId)->min('chapters');
        $status = (is_numeric($total) && (int) $total > 0 && $progress >= (int) $total)
            ? 'COMPLETED'
            : 'CURRENT';

        try {
            $this->client->forUser($account->access_token, <<<'GQL'
                mutation Save($mediaId: Int!, $progress: Int, $status: MediaListStatus) {
                    SaveMediaListEntry(mediaId: $mediaId, progress: $progress, status: $status) {
                        id
                        progress
                        status
                    }
                }
                GQL, ['mediaId' => $mediaId, 'progress' => $progress, 'status' => $status]);
        } catch (AniListTokenRejected) {
            // The token cannot be renewed: AniList issues no refresh token, so the
            // row goes and the reader reconnects when they next care.
            $this->disconnect($userId);

            return 'token_rejected';
        }

        return $status === 'COMPLETED' ? 'completed' : 'pushed';
    }

    /**
     * Forget the queued marker once a sync has run, so the next read event can
     * book another. Called by the job even when the push gave up.
     */
    public function clearPending(int $userId, int $mangaId): void
    {
        Cache::forget($this->pendingKey($userId, $mangaId));
    }
}
