<?php

namespace App\Support;

use App\Http\Controllers\ReaderController;
use App\Models\Scopes\OwnedByUser;
use App\Models\SeriesState;
use App\Models\Source;
use App\Models\User;
use App\Models\UserSource;
use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SuwayomiException;
use Illuminate\Support\Facades\Cache;

/**
 * Whether this reader leaves a record on this series' source.
 *
 * ## What it means here, and what it deliberately does not
 *
 * Incognito suppresses **read state only**: opening a chapter records nothing, so no
 * `last_read_at`, no page position, no read flag, no read seconds, and no AniList push.
 * Nothing is hidden from the reader's own searches, because that is what the existing
 * `user_sources.disabled` flag already does, and two columns meaning one thing is how a
 * preference becomes something nobody trusts.
 *
 * So this is deliberately narrower than Mihon's incognito, which also suppresses the source
 * from listings. Tsumiru and WebUI (#1048) both treat it as "do not leave a trace", and the
 * trace is the read state. Naming the difference is the point: a reader who expects their
 * source to vanish from search and finds it there has been misled, and the control on the
 * health page already offers them that under its own name.
 *
 * ## Why a manga id is not enough, and what it costs to resolve one
 *
 * Every call site knows a Suwayomi manga id. Incognito is a per-source preference, so the
 * decision needs that manga's source id, and no local table pairs them for a series reached
 * without searching: `search_results` holds both but only for rows a fan-out produced.
 * Measured across the schema, one table carries both columns and it is empty of the general
 * case.
 *
 * Resolving it means one `manga(id:)`, which measured 4ms to 5ms warm and 598ms cold on the
 * first call of a session. That is affordable once per reader page load, because
 * {@see ReaderController::neighbours()} already makes the same call on
 * the same request and its answer is in hand.
 *
 * It is not affordable on the progress endpoint, which fires every few seconds for the length
 * of a chapter. So the mapping is cached, which measured 19 hits and 1 miss across 20 pings
 * for 19ms total. The window is an hour because a manga id does not change which source it
 * came from: ids are global and assigned once, and a source migrating is a different manga id
 * on the other side of the move.
 *
 * ## Why the decision is made per request and not per ping
 *
 * A reader opens a chapter and reads it. Whether the source is incognito cannot change
 * halfway through, so the reader resolves it once and the ping endpoint reads a cached
 * boolean. That is the same shape as the rest of the app's read-state writes, and it means the
 * hot path costs one cache read rather than one cache read plus a branch over a query.
 */
class Incognito
{
    /**
     * How long a manga's source id is trusted.
     *
     * An hour, because the mapping is immutable rather than merely slow to change: Suwayomi
     * assigns a manga id once and it stays with the source that produced it. A reader who
     * migrates a series gets a different manga id on the destination, which resolves
     * separately.
     */
    public const SOURCE_TTL_SECONDS = 3600;

    private const SOURCE_KEY = 'incognito:manga-source:';

    /**
     * Resolved once per instance, because every call site asks about one series per request.
     *
     * @var array<int, string|null>
     */
    private array $sources = [];

    public function __construct(
        private readonly SeriesGateway $gateway,
    ) {}

    /**
     * Whether reading this series would leave a trace.
     *
     * True means "do not write", so a caller reads it as a guard rather than a permission.
     *
     * ## The cheap question is asked first, and that is the whole design
     *
     * If this reader has **no** incognito sources at all, nothing can suppress anything, and
     * the answer is false without ever resolving which source the series came from. So the set
     * is read first, which is one indexed local query, and the `manga(id:)` round trip only
     * happens for a reader who has actually turned the feature on.
     *
     * The order is not a micro-optimisation. Resolving first means every chapter open on every
     * install with the feature unused pays a network call to learn a thing that was already
     * known, and it was found the hard way: `ReadingStateTest` registers no `Http::fake()`, so
     * the first version reached the real server and seven tests died on "Incorrect username or
     * password". A gate that only runs when nobody has opted in is not merely slow, it is a
     * network dependency inside a unit test.
     *
     * A series whose source cannot be resolved is **not** incognito: failing closed would
     * silently stop recording read state for every series on a source we could not look up,
     * which is a far worse failure than recording state a reader asked to keep.
     *
     * @throws SuwayomiException
     */
    public function suppresses(User|int $user, int $mangaId): bool
    {
        $userId = UserKey::of($user);

        $incognito = $this->incognitoIds($userId);

        if ($incognito === []) {
            return false;
        }

        $sourceId = $this->sourceFor($mangaId);

        if ($sourceId === null) {
            return false;
        }

        return in_array($sourceId, $incognito, true);
    }

