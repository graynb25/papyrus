<?php

namespace App\Support;

use App\Covers\CoverStore;
use App\Library\ContinueReading;
use App\Models\Category;
use App\Models\SeriesCover;
use App\Models\SeriesState;
use App\Models\User;
use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SuwayomiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The library: this reader's series, their shelves, and what is unread.
 *
 * ## Why this exists rather than a query on series_states
 *
 * A library page needs a title and a cover for every series the reader added, and
 * neither is stored here. Both live in Suwayomi. The obvious implementation is one
 * `manga(id:)` call per series, and that was measured: about 15ms each, so 4.5
 * seconds of serial waiting for a 300 series library.
 *
 * The alternative is one call with an id filter, which Suwayomi does expose:
 *
 *     mangas(filter: {id: {in: [...]}}, first: N) { nodes { ... } }
 *
 * Measured against the live server with 300 ids: 246ms, 63KB, one request. The
 * cost tracks the response rather than the number of round trips, so the page cost
 * is flat in library size. That measurement is why there is no local metadata
 * cache and no per-series loop anywhere in this class.
 *
 * ## Why unread is computed here and not read from Suwayomi
 *
 * `MangaType.unreadCount` exists and is useless to us. It is derived from the read
 * state of whichever account the server resolved, and it always resolves to
 * Admin(1). Measured: it reported 41 unread on a series where this reader has read
 * thirteen chapters of. So unread is `chapters.totalCount` from the source minus
 * the chapter rows this reader has read, both of which we can trust.
 *
 * ## Why nothing here touches auth
 *
 * Every method takes a user explicitly and scopes by that id rather than going
 * through the tenancy scope, which reads the current auth context. The scope is
 * correct for a web request and silently narrows to `user_id is null` in a command
 * or a queue job, where it produces a write that does nothing and no error. The
 * library has to work in a scheduled command too, so every query here names its
 * user. Same reasoning as {@see ReadingState}, which sets user_id by hand for the
 * same reason.
 *
 * @see SeriesGateway::fetchMany() for the batch call and its schema constraints
 */
class Library
{
    public function __construct(private readonly SeriesGateway $gateway) {}

    // Reading ----------------------------------------------------------

    /**
     * The reader's series, newest first, with everything a card needs.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function entries(User|int $user): Collection
    {
        $userId = $this->userId($user);

        $states = SeriesState::forUser($userId)
            ->where('in_library', true)
            ->orderByDesc('last_read_at')
            ->orderByDesc('added_at')
            ->get();

        if ($states->isEmpty()) {
            /** @var Collection<int, array<string, mixed>> $none */
            $none = collect();

