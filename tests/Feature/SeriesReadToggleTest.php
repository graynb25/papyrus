<?php

namespace Tests\Feature;

use App\Models\ChapterState;
use App\Models\SeriesState;
use App\Models\User;
use App\Support\ReadingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * Read state, set by hand.
 *
 * The progress endpoint infers read from the page number, which is the only
 * way read state could arrive before now. A reader carrying a history from
 * another app needs to state the opposite of what the app believes, so the
 * series page's dot became a control and the list grew bulk actions. These
 * tests are about what each of those writes actually does to the rows and to
 * the continue-reading pointer, which is the value a wrong guess would corrupt.
 */
class SeriesReadToggleTest extends TestCase
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

            if (str_contains($query, 'manga(id:')) {
                return Http::response(['data' => ['manga' => $this->manga()]]);
            }

            return Http::response(['data' => []]);
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
            'realUrl' => null,
            'thumbnailUrl' => '/api/v1/manga/76/thumbnail',
            'status' => 'ONGOING',
            'author' => null,
            'artist' => null,
            'genre' => [],
            'description' => null,
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

    public function test_toggling_read_marks_an_unread_chapter_read(): void
    {
        Livewire::test('pages::series', ['manga' => 76])
            ->call('toggleRead', 7602)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('chapter_states', [
            'user_id' => $this->user->id,
            'suwayomi_chapter_id' => 7602,
            'is_read' => true,
        ]);
    }

    public function test_toggling_again_marks_it_unread(): void
    {
        app(ReadingState::class)->markRead($this->user, 76, 7602, true);

        Livewire::test('pages::series', ['manga' => 76])
            ->call('toggleRead', 7602)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('chapter_states', [
            'user_id' => $this->user->id,
            'suwayomi_chapter_id' => 7602,
            'is_read' => false,
        ]);
    }

    public function test_marking_read_moves_the_continue_pointer_forward(): void
    {
        $state = app(ReadingState::class);
        $state->markRead($this->user, 76, 7601, true);
        $state->setPointer($this->user, 76, 7601);

        Livewire::test('pages::series', ['manga' => 76])
            ->call('toggleRead', 7603)
            ->assertHasNoErrors();

        $this->assertSame(7603, $state->findSeries($this->user, 76)->last_read_chapter_id);
    }

    public function test_marking_read_never_moves_the_pointer_backwards(): void
    {
        $state = app(ReadingState::class);
        $state->setPointer($this->user, 76, 7603);

        Livewire::test('pages::series', ['manga' => 76])
            ->call('toggleRead', 7601)
            ->assertHasNoErrors();

        $this->assertSame(
            7603,
            $state->findSeries($this->user, 76)->last_read_chapter_id,
            'Re-reading an early chapter must not drag continue-reading backwards.',
        );
    }

    public function test_mark_all_read_marks_every_chapter_and_lands_the_pointer_on_the_last(): void
    {
        app(ReadingState::class)->markRead($this->user, 76, 7601, true);

        Livewire::test('pages::series', ['manga' => 76])
            ->call('markAllRead')
            ->assertHasNoErrors();

        foreach ([7601, 7602, 7603] as $chapterId) {
            $this->assertDatabaseHas('chapter_states', [
                'user_id' => $this->user->id,
                'suwayomi_chapter_id' => $chapterId,
                'is_read' => true,
            ]);
        }

        $this->assertSame(
            7603,
            app(ReadingState::class)->findSeries($this->user, 76)->last_read_chapter_id,
        );
    }

    public function test_mark_all_read_creates_rows_for_chapters_that_have_none(): void
    {
        // A reader who has never opened the series has no chapter_states rows at
        // all, and "mark all read" is mostly those.
        $before = ChapterState::forUser($this->user->id)->count();
        $this->assertSame(0, $before);

        Livewire::test('pages::series', ['manga' => 76])
            ->call('markAllRead')
            ->assertHasNoErrors();

        $this->assertSame(3, ChapterState::forUser($this->user->id)->where('is_read', true)->count());
    }

    public function test_mark_all_read_lands_the_pointer_on_the_furthest_chapter_whatever_the_display_order(): void
    {
        // The sort is how this reader is looking at the list; "all read" is a
        // statement about the series. Both directions land on the newest chapter.
        foreach (['asc', 'desc'] as $order) {
            ChapterState::query()->delete();
            SeriesState::query()->delete();

            Livewire::test('pages::series', ['manga' => 76])
                ->set('order', $order)
                ->call('markAllRead')
                ->assertHasNoErrors();

            $this->assertSame(
                7603,
                app(ReadingState::class)->findSeries($this->user, 76)->last_read_chapter_id,
                "Order {$order} must not change where the pointer lands.",
            );
        }
    }

    public function test_mark_all_unread_clears_every_flag_and_the_pointer(): void
    {
        app(ReadingState::class)->markRead($this->user, 76, 7603, true);
        app(ReadingState::class)->setPointer($this->user, 76, 7603);

        Livewire::test('pages::series', ['manga' => 76])
            ->call('markAllUnread')
            ->assertHasNoErrors();

        $this->assertSame(0, ChapterState::forUser($this->user->id)->where('is_read', true)->count());
        $this->assertSame(3, ChapterState::forUser($this->user->id)->count(), 'The rows stay, so page positions survive.');
        $this->assertNull(app(ReadingState::class)->findSeries($this->user, 76)->last_read_chapter_id);
    }

    public function test_a_page_position_survives_mark_unread_and_back(): void
    {
        $state = app(ReadingState::class);
        $state->recordProgress($this->user, 76, 7601, 4, 20);

        // Read, unread, read: the flag flips twice and the page position the
        // progress endpoint recorded is none of the toggles' business.
        Livewire::test('pages::series', ['manga' => 76])
            ->call('toggleRead', 7601)
            ->call('toggleRead', 7601)
            ->call('toggleRead', 7601)
            ->assertHasNoErrors();

        $row = $state->findChapter($this->user, 7601);

        $this->assertTrue($row->is_read);
        $this->assertSame(4, (int) $row->last_page_read, 'The page position is not the read flag and must not be overwritten by it.');
    }

    public function test_the_dot_on_each_row_is_a_control(): void
    {
        $html = Livewire::test('pages::series', ['manga' => 76])->html();

        $this->assertStringContainsString('data-test="read-toggle"', $html);
        $this->assertStringContainsString('wire:click="toggleRead(7601)"', $html);
    }

    public function test_marks_are_scoped_to_the_signed_in_reader(): void
    {
        $other = User::factory()->create();

        Livewire::actingAs($this->user)->test('pages::series', ['manga' => 76])
            ->call('markAllRead')
            ->assertHasNoErrors();

        $this->assertSame(0, ChapterState::forUser($other->id)->count());
        $this->assertSame(0, SeriesState::forUser($other->id)->count());
    }
}
