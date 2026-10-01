<?php

namespace App\Support;

use App\Models\ChapterState;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Per-chapter notes: one memo per chapter, written from the reader or the series page.
 *
 * ## Why this is a thin class over a table that already existed
 *
 * Migration 5 built a polymorphic `notes` table, migration 5's model is `Note`, and
 * `ChapterState::notes()` has been declared since. Nothing wrote to it: measured 0 rows.
 * So the storage question was answered before the feature was, and this is the write
 * path and the reads that go with it rather than a schema.
 *
 * ## Why a note may sit on a chapter nobody has read
 *
 * A note points at a `ChapterState` row, and rows exist only for chapters the reader
 * opened. Writing one therefore has to create that row, which would be careless if it
 * fed anything counted. Measured, it feeds nothing counted: the read count, the
 * continue-reading feed and unread all filter `chapter_states` on `is_read`, so a row
 * with `is_read` false moves the read count by 0. The one consumer that sees every row
 * is the chapter navigator, and it reads such a row as `unknown`, which is what an
 * unopened chapter should read as.
 *
 * So the note sits on any chapter in the series, and "read this one first" is
 * expressible, which is the most useful thing a per-chapter note can say.
 */
class ChapterNotes
{
    public function __construct(
        private readonly ReadingState $reading,
    ) {}

    /**
     * This reader's note on one chapter, or null.
     */
    public function find(User|int $user, int $chapterId): ?Note
    {
        if ($chapterId === 0) {
            return null;
        }

        return $this->query($user)
            ->where('noteable_type', $this->targetType())
            ->where('noteable_id', $this->targetId($user, $chapterId))
            ->first();
    }

    /**
     * This reader's notes for a set of chapters, keyed by chapter id.
     *
     * One query for the whole series page rather than one per chapter, because the page
     * renders every chapter and a 107 chapter series would otherwise cost 107 queries.
     * A chapter with no note is simply absent from the map, which is cheaper than
     * storing a null and means the caller cannot tell an absent note from an empty one
     * by forgetting to check.
     *
     * @param  array<int, int>  $chapterIds
     * @return array<int, Note>
     */
    public function forChapters(User|int $user, array $chapterIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $chapterIds)));

        if ($ids === []) {
            return [];
        }

        // The target ids are chapter_states row ids, so the chapter ids have to be
        // resolved first. One query, and it is the same one the read path needs.
        $states = $this->states($user, $ids);

        if ($states === []) {
            return [];
        }

        $byId = [];

        foreach ($states as $chapterId => $state) {
            $byId[(int) $state->getKey()] = $chapterId;
        }

        $rows = $this->query($user)
            ->where('noteable_type', $this->targetType())
            ->whereIn('noteable_id', array_keys($byId))
            ->get();

        $notes = [];

        foreach ($rows as $note) {
            $chapterId = $byId[(int) $note->noteable_id] ?? null;

            if ($chapterId !== null) {
                $notes[$chapterId] = $note;
            }
        }

        return $notes;
    }

    /**
     * Save a note, or clear it when the body is empty.
     *
     * Empty clears rather than saving a blank, so the client needs one verb and does
     * not have to know which of "save" and "delete" a reader meant by clearing the box.
     *
     * @return array{saved: bool, cleared: bool, body: string}
     */
    public function save(User|int $user, int $mangaId, int $chapterId, string $body): array
    {
        $body = trim($body);

        $existing = $this->find($user, $chapterId);

        if ($body === '') {
            $existing?->delete();

            return ['saved' => false, 'cleared' => $existing !== null, 'body' => ''];
        }

        $state = $this->state($user, $mangaId, $chapterId);

        if ($existing === null) {
            $note = Note::createFor($user, [
                'noteable_type' => $this->targetType(),
                'noteable_id' => $state->getKey(),
                'body' => $body,
            ]);
        } else {
            $existing->body = $body;
            $existing->save();
            $note = $existing;
        }

        return ['saved' => true, 'cleared' => false, 'body' => $note->body];
    }

    /**
     * How many chapters in a set carry a note, for a badge.
     *
     * @param  array<int, int>  $chapterIds
     */
    public function countForChapters(User|int $user, array $chapterIds): int
    {
        return count($this->forChapters($user, $chapterIds));
    }

    /**
     * This reader's `chapter_states` rows for a set of chapters, keyed by chapter id.
     *
     * @param  array<int, int>  $chapterIds
     * @return array<int, ChapterState>
     */
    private function states(User|int $user, array $chapterIds): array
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        $rows = ChapterState::query()
            ->forUser($userId)
            ->whereIn('suwayomi_chapter_id', $chapterIds)
            ->get();

        $byChapter = [];

        foreach ($rows as $state) {
            $byChapter[(int) $state->suwayomi_chapter_id] = $state;
        }

        return $byChapter;
    }

    /**
     * The `chapter_states` row a note hangs off, created if the chapter was never opened.
     */
    private function state(User|int $user, int $mangaId, int $chapterId): ChapterState
    {
        $state = $this->reading->chapter($user, $mangaId, $chapterId);

        // `chapter()` returns an existing row or an unsaved one, and only the second
        // needs writing. Measured: an unsaved row moves no count, because every reader
        // of chapter_states filters on is_read.
        if (! $state->exists) {
            $state->save();
        }

        return $state;
    }

    /**
     * @return Builder<Note>
     */
    private function query(User|int $user): Builder
    {
        return Note::query()->forUser($user instanceof User ? $user->getKey() : $user);
    }

    /**
     * The polymorphic type, stored as the model class because no morph map is set.
     */
    private function targetType(): string
    {
        return ChapterState::class;
    }

    /**
     * The target id for a chapter, or 0 when the reader has no row for it.
     *
     * The 0 is what makes a missing chapter read as "no note" rather than matching
     * something: a note's target id is a chapter_states row id, and row id 0 does not
     * exist.
     */
    private function targetId(User|int $user, int $chapterId): int
    {
        $states = $this->states($user, [$chapterId]);

        return isset($states[$chapterId]) ? (int) $states[$chapterId]->getKey() : 0;
    }
}
