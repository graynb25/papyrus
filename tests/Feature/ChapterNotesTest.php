<?php

namespace Tests\Feature;

use App\Library\ChapterNavigator;
use App\Library\ContinueReading;
use App\Livewire\Series;
use App\Models\ChapterState;
use App\Models\Note;
use App\Models\SeriesState;
use App\Models\User;
use App\Support\ChapterNotes;
use App\Support\ReadingState;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * Per-chapter notes.
 *
 * ## What these hold, and what they are protecting
 *
 * The storage already existed and was unused: a polymorphic `notes` table from
 * migration 5, a `Note` model, and `ChapterState::notes()`. Measured 0 rows. So the
 * design decisions are the ones a fresh feature forces, not the ones a new schema
 * forces.
 *
 * Two of them matter enough to be tested as claims rather than as coverage:
 *
 *  - **A note may sit on a chapter nobody has read.** It needs a `chapter_states` row,
 *    which exists only for chapters the reader opened. Measured, such a row moves the
 *    read count by 0, because the read count, the continue-reading feed and unread all
 *    filter `chapter_states` on `is_read`. "Read this one first" is the most useful
 *    thing a per-chapter note can say, so it is a feature rather than an accident.
 *
 *  - **One note per chapter, as a database fact.** Migration 22 adds a unique index,
 *    so this holds whatever the application code does. These tests check the index
 *    exists rather than only that two saves produce one row.
 */
class ChapterNotesTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->fakeSuwayomi();
    }

    /**
     * Chapter 7601 has pages, so the reader renders rather than the error view.
     */
    private function fakeSuwayomi(): void
    {
        Http::fake(function (Request $request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if (str_contains($query, 'fetchChapterPages')) {
                $chapterId = (int) (data_get($request->data(), 'variables.id') ?? 0);

                return Http::response(['data' => ['fetchChapterPages' => [
                    'chapter' => ['id' => (string) $chapterId, 'name' => 'Chapter', 'pageCount' => 3],
                    'pages' => [
                        "/api/v1/manga/76/chapter/{$chapterId}/page/0",
                        "/api/v1/manga/76/chapter/{$chapterId}/page/1",
                        "/api/v1/manga/76/chapter/{$chapterId}/page/2",
                    ],
                ]]]);
            }

            /*
             * The series page fetches one series rather than a batch, and without this
             * branch its component mounts with no chapters at all, so a computed note
             * list correctly comes back empty and the test fails for the wrong reason.
             */
            if (str_contains($query, 'manga(id:') || str_contains($query, 'manga(id :')) {
                return Http::response(['data' => ['manga' => $this->manga()]]);
            }

            $ids = (array) (data_get($request->data(), 'variables.ids') ?? []);

            $nodes = [];

            foreach ($ids as $mangaId) {
                $nodes[] = [
                    'id' => (string) $mangaId,
                    'title' => 'Series '.$mangaId,
                    'chapters' => ['totalCount' => 3, 'nodes' => [
                        ['id' => '7601', 'name' => 'Chapter 1', 'chapterNumber' => 1, 'sourceOrder' => 1],
                        ['id' => '7602', 'name' => 'Chapter 2', 'chapterNumber' => 2, 'sourceOrder' => 2],
                        ['id' => '7603', 'name' => 'Chapter 3', 'chapterNumber' => 3, 'sourceOrder' => 3],
                    ]],
                ];
            }

            return Http::response(['data' => ['mangas' => ['nodes' => $nodes]]]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function manga(): array
    {
        return [
            'id' => '76',
            'title' => 'Series 76',
            'url' => '/series/x',
            'realUrl' => 'https://example.test/comics/x',
            'thumbnailUrl' => '/api/v1/manga/76/thumbnail',
            'status' => 'ONGOING',
            'author' => 'Nobody',
            'artist' => 'Nobody',
            'genre' => ['Action'],
            'description' => 'Rating: 8.24',
            'initialized' => true,
            'unreadCount' => 3,
            'updateStrategy' => 'ALWAYS_UPDATE',
            'chaptersLastFetchedAt' => '1790776498',
            'source' => ['id' => '6247824327199706550', 'name' => 'Example', 'lang' => 'en', 'contentWarning' => 'SAFE'],
            'chapters' => ['totalCount' => 3, 'nodes' => [
                ['id' => '7601', 'name' => 'Chapter 1', 'chapterNumber' => 1, 'sourceOrder' => 1, 'uploadDate' => null],
                ['id' => '7602', 'name' => 'Chapter 2', 'chapterNumber' => 2, 'sourceOrder' => 2, 'uploadDate' => null],
                ['id' => '7603', 'name' => 'Chapter 3', 'chapterNumber' => 3, 'sourceOrder' => 3, 'uploadDate' => null],
            ]],
        ];
    }

    private function notes(): ChapterNotes
    {
        return app(ChapterNotes::class);
    }

    // The write path -------------------------------------------------------

    public function test_a_saved_note_comes_back(): void
    {
        $result = $this->notes()->save($this->user, 76, 7601, 'The fight at the docks.');

        $this->assertTrue($result['saved']);
        $this->assertSame('The fight at the docks.', $result['body']);

        $this->assertSame(
            'The fight at the docks.',
            $this->notes()->find($this->user, 7601)?->body,
        );
    }

    public function test_saving_again_overwrites_rather_than_appending(): void
    {
        $this->notes()->save($this->user, 76, 7601, 'First thought.');
        $this->notes()->save($this->user, 76, 7601, 'Second thought.');

        $this->assertSame(
            1,
            Note::query()->forUser($this->user->id)->count(),
            'A memo has one value. Two notes on one chapter would be a journal.',
        );

        $this->assertSame('Second thought.', $this->notes()->find($this->user, 7601)?->body);
    }

    public function test_an_empty_body_clears_rather_than_saving_a_blank(): void
    {
        $this->notes()->save($this->user, 76, 7601, 'Something.');

        $result = $this->notes()->save($this->user, 76, 7601, '');

        $this->assertFalse($result['saved']);
        $this->assertTrue($result['cleared']);
        $this->assertNull($this->notes()->find($this->user, 7601));
    }

    public function test_whitespace_alone_clears(): void
    {
        $this->notes()->save($this->user, 76, 7601, 'Something.');

        $this->notes()->save($this->user, 76, 7601, "  \n\t ");

        $this->assertNull($this->notes()->find($this->user, 7601));
    }

    public function test_a_body_is_trimmed_before_it_is_stored(): void
    {
        $result = $this->notes()->save($this->user, 76, 7601, "  Padded.  \n");

        $this->assertSame('Padded.', $result['body']);
    }

    public function test_clearing_a_chapter_with_no_note_is_harmless(): void
    {
        $result = $this->notes()->save($this->user, 76, 7601, '');

        $this->assertFalse($result['saved']);
        $this->assertFalse($result['cleared'], 'Nothing was there to clear.');
    }

    public function test_several_chapters_carry_separate_notes(): void
    {
        $this->notes()->save($this->user, 76, 7601, 'One.');
        $this->notes()->save($this->user, 76, 7602, 'Two.');

        $this->assertSame('One.', $this->notes()->find($this->user, 7601)?->body);
        $this->assertSame('Two.', $this->notes()->find($this->user, 7602)?->body);
    }

    // The unique index ----------------------------------------------------

    public function test_one_note_per_chapter_is_enforced_by_the_database(): void
    {
        $this->notes()->save($this->user, 76, 7601, 'First.');

        $state = ChapterState::query()
            ->forUser($this->user->id)
            ->where('suwayomi_chapter_id', 7601)
            ->firstOrFail();

        /*
         * A raw insert, not a second call to save(). The claim is that the index is
         * what stops it, and the app's own upsert would never produce the duplicate, so
         * testing through the app would prove nothing about the index.
         */
        $this->expectException(UniqueConstraintViolationException::class);

        DB::connection()->table('notes')->insert([
            'user_id' => $this->user->id,
            'noteable_type' => ChapterState::class,
            'noteable_id' => $state->getKey(),
            'body' => 'Second, by another route.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_unique_key_includes_the_noteable_type(): void
    {
        /*
         * Without the type in the key, a note on chapter 7 and a note on series 7 would
         * collide, because ids are per table. A series id of the same number as a
         * chapter id has to be able to carry its own note.
         */
        $state = ChapterState::createFor($this->user, [
            'suwayomi_manga_id' => 76,
            'suwayomi_chapter_id' => 7601,
        ]);

        $this->notes()->save($this->user, 76, 7601, 'On the chapter.');

        // A row with the same id and a different type is a different note.
        DB::connection()->table('notes')->insert([
            'user_id' => $this->user->id,
            'noteable_type' => SeriesState::class,
            'noteable_id' => $state->getKey(),
            'body' => 'On the series.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(2, Note::query()->forUser($this->user->id)->count());
    }

    // A note on a chapter nobody opened -------------------------------

    public function test_a_note_can_be_written_on_a_chapter_never_opened(): void
    {
        $result = $this->notes()->save($this->user, 76, 7601, 'Read this one first.');

        $this->assertTrue($result['saved']);

        $this->assertSame(
            1,
            ChapterState::query()->forUser($this->user->id)->count(),
            'The row the note needs is created.',
        );

        $state = ChapterState::query()->forUser($this->user->id)->firstOrFail();

        $this->assertFalse((bool) $state->is_read, 'And it is not read, because nothing was.');
        $this->assertSame(0, (int) $state->last_page_read);
    }

    public function test_a_note_created_row_moves_no_read_count(): void
    {
        app(ReadingState::class)->markRead($this->user->id, 76, 7602, true);

        $before = ChapterState::query()->forUser($this->user->id)->where('is_read', true)->count();

        $this->notes()->save($this->user, 76, 7601, 'Read this one first.');

        $after = ChapterState::query()->forUser($this->user->id)->where('is_read', true)->count();

        $this->assertSame(
            $before,
            $after,
            'Every reader of chapter_states filters on is_read, so a note cannot be counted as reading.',
        );
    }

    public function test_the_navigator_reads_a_note_only_chapter_as_unknown(): void
    {
        $this->notes()->save($this->user, 76, 7601, 'Read this one first.');

        $result = app(ChapterNavigator::class)->forSeries($this->user, 76, 7601);

        $this->assertSame(
            'unknown',
            $result['chapters'][0]['state'],
            'A chapter with a note and no reading is still an unopened chapter.',
        );
    }

    public function test_the_continue_reading_feed_ignores_a_note_only_chapter(): void
    {
        SeriesState::createFor($this->user, [
            'suwayomi_manga_id' => 76,
            'in_library' => true,
        ]);

        $this->notes()->save($this->user, 76, 7601, 'Read this one first.');

        /*
         * forLibrary(), not feed(): forLibrary resolves what is next for every series,
         * and the recency filter is feed(). What a note could break here is the read
         * count, because that is what it would inflate if a note counted as reading.
         *
         * Offering chapter one for a series nothing is read on is correct: it is the
         * honest answer for an unread series, and the feed is what leaves it out.
         */
        $result = app(ContinueReading::class)->forLibrary($this->user, [
            76 => [
                'title' => 'Series 76',
                'chapters' => ['totalCount' => 3, 'nodes' => [
                    ['id' => '7601', 'name' => 'Chapter 1', 'chapterNumber' => 1, 'sourceOrder' => 1],
                    ['id' => '7602', 'name' => 'Chapter 2', 'chapterNumber' => 2, 'sourceOrder' => 2],
                ]],
            ],
        ]);

        $this->assertArrayHasKey(76, $result);
        $this->assertSame(
            0,
            $result[76]['read'],
            'A note is not reading, so it contributes nothing to the read set.',
        );
    }

    // Reading many --------------------------------------------------------

    public function test_for_chapters_returns_only_the_ones_that_carry_a_note(): void
    {
        $this->notes()->save($this->user, 76, 7602, 'Two.');

        $found = $this->notes()->forChapters($this->user, [7601, 7602, 7603]);

        $this->assertSame([7602], array_keys($found));
    }

    public function test_for_chapters_of_nothing_is_one_query_not_none(): void
    {
        $this->assertSame([], $this->notes()->forChapters($this->user, []));
    }

    public function test_for_chapters_survives_a_manga_the_reader_never_opened(): void
    {
        $this->assertSame(
            [],
            $this->notes()->forChapters($this->user, [9999]),
            'No chapter_states rows means no targets, and an empty map rather than an error.',
        );
    }

    public function test_counting_notes_across_chapters(): void
    {
        $this->notes()->save($this->user, 76, 7601, 'One.');
        $this->notes()->save($this->user, 76, 7603, 'Three.');

        $this->assertSame(2, $this->notes()->countForChapters($this->user, [7601, 7602, 7603]));
    }

    // Tenancy -------------------------------------------------------------

    public function test_another_readers_note_is_not_visible(): void
    {
        $bob = User::factory()->create();

        $this->notes()->save($bob, 76, 7601, 'Bob wrote this.');

        $this->assertNull($this->notes()->find($this->user, 7601));
    }

    public function test_saving_over_another_readers_note_writes_our_own(): void
    {
        $bob = User::factory()->create();

        $this->notes()->save($bob, 76, 7601, 'Bob wrote this.');
        $this->notes()->save($this->user, 76, 7601, 'I wrote this.');

        $this->assertSame('Bob wrote this.', $this->notes()->find($bob, 7601)?->body);
        $this->assertSame('I wrote this.', $this->notes()->find($this->user, 7601)?->body);
    }

    public function test_clearing_never_touches_another_readers_note(): void
    {
        $bob = User::factory()->create();

        $this->notes()->save($bob, 76, 7601, 'Bob wrote this.');

        $this->notes()->save($this->user, 76, 7601, '');

        $this->assertSame(
            'Bob wrote this.',
            $this->notes()->find($bob, 7601)?->body,
            'An empty save by one reader is not a delete of another reader\'s note.',
        );
    }

    public function test_a_note_carries_no_user_id_from_mass_assignment(): void
    {
        // The owner is never fillable, so a note cannot be planted on someone else
        // through a request. This is the guard, not the feature.
        $note = new Note;
        $note->fill(['body' => 'x', 'noteable_type' => ChapterState::class, 'noteable_id' => 1]);

        $this->assertArrayNotHasKey('user_id', $note->getAttributes());
    }

    // The reader route ----------------------------------------------------

    public function test_the_reader_endpoint_saves_a_note(): void
    {
        $this->putJson('/read/76/7601/note', ['body' => 'From the reader.'])
            ->assertOk()
            ->assertJson(['saved' => true, 'body' => 'From the reader.']);

        $this->assertSame('From the reader.', $this->notes()->find($this->user, 7601)?->body);
    }

    public function test_the_reader_endpoint_clears_on_an_empty_body(): void
    {
        $this->notes()->save($this->user, 76, 7601, 'Something.');

        $this->putJson('/read/76/7601/note', ['body' => ''])
            ->assertOk()
            ->assertJson(['saved' => false, 'cleared' => true]);

        $this->assertNull($this->notes()->find($this->user, 7601));
    }

    public function test_the_reader_endpoint_requires_a_body_key(): void
    {
        // `present`, not `required`: clearing is a legitimate request that sends an
        // empty string, so the key has to be there but may be empty.
        $this->putJson('/read/76/7601/note', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    public function test_the_reader_endpoint_rejects_an_enormous_body(): void
    {
        $this->putJson('/read/76/7601/note', ['body' => str_repeat('a', 10001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    public function test_the_reader_endpoint_needs_authentication(): void
    {
        auth()->logout();

        $this->putJson('/read/76/7601/note', ['body' => 'Nope.'])->assertUnauthorized();
    }

    public function test_the_reader_page_shows_the_saved_note(): void
    {
        $this->notes()->save($this->user, 76, 7601, 'The fight at the docks.');

        $this->get('/read/76/7601')
            ->assertOk()
            ->assertSee('The fight at the docks.');
    }

    public function test_the_reader_page_marks_a_chapter_that_has_a_note(): void
    {
        $this->notes()->save($this->user, 76, 7601, 'Noted.');

        $html = $this->get('/read/76/7601')->assertOk()->content();

        $this->assertStringContainsString(
            '<span class="reader-note-dot"',
            $html,
            'The dot is an element in the markup, not only a selector in the stylesheet.',
        );
    }

    public function test_the_reader_page_has_no_dot_without_a_note(): void
    {
        $html = $this->get('/read/76/7601')->assertOk()->content();

        $this->assertStringNotContainsString('<span class="reader-note-dot"', $html);
    }

    // The series page -----------------------------------------------------

    public function test_the_series_page_marks_chapters_that_carry_a_note(): void
    {
        $this->notes()->save($this->user, 76, 7602, 'Two.');

        $html = Livewire::test('pages::series', ['manga' => 76])
            ->assertOk()
            ->html();

        $this->assertStringContainsString(
            'aria-label="Read your note on this chapter"',
            $html,
            'A chapter with a note says so in the row, which is what makes it findable.',
        );
    }

    public function test_a_chapter_without_a_note_offers_to_add_one(): void
    {
        $html = Livewire::test('pages::series', ['manga' => 76])
            ->assertOk()
            ->html();

        $this->assertStringContainsString('aria-label="Add a note on this chapter"', $html);
    }

    public function test_only_the_open_chapter_shows_an_editor(): void
    {
        $this->notes()->save($this->user, 76, 7602, 'Two.');

        // Two chapters on the page and one editor, because the editor is keyed on the
        // chapter it belongs to. Two open editors would be two notes about different
        // chapters on screen at once.
        $html = Livewire::test('pages::series', ['manga' => 76])
            ->call('openNote', 7602)
            ->assertOk()
            ->html();

        $this->assertSame(
            1,
            substr_count($html, 'wire:model="noteBody"'),
            'One editor, not one per chapter.',
        );
    }

    public function test_the_editor_carries_the_chapter_it_is_for(): void
    {
        $html = Livewire::test('pages::series', ['manga' => 76])
            ->call('openNote', 7602)
            ->assertOk()
            ->html();

        $this->assertStringContainsString(
            'for="chapter-note-7602"',
            $html,
            'The label points at this chapter\'s textarea, so a reader can see which row it belongs to.',
        );
    }

    public function test_opening_the_same_chapter_again_closes_the_editor(): void
    {
        // A reader who clicks the pencil, changes their mind, and clicks again means
        // close, not "open again".
        Livewire::test('pages::series', ['manga' => 76])
            ->call('openNote', 7602)
            ->assertSet('noteEditing', 7602)
            ->call('openNote', 7602)
            ->assertSet('noteEditing', 0);
    }

    public function test_moving_to_another_chapter_moves_the_editor(): void
    {
        Livewire::test('pages::series', ['manga' => 76])
            ->call('openNote', 7602)
            ->set('noteBody', 'About chapter 2.')
            ->call('openNote', 7603)
            ->assertSet('noteEditing', 7603)
            ->assertSet('noteBody', '', 'And the body does not carry over.');
    }

    public function test_closing_the_editor_writes_nothing(): void
    {
        Livewire::test('pages::series', ['manga' => 76])
            ->call('openNote', 7602)
            ->set('noteBody', 'Never saved.')
            ->call('closeNote')
            ->assertSet('noteEditing', 0);

        $this->assertNull($this->notes()->find($this->user, 7602));
    }

    public function test_opening_the_editor_seeds_the_existing_body(): void
    {
        $this->notes()->save($this->user, 76, 7602, 'Second chapter thought.');

        Livewire::test('pages::series', ['manga' => 76])
            ->call('openNote', 7602)
            ->assertSet('noteEditing', 7602)
            ->assertSet('noteBody', 'Second chapter thought.');
    }

    public function test_opening_the_editor_on_a_chapter_without_a_note_is_empty(): void
    {
        Livewire::test('pages::series', ['manga' => 76])
            ->call('openNote', 7601)
            ->assertSet('noteBody', '');
    }

    public function test_saving_from_the_editor_writes_and_closes(): void
    {
        Livewire::test('pages::series', ['manga' => 76])
            ->call('openNote', 7603)
            ->set('noteBody', 'From the series page.')
            ->call('saveNoteFromEditor')
            ->assertSet('noteEditing', 0);

        $this->assertSame('From the series page.', $this->notes()->find($this->user, 7603)?->body);
    }

    public function test_saving_from_the_editor_with_nothing_open_does_nothing(): void
    {
        Livewire::test('pages::series', ['manga' => 76])
            ->call('saveNoteFromEditor')
            ->assertSet('noteEditing', 0);

        $this->assertSame(0, Note::query()->forUser($this->user->id)->count());
    }

    public function test_the_note_chapter_cannot_be_changed_from_the_browser(): void
    {
        /*
         * #[Locked] means Livewire refuses the assignment rather than applying it, so
         * the chapter the browser tried to redirect the note to is ignored and the note
         * lands on the one that was opened. Without the lock a request could point the
         * editor at a chapter the reader is not looking at.
         *
         * Caught rather than declared with expectException(): expectException() ends the
         * test, so nothing after it can check where the note actually landed, and this
         * claim is as much about the note landing on 7602 as about 7603 being refused.
         */
        $component = Livewire::test('pages::series', ['manga' => 76])
            ->call('openNote', 7602)
            ->set('noteBody', 'Belongs to chapter 2.');

        $refused = false;

        try {
            $component->set('noteEditing', 7603);
        } catch (CannotUpdateLockedPropertyException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'Livewire refuses to move the chapter a note belongs to.');

        $component->call('saveNoteFromEditor');

        $this->assertNull($this->notes()->find($this->user, 7603), 'Nothing lands on chapter 3.');
        $this->assertSame('Belongs to chapter 2.', $this->notes()->find($this->user, 7602)?->body);
    }

    public function test_the_series_page_and_the_reader_write_the_same_row(): void
    {
        $this->notes()->save($this->user, 76, 7602, 'From the series page.');

        $this->putJson('/read/76/7602/note', ['body' => 'From the reader.'])
            ->assertOk();

        $this->assertSame(
            1,
            Note::query()->forUser($this->user->id)->count(),
            'Two surfaces writing one note is still one note.',
        );

        $this->assertSame('From the reader.', $this->notes()->find($this->user, 7602)?->body);
    }
}