    /**
     * Whether this source is incognito for this reader.
     *
     * One query for the whole set rather than one per source, because a reader who has
     * incognito on MangaFire and opens a MangaDex series should not pay two queries to learn
     * that neither applies.
     */
    public function sourceIsIncognito(User|int $user, string $sourceId): bool
    {
        $userId = UserKey::of($user);

        return in_array(
            $sourceId,
            $this->incognitoIds($userId),
            true,
        );
    }

    /**
     * The manga's source, from the payload when the caller already has it and from the cache
     * otherwise.
     *
     * The payload parameter exists because {@see ReaderController}
     * resolves the series anyway, and a cache round trip for an answer already in hand is the
     * kind of cost that makes a feature look expensive for no reason.
     *
     * @param  array<string, mixed>|null  $series  a hydrated SeriesGateway::find() payload
     */
    public function sourceFor(int $mangaId, ?array $series = null): ?string
    {
        if (array_key_exists($mangaId, $this->sources)) {
            return $this->sources[$mangaId];
        }

        if ($series !== null) {
            $id = $series['source']['id'] ?? null;

            return $this->sources[$mangaId] = is_string($id) && $id !== '' ? $id : null;
        }

        return $this->sources[$mangaId] = $this->cachedSourceFor($mangaId);
    }

