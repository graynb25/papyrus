<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ReaderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * The two toggles above a series' chapter list, and the fact that they were not the same kind of thing.
 *
 * ## What the reader reported
 *
 * Sort a series newest-first, go away, come back, and it is oldest-first again. The reading-mode
 * buttons either side of it, in the same group, remembered. So a reader had one control that stuck
 * and one that did not, three buttons apart, on the same screen.
 *
 * ## Why it happened
 *
 * `setReadingMode` writes through `ReaderSettings` into the same column the reader is read back
 * from, so the mode survives a navigation by construction. `setOrder` assigned a Livewire property,
 * which survives a re-render and nothing else. Both were correct in isolation. The bug is only
 * visible in the comparison, which is why it survived: there is no check anywhere that says two
 * controls in one group must have the same lifetime.
 *
 * ## What these tests hold
 *
 * That a chosen order is written through and read back on a **fresh mount**, not merely on the
 * component that chose it. The second half is the part that matters, because asserting on the same
 * component instance passes against the original code: the property is set, so `order` is right,
 * and the test is happy while the reader's next page load forgets it.
 */
class SeriesChapterOrderTest extends TestCase
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

    /**
     * The reported bug, exactly: choose newest, leave, come back.
     *
     * A **new** `Livewire::test(...)` for the second half. Re-reading `$order` off the component
     * that set it would pass against the original code, because a Livewire property is set, and the
     * reader's next page load would still be wrong.
     */
    public function test_a_chosen_order_survives_navigating_away_and_back(): void
    {
        Livewire::test('pages::series', ['manga' => 76])
            ->call('setOrder', ReaderSettings::ORDER_NEWEST)
            ->assertSet('order', ReaderSettings::ORDER_NEWEST)
            ->assertHasNoErrors();

        Livewire::test('pages::series', ['manga' => 76])
            ->assertSet('order', ReaderSettings::ORDER_NEWEST);
    }

    /**
     * And it has actually been written somewhere, not just remembered in a property.
     *
     * The assertion above would also pass if the order were stashed in the session, which would
     * survive a page load but not a different browser, and would be the wrong answer again.
     */
    public function test_a_chosen_order_is_written_through_to_the_settings(): void
    {
        Livewire::test('pages::series', ['manga' => 76])
            ->call('setOrder', ReaderSettings::ORDER_NEWEST)
            ->assertHasNoErrors();

        $resolved = app(ReaderSettings::class)->resolve($this->user, 76);

        $this->assertSame(
            ReaderSettings::ORDER_NEWEST,
            $resolved['chapter_order'],
            'the order must reach the same place the reading mode does, or it will not outlive the page',
        );
    }

    /**
     * Going back to oldest also sticks, rather than being the only direction that works.
     */
    public function test_switching_back_to_oldest_also_survives(): void
    {
        Livewire::test('pages::series', ['manga' => 76])
            ->call('setOrder', ReaderSettings::ORDER_NEWEST);
        Livewire::test('pages::series', ['manga' => 76])
            ->call('setOrder', ReaderSettings::ORDER_OLDEST);

        Livewire::test('pages::series', ['manga' => 76])
            ->assertSet('order', ReaderSettings::ORDER_OLDEST);
    }

    /**
     * A series with nothing saved starts oldest-first, which is the right default for catching up.
     */
    public function test_a_series_with_no_saved_order_starts_oldest_first(): void
    {
        Livewire::test('pages::series', ['manga' => 76])
            ->assertSet('order', ReaderSettings::ORDER_OLDEST);
    }

    /**
     * The order belongs to the series, so it does not follow the reader to a different one.
     *
     * Persisting it on the profile instead would be a different feature and a worse one: a reader
     * who wants one long series newest-first does not want all of them that way.
     */
    public function test_the_order_is_per_series_and_does_not_leak_to_another(): void
    {
        Livewire::test('pages::series', ['manga' => 76])
            ->call('setOrder', ReaderSettings::ORDER_NEWEST);

        Livewire::test('pages::series', ['manga' => 77])
            ->assertSet('order', ReaderSettings::ORDER_OLDEST);
    }

    /**
     * One reader's choice is not another's.
     *
     * The order is stored against the series state row, which carries the user, so this is the
     * assertion that a bug writing it to the wrong place would fail.
     */
    public function test_the_order_is_per_reader(): void
    {
        Livewire::test('pages::series', ['manga' => 76])
            ->call('setOrder', ReaderSettings::ORDER_NEWEST);

        $other = User::factory()->create();

        $this->assertSame(
            ReaderSettings::ORDER_OLDEST,
            app(ReaderSettings::class)->resolve($other, 76)['chapter_order'],
            'one reader sorting a series must not sort it for everybody',
        );
    }

    /**
     * Nonsense is refused rather than stored, so it cannot come back as a third order.
     */
    public function test_an_unknown_order_is_refused(): void
    {
        Livewire::test('pages::series', ['manga' => 76])
            ->call('setOrder', 'sideways')
            ->assertSet('order', ReaderSettings::ORDER_OLDEST)
            ->assertHasNoErrors();
    }

    /**
     * The reading mode and the order have the same lifetime, which is the whole complaint.
     *
     * Stated as one assertion about both so that the next control added to this group cannot be
     * component-local while these two are not. The failure this prevents is not "someone typed
     * asc=desc wrong", it is "someone added a third toggle and it behaved like the old one".
     */
    public function test_the_order_and_the_reading_mode_have_the_same_lifetime(): void
    {
        Livewire::test('pages::series', ['manga' => 76])
            ->call('setOrder', ReaderSettings::ORDER_NEWEST)
            ->call('setReadingMode', ReaderSettings::MODE_PAGED)
            ->assertHasNoErrors();

        $resolved = app(ReaderSettings::class)->resolve($this->user, 76);

        $this->assertSame(ReaderSettings::ORDER_NEWEST, $resolved['chapter_order']);
        $this->assertSame(ReaderSettings::MODE_PAGED, $resolved['mode']);
    }
}