            return $none;
        }

        /*
         * Ids for the one batched Suwayomi call, with the filed-only rows left out.
         *
         * A series imported from a backup whose source has since shut down has a null
         * `suwayomi_manga_id`. Sending null to `mangas(filter: {id: {in: [...]}})` asks the
         * server for a manga that does not exist, and casting it to 0 would do the same while
         * looking like a real id everywhere else in this class. So they are excluded here and
         * rendered from the row alone by `present()`.
         */
        $ids = $states
            ->pluck('suwayomi_manga_id')
            ->filter(fn ($id): bool => $id !== null)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $source = $this->fetchFromSource($ids);
        $readCounts = $this->readCounts($userId, $ids);

        /*
         * This reader's own covers, in one query.
         *
         * Resolved here rather than per entry because the page renders up to 60 covers,
         * and one query per cover would be 60 queries to learn which of them are custom.
         * The same reasoning as the read counts beside it.
         */
        $covers = app(CoverStore::class)->mapFor($userId, $ids);

        /** @var Collection<int, array<string, mixed>> $entries */
        $entries = $states
            ->map(fn (SeriesState $state): array => $this->present(
                $state,
                // Null for a filed-only row, which is exactly the "server does not know this
                // manga" case `present()` already handles, except that it also has a title.
                $state->suwayomi_manga_id === null
                    ? null
                    : $source[(int) $state->suwayomi_manga_id] ?? null,
                $state->suwayomi_manga_id === null
                    ? 0
                    : $readCounts[(int) $state->suwayomi_manga_id] ?? 0,
                $covers,
            ))
            ->values();

        /*
         * Where to pick up, per series, attached to the rows already built.
         *
         * `ContinueReading` reads the same `$source` this method fetched, so the
         * feature costs no additional call to Suwayomi. Measured: the batch returns
         * 575 chapter ids for 14 series in 539ms, and the read set is local, so the
         * whole feed is a join over rows this page was loading regardless.
         *
         * Attached here rather than resolved in the view so the Livewire component
         * has one array of rows to reason about, and so a card asking where to
         * continue does not trigger its own lookup.
         */
        $continue = app(ContinueReading::class)->forLibrary($userId, $source);

        /*
         * Built through a plain loop rather than a chained `->map()`, and assembled
         * into a named variable before returning, because that is where the type
         * annotation has to live.
         *
         * Assigning `continue_at` makes PHPStan infer `non-empty-array` for each row,
         * `Collection`'s template is invariant, and a non-empty array can never satisfy
         * a declared `array<string, mixed>`. Widening the method's declared return
         * instead would break the early return above, which yields an empty
         * collection. So the rows are annotated here, where the widening happens.
         *
         * @var array<int, array<string, mixed>> $rows
         */
        $rows = [];

        foreach ($entries as $entry) {
            $entry['continue_at'] = $continue[(int) $entry['manga_id']] ?? null;
            $rows[] = $entry;
        }

        /** @var Collection<int, array<string, mixed>> $result */
        $result = new Collection($rows);

        return $result;
    }

    /**
     * One library row: our state joined against whatever the source told us.
     *
     * @param  array<string, mixed>|null  $source
     * @param  array<int, SeriesCover>  $covers  this reader's custom covers, keyed by manga id
     * @return array<string, mixed>
     */
    private function present(
        SeriesState $state,
        ?array $source,
        int $readCount,
        array $covers = [],
    ): array {
        /*
         * Three states, not two, and collapsing them is a lie.
         *
         * - The server does not know this manga at all.
         * - It knows it, but nobody has fetched its chapters, so `totalCount` is 0
         *   because the list is empty, not because the series has no chapters.
         * - It has the list, and the subtraction means something.
         *
         * Only the third can produce an unread count. Reading a 0 out of the middle
         * case shows a freshly added series as "caught up", which is the one thing
         * a reader opens this page to find out.
         */
        $known = $source !== null;
        $fetched = $known && (bool) ($source['initialized'] ?? false);
        $total = (int) ($source['chapters']['totalCount'] ?? 0);

        /*
         * The title falls back to the row's own.
         *
         * A series filed from a backup has a title here and none in the server's response,
         * because there is no source to ask. Measured: 195 series on the real backup land this
         * way. `$source['title'] ?? ''` gave an empty card, which is the one thing a reader
         * cannot recognise, and the whole reason for filing them.
         */
        $title = $source['title'] ?? $state->title ?? '';

        return [
            'state' => $state,
            // Null rather than 0 for a filed-only row: a link built from it would be a request
            // for a manga that does not exist, and 0 reads as a real id everywhere else.
            'manga_id' => $state->suwayomi_manga_id === null ? null : (int) $state->suwayomi_manga_id,
            'title' => (string) $title,
            'unavailable_reason' => $state->unavailable_reason,
            'status' => $source['status'] ?? null,
            /*
             * The reader's own cover wins, and the source's is the fallback rather than
             * the other way round: a reader who changed a cover changed it so they would
             * not see the old one.
             *
             * $covers is the batch lookup, resolved once by the caller. Measured, this
             * page renders up to 60 covers, and one query per cover would be 60 queries
             * to learn which of them are custom.
             */
            /*
             * No cover when the server does not know the manga, which is two different cases
             * that used to be one: a manga id the server has never heard of, and a row with no
             * id at all. Keying only on the id made the first case grow a cover link to a
             * 404, which `test_no_cover_is_reported_rather_than_shown_as_a_broken_image` caught.
             */
            'cover' => $known && $state->suwayomi_manga_id !== null
                ? (isset($covers[(int) $state->suwayomi_manga_id])
                    ? route('covers.show', ['manga' => (int) $state->suwayomi_manga_id])
                    : ImageUrl::thumbnail((int) $state->suwayomi_manga_id))
                : null,
            'has_custom_cover' => $state->suwayomi_manga_id !== null
                && isset($covers[(int) $state->suwayomi_manga_id]),
            /*
             * The source id alongside the name and language, for anything that has to name one
             * source exactly rather than by its label. A string because source ids exceed
             * 2^53 and would be corrupted by a JSON number, the same reason the gateway keeps
             * them as strings. See trap 4.
             *
             * This exists because a name is not an identity: measured, MangaFire appears as
             * seven language variants and several share a display name, so "everything on
             * MangaFire" is ambiguous where "everything on source id X" is not.
             */
            'source_id' => isset($source['source']['id']) ? (string) $source['source']['id'] : null,
            'source_name' => $source['source']['name'] ?? null,
            'source_lang' => $source['source']['lang'] ?? null,
            'total_chapters' => $fetched ? $total : null,
            'read_chapters' => $readCount,
            'unread_chapters' => $fetched ? max(0, $total - $readCount) : null,
            'last_read_at' => $state->last_read_at,
            'added_at' => $state->added_at,
            'default_category_id' => $state->default_category_id,
            'has_source_data' => $known,
            'has_chapter_list' => $fetched,
            'needs_refresh' => $fetched && ! $this->fingerprintMatches($state, $source),
        ];
    }

    /**
     * The source records for a set of manga, in one call.
     *
     * Returns an empty array rather than throwing when the server is unreachable. A
     * library page with the titles missing is still a library page, and a reader
     * with 300 series should not get an error page because one upstream stalled.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function fetchFromSource(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        try {
            return $this->gateway->fetchMany($ids);
        } catch (SuwayomiException) {
            return [];
        }
    }

    /**
     * How many chapters this reader has read, per series, in one grouped query.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function readCounts(int $userId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::connection()->table('chapter_states')
            ->selectRaw('suwayomi_manga_id, count(*) as read_count')
            ->where('user_id', $userId)
            ->where('is_read', true)
            ->whereIn('suwayomi_manga_id', $ids)
            ->groupBy('suwayomi_manga_id')
            ->pluck('read_count', 'suwayomi_manga_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    // Shelves ----------------------------------------------------------

    /**
     * This reader's shelves, with how many series each holds.
     *
     * The count comes from one grouped query rather than `withCount` per shelf,
     * which would be a query per row and the whole point of this class is that the
     * page cost does not scale.
     *
     * @return Collection<int, array{id: int, name: string, order: int, count: int}>
     */
    public function categories(User|int $user): Collection
    {
        $userId = $this->userId($user);

        $categories = Category::forUser($userId)->ordered()->get();

        /** @var Collection<int, array{id: int, name: string, order: int, count: int}> $none */
        $none = collect();

        if ($categories->isEmpty()) {
            return $none;
        }

        $counts = DB::connection()->table('category_series')
            ->selectRaw('category_id, count(*) as series_count')
            ->where('user_id', $userId)
            ->whereIn('category_id', $categories->pluck('id'))
            ->groupBy('category_id')
            ->pluck('series_count', 'category_id');

        /** @var Collection<int, array{id: int, name: string, order: int, count: int}> $rows */
        $rows = $categories
            ->map(fn (Category $category): array => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'order' => (int) $category->order_column,
                'count' => (int) ($counts[$category->id] ?? 0),
            ])
            ->values();

        return $rows;
    }

    /**
     * Create a shelf, or return the existing one with that name.
     *
     * Returning the existing shelf rather than failing is deliberate. The unique
     * index on (user_id, name) already prevents duplicates, and a reader who types
     * a name they have used should land on that shelf, not on an error.
     */
    public function createCategory(User|int $user, string $name): ?Category
    {
        $userId = $this->userId($user);
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $existing = Category::forUser($userId)->where('name', $name)->first();

        if ($existing !== null) {
            return $existing;
        }

        return Category::createFor($userId, [
            'name' => $name,
            'order_column' => $this->nextOrder($userId),
        ]);
    }

    public function renameCategory(User|int $user, int $categoryId, string $name): ?Category
    {
        $userId = $this->userId($user);
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $category = $this->ownedCategory($userId, $categoryId);

        if ($category === null) {
            return null;
        }

        $category->name = $name;
        $category->save();

        return $category;
    }

    /**
     * Delete a shelf and unfile everything in it.
     *
     * Also clears the shelf from any series that had it as their home. Left
     * dangling it renders as a series pointing at a shelf that no longer exists,
     * which is worse than unfiled.
     *
     * The series themselves are untouched. A reader who removes a shelf did not ask
     * to lose everything that was on it.
     */
    public function deleteCategory(User|int $user, int $categoryId): bool
    {
        $userId = $this->userId($user);

        if ($this->ownedCategory($userId, $categoryId) === null) {
            return false;
        }

        DB::connection()->table('category_series')
            ->where('user_id', $userId)
            ->where('category_id', $categoryId)
            ->delete();

        SeriesState::forUser($userId)
            ->where('default_category_id', $categoryId)
            ->update(['default_category_id' => null]);

        Category::forUser($userId)->whereKey($categoryId)->delete();

        return true;
    }

    // Filing -----------------------------------------------------------

    /**
     * File a series into a shelf.
     *
     * Creates the series row if there is not one already: the series page lets a
     * reader shelve something they have not opened yet, and the membership is
     * keyed on that row.
     *
     * @return array<int, int> every shelf this series is now in
     */
    public function attach(User|int $user, int $mangaId, int $categoryId): array
    {
        $userId = $this->userId($user);

        if ($this->ownedCategory($userId, $categoryId) === null) {
            return [];
        }

        $state = app(ReadingState::class)->loadOrCreate($userId, $mangaId);
        $state->save();

        if (! $this->isFiled($userId, (int) $state->id, $categoryId)) {
            DB::connection()->table('category_series')->insert([
                'user_id' => $userId,
                'category_id' => $categoryId,
                'series_state_id' => $state->id,
                'added_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        /*
         * First shelf wins as the home one, so a series is never homeless by
         * accident the first time it is filed. Only set when unset: a reader who
         * chose where this lives should not have it moved by a later filing.
         */
        if ($state->default_category_id === null) {
            $state->default_category_id = $categoryId;
            $state->save();
        }

        return $this->categoryIdsFor($userId, (int) $state->id);
    }

    /**
     * Take a series out of one shelf.
     *
     * Leaving the last shelf makes it unfiled rather than removing the series, and
     * the home shelf moves to another membership if there is one, so unfiling never
     * leaves a dangling default behind.
     *
     * @return array<int, int> every shelf this series is now in
     */
    public function detach(User|int $user, int $mangaId, int $categoryId): array
    {
        $userId = $this->userId($user);

        $state = SeriesState::forUser($userId)->where('suwayomi_manga_id', $mangaId)->first();

        if ($state === null) {
            return [];
        }

        DB::connection()->table('category_series')
            ->where('user_id', $userId)
            ->where('series_state_id', $state->id)
            ->where('category_id', $categoryId)
            ->delete();

        if ((int) $state->default_category_id === $categoryId) {
            $state->default_category_id = $this->categoryIdsFor($userId, (int) $state->id)[0] ?? null;
            $state->save();
        }

        return $this->categoryIdsFor($userId, (int) $state->id);
    }

    /**
     * Move a series to a different home shelf.
     *
     * Sets the default without touching membership. A series can be its home in a
     * shelf it is filed in, and changing where it "lives" is not the same operation
     * as changing which shelves it is in.
     */
    public function setDefault(User|int $user, int $mangaId, ?int $categoryId): ?SeriesState
    {
        $userId = $this->userId($user);

        $state = SeriesState::forUser($userId)->where('suwayomi_manga_id', $mangaId)->first();

        if ($state === null) {
            return null;
        }

        /*
         * A default has to be a membership. Storing a shelf the series is not filed
         * in would make the series page and the shelf disagree about where it
         * lives, and would survive the series being unfiled from that shelf.
         */
        $state->default_category_id = match (true) {
            $categoryId === null => null,
            $this->isFiled($userId, (int) $state->id, $categoryId) => $categoryId,
            default => null,
        };

        $state->save();

        return $state;
    }

    /**
     * Every shelf id for one series, in the reader's own shelf order.
     *
     * Takes ids rather than a model, so it works with no auth context.
     *
     * @return array<int, int>
     */
    public function categoryIdsFor(User|int $user, int $seriesStateId): array
    {
        $userId = $this->userId($user);

        return DB::connection()->table('category_series')
            ->join('categories', 'categories.id', '=', 'category_series.category_id')
            ->where('category_series.user_id', $userId)
            ->where('category_series.series_state_id', $seriesStateId)
            ->orderBy('categories.order_column')
            ->orderBy('categories.name')
            ->pluck('categories.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Which shelves a set of series are filed in, in one query.
     *
     * The library page renders a filing control on every card and would otherwise
     * issue one membership query per card. Same rule as the rest of this class: the
     * query count must not scale with what is on screen.
     *
     * @param  array<int, int>  $mangaIds
     * @return array<int, array<int, int>> manga id to its shelf ids
     */
    public function filingsFor(User|int $user, array $mangaIds): array
    {
        $userId = $this->userId($user);

        if ($mangaIds === []) {
            return [];
        }

        $stateIds = SeriesState::forUser($userId)
            ->whereIn('suwayomi_manga_id', $mangaIds)
            ->pluck('id', 'suwayomi_manga_id');

        if ($stateIds->isEmpty()) {
            return [];
        }

        /*
         * Reverse map first. Resolving series_state_id back to a manga id per row
         * would otherwise be a search through a keyed collection, which is a loop
         * inside a loop and the sort of thing that stays invisible until the
         * library has a few hundred entries.
         */
        $byStateId = [];

        foreach ($stateIds as $mangaId => $stateId) {
            $byStateId[(int) $stateId] = (int) $mangaId;
        }

        $rows = DB::connection()->table('category_series')
            ->join('categories', 'categories.id', '=', 'category_series.category_id')
            ->where('category_series.user_id', $userId)
            ->whereIn('category_series.series_state_id', array_keys($byStateId))
            ->orderBy('categories.order_column')
            ->orderBy('categories.name')
            ->get(['category_series.series_state_id', 'category_series.category_id']);

        $filings = [];

        foreach ($rows as $row) {
            $mangaId = $byStateId[(int) $row->series_state_id] ?? null;

            if ($mangaId !== null) {
                $filings[$mangaId][] = (int) $row->category_id;
            }
        }

        return $filings;
    }

    // Staleness --------------------------------------------------------

    /**
     * Record what the source's chapter list looked like.
     *
     * Called when the full list is in hand, which is when the series page refreshes
     * or the reader opens a chapter.
     *
     * @param  array<int, array<string, mixed>>  $chapters
     */
    public function recordChapterFingerprint(User|int $user, int $mangaId, array $chapters): void
    {
        $userId = $this->userId($user);

        $state = SeriesState::forUser($userId)->where('suwayomi_manga_id', $mangaId)->first();

        if ($state === null) {
            return;
        }

        $state->chapter_list_fingerprint = self::chapterFingerprint($chapters);
        $state->chapter_list_checked_at = now();
        $state->save();
    }

    /**
     * A stable digest of a chapter list.
     *
     * Built from the chapter ids in order rather than from the count, because the
     * case that matters is a source renumbering: the count is unchanged and every
     * reader's unread total silently becomes wrong.
     *
     * @param  array<int, array<string, mixed>>  $chapters
     */
    public static function chapterFingerprint(array $chapters): string
    {
        $ids = [];

        foreach ($chapters as $chapter) {
            $ids[] = (string) ($chapter['id'] ?? '');
        }

        return hash('sha256', implode(',', $ids));
    }

    /**
     * Whether the chapter list still looks the way it did when we last saw it.
     *
     * @param  array<string, mixed>  $source
     */
    private function fingerprintMatches(SeriesState $state, array $source): bool
    {
        $recorded = $state->chapter_list_fingerprint;

        /*
         * Never fingerprinted means never opened, which is not staleness. A series
         * the reader has only just added has nothing to compare against and
         * flagging it would mark half a new library as stale.
         */
        if ($recorded === null) {
            return true;
        }

        $ids = $source['chapters']['nodes'] ?? null;

        // Only the count came back, so there is nothing to compare.
        if (! is_array($ids)) {
            return true;
        }

        return $recorded === self::chapterFingerprint($ids);
    }

    // Helpers ----------------------------------------------------------

    private function isFiled(int $userId, int $seriesStateId, int $categoryId): bool
    {
        return DB::connection()->table('category_series')
            ->where('user_id', $userId)
            ->where('series_state_id', $seriesStateId)
            ->where('category_id', $categoryId)
            ->exists();
    }

    private function nextOrder(int $userId): int
    {
        return (int) (Category::forUser($userId)->max('order_column') ?? -1) + 1;
    }

    private function ownedCategory(int $userId, int $categoryId): ?Category
    {
        return Category::forUser($userId)->whereKey($categoryId)->first();
    }

    private function userId(User|int $user): int
    {
        return $user instanceof User ? (int) $user->getKey() : $user;
    }
}