    /**
     * Incognito sources for a reader, as Suwayomi ids.
     *
     * A join through the local mirror rather than a column on `user_sources`, because the
     * mirror is the only table that maps our rows onto a *named* source and a reader turning
     * this on from the health page is thinking in sites, not ids.
     *
     * @return array<int, string>
     */
    public function incognitoIds(int $userId): array
    {
        return UserSource::query()
            ->forUser($userId)
            ->where('incognito', true)
            ->pluck('source_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * Turn incognito on or off for a set of sources, creating rows as needed.
     *
     * An upsert for the same reason {@see UserSource::setDisabledFor()} is one: the rows
     * usually do not exist, so a mass update matches nothing and the preference appears to
     * have been set while the source keeps writing read state.
     *
     * @param  array<int, int|string>  $sourceIds
     * @return int rows written
     */
    public static function setFor(User|int $user, array $sourceIds, bool $incognito): int
    {
        if ($sourceIds === []) {
            return 0;
        }

        $userId = UserKey::of($user);
        $now = now();

        $rows = array_map(static fn (int|string $sourceId): array => [
            'user_id' => $userId,
            // Source ids exceed 2^53, so they are kept as strings and the column is an
            // unsignedBigInteger. Casting to int here would corrupt every id that exceeds
            // JavaScript's safe range, which is nearly all of them: measured, the mirror's
            // ids run to 6,247,824,327,199,706,550.
            'source_id' => (string) $sourceId,
            'incognito' => $incognito,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values(array_unique($sourceIds)));

        // The tenant scope is dropped for the same reason setDisabledFor() drops it: the
        // owner is written explicitly, and letting the scope add its own filter would make
        // the conflict target depend on who is currently logged in.
        return UserSource::withoutGlobalScope(OwnedByUser::class)->upsert(
            $rows,
            ['user_id', 'source_id'],
            ['incognito', 'updated_at'],
        );
    }

    /**
     * The source ids behind a site, as the mirror knows them.
     *
     * Grouped by `home_url` for the same reason the health dashboard groups: 61 of the 82
     * sources are language variants of one MangaDex, so a per-source list repeats one site 61
     * times and a reader cannot tell which of them they are turning on.
     *
     * @return array<int, string>
     */
    public static function siteSourceIds(string $site): array
    {
        if ($site === 'unknown') {
            return Source::query()
                ->where(fn ($q) => $q->whereNull('home_url')->orWhere('home_url', ''))
                ->pluck('suwayomi_source_id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all();
        }

        return Source::query()
            ->where('home_url', $site)
            ->pluck('suwayomi_source_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * How many of this reader's library series sit on these sources.
     *
     * Shown on the control before it is pressed, because it is the number that decides whether
     * stopping the record is a good idea on a site a reader keeps twenty series from.
     *
     * ## Why one batch and not one call per series
     *
     * The obvious version resolves each library series to its source, and measured 1,379ms for
     * 20 series at 69ms each. Extrapolated to the 300-series library this app is designed for,
     * that is over twenty seconds spent rendering a health page, which is trap 52's shape with a
     * new cause: a page asking Suwayomi once per card.
     *
     * `SeriesGateway::fetchMany()` answers it in 350ms for the same 20 and was verified to
     * agree with the per-series form on 20 of 20, so it is the batch that ships. At 300 ids the
     * same call measures 246ms, so the cost tracks the response rather than the library size.
     *
     * A manga the server silently drops is normal and measured: the filter drops unknown ids
     * without an error, so a short result is not a fault to report.
     *
     * @param  array<int, int|string>  $sourceIds
     */
    public function seriesCountFor(int $userId, array $sourceIds): int
    {
        if ($sourceIds === []) {
            return 0;
        }

        $owned = SeriesState::forUser($userId)
            ->where('in_library', true)
            ->pluck('suwayomi_manga_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($owned === []) {
            return 0;
        }

        try {
            $series = $this->gateway->fetchMany($owned);
        } catch (SuwayomiException) {
            /*
             * Zero, not an exception, and that is a decision rather than a shrug. This number
             * sits inside a confirmation body, and a control that cannot render because a
             * source is down would make the outage look like a broken page. Understating the
             * count is the safe direction: the reader turns incognito on believing nothing is
             * recorded either way, and nothing about the library is touched when they do.
             */
            return 0;
        }

        // Source ids exceed 2^53, so both sides are compared as strings and never as numbers.
        $candidates = array_flip(array_map(strval(...), $sourceIds));

        $count = 0;

        foreach ($series as $node) {
            $sourceId = (string) ($node['source']['id'] ?? '');

            if ($sourceId !== '' && isset($candidates[$sourceId])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Drop the cached mapping for a series, so a change lands immediately.
     *
     * Only needed when the mapping itself is wrong, which the TTL makes rare. Exposed because
     * a diagnostic that has just measured the wrong answer needs a way to ask again, and a
     * cache with no invalidation is the trap 145 lesson.
     */
    public function forget(int $mangaId): void
    {
        Cache::forget(self::SOURCE_KEY.$mangaId);
        unset($this->sources[$mangaId]);
    }

    /**
     * The cached lookup, tolerating a server that cannot answer.
     *
     * A null here is not cached, deliberately: caching a failure would make a server that was
     * briefly unreachable suppress read state for an hour afterwards, which is the one
     * outcome this class must never produce.
     *
     * @throws SuwayomiException
     */
    private function cachedSourceFor(int $mangaId): ?string
    {
        $key = self::SOURCE_KEY.$mangaId;

        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached === '' ? null : $cached;
        }

        $series = $this->gateway->find($mangaId);

        $id = $series['source']['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        Cache::put($key, $id, self::SOURCE_TTL_SECONDS);

        return $id;
    }

    /**
     * Kept for callers that hold a {@see SeriesState} rather than a manga id.
     */
    public function suppressesSeries(User|int $user, ?SeriesState $state): bool
    {
        if ($state === null) {
            return false;
        }

        return $this->suppresses($user, (int) $state->suwayomi_manga_id);
    }
}
