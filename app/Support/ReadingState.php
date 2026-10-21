<?php

namespace App\Support;

use App\Http\Controllers\ReaderController;
use App\Models\CategorySeries;
use App\Models\ChapterState;
use App\Models\SeriesState;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The per-user reading state that Suwayomi cannot hold.
 *
 * ## Why none of this is in Suwayomi
 *
 * `updateMangaPatchInput` exposes only `inLibrary`, and even that is useless to
 * us: the server is single-tenant, so every authenticated request resolves to
 * `Admin(1)` and its "library" belongs to whoever logged in last. Measured in
 * `AuthMode.kt`, which carries a `// TODO: ACCOUNT` above the check.
 *
 * So library membership, read state and progress are ours. The ids we key on are
 * Suwayomi's, which keeps the server as the source of truth for what a series
 * *is* while we own everything about a person's relationship with it.
 *
 * ## Why manga ids need no source segment
 *
 * Measured against v2.3.2243: `manga(id:)` resolves with no source named, and
 * manga 174 is One Piece on MangaDex while manga 76 is a Berserker title on
 * Asura Scans. The ids are global, so `(user, manga_id)` identifies a series
 * within a user. An earlier research note claimed ids were per-source; that was
 * wrong and would have made every state lookup ambiguous.
 *
 * ## pageCount is not trustworthy before a read
 *
 * Suwayomi reports `pageCount: -1` for every chapter whose pages have not been
 * fetched, which is all of them until the reader opens one. So progress here is
 * recorded as *pages read*, never as a fraction of a count we do not have. A
 * percentage computed from -1 is either 0% or a division by zero, and both lie.
 *
 * ## Incognito sources write nothing
 *
 * `openChapter()`, `recordProgress()` and `addReadSeconds()` all return without writing when
 * the series' source is incognito for this reader. The gate lives here rather than at each
 * call site because these three are the only things that record a reader having read, and a
 * guard that has to be remembered at every future caller is a guard that will be forgotten.
 * {@see Incognito} answers the question; this class asks it.
 *
 * Everything else still writes. Library membership, shelves, favourites, read later, notes and
 * covers are the reader making a decision about a series, which is not the same gesture as
 * opening it and is not what incognito suppresses. The plan scoped this deliberately: read
 * state only, because `user_sources.disabled` already owns search suppression and two
 * preferences meaning one thing is how a preference stops being trusted.
 */
class ReadingState
{
    public function __construct(
        private readonly Incognito $incognito,
    ) {}

    /**
     * Whether this reader's incognito settings say not to record reading this series.
     *
     * Public because {@see ReaderController} needs the same answer to
     * decide whether to pass the source along at all, and one answer should serve both.
     */
    public function suppresses(User|int $user, int $mangaId): bool
    {
        return $this->incognito->suppresses($user, $mangaId);
    }

    /**
     * The user's row for a series, unsaved, ready for the caller to fill in.
     *
     * Not firstOrNew with a where on user_id, because user_id is deliberately not
     * mass-assignable and OwnedModel's creating hook only fills it from the
     * current auth context. Set explicitly instead, which is what makes this work
     * from a queue job or a command with nobody logged in.
     */
    public function series(User|int $user, int $mangaId): SeriesState
    {
        $state = new SeriesState;
        $state->setAttribute('user_id', $user instanceof User ? $user->getKey() : $user);
        $state->suwayomi_manga_id = $mangaId;

        return $state;
    }

    /**
     * Read-only, so a render never creates rows as a side effect.
     */
    public function findSeries(User|int $user, int $mangaId): ?SeriesState
    {
        return SeriesState::forUser($user instanceof User ? $user->getKey() : $user)
            ->where('suwayomi_manga_id', $mangaId)
            ->first();
    }

    /**
     * The user's row for a chapter, unsaved, ready to fill in.
     *
     * Loads an existing row first so a caller can mutate and save without knowing
     * whether this is the first write. Setting user_id explicitly is what lets
     * this work with no authenticated user; see {@see self::series()}.
     */
    public function chapter(User|int $user, int $mangaId, int $chapterId): ChapterState
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        $existing = ChapterState::forUser($userId)
            ->where('suwayomi_chapter_id', $chapterId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $chapter = new ChapterState;
        $chapter->setAttribute('user_id', $userId);
        $chapter->suwayomi_chapter_id = $chapterId;
        $chapter->suwayomi_manga_id = $mangaId;

        return $chapter;
    }

