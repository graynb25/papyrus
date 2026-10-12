<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Library;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reader choices that must outlive the page they were made on.
 *
 * ## The family of bug
 *
 * A reader picked something, navigated away, came back, and found it had not been picked. On the
 * series page that was the chapter order: sort newest-first, open another series, return, and it was
 * oldest-first again, while the reading-mode buttons either side of it remembered. The reader
 * reported it as one small thing about sorting.
 *
 * It was one small thing about **state that looks remembered because the button is highlighted**.
 * The button is highlighted from the property, so a reset looks like a choice being made rather
 * than a choice being lost, and nothing on screen distinguishes the two.
 *
 * ## The audit that found the rest
 *
 * Every page component was checked for where its state lives. `browse`, `health`, `search` and
 * `sources` all carry their filters in `#[Url]`, which is why none of them had this problem.
 * `series` and `library` carried none, which is why both did.
 *
 * The audit nearly reported the wrong answer. It looked for `#[Url` with a backslash under
 * `-SimpleMatch`, matched nothing, and `browse` looked like a second instance of this bug until the
 * declaration was read directly: `#[Url(as: 'type', keep: false)]`. A grep that cannot match what is
 * there is not evidence that what is there is absent, which is worth remembering the next time an
 * audit says everything is fine.
 *
 * ## What is deliberately not here
 *
 * `$page` on the library. Returning to page one after opening a series is conventional, and putting
 * it in the address would make the reader's back button push entries rather than walk them.
 *
 * `selecting` and the selection set. Those are an action in progress, not a preference, and
 * abandoning one by navigating away is the right outcome.
 */
class ReaderChoicePersistenceTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- the library

    public function test_the_library_shelf_survives_navigating_away_and_back(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $shelf = app(Library::class)->createCategory($user, 'Reading');

        Livewire::test('pages::library')
            ->set('shelf', $shelf->id)
            ->assertSet('shelf', $shelf->id);

        // The whole point: a *fresh* component, arriving at the same address.
        Livewire::withQueryParams(['shelf' => (string) $shelf->id])
            ->test('pages::library')
            ->assertSet('shelf', $shelf->id);
    }

    public function test_the_library_sort_survives_navigating_away_and_back(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::library')
            ->set('sort', 'unread')
            ->assertSet('sort', 'unread');

        Livewire::withQueryParams(['sort' => 'unread'])
            ->test('pages::library')
            ->assertSet('sort', 'unread');
    }

    public function test_the_library_search_box_survives_navigating_away_and_back(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::library')
            ->set('search', 'one piece')
            ->assertSet('search', 'one piece');

        // As `q`, so a link means the same thing here as it does on the search page.
        Livewire::withQueryParams(['q' => 'one piece'])
            ->test('pages::library')
            ->assertSet('search', 'one piece');
    }

    /**
     * A library with nothing chosen is a clean `/library`, not a decorated one.
     *
     * The reason `except` is on each of them. A URL carrying `?sort=recent&shelf=&q=` is a URL
     * nobody should ever have to look at, and it is what every share and every bookmark would be.
     */
    public function test_an_untouched_library_has_no_filter_parameters(): void
    {
        $this->actingAs(User::factory()->create());

        $html = Livewire::test('pages::library')->html();

        // All three defaults, not just the sort. `?shelf=` with an empty value is the shape that
        // arrives when `except` is given `null` and the framework decides to keep the key anyway,
        // and it is exactly the kind of thing nobody looks at until they share the link.
        foreach (['sort=recent', 'shelf=', 'q='] as $parameter) {
            $this->assertStringNotContainsString(
                $parameter,
                $html,
                "an untouched library must not carry {$parameter} in the address, or every shared "
                    .'link and every bookmark carries a decision nobody made',
            );
        }
    }

    /**
     * Changing a filter still returns to page one.
     *
     * The existing behaviour, held deliberately rather than by accident. Landing on page 7 of a
     * result set that now has two pages reads as an empty library, which is the comment on
     * `updated()`. Putting the filters in the URL must not have quietly stopped that.
     */
    public function test_changing_a_filter_still_returns_to_the_first_page(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::library')
            ->set('page', 4)
            ->set('sort', 'unread')
            ->assertSet('page', 1);
    }

    /**
     * A shelf the reader does not have falls back to the whole library.
     *
     * `#[Url]` is what makes this reachable: these three could only be set by clicking before, so
     * `normalise()` running from `updated()` was enough. A shelf id belonging to somebody else now
     * arrives typed, and the filter is applied in PHP, so nothing breaks: the library filters down to
     * nothing and the page says "This shelf is empty" about a shelf the reader never had.
     *
     * A wrong answer about the reader's own data is worse than an empty page, so the clamp happens
     * on mount.
     */
    public function test_a_shelf_the_reader_does_not_have_falls_back_to_the_whole_library(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        app(Library::class)->createCategory($user, 'Reading');

        // 999999 is not this reader's shelf and, deliberately, is not anybody's.
        Livewire::withQueryParams(['shelf' => '999999'])
            ->test('pages::library')
            ->assertSet('shelf', null);
    }

    /**
     * A sort nobody offers falls back to the default.
     *
     * The match below it has a `default` arm so this cannot 500, but it would render an unsorted
     * list while the address still said something nobody chose. The address is normalised instead.
     */
    public function test_a_sort_nobody_offers_falls_back_to_the_default(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::withQueryParams(['sort' => 'title; drop table'])
            ->test('pages::library')
            ->assertSet('sort', 'recent');
    }

    // ---------------------------------------------------------------- the invariant

    /**
     * Every filter on the library has a lifetime, named in one place.
     *
     * Asserted over a list rather than one property at a time, because the failure is not "someone
     * typed `sort` wrong". It is "someone added a filter, wired it to a plain property the way the
     * ones already there were, and it reset exactly like the three this removed".
     *
     * The series page's chapter order is held by `SeriesChapterOrderTest`, because it is stored
     * against the series rather than carried in the address and the two are not the same rule. This
     * file deliberately does not mount the series component: doing so reaches the real Suwayomi,
     * which is the mistake `SeriesChapterOrderTest` avoids by faking it.
     */
    public function test_every_library_filter_is_carried_in_the_address(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/pages/⚡library.blade.php'
        );

        foreach (['shelf', 'sort', 'search'] as $property) {
            $this->assertMatchesRegularExpression(
                '/#\[Url[^\]]*\]\s*\n\s*public [^\n]*\$'.$property.'\b/',
                $source,
                "the library's \${$property} has no #[Url], so it resets on every navigation, which "
                    .'is the defect this file exists to hold closed',
            );
        }
    }

    /**
     * `$page` stays out of the address on purpose.
     *
     * Recorded as a negative so the omission is a decision rather than an oversight someone
     * "tidies up" later. Returning to page one after opening a series is conventional, and putting it
     * in the URL would push an entry on the reader's back button instead of walking them.
     */
    public function test_the_library_page_number_is_deliberately_not_in_the_address(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/pages/⚡library.blade.php'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/#\[Url[^\]]*\]\s*\n\s*public [^\n]*\$page\b/',
            $source,
            'the page number must not become a URL parameter; see the docblock',
        );
    }
}
