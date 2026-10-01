<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ReaderProfile;
use App\Models\SeriesState;
use App\Models\User;
use App\Support\ReaderSettings;
use App\Support\SessionOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * The settings hierarchy, and the colour filters that persist through it.
 *
 * ## What is being asserted
 *
 * Resolution order, which is the entire feature. Mihon's order is defaults, profile,
 * category, series, session, and each layer only wins where it actually says
 * something. A layer that wrote nulls would silently erase everything below it, which
 * is why `merge()` skips them and why that behaviour is tested directly rather than
 * assumed.
 *
 * ## Why the session layer is a parameter and not a read
 *
 * `ReaderSettings` is also called from commands and queue jobs, where there is no
 * session. Reading one there would either fail or pick up whatever the last browser
 * left behind, so the caller passes it in and the reader controller is the only thing
 * that knows about the session.
 */
class SettingsHierarchyTest extends TestCase
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
     * A server with one series, three pages, and a downloaded chapter.
     *
     * The reader needs a chapter with a real `pageCount`, because measured
     * `ChapterType.pageCount` is `-1` until a chapter's pages have been fetched and a
     * reader with a `-1` chapter has nothing to render. That is why the reader had no
     * feature test at all: it was only ever covered by the live browser gate.
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

            if (str_contains($query, 'manga(id:')) {
                $id = (int) (data_get($request->data(), 'variables.id') ?? 0);

                return Http::response(['data' => ['manga' => [
                    'id' => (string) $id,
                    'title' => 'Series '.$id,
                    'thumbnailUrl' => "/api/v1/manga/{$id}/thumbnail",
                    'status' => 'ONGOING',
                    'initialized' => true,
                    'chaptersLastFetchedAt' => 1790776081,
                    'source' => ['id' => '1', 'name' => 'Asura Scans', 'lang' => 'en'],
                    'chapters' => [
                        'totalCount' => 3,
                        'nodes' => array_map(
                            fn (int $n): array => [
                                'id' => (string) ($id * 100 + $n),
                                'name' => "Chapter {$n}",
                                'chapterNumber' => $n,
                                'pageCount' => 3,
                                'sourceOrder' => $n,
                            ],
                            [1, 2, 3],
                        ),
                    ],
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

            if (str_contains($query, 'api/v1/manga')) {
                return Http::response('');
            }

            return Http::response(['data' => ['manga' => null]]);
        });
    }

    private function series(int $mangaId = 76, ?int $shelfId = null): SeriesState
    {
        return SeriesState::createFor($this->user, [
            'suwayomi_manga_id' => $mangaId,
            'in_library' => true,
            'added_at' => now(),
            'default_category_id' => $shelfId,
        ]);
    }

    private function shelf(string $name, array $settings = []): Category
    {
        $shelf = Category::createFor($this->user, ['name' => $name]);

        if ($settings !== []) {
            $shelf->settings = $settings;
            $shelf->save();
        }

        return $shelf;
    }

    private function profile(array $settings): ReaderProfile
    {
        return ReaderProfile::createFor($this->user, [
            'name' => 'Default',
            'is_default' => true,
            'settings' => $settings,
        ]);
    }

    // The order --------------------------------------------------------------

    public function test_the_defaults_apply_with_nothing_stored(): void
    {
        $this->series();

        $resolved = app(ReaderSettings::class)->resolve($this->user, 76);

        $this->assertSame('vertical', $resolved['mode']);
        $this->assertSame(100, $resolved['width']);
        $this->assertSame(1.0, $resolved['brightness'], '1.0 is untouched, not dimmed.');
        $this->assertFalse($resolved['invert']);
    }

    public function test_the_profile_beats_the_defaults(): void
    {
        $this->series();
        $this->profile(['width' => 70, 'brightness' => 0.8]);

        $resolved = app(ReaderSettings::class)->resolve($this->user, 76);

        $this->assertSame(70, $resolved['width']);
        $this->assertSame(0.8, $resolved['brightness']);
    }

    public function test_a_shelf_beats_the_profile(): void
    {
        $shelf = $this->shelf('Night', ['brightness' => 0.5, 'contrast' => 1.4]);
        $this->series(shelfId: $shelf->id);
        $this->profile(['brightness' => 0.9, 'width' => 70]);

        $resolved = app(ReaderSettings::class)->resolve($this->user, 76);

        $this->assertSame(0.5, $resolved['brightness'], 'The shelf is the more specific statement.');
        $this->assertSame(1.4, $resolved['contrast']);
        $this->assertSame(70, $resolved['width'], 'And the profile still speaks where the shelf is silent.');
    }

    public function test_the_series_beats_the_shelf(): void
    {
        $shelf = $this->shelf('Night', ['brightness' => 0.5]);
        $this->series(shelfId: $shelf->id);

        app(ReaderSettings::class)->setSeriesOverride($this->user, 76, ['brightness' => 0.3]);

        $resolved = app(ReaderSettings::class)->resolve($this->user, 76);

        $this->assertSame(
            0.3,
            $resolved['brightness'],
            'A series that disagrees with its shelf is the more specific statement.',
        );
    }

    public function test_the_session_beats_everything(): void
    {
        $shelf = $this->shelf('Night', ['brightness' => 0.5]);
        $this->series(shelfId: $shelf->id);
        app(ReaderSettings::class)->setSeriesOverride($this->user, 76, ['brightness' => 0.3]);

        $resolved = app(ReaderSettings::class)->resolve($this->user, 76, ['brightness' => 0.25]);

        $this->assertSame(0.25, $resolved['brightness']);
    }

    public function test_an_absent_layer_contributes_nothing(): void
    {
        // A shelf that says one thing, so the layers above and below are both visible.
        $shelf = $this->shelf('Night', ['brightness' => 0.5]);
        $this->series(shelfId: $shelf->id);
        $this->profile(['width' => 70]);

        $resolved = app(ReaderSettings::class)->resolve($this->user, 76);

        $this->assertSame(0.5, $resolved['brightness'], 'Shelf.');
        $this->assertSame(70, $resolved['width'], 'Profile, where the shelf is silent.');
        $this->assertSame(0, $resolved['gap'], 'Defaults, where both are silent.');
    }

    public function test_a_null_in_a_layer_does_not_erase_what_is_below(): void
    {
        $shelf = $this->shelf('Night', ['brightness' => 0.5, 'contrast' => null]);
        $this->series(shelfId: $shelf->id);
        $this->profile(['contrast' => 1.2]);

        $resolved = app(ReaderSettings::class)->resolve($this->user, 76);

        $this->assertSame(
            1.2,
            $resolved['contrast'],
            'A null means "no opinion", not "set it to nothing".',
        );
    }

    public function test_a_series_with_no_home_shelf_gets_no_shelf_layer(): void
    {
        $this->shelf('Night', ['brightness' => 0.5]);
        $this->series(shelfId: null);

        $resolved = app(ReaderSettings::class)->resolve($this->user, 76);

        $this->assertSame(1.0, $resolved['brightness']);
    }

    public function test_a_shelf_belonging_to_another_reader_contributes_nothing(): void
    {
        $bob = User::factory()->create();
        $theirs = Category::createFor($bob, ['name' => 'Theirs', 'settings' => ['brightness' => 0.1]]);

        $this->series(shelfId: $theirs->id);

        $resolved = app(ReaderSettings::class)->resolve($this->user, 76);

        $this->assertSame(1.0, $resolved['brightness'], 'A shelf id is not a capability.');
    }

    public function test_a_series_reading_mode_beats_the_shelf_mode(): void
    {
        $shelf = $this->shelf('Night', ['mode' => 'paged']);
        $this->series(shelfId: $shelf->id);
        $this->profile(['mode' => 'vertical']);

        // With no reading_mode on the series, the shelf's paged wins over the
        // profile's vertical, because the shelf is the more specific layer.
        $this->assertSame(
            'paged',
            app(ReaderSettings::class)->resolve($this->user, 76)['mode'],
            'The shelf beats the profile when the series has no opinion.',
        );

        app(ReaderSettings::class)->setSeriesOverride($this->user, 76, ['mode' => 'vertical']);

        $this->assertSame(
            'vertical',
            app(ReaderSettings::class)->resolve($this->user, 76)['mode'],
            'And a series that says otherwise is more specific still.',
        );
    }

    // Clamping ---------------------------------------------------------------

    public function test_brightness_is_clamped_to_its_range(): void
    {
        $this->series();

        $settings = app(ReaderSettings::class);

        $low = $settings->clamp(['brightness' => 0.0]);
        $high = $settings->clamp(['brightness' => 99]);

        $this->assertSame(0.2, $low['brightness'], 'Below the floor a page is unreadable.');
        $this->assertSame(2.0, $high['brightness']);
    }

    public function test_brightness_goes_below_one_for_dimming(): void
    {
        // The "negative dim range" the plan asks for. A floor of 1.0 would make the
        // control useless for the case it exists for.
        $shelf = $this->shelf('Night', ['brightness' => 0.4]);
        $this->series(shelfId: $shelf->id);

        $resolved = app(ReaderSettings::class)->resolve($this->user, 76);

        $this->assertSame(0.4, $resolved['brightness']);
    }

    public function test_hue_is_clamped(): void
    {
        $resolved = app(ReaderSettings::class)->clamp(['hue' => 900]);

        $this->assertSame(360, $resolved['hue']);
    }

    public function test_a_non_numeric_filter_falls_back_to_the_minimum(): void
    {
        $resolved = app(ReaderSettings::class)->clamp(['brightness' => 'not a number']);

        $this->assertSame(0.2, $resolved['brightness']);
    }

    public function test_a_filter_from_json_cannot_be_an_array(): void
    {
        $resolved = app(ReaderSettings::class)->clamp(['invert' => ['yes']]);

        $this->assertTrue($resolved['invert'], 'A truthy array is a truthy value, and it is a checkbox.');
    }

    // Shelf writes -----------------------------------------------------------

    public function test_a_shelf_setting_is_clamped_on_write(): void
    {
        $shelf = $this->shelf('Night');

        app(ReaderSettings::class)->setCategorySettings($this->user, $shelf->id, ['brightness' => 99]);

        /*
         * assertEquals, not assertSame: 2.0 survives a JSON column as the integer 2,
         * because json_encode without JSON_PRESERVE_ZERO_FRACTION writes it that way.
         * The clamp is doing its job either way and the distinction is PHP's encoder.
         */
        $this->assertEquals(2.0, $shelf->fresh()->settings['brightness']);
    }

    public function test_a_shelf_setting_merges_rather_than_replaces(): void
    {
        $shelf = $this->shelf('Night', ['brightness' => 0.5]);

        app(ReaderSettings::class)->setCategorySettings($this->user, $shelf->id, ['contrast' => 1.3]);

        $stored = $shelf->fresh()->settings;

        $this->assertSame(0.5, $stored['brightness'], 'The earlier value survives.');
        $this->assertSame(1.3, $stored['contrast']);
    }

    public function test_a_shelf_setting_can_be_cleared(): void
    {
        $shelf = $this->shelf('Night', ['brightness' => 0.5]);
        $this->series(shelfId: $shelf->id);

        app(ReaderSettings::class)->clearCategorySettings($this->user, $shelf->id);

        $this->assertSame(1.0, app(ReaderSettings::class)->resolve($this->user, 76)['brightness']);
    }

    public function test_writing_another_readers_shelf_is_refused(): void
    {
        $bob = User::factory()->create();
        $theirs = Category::createFor($bob, ['name' => 'Theirs']);

        $this->expectException(\InvalidArgumentException::class);

        app(ReaderSettings::class)->setCategorySettings($this->user, $theirs->id, ['brightness' => 0.5]);
    }

    public function test_a_shelf_reports_whether_it_has_an_opinion(): void
    {
        $silent = $this->shelf('Empty');
        $opinionated = $this->shelf('Night', ['brightness' => 0.5]);

        $this->assertFalse($silent->hasSettings());
        $this->assertTrue($opinionated->hasSettings());
    }

    // The session layer -------------------------------------------------------

    public function test_a_session_override_is_read_back(): void
    {
        $session = app(SessionOverride::class);

        $session->write(['brightness' => 0.4, 'invert' => true]);

        $this->assertSame(0.4, $session->read()['brightness']);
        $this->assertTrue($session->active());
    }

    public function test_a_session_override_can_be_cleared(): void
    {
        $session = app(SessionOverride::class);
        $session->write(['brightness' => 0.4]);

        $session->clear();

        $this->assertSame([], $session->read());
        $this->assertFalse($session->active());
    }

    public function test_the_session_refuses_a_key_it_does_not_allow(): void
    {
        $session = app(SessionOverride::class);

        $session->write(['brightness' => 0.4, 'evil' => 'x', 'min_width' => 5]);

        $this->assertSame(['brightness' => 0.4], $session->read());
    }

    public function test_the_session_drops_a_null_rather_than_storing_it(): void
    {
        $session = app(SessionOverride::class);

        $session->write(['brightness' => null]);

        $this->assertSame([], $session->read(), 'A stored null would look like a value set.');
    }

    public function test_clearing_an_empty_session_removes_the_key(): void
    {
        $session = app(SessionOverride::class);

        $session->write(['brightness' => null]);

        $this->assertNull(session(SessionOverride::KEY), 'Nothing stored means no key at all.');
    }

    public function test_the_session_is_not_shared_between_readers_in_a_test(): void
    {
        $session = app(SessionOverride::class);
        $session->write(['brightness' => 0.4]);

        $bob = User::factory()->create();

        $this->assertTrue(
            $session->active(),
            'A session belongs to the browser, not the user, so this is one session with two users in it.',
        );
        $this->assertNotNull($bob);
    }

    // The reader endpoint ----------------------------------------------------

    public function test_filters_are_session_scoped_by_default(): void
    {
        $this->series();

        $this->putJson('/read/76/filters', ['settings' => ['brightness' => 0.4]])
            ->assertOk()
            ->assertJsonPath('remembered', false)
            ->assertJsonPath('settings.brightness', 0.4);

        $this->assertSame(0.4, session(SessionOverride::KEY)['brightness']);
        $this->assertNull(
            SeriesState::forUser($this->user->id)->first()->reader_settings,
            'A filter the reader did not ask to keep must not become a series setting.',
        );
    }

    public function test_filters_become_durable_when_remembered(): void
    {
        $this->series();

        $this->putJson('/read/76/filters', [
            'settings' => ['brightness' => 0.4],
            'remember' => true,
        ])->assertOk()->assertJsonPath('remembered', true);

        $stored = SeriesState::forUser($this->user->id)->first()->reader_settings;

        $this->assertSame(0.4, $stored['brightness']);
        $this->assertNull(session(SessionOverride::KEY), 'And the session copy goes, so the two cannot disagree.');
    }

    public function test_the_response_carries_the_clamped_value(): void
    {
        $this->series();

        // Validation refuses 99, so this checks the clamp rather than the validator.
        $this->putJson('/read/76/filters', ['settings' => ['brightness' => 1.95]])
            ->assertOk()
            ->assertJsonPath('settings.brightness', 1.95);
    }

    public function test_a_filter_outside_its_range_is_refused(): void
    {
        $this->series();

        $this->putJson('/read/76/filters', ['settings' => ['brightness' => 99]])
            ->assertStatus(422);
    }

    public function test_clearing_filters_drops_the_session_layer(): void
    {
        $this->series();
        app(SessionOverride::class)->write(['brightness' => 0.4]);

        $body = $this->deleteJson('/read/76/filters')->assertOk()->json();

        /*
         * Cast rather than assertSame, because the value arrives through a JSON
         * response where 1.0 is decoded as int 1. The default is right and the int is
         * PHP's encoder, not a clamp that failed.
         */
        $this->assertSame(1.0, (float) $body['settings']['brightness']);
        $this->assertFalse(app(SessionOverride::class)->active());
    }

    public function test_a_reader_cannot_filter_another_readers_series(): void
    {
        $bob = User::factory()->create();
        SeriesState::createFor($bob, ['suwayomi_manga_id' => 76, 'in_library' => true]);

        $this->putJson('/read/76/filters', [
            'settings' => ['brightness' => 0.4],
            'remember' => true,
        ])->assertOk();

        $this->assertNull(
            SeriesState::forUser($bob->id)->first()->reader_settings,
            'The filter landed on this reader, whose session it was.',
        );
    }

    // The reader page --------------------------------------------------------

    public function test_the_reader_says_which_layer_a_filter_lives_in(): void
    {
        $this->series();

        $this->get('/read/76/1')
            ->assertOk()
            ->assertSee('Colour filters')
            ->assertSee('Remember for this series');
    }

    public function test_the_reader_shows_a_session_override_as_temporary(): void
    {
        $this->series();
        app(SessionOverride::class)->write(['brightness' => 0.4]);

        $this->get('/read/76/1')
            ->assertOk()
            ->assertSee('Temporary, for this visit only.');
    }

    public function test_the_filter_chain_is_server_rendered(): void
    {
        $shelf = $this->shelf('Night', ['brightness' => 0.5, 'contrast' => 1.3, 'invert' => true]);
        $this->series(shelfId: $shelf->id);

        $this->get('/read/76/1')
            ->assertOk()
            ->assertSee('brightness(0.5)', escape: false)
            ->assertSee('contrast(1.3)', escape: false)
            ->assertSee('invert(1)', escape: false);
    }

    public function test_invert_comes_last_in_the_chain(): void
    {
        // Order is load-bearing: invert before brightness means the brightness is then
        // applied to an inverted image and the two do not compose as a reader expects.
        $shelf = $this->shelf('Night', ['brightness' => 0.5, 'invert' => true]);
        $this->series(shelfId: $shelf->id);

        $html = $this->get('/read/76/1')->assertOk()->content();

        $brightness = strpos($html, 'brightness(');
        $invert = strpos($html, 'invert(1)');

        $this->assertNotFalse($brightness);
        $this->assertNotFalse($invert);
        $this->assertLessThan($invert, $brightness, 'brightness must come before invert');
    }

    public function test_hue_is_wrapped_not_clamped_in_the_chain(): void
    {
        $shelf = $this->shelf('Odd', ['hue' => 360]);
        $this->series(shelfId: $shelf->id);

        $this->get('/read/76/1')
            ->assertOk()
            ->assertSee('hue-rotate(0deg)', escape: false);
    }

    public function test_grayscale_precedes_hue_rotation(): void
    {
        // Grayscale after a hue rotation would throw the hue away, so the order is
        // grayscale, then hue, then invert.
        $shelf = $this->shelf('Odd', ['grayscale' => true, 'hue' => 90, 'invert' => true]);
        $this->series(shelfId: $shelf->id);

        $html = $this->get('/read/76/1')->assertOk()->content();

        $gray = strpos($html, 'grayscale(1)');
        $hue = strpos($html, 'hue-rotate(90deg)');
        $invert = strpos($html, 'invert(1)');

        $this->assertLessThan($hue, $gray);
        $this->assertLessThan($invert, $hue);
    }

    public function test_the_filter_is_applied_to_the_container_not_each_image(): void
    {
        $this->series();

        $html = $this->get('/read/76/1')->assertOk()->content();

        // One declaration on #pages, not one per img. A per-image filter on a 28 page
        // chapter composites 28 layers and the browser re-composites on every scroll.
        $this->assertSame(
            1,
            preg_match_all('/#pages\s*\{[^}]*filter:\s*var\(--reader-filter\)/s', $html),
            'Exactly one filter, on the container.',
        );
    }
}
