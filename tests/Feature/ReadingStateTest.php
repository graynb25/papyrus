<?php

namespace Tests\Feature;

use App\Models\ChapterState;
use App\Models\SeriesState;
use App\Models\User;
use App\Support\ReadingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadingStateTest extends TestCase
{
    use RefreshDatabase;

    private ReadingState $state;

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->state = app(ReadingState::class);
        $this->alice = User::factory()->create();
    }

    public function test_it_writes_series_state_for_a_named_user_with_no_auth_context(): void
    {
        // The reason createFor exists: a queue job or command has no authenticated
        // user, and relaxing the tenant scope to make this work would be worse.
        auth()->logout();

        $series = $this->state->setInLibrary($this->alice, 76, true);

        $this->assertSame($this->alice->id, $series->user_id);
        $this->assertDatabaseHas('series_states', [
            'user_id' => $this->alice->id,
            'suwayomi_manga_id' => 76,
            'in_library' => true,
        ]);
    }

    public function test_it_does_not_write_rows_while_rendering(): void
    {
        $this->assertNull($this->state->findSeries($this->alice, 76));
        $this->assertDatabaseCount('series_states', 0);

        $this->assertNull($this->state->findChapter($this->alice, 1));
        $this->assertDatabaseCount('chapter_states', 0);
    }

    public function test_library_membership_is_per_user(): void
    {
        $bob = User::factory()->create();

        $this->state->setInLibrary($this->alice, 76, true);

        $this->assertTrue((bool) $this->state->findSeries($this->alice, 76)?->in_library);
        $this->assertNull($this->state->findSeries($bob, 76));
    }

    public function test_removing_from_the_library_clears_the_added_date(): void
    {
        $this->state->setInLibrary($this->alice, 76, true);
        $added = $this->state->findSeries($this->alice, 76)?->added_at;

        $this->assertNotNull($added);

        $this->state->setInLibrary($this->alice, 76, false);

        $series = $this->state->findSeries($this->alice, 76);

        $this->assertFalse((bool) $series->in_library);
        $this->assertNull($series->added_at, 'A removed series has no add date.');
    }

    public function test_favourite_and_read_later_are_independent(): void
    {
        $this->state->setFavourited($this->alice, 76, true);

        $series = $this->state->findSeries($this->alice, 76);

        $this->assertNotNull($series->favourited_at);
        $this->assertNull($series->read_later_at);
        $this->assertFalse((bool) $series->in_library);
    }

    /**
     * A chapter marked read with no series pointer leaves "continue reading"
     * pointing at the wrong chapter, so the two writes are one transaction.
     */
    public function test_opening_a_chapter_moves_the_series_pointer_with_it(): void
    {
        $this->state->openChapter($this->alice, 76, 12);

        $this->assertDatabaseHas('chapter_states', [
            'user_id' => $this->alice->id,
            'suwayomi_chapter_id' => 12,
            'suwayomi_manga_id' => 76,
        ]);

        $series = $this->state->findSeries($this->alice, 76);

        $this->assertSame(12, $series->last_read_chapter_id);
        $this->assertNotNull($series->last_read_at);
    }

    public function test_progress_never_moves_backwards(): void
    {
        $this->state->recordProgress($this->alice, 76, 1, 10);
        $this->state->recordProgress($this->alice, 76, 1, 3);

        $this->assertSame(10, $this->state->findChapter($this->alice, 1)?->last_page_read);
    }

    /**
     * Suwayomi reports pageCount -1 until a chapter's pages have been fetched, so
     * a page number alone cannot decide "read". The reader passes the count it
     * just learned, and without it nothing is inferred.
     */
    public function test_a_chapter_is_only_marked_read_when_the_page_count_is_known(): void
    {
        $this->state->recordProgress($this->alice, 76, 1, 21);

        $this->assertFalse((bool) $this->state->findChapter($this->alice, 1)?->is_read);

        $this->state->recordProgress($this->alice, 76, 1, 21, totalPages: 22);

        $this->assertTrue((bool) $this->state->findChapter($this->alice, 1)?->is_read);
    }

    /**
     * Page 21 of a 22 page chapter is the last page, but page 21 of a 40 page
     * chapter is not. An off-by-one here marks half-read chapters as finished.
     */
    public function test_the_last_page_is_the_count_minus_one(): void
    {
        $this->state->recordProgress($this->alice, 76, 1, 21, totalPages: 22);
        $this->assertTrue((bool) $this->state->findChapter($this->alice, 1)?->is_read);

        $this->state->recordProgress($this->alice, 76, 2, 21, totalPages: 40);
        $this->assertFalse((bool) $this->state->findChapter($this->alice, 2)?->is_read);
    }

    public function test_a_zero_page_count_never_marks_a_chapter_read(): void
    {
        $this->state->recordProgress($this->alice, 76, 1, 0, totalPages: 0);

        $this->assertFalse((bool) $this->state->findChapter($this->alice, 1)?->is_read);
    }

    public function test_read_time_accumulates_and_ignores_nonsense(): void
    {
        ChapterState::factory()
            ->for($this->alice)
            ->create(['suwayomi_chapter_id' => 5, 'suwayomi_manga_id' => 76, 'read_seconds' => 100]);

        $this->state->addReadSeconds($this->alice, 5, 30);
        $this->state->addReadSeconds($this->alice, 5, -5);
        $this->state->addReadSeconds($this->alice, 5, 0);

        $this->assertSame(130, $this->state->findChapter($this->alice, 5)?->read_seconds);
    }

    /**
     * addReadSeconds has no manga id, so it must not create a row for a chapter
     * the reader never opened.
     */
    public function test_read_time_is_not_recorded_for_a_chapter_that_does_not_exist(): void
    {
        $this->state->addReadSeconds($this->alice, 999, 30);

        $this->assertDatabaseCount('chapter_states', 0);
    }

    public function test_it_loads_a_whole_chapter_list_in_one_query(): void
    {
        foreach ([1, 2, 3, 4, 5] as $id) {
            ChapterState::factory()
                ->for($this->alice)
                ->create(['suwayomi_chapter_id' => $id, 'suwayomi_manga_id' => 76]);
        }

        $states = $this->state->chapterStates($this->alice, [1, 2, 3, 99]);

        $this->assertCount(3, $states);
        $this->assertArrayHasKey(2, $states);
        $this->assertArrayNotHasKey(99, $states);

        // One query, not one per chapter. A 1000 chapter series would otherwise
        // issue 1000 queries to render a page of 50.
        DB::enableQueryLog();
        $this->state->chapterStates($this->alice, [1, 2, 3, 4, 5]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries);
    }

    public function test_an_empty_id_list_does_not_query(): void
    {
        $this->assertSame([], $this->state->chapterStates($this->alice, []));
    }

    public function test_it_counts_read_chapters_across_a_list(): void
    {
        foreach ([1 => true, 2 => false, 3 => true] as $id => $read) {
            ChapterState::factory()
                ->for($this->alice)
                ->create([
                    'suwayomi_chapter_id' => $id,
                    'suwayomi_manga_id' => 76,
                    'is_read' => $read,
                ]);
        }

        $chapters = [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]];
        $states = $this->state->chapterStates($this->alice, [1, 2, 3, 4]);

        $this->assertSame(2, $this->state->readCount($chapters, $states));
    }

    public function test_marking_read_and_unmarking_both_stick(): void
    {
        $this->state->markRead($this->alice, 76, 7, true);
        $this->assertTrue((bool) $this->state->findChapter($this->alice, 7)?->is_read);

        $this->state->markRead($this->alice, 76, 7, false);
        $this->assertFalse((bool) $this->state->findChapter($this->alice, 7)?->is_read);
    }

    public function test_a_chapter_state_cannot_be_written_for_another_user(): void
    {
        $bob = User::factory()->create();

        $this->state->openChapter($this->alice, 76, 12);

        $this->assertNull($this->state->findChapter($bob, 12));
        $this->assertDatabaseMissing('chapter_states', [
            'user_id' => $bob->id,
            'suwayomi_chapter_id' => 12,
        ]);
    }

    public function test_a_series_state_row_is_unique_per_user_and_manga(): void
    {
        $this->state->setInLibrary($this->alice, 76, true);
        $this->state->setFavourited($this->alice, 76, true);

        // Two calls must not create two rows.
        $this->assertSame(1, SeriesState::forUser($this->alice->id)->count());
    }
}