    public function findChapter(User|int $user, int $chapterId): ?ChapterState
    {
        return ChapterState::forUser($user instanceof User ? $user->getKey() : $user)
            ->where('suwayomi_chapter_id', $chapterId)
            ->first();
    }

    /**
     * Add or remove a series from this user's library.
     */
    /**
     * Load or create, in one call.
     *
     * Every mutator needs this, and forgetting it means a second save() creating
     * a duplicate row rather than updating, which the unique index on
     * (user_id, suwayomi_manga_id) would turn into a query exception.
     *
     * Public because something outside this class is a mutator too: filing a
     * series into a shelf writes a membership keyed on this row, and
     * {@see Library} is not a mutator on the series itself.
     */
    public function loadOrCreate(User|int $user, int $mangaId): SeriesState
    {
        return $this->findSeries($user, $mangaId) ?? $this->series($user, $mangaId);
    }

    public function setInLibrary(User|int $user, int $mangaId, bool $inLibrary): SeriesState
    {
        $state = $this->loadOrCreate($user, $mangaId);
        $state->in_library = $inLibrary;

        // The date is what makes "added on" answerable, and clearing it on removal
        // means re-adding records when the reader actually re-added it.
        $state->added_at = $inLibrary ? now() : null;
        $state->save();

        return $state;
    }

    public function setFavourited(User|int $user, int $mangaId, bool $favourited): SeriesState
    {
        $state = $this->loadOrCreate($user, $mangaId);
        $state->favourited_at = $favourited ? now() : null;
        $state->save();

        return $state;
    }

    public function setReadLater(User|int $user, int $mangaId, bool $readLater): SeriesState
    {
        $state = $this->loadOrCreate($user, $mangaId);
        $state->read_later_at = $readLater ? now() : null;
        $state->save();

        return $state;
    }

    /**
     * How many stars a series can carry, and the floor.
     *
     * Five and one, and the floor matters as much as the ceiling: a rating of zero is not a
     * low rating, it is the absence of one, and it is stored as `null` so that "unrated" and
     * "rated zero" cannot be confused by a query written later by someone who has forgotten.
     *
     * The column is `decimal(3, 1)`, which permits 3.5. Nothing writes a half. The extra
     * precision is left in the schema because narrowing a column is a migration and widening
     * a rating later would then need one, so the cheap half of the decision was taken up
     * front and the expensive half was not.
     */
    public const RATING_MIN = 1;

    public const RATING_MAX = 5;

    /**
     * Set or clear a rating.
     *
     * `null` clears rather than storing a zero, so the column's own null is the only way to
     * say "unrated". That means the list of rated series is a `whereNotNull('rating')` with no
     * numeric comparison to get wrong.
     *
     * ## Out-of-range values are refused, not clamped
     *
     * A rating of 9 or of -3 comes from a caller, not from a reader: the five buttons are the
     * only path the UI offers. Clamping would store 5 for an input of 9, which means a bug
     * upstream shows up as a plausible rating rather than as an exception, and a bug that
     * looks like a working feature is the expensive kind. Refusing leaves the write unissued
     * and the caller has to decide what a 9 means.
     *
     * @throws \InvalidArgumentException on a value outside RATING_MIN to RATING_MAX
     */
    public function setRating(User|int $user, int $mangaId, ?int $rating): SeriesState
    {
        if ($rating !== null && ($rating < self::RATING_MIN || $rating > self::RATING_MAX)) {
            throw new \InvalidArgumentException(
                'A rating must be between '.self::RATING_MIN.' and '.self::RATING_MAX
                .', or null to clear it. Got '.var_export($rating, true).'.'
            );
        }

        $state = $this->loadOrCreate($user, $mangaId);

        /*
         * Written as a string because that is what the column holds.
         *
         * `decimal:1` casts through `BigDecimal::of(...)->toScale(1, HalfUp)`, which returns a
         * string, and the model declares `string|null`. Assigning the int works at runtime
         * because the cast stringifies it on the way in, but it is the wrong type to hand the
         * attribute, and an int here would mean a different value depending on which path set
         * the row: `4` from this line and `"4.0"` from anything that read and wrote it back.
         *
         * "4" in, "4.0" stored. The one decimal place is the cast's job, not this method's.
         */
        $state->rating = $rating === null ? null : (string) $rating;
        $state->save();

        return $state;
    }

    /**
     * Replace a series' tags with a normalised set.
     *
     * The whole set rather than an add or a remove, because the reader's own view of their
     * tags is the set and a component holding a stale copy of it is the bug this avoids. Two
     * quick edits in a row each read the current row, so neither loses the other's work.
     *
     * Normalisation lives here rather than in the component for the same reason the incognito
     * gate does: every future caller would otherwise have to remember it, and the cost of
     * forgetting is a column holding `'action  '` and `'Action'` and `'ACTION'` as three
     * distinct tags. See {@see normaliseTags()} for what it guarantees.
     *
     * An empty set is stored as `null` rather than `[]`, matching every other "nothing here"
     * in this class. `whereNotNull('tags')` then means "has tags" with no emptiness test.
     *
     * @param  array<int, mixed>  $tags  passed through {@see normaliseTags()}, which drops
     *                                   anything that is not a string
     */
    public function setTags(User|int $user, int $mangaId, array $tags): SeriesState
    {
        $state = $this->loadOrCreate($user, $mangaId);
        $normalised = $this->normaliseTags($tags);

        $state->tags = $normalised === [] ? null : $normalised;
        $state->save();

        return $state;
    }

    /**
     * Add one tag, keeping the rest.
     *
     * A separate method because "the reader typed a new label" is not a set replacement, and
     * making the component read-modify-write would put a read and a write on a round trip
     * that already has one.
     *
     * The row is re-read inside the transaction and locked, so two tabs adding two tags at the
     * same moment cannot lose one. That is trap 52's shape one level down: an unlocked
     * read-modify-write on a row two people are editing at once.
     */
    public function addTag(User|int $user, int $mangaId, string $tag): SeriesState
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        return SeriesState::query()->getConnection()->transaction(function () use ($userId, $mangaId, $tag): SeriesState {
            $state = SeriesState::query()
                ->forUser($userId)
                ->where('suwayomi_manga_id', $mangaId)
                ->lockForUpdate()
                ->first();

            // Missing rather than absent: loadOrCreate() would insert inside this transaction
            // and then the update below would be a second statement for no reason.
            if ($state === null) {
                $state = SeriesState::createFor($userId, ['suwayomi_manga_id' => $mangaId]);
            }

            $state->tags = $this->normaliseTags([...($state->tags ?? []), $tag]) ?: null;
            $state->save();

            return $state;
        });
    }

    /**
     * Remove one tag, keeping the rest.
     *
     * Removes case-insensitively, because {@see normaliseTags()} dedupes that way and a
     * reader who typed `Action` and then typed `action` has two tags that are one tag.
     */
    public function removeTag(User|int $user, int $mangaId, string $tag): SeriesState
    {
        $state = $this->loadOrCreate($user, $mangaId);

        $needle = $this->foldTag($tag);
        $state->tags = $this->normaliseTags(
            array_values(array_filter(
                $state->tags ?? [],
                fn (string $existing): bool => $this->foldTag($existing) !== $needle,
            ))
        ) ?: null;

        $state->save();

        return $state;
    }

    /**
     * How long a tag may be, in characters.
     *
     * 40, which is long enough for "reading order: official" and short enough that a row of
     * chips stays on one line in the sidebar this app is built for. Counted in characters
     * rather than bytes because the limit is about layout.
     */
    public const TAG_MAX_LENGTH = 40;

    /**
     * How many tags one series may carry.
     *
     * 20, measured against the same constraint: at the chip size used on the series page this
     * is about four rows, which is the point where a set stops being scannable. Beyond it a
     * reader is filing rather than labelling, and a shelf is the right tool for that.
     *
     * The excess is dropped silently rather than refused, and that is a deliberate asymmetry
     * with {@see setRating()}: a rating of 9 is a malformed value, while a seventeenth tag is
     * a real tag the reader wants. Truncation loses the tail of a long list, so the caller
     * tells the reader it happened rather than letting it look like the tags were saved.
     */
    public const TAG_MAX_COUNT = 20;

    /**
     * The guarantees a stored tag list holds to.
     *
     * In order:
     *
     *  - trimmed, with internal runs of whitespace collapsed to one space, so `'  slow burn '`
     *    and `'slow burn'` are one tag rather than two that differ invisibly
     *  - non-empty, so a list of blanks never becomes a set of empty strings
     *  - deduped case-insensitively keeping the first spelling, so `Action` and `action`
     *    collapse but the reader's own capitalisation survives
     *  - no longer than {@see TAG_MAX_LENGTH}, truncated rather than rejected
     *  - at most {@see TAG_MAX_COUNT} entries
     *  - sorted case-insensitively, so the rendered order does not depend on the order things
     *    happened to be typed
     *
     * The sort is the one choice here that could go the other way. Insertion order would
     * preserve a grouping the reader made by typing, and nothing in the UI lets them reorder
     * afterwards, so that grouping would become invisible the moment they reloaded. Alphabetical
     * is predictable and has no hidden state.
     *
     * @param  array<int, mixed>  $tags
     * @return array<int, string>
     */
    public function normaliseTags(array $tags): array
    {
        $seen = [];
        $out = [];

        foreach ($tags as $tag) {
            /*
             * Not defensive noise, and that is why the parameter is `mixed` rather than
             * `string`. A tag arrives from a Livewire action, so it arrives from a request body,
             * and a caller sending `{"tags":{"0":{"a":1}}}` would put an array into the column's
             * JSON without this line. Typing the input as trustworthy strings would only move
             * that failure somewhere less visible.
             */
            if (! is_string($tag)) {
                continue;
            }

            $clean = trim((string) preg_replace('/\s+/u', ' ', $tag));

            if ($clean === '') {
                continue;
            }

            $clean = mb_substr($clean, 0, self::TAG_MAX_LENGTH);

            $fold = $this->foldTag($clean);

            if (isset($seen[$fold])) {
                continue;
            }

            $seen[$fold] = true;
            $out[] = $clean;

            if (count($out) >= self::TAG_MAX_COUNT) {
                break;
            }
        }

        usort($out, fn (string $a, string $b): int => strcasecmp($a, $b));

        return $out;
    }

    /**
     * The case-insensitive key two spellings of one tag share.
     *
     * Private because nothing outside should be inventing its own comparison, and the one
     * thing that must not happen is a removal that misses a tag because the casing differed.
     */
    private function foldTag(string $tag): string
    {
        return mb_strtolower(trim($tag));
    }

    /**
     * Record that a chapter was opened, and move the series pointer with it.
     *
     * Both writes go in one transaction because a chapter marked read with no
     * series pointer leaves "continue reading" pointing at the wrong chapter,
     * which is the single most annoying state for a reader to be in.
     */
    public function openChapter(User|int $user, int $mangaId, int $chapterId): ChapterState
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        /*
         * Incognito, and this is the write that would leave the most behind: it stamps
         * `last_read_at` on the chapter and on the series and moves the continue-reading
         * pointer, so a reader with the source on incognito would otherwise appear in their own
         * history having read a chapter they chose not to record.
         *
         * An unsaved model is returned rather than a saved one, and that is deliberate rather
         * than convenient. Callers read `last_page_read` off the result, which is correctly
         * null for a chapter this reader has never recorded, so the reader opens at page one as
         * it should. Returning a model rather than null keeps every existing caller working,
         * which is the difference between a feature and a rewrite.
         */
        if ($this->suppresses($userId, $mangaId)) {
            return $this->chapter($userId, $mangaId, $chapterId);
        }

        return ChapterState::query()->getConnection()->transaction(function () use ($userId, $mangaId, $chapterId): ChapterState {
            $chapter = $this->chapter($userId, $mangaId, $chapterId);
            $chapter->last_read_at = now();
            $chapter->save();

            $series = $this->loadOrCreate($userId, $mangaId);
            $series->last_read_chapter_id = $chapterId;
            $series->last_read_at = now();
            $series->save();

            return $chapter;
        });
    }

    /**
     * Record the furthest page read.
     *
     * Never moves backwards. A reader scrolling back to re-read a page should not
     * lose their place, and "resume" means forward, not where they happen to be.
     */
    public function recordProgress(User|int $user, int $mangaId, int $chapterId, int $page, ?int $totalPages = null): ChapterState
    {
        /*
         * Incognito. This is the endpoint the browser pings every few seconds, so it is the
         * write a reader would leave the most of, and returning an unsaved model means the
         * endpoint still answers with a page number rather than 500ing on a null.
         */
        if ($this->suppresses($user, $mangaId)) {
            return $this->chapter($user, $mangaId, $chapterId);
        }

        $chapter = $this->chapter($user, $mangaId, $chapterId);

        $chapter->last_page_read = max((int) $chapter->last_page_read, $page);
        $chapter->last_read_at = now();

        /*
         * Read is inferred once we know the count. Suwayomi's own pageCount is -1
         * until the pages are fetched, so a page number alone cannot decide this
         * and the reader page has to pass the count it just learned.
         */
        if ($totalPages !== null && $totalPages > 0) {
            $chapter->is_read = $chapter->last_page_read >= $totalPages - 1;
        }

        $chapter->save();

        return $chapter;
    }

    public function addReadSeconds(User|int $user, int $chapterId, int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        /*
         * Read time is read state by any reading of the phrase: it is a measurement of having
         * read, not something the reader decided. It takes a manga id of 0 because the caller
         * has only a chapter id, which means there is nothing here to resolve the source from.
         *
         * The existing row's own `suwayomi_manga_id` is the series, so that is what this asks
         * about. Nothing calls this method today (measured: one declaration, no callers), and
         * the signature is why: it is the one read-state writer that cannot answer the
         * incognito question from its own arguments. Fixing that is a caller-side change and
         * is recorded rather than guessed at.
         */
        $chapter = $this->chapter($user, 0, $chapterId);

        if ($chapter->exists) {
            $chapter->read_seconds = (int) $chapter->read_seconds + $seconds;
            $chapter->save();
        }
    }

    public function setBookmark(User|int $user, int $mangaId, int $chapterId, bool $bookmarked): ChapterState
    {
        $chapter = $this->chapter($user, $mangaId, $chapterId);
        $chapter->is_bookmarked = $bookmarked;
        $chapter->save();

        return $chapter;
    }

    public function markRead(User|int $user, int $mangaId, int $chapterId, bool $read = true): ChapterState
    {
        $chapter = $this->chapter($user, $mangaId, $chapterId);
        $chapter->is_read = $read;

        if ($read) {
            $chapter->last_read_at = now();
        }

        $chapter->save();

        return $chapter;
    }

    /**
     * Point continue-reading at a chapter, or nowhere.
     *
     * A separate write path rather than a model save from the caller, because this
     * class is the only sanctioned writer of series state and the pointer decides
     * what the reader is offered next. Null is meaningful: it is how "mark all
     * unread" says there is nowhere to continue from, rather than leaving the
     * pointer on a chapter that is now unread.
     */
    public function setPointer(User|int $user, int $mangaId, ?int $chapterId): void
    {
        $state = $this->loadOrCreate($user, $mangaId);
        $state->last_read_chapter_id = $chapterId;

        if ($chapterId !== null) {
            $state->last_read_at = now();
        }

        $state->save();
    }

    /**
     * Mark a whole set of chapters read or unread, in one write path.
     *
     * Upsert rather than a mass update, because a mass update against rows that do
     * not exist yet writes nothing: the chapters a reader has never opened have no
     * `chapter_states` row, and "mark all read" is mostly those. The interface would
     * report a change that never reached the database.
     *
     * Only the read flag and its timestamp are written on existing rows, so a
     * reader's page position survives being marked unread and back.
     *
     * @param  array<int, int>  $chapterIds
     */
    public function markManyRead(User|int $user, int $mangaId, array $chapterIds, bool $read = true): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $chapterIds))));

        if ($ids === []) {
            return;
        }

        $userId = $user instanceof User ? (int) $user->getKey() : $user;
        $now = now();

        /*
         * The inserted rows carry the end state as well as the conflict update,
         * because for a row that does not exist yet the insert IS the write:
         * ON CONFLICT never fires, and the database default of is_read = false
         * would stand for exactly the chapters "mark all read" was pressed for.
         */
        $updates = $read
            ? ['is_read' => true, 'last_read_at' => $now, 'updated_at' => $now]
            : ['is_read' => false, 'updated_at' => $now];

        $rows = array_map(static fn (int $id): array => [
            'user_id' => $userId,
            'suwayomi_chapter_id' => $id,
            'suwayomi_manga_id' => $mangaId,
            'is_read' => $read,
            'last_read_at' => $read ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $ids);

        foreach (array_chunk($rows, 200) as $chunk) {
            ChapterState::query()->upsert($chunk, ['user_id', 'suwayomi_chapter_id'], $updates);
        }
    }

    /**
     * The chapters a reader has marked read, per series, in one grouped query.
     *
     * This is the export's ledger half: a fresh Suwayomi export marks what the server
     * learned, and this method is what the chapter the server never opened looks like from
     * the reader half. One query per call, grouped by series, because an export walks a
     * whole library and one query per series is a library's worth of round trips.
     *
     * @param  array<int, int>  $mangaIds
     * @return array<int, list<int>> chapter ids carrying `is_read = true`, keyed by series id
     */
    public function readChapterIdsByManga(User|int $user, array $mangaIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $mangaIds)));

        if ($ids === []) {
            return [];
        }

        $rows = DB::connection()->table('chapter_states')
            ->where('user_id', $user instanceof User ? (int) $user->getKey() : $user)
            ->where('is_read', true)
            ->whereIn('suwayomi_manga_id', $ids)
            ->get(['suwayomi_manga_id', 'suwayomi_chapter_id']);

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->suwayomi_manga_id][] = (int) $row->suwayomi_chapter_id;
        }

        return $out;
    }

    /**
     * Read state for a whole chapter list, keyed by chapter id.
     *
     * One query for the list rather than one per chapter: a 1000 chapter series
     * would otherwise issue 1000 queries to render a page of 50.
     *
     * @param  array<int, int>  $chapterIds
     * @return array<int, ChapterState>
     */
    public function chapterStates(User|int $user, array $chapterIds): array
    {
        if ($chapterIds === []) {
            return [];
        }

        return ChapterState::forUser($user instanceof User ? $user->getKey() : $user)
            ->whereIn('suwayomi_chapter_id', $chapterIds)
            ->get()
            ->keyBy('suwayomi_chapter_id')
            ->all();
    }

    /**
     * How many chapters in this list the reader has finished.
     *
     * @param  array<int, array<string, mixed>>  $chapters
     * @param  array<int, ChapterState>  $states
     */
    public function readCount(array $chapters, array $states): int
    {
        $read = 0;

        foreach ($chapters as $chapter) {
            $state = $states[(int) ($chapter['id'] ?? 0)] ?? null;

            if ($state !== null && $state->is_read) {
                $read++;
            }
        }

        return $read;
    }

    // Migration ------------------------------------------------------------

    /**
     * Moves a reader's whole state for one series onto another series.
     *
     * The only reason this lives here and not in the migrator: this class is the
     * only sanctioned path to series and chapter state rows, and a migration writes
     * both of them in bulk. Reaching for the models directly from a caller would be
     * the first place in the app that does.
     *
     * ## Why this is not a pair of updates
     *
     * Two unique indexes make a naive remap fail rather than merely lose data:
     * `chapter_states` is unique on `(user_id, suwayomi_chapter_id)` and
     * `series_states` is unique on `(user_id, suwayomi_manga_id)`. So if the reader
     * has any state on the destination series already, updating a row onto a chapter
     * id that exists raises a constraint violation halfway through, and a migration
     * is exactly the operation that must not half-apply.
     *
     * The two cases are therefore separate:
     *
     *   - the destination already has a row: merge into it, then drop ours
     *   - it does not: move ours
     *
     * and the destination series state is handled the same way. All of it runs in one
     * transaction, so a failure anywhere leaves the reader where they were.
     *
     * ## What is merged rather than overwritten
     *
     * Read flags are OR-ed, page positions take the larger, read seconds add up and
     * the later timestamp wins. A reader who read chapter 3 on the old source and
     * chapter 5 on the new one has read two chapters, not one, and the seconds are
     * time genuinely spent.
     *
     * The chapter list fingerprint is cleared rather than copied. It is a hash over
     * the chapter ids in order, and every one of them is about to change, so keeping
     * it would tell the library the list is unchanged when it has entirely turned
     * over. That is the exact failure it exists to catch.
     *
     * @param  array<int, int>  $chapterMap  old chapter id to new chapter id
     * @param  bool  $carryChapters  false leaves every read row on the old id, which
     *                               is the reader declining the carry: the place stays recorded where it was,
     *                               the way stranded downloads do, and the moved series starts with no pointer
     * @param  bool  $carryShelves  false removes the old row's memberships instead
     *                              of copying them, so the series arrives unfiled; the categories themselves
     *                              are the reader's either way and nothing here deletes one
     * @return array{chapter_rows_moved: int, chapter_rows_merged: int, series_merged: bool}
     */
    public function moveSeries(
        User|int $user,
        int $fromMangaId,
        int $toMangaId,
        array $chapterMap,
        bool $carryChapters = true,
        bool $carryShelves = true,
    ): array {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        return DB::transaction(function () use ($userId, $fromMangaId, $toMangaId, $chapterMap, $carryChapters, $carryShelves): array {
            $moved = 0;
            $merged = 0;

            /*
             * Chapter rows first, because the series row's last_read_chapter_id is
             * remapped using the same table and should see the final ids. Skipped
             * whole when the reader declined the carry, so the rows stay behind on
             * the old ids rather than being rewritten or dropped.
             */
            $rows = ChapterState::forUser($userId)
                ->where('suwayomi_manga_id', $fromMangaId)
                ->get();

            foreach ($carryChapters ? $rows : [] as $row) {
                $oldId = (int) $row->suwayomi_chapter_id;
                $newId = $chapterMap[$oldId] ?? null;

                if ($newId === null || $newId === $oldId) {
                    // Unplaceable, or already pointing at itself. Left in place and
                    // inert: nothing joins on it any more, and deleting a reader's
                    // history because a new source lacked a counterpart is worse than
                    // keeping an unreachable row.
                    continue;
                }

                $existing = ChapterState::forUser($userId)
                    ->where('suwayomi_chapter_id', $newId)
                    ->first();

                if ($existing === null) {
                    $row->suwayomi_chapter_id = $newId;
                    $row->suwayomi_manga_id = $toMangaId;
                    $row->save();

                    $moved++;

                    continue;
                }

                $existing->is_read = $existing->is_read || $row->is_read;
                $existing->is_bookmarked = $existing->is_bookmarked || $row->is_bookmarked;
                $existing->last_page_read = max((int) $existing->last_page_read, (int) $row->last_page_read);
                $existing->read_seconds = (int) $existing->read_seconds + (int) $row->read_seconds;
                $existing->last_read_at = $this->latest($existing->last_read_at, $row->last_read_at);
                $existing->save();

                $row->delete();

                $merged++;
            }

            $seriesMerged = $this->moveSeriesState($userId, $fromMangaId, $toMangaId, $chapterMap, $carryChapters, $carryShelves);

            return [
                'chapter_rows_moved' => $moved,
                'chapter_rows_merged' => $merged,
                'series_merged' => $seriesMerged,
            ];
        });
    }

    /**
     * The series row itself, with the same collision handling as its chapters.
     *
     * @param  array<int, int>  $chapterMap
     */
    private function moveSeriesState(
        int $userId,
        int $fromMangaId,
        int $toMangaId,
        array $chapterMap,
        bool $carryChapters,
        bool $carryShelves,
    ): bool {
        $source = SeriesState::forUser($userId)->where('suwayomi_manga_id', $fromMangaId)->first();

        if ($source === null) {
            return false;
        }

        $target = SeriesState::forUser($userId)->where('suwayomi_manga_id', $toMangaId)->first();

        if ($target === null) {
            $source->suwayomi_manga_id = $toMangaId;

            /*
             * Without the carry the pointer names a chapter id of the old home, so
             * instead of being remapped it is dropped: resuming into a chapter id
             * this reader's new home holds no state for is the worse half of the
             * choice. The rows stayed on the old id, so the place itself survives
             * there, the way stranded downloads do.
             */
            if ($carryChapters) {
                $this->remapChapterPointer($source, $chapterMap);
            } else {
                $source->last_read_chapter_id = null;
            }

            // Cleared on this path too, not only when two rows merge. The fingerprint
            // is a hash over chapter ids in order and every one of them is about to
            // change, so keeping it tells the library the list is unchanged when it
            // has turned over entirely. Caught by a test, because the merge path had
            // it and this one did not.
            $source->chapter_list_fingerprint = null;
            $source->chapter_list_checked_at = null;

            if (! $carryShelves) {
                // On this path the row is re-pointed rather than replaced, so its
                // memberships ride along by construction. A carry the reader
                // declined must not ride, so they are removed here, on the row the
                // move is keeping.
                CategorySeries::query()->where('series_state_id', (int) $source->getKey())->delete();
            }

            $source->save();

            return false;
        }

        // The reader already had the destination filed, so the two rows are one now.
        $target->in_library = $target->in_library || $source->in_library;
        $target->favourited_at = $this->earliest($target->favourited_at, $source->favourited_at);
        $target->read_later_at = $this->earliest($target->read_later_at, $source->read_later_at);
        $target->last_read_at = $this->latest($target->last_read_at, $source->last_read_at);
        $target->added_at = $this->earliest($target->added_at, $source->added_at);
        $target->rating ??= $source->rating;
        $target->tags ??= $source->tags;
        $target->reader_settings ??= $source->reader_settings;
        $target->reading_mode ??= $source->reading_mode;
        $target->chapter_list_settings ??= $source->chapter_list_settings;
        $target->excluded_scanlators ??= $source->excluded_scanlators;
        $target->fetch_interval_days ??= $source->fetch_interval_days;
        $target->update_strategy ??= $source->update_strategy;
        $target->default_category_id ??= $source->default_category_id;

        /*
         * The last chapter read is a chapter id, so it moves with the chapters. If
         * that chapter found no counterpart the pointer is dropped rather than left
         * addressing a chapter id this reader no longer has any state for. Without
         * the carry it is the destination's own pointer that survives untouched:
         * the rows making up the old place never moved.
         */
        if ($carryChapters) {
            $lastRead = (int) ($source->last_read_chapter_id ?? 0);
            $target->last_read_chapter_id = $lastRead === 0
                ? $target->last_read_chapter_id
                : ($chapterMap[$lastRead] ?? $target->last_read_chapter_id);
        }

        // Every chapter id changed, so the fingerprint describing them is void.
        $target->chapter_list_fingerprint = null;
        $target->chapter_list_checked_at = null;

        $target->save();

        $this->mergeShelves($source, $target, $userId, $carryShelves);
        $source->delete();

        return true;
    }

    /**
     * Carry the source series' shelf memberships onto the destination, or return
     * the reader's declined ones to nothing.
     *
     * Copying is a union rather than a copy: a reader who filed the old series on
     * two shelves and the new one on a third should end up on all three, and a
     * shelf they were on must not be quietly forgotten because the destination
     * already had a row.
     *
     * Either way the source row's memberships are removed, because the row itself
     * is about to be deleted and the destination has been given what it keeps.
     * The categories themselves are the reader's, and neither path deletes one.
     *
     * Written through the query builder rather than the pivot model, because
     * `category_series` carries a `user_id` that the pivot's own definition does not
     * declare, and letting the model insert produced a NOT NULL violation halfway
     * through a migration. {@see Library::attach()} sets the same four columns and
     * this deliberately matches it.
     */
    private function mergeShelves(SeriesState $source, SeriesState $target, int $userId, bool $carryShelves = true): void
    {
        $sourceId = (int) $source->getKey();
        $targetId = (int) $target->getKey();

        if ($carryShelves) {
            $categories = CategorySeries::query()
                ->where('series_state_id', $sourceId)
                ->pluck('category_id')
                ->all();

            $existing = CategorySeries::query()
                ->where('series_state_id', $targetId)
                ->pluck('category_id')
                ->all();

            $now = now();
            $rows = [];

            foreach (array_diff($categories, $existing) as $categoryId) {
                $rows[] = [
                    'user_id' => $userId,
                    'category_id' => (int) $categoryId,
                    'series_state_id' => $targetId,
                    'added_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::connection()->table('category_series')->insert($rows);
            }
        }

        CategorySeries::query()->where('series_state_id', $sourceId)->delete();
    }

    /**
     * Point a series row at the counterpart of the chapter it last read.
     *
     * @param  array<int, int>  $chapterMap
     */
    private function remapChapterPointer(SeriesState $state, array $chapterMap): void
    {
        $lastRead = (int) ($state->last_read_chapter_id ?? 0);

        if ($lastRead === 0) {
            return;
        }

        $state->last_read_chapter_id = $chapterMap[$lastRead] ?? null;
    }

    private function latest(mixed $a, mixed $b): mixed
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return $a->gt($b) ? $a : $b;
    }

    private function earliest(mixed $a, mixed $b): mixed
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return $a->lt($b) ? $a : $b;
    }
}
