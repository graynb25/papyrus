<?php

namespace Tests\Feature;

use App\Jobs\FetchSeriesChapters;
use App\Models\ChapterState;
use App\Models\SeriesState;
use App\Models\User;
use App\Support\ReaderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * The read path: the series page, the chapter list, and the reader shell.
 *
 * The service layer behind these is covered in SeriesGatewayTest and
 * ReadingStateTest. What is checked here is the wiring: that the ids route where
 * they should, that the reader never renders a URL the proxy will refuse, and
 * that progress survives the round trip.
 */
class ReadPathTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    /**
     * Responses keyed by the document they answer, so one test can fail a single
     * call while the rest keep working.
     *
     * A property rather than a second Http::fake(): Laravel keeps the earliest
     * matching stub, so re-faking silently serves the original data. Trap 23.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $overrides = [];

    /**
     * The series page under test.
     *
     * Held rather than re-created per assertion, because Livewire::test() builds a
     * new component each time and a `set()` on one instance is invisible to the
     * next.
     */
    private ?Testable $component = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->overrides = [];
        $this->component = null;

        $this->fakeSuwayomi();
    }

    /**
     * The route parameter name, which is also the mount() argument name.
     *
     * Passed explicitly because the component reads it from the route rather than
     * from a query string, which is what makes /series/76 addressable.
     */
    protected function seriesParams(): array
    {
        return ['manga' => 76];
    }

    private function fakeSuwayomi(): void
    {
        Http::fake(function (Request $request) {
            $query = $request->data()['query'] ?? '';

            if (str_contains($query, 'mutation Login')) {
                return Http::response([
                    'data' => ['login' => ['accessToken' => $this->accessToken(), 'refreshToken' => $this->refreshToken()]],
                ]);
            }

            foreach ($this->overrides as $needle => $response) {
                if (str_contains($query, $needle)) {
                    return Http::response($response);
                }
            }

            if (str_contains($query, 'fetchChapterPages')) {
                return Http::response(['data' => ['fetchChapterPages' => [
                    'chapter' => ['id' => '1', 'name' => 'Chapter 1', 'pageCount' => 3],
                    'pages' => [
                        '/api/v1/manga/76/chapter/1/page/0',
                        '/api/v1/manga/76/chapter/1/page/1',
                        '/api/v1/manga/76/chapter/1/page/2',
                    ],
                ]]]);
            }

            if (str_contains($query, 'mutation Refresh')) {
                return Http::response(['data' => ['fetchMangaAndChapters' => [
                    'manga' => $this->manga(),
                    'chapters' => $this->chapters(),
                ]]]);
            }

            return Http::response(['data' => ['manga' => $this->manga() + [
                'chapters' => ['totalCount' => count($this->chapters()), 'nodes' => $this->chapters()],
            ]]]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function manga(): array
    {
        return [
            'id' => '76',
            'title' => 'The Berserker\'s Second Playthrough',
            'url' => '/series/x',
            'realUrl' => 'https://asurascans.com/comics/x',
            'thumbnailUrl' => '/api/v1/manga/76/thumbnail',
            'status' => 'ONGOING',
            'author' => 'Life Ruined',
            'artist' => 'Isang',
            'genre' => ['Manhwa', 'Action'],
            'description' => 'Rating: 8.24',
            'initialized' => true,
            'unreadCount' => 3,
            'updateStrategy' => 'ALWAYS_UPDATE',
            'chaptersLastFetchedAt' => '1790776498',
            'source' => ['id' => '6247824327199706550', 'name' => 'Asura Scans', 'lang' => 'en', 'contentWarning' => 'SAFE'],
        ];
    }

    /**
     * Two chapters share a number from different scanlators, which is the case the
     * exclusion control exists for.
     *
     * @return array<int, array<string, mixed>>
     */
    private function chapters(): array
    {
        return [
            $this->chapter(1, 1.0, 1, null, '1767000000000'),
            $this->chapter(2, 1.0, 2, 'Group A', '1767100000000'),
            $this->chapter(3, 2.0, 3, null, '1767198093000'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chapter(int $id, float $number, int $order, ?string $scanlator, string $uploadDate): array
    {
        return [
            'id' => (string) $id,
            'name' => 'Chapter '.(int) $number,
            'chapterNumber' => $number,
            'scanlator' => $scanlator,
            'uploadDate' => $uploadDate,
            'pageCount' => -1,
            'isRead' => false,
            'lastPageRead' => 0,
            'sourceOrder' => $order,
        ];
    }

    /**
     * The series page, built once per test and reused across assertions.
     */
    private function series(): Testable
    {
        return $this->component ??= Livewire::test('pages::series', $this->seriesParams());
    }

    // Series page ---------------------------------------------------------

    public function test_the_series_page_needs_authentication(): void
    {
        auth()->logout();

        $this->get('/series/76')->assertRedirect(route('login'));
    }

    public function test_it_renders_the_series_metadata(): void
    {
        $this->series()
            ->assertOk()
            ->assertSee("The Berserker's Second Playthrough")
            ->assertSee('Asura Scans')
            ->assertSee('Life Ruined')
            ->assertSee('Manhwa');
    }

    /**
     * The tab names the series, not every series the same word.
     *
     * The static `#[Title('Series')]` made every one of the reader's series the same
     * browser tab.
     */
    public function test_the_browser_tab_names_the_series(): void
    {
        $this->get('/series/76')
            ->assertOk()
            ->assertSee("The Berserker's Second Playthrough - ".config('app.name'));
    }

    /**
     * A series the server cannot name still says which page the tab is.
     */
    public function test_the_browser_tab_falls_back_to_the_series_id(): void
    {
        $this->overrides['query Series'] = ['data' => ['manga' => null]];

        $this->get('/series/76')
            ->assertOk()
            ->assertSee('Series 76 - '.config('app.name'));
    }

    public function test_the_browser_tab_falls_back_to_the_series_id_in_spanish(): void
    {
        $this->overrides['query Series'] = ['data' => ['manga' => null]];

        $this->withSession(['locale' => 'es'])
            ->get('/series/76')
            ->assertOk()
            ->assertSee('Serie 76 - '.config('app.name'));
    }

    /**
     * One number, no source segment, because manga ids are global. Verified
     * against the live server: manga 174 is One Piece on MangaDex and 76 is a
     * Berserker title on Asura Scans, and both resolve from the bare id.
     */
    public function test_the_series_route_takes_only_a_manga_id(): void
    {
        $this->get('/series/76')->assertOk();

        // A non-numeric id cannot reach the page, and must not be coerced.
        $this->get('/series/not-a-number')->assertNotFound();
    }

    public function test_it_lists_every_chapter(): void
    {
        $this->series()
            ->assertSee('Chapter 1')
            ->assertSee('Chapter 2')
            ->assertSee('Group A');
    }

    public function test_chapter_dates_are_readable_not_in_the_year_57970(): void
    {
        // uploadDate is milliseconds. Read as seconds every chapter lands in 57970.
        $html = $this->series()->html();

        $this->assertStringContainsString('2025-12-31', $html);
        $this->assertStringNotContainsString('57970', $html);
    }

    /**
     * The upstream host appears legitimately in two places: the "view on source"
     * link, which is the point of it, and nowhere else. What must not appear is an
     * image src pointing upstream, since the proxy is what keeps the token off the
     * browser and the referer off the source.
     */
    public function test_the_cover_is_proxied_and_never_hotlinked(): void
    {
        $html = $this->series()->html();

        $this->assertStringContainsString('/suwayomi/api/v1/manga/76/thumbnail', $html);

        preg_match_all('/<img[^>]+src="([^"]+)"/', (string) $html, $matches);

        foreach ($matches[1] as $src) {
            $this->assertStringNotContainsString('asurascans.com', $src);
            $this->assertStringContainsString('/suwayomi/api/v1/', $src);
        }
    }

    /**
     * A series nobody has opened has no chapter list, and a blank page would look
     * like a broken app rather than an unpopulated cache.
     */
    public function test_it_explains_an_empty_chapter_list_rather_than_showing_nothing(): void
    {
        $this->overrides['query Series'] = ['data' => ['manga' => $this->manga() + [
            'chapters' => ['totalCount' => 0, 'nodes' => []],
        ]]];

        $component = $this->series();

        /*
         * The automatic fetch releases this claim in its `finally` when it finishes. The
         * queue is database backed now, so nothing runs the job inline and the page would
         * otherwise stay on its in-flight panel. Releasing it here models the finished
         * fetch, which is the state this test is about.
         */
        FetchSeriesChapters::release((int) $this->user->id);

        $component->call('$refresh')
            ->assertSee('No chapters yet')
            ->assertSee('Refresh chapters');
    }

    /**
     * "No chapters found" is a bare java.lang.Exception from the source arriving as
     * a GraphQL error on HTTP 200, so it has to be surfaced rather than swallowed
     * into a generic failure.
     */
    public function test_a_no_chapters_response_is_surfaced_not_swallowed(): void
    {
        $this->overrides['mutation Refresh'] = ['errors' => [[
            'message' => 'Exception while fetching data (/fetchChapters) : No chapters found',
        ]]];

        $this->series()
            ->call('refresh')
            ->assertSee('The source returned no chapters')
            ->assertSee('The source answered, but it listed no chapters.')
            ->assertSee('If the series moved, you can move it to another source from here.')
            ->assertSee('The source may have changed its layout or it may be broken.')
            ->assertDontSee('upstream condition');
    }

    public function test_an_unknown_manga_is_explained_rather_than_a_blank_page(): void
    {
        $this->overrides['query Series'] = ['data' => ['manga' => null]];

        $this->series()->assertSee('This series is not in the catalogue');
    }

    // Library and reading state -------------------------------------------

    public function test_adding_to_the_library_records_the_date(): void
    {
        $this->series()->call('toggleLibrary');

        $this->assertDatabaseHas('series_states', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => 76,
            'in_library' => true,
        ]);

        $this->assertNotNull(
            SeriesState::forUser($this->user->id)->where('suwayomi_manga_id', 76)->first()?->added_at,
        );
    }

    public function test_toggling_the_library_twice_removes_it(): void
    {
        $this->series()->call('toggleLibrary')->call('toggleLibrary');

        $this->assertFalse(
            (bool) SeriesState::forUser($this->user->id)->where('suwayomi_manga_id', 76)->first()?->in_library,
        );
    }

    public function test_state_is_per_user(): void
    {
        $bob = User::factory()->create();

        $this->series()->call('toggleLibrary');

        $this->assertDatabaseMissing('series_states', [
            'user_id' => $bob->id,
            'suwayomi_manga_id' => 76,
        ]);
    }

    /**
     * A new reader gets "start reading" on the first chapter, not a dead control,
     * because there is no recorded pointer yet.
     */
    public function test_a_fresh_reader_is_offered_the_first_chapter(): void
    {
        $this->series()->assertSee('Start reading');
    }

    public function test_a_reader_with_history_is_offered_their_last_chapter(): void
    {
        SeriesState::createFor($this->user->id, [
            'suwayomi_manga_id' => 76,
            'last_read_chapter_id' => 2,
            'last_read_at' => now(),
        ]);

        $this->series()->assertSee('Continue reading');
    }

    // Scanlator exclusion --------------------------------------------------

    /**
     * Duplicate chapters from different groups are why this control exists, so
     * excluding a group has to actually remove its chapter from the list.
     */
    public function test_excluding_a_scanlator_hides_its_chapters(): void
    {
        $this->assertSame(3, $this->series()->instance()->visibleCount);

        $this->series()->set('excludedScanlators', ['Group A']);

        $this->assertSame(2, $this->series()->instance()->visibleCount);
    }

    public function test_exclusion_is_case_insensitive(): void
    {
        $this->series()->set('excludedScanlators', ['group a']);

        $this->assertSame(2, $this->series()->instance()->visibleCount);
    }

    /**
     * A chapter with no scanlator has nothing to match against, so excluding
     * everything would otherwise silently lose chapters.
     */
    public function test_chapters_without_a_scanlator_are_never_excluded(): void
    {
        $this->series()->set('excludedScanlators', ['Group A', 'Nonexistent']);

        $this->assertSame(2, $this->series()->instance()->visibleCount);
    }

    /**
     * An invisible scanlator is no scanlator.
     *
     * Measured: one source sends a zero-width space. `filled()` counts it as present, so
     * it rendered a fourth group button labelled with nothing and a chapter row with an
     * invisible name. Whitespace-only names are the same shape.
     */
    public function test_an_invisible_scanlator_is_treated_as_no_scanlator(): void
    {
        $chapters = [
            $this->chapter(1, 1.0, 1, 'Group A', '1767000000000'),
            $this->chapter(2, 1.0, 2, 'Group B', '1767100000000'),
            $this->chapter(3, 2.0, 3, "\u{200B}", '1767198093000'),
        ];

        $this->overrides['query Series'] = ['data' => ['manga' => $this->manga() + [
            'chapters' => ['totalCount' => 3, 'nodes' => $chapters],
        ]]];

        $component = $this->series();

        $component->assertSee('2 groups')
            ->assertDontSee("\u{200B}");

        // Excluding both real groups leaves the invisible one, because it is not a
        // group at all and its chapter is never dropped.
        $component->set('excludedScanlators', ['Group A', 'Group B'])
            ->assertDontSee('Chapter 1')
            ->assertSee('Chapter 2');
    }

    /**
     * The cover upload and the tag input are named.
     *
     * The cover input sits inside a visible label, and the tag input has an sr-only
     * label, but the sweep found both unnamed. The explicit names match what the page
     * shows, so the browser and the walk agree.
     */
    public function test_the_cover_and_tag_inputs_are_named(): void
    {
        $html = $this->series()->assertOk()->html();

        $doc = new \DOMDocument;
        $doc->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><body>'.$html.'</body>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        $xpath = new \DOMXPath($doc);
        $cover = $xpath->query('//input[@id="cover-upload"]');
        $tags = $xpath->query('//input[@id="series-tags"]');

        $this->assertNotFalse($cover);
        $this->assertNotFalse($tags);
        $this->assertSame(1, $cover->length);
        $this->assertSame(1, $tags->length);

        $this->assertSame('Use your own cover', $cover->item(0)?->getAttribute('aria-label'));
        $this->assertSame('Add a tag', $tags->item(0)?->getAttribute('aria-label'));
    }

    // Ordering -------------------------------------------------------------

    public function test_chapters_order_by_the_sources_own_sequence(): void
    {
        $ascending = array_column($this->series()->instance()->chapters, 'sourceOrder');
        $descending = $this->series()->set('order', 'desc')->instance()->chapters;

        $this->assertSame([1, 2, 3], $ascending);
        $this->assertSame([3, 2, 1], array_column($descending, 'sourceOrder'));
    }

    public function test_an_unknown_order_is_ignored(): void
    {
        $this->series()->set('order', 'sideways')->assertSet('order', 'sideways');
    }

    // Reader ---------------------------------------------------------------

    public function test_the_reader_needs_authentication(): void
    {
        auth()->logout();

        $this->get('/read/76/1')->assertRedirect(route('login'));
    }

    public function test_it_renders_one_image_per_page(): void
    {
        $response = $this->get('/read/76/1');

        $response->assertOk();
        $response->assertSee('/suwayomi/api/v1/manga/76/chapter/1/page/0', false);
        $response->assertSee('/suwayomi/api/v1/manga/76/chapter/1/page/2', false);
    }

    public function test_the_reader_shows_a_visible_back_to_series_link(): void
    {
        $html = $this->get('/read/76/1')->assertOk()->getContent();

        $this->assertStringContainsString('Back to the series', $html);
        $this->assertStringContainsString('/series/76', $html);
    }

    /**
     * The reader is a plain document on purpose: a Livewire round trip per page
     * turn would put the 2.0s fetchChapterPages cost on every scroll. So there
     * must be no Livewire payload in it.
     */
    public function test_the_reader_carries_no_livewire_payload(): void
    {
        $html = $this->get('/read/76/1')->assertOk()->getContent();

        $this->assertStringNotContainsString('wire:snapshot', $html);
        $this->assertStringNotContainsString('livewire.js', $html);
    }

    /**
     * The counterpart to the Livewire check, and a style guard.
     *
     * The reader script is deliberately plain JavaScript. Alpine is in fact loaded
     * on this page, since partials.head pulls in Flux and Livewire, so nothing
     * here is prevented from using a directive. The choice is that the work is
     * imperative and the state is five numbers, and the previous Alpine version
     * of the script did not behave when verified in a browser.
     *
     * If a future change reaches for a directive, this fails and the reasoning
     * has to be written down here again.
     */
    public function test_the_reader_uses_no_framework_directives(): void
    {
        $html = $this->get('/read/76/1')->assertOk()->getContent();
        $code = $this->readerCode();

        foreach (['x-data', 'x-text', 'x-show', 'x-model', '@click', 'Alpine.data', 'alpine:init'] as $directive) {
            $this->assertStringNotContainsString($directive, $html, 'Found in the page: '.$directive);
            $this->assertStringNotContainsString($directive, $code, 'Found in the reader module: '.$directive);
        }
    }

    /**
     * The settings the reader renders must reach the browser, or the mode button
     * and the progress ping have nothing to work from.
     */
    public function test_the_reader_script_carries_its_configuration(): void
    {
        $html = $this->get('/read/76/1')->assertOk()->getContent();
        $code = $this->readerCode();

        $this->assertStringContainsString('data-reader-config=', $html);
        $this->assertStringContainsString('/read/76/1/progress', $html);
        $this->assertStringContainsString('/read/76/settings', $html);
        $this->assertStringContainsString('/read/76/1/note', $html);

        $this->assertStringContainsString('navigator.sendBeacon', $code);
        $this->assertStringContainsString(basename($this->readerAsset()), $html);
    }

    /**
     * The attribute is the module's only input, so every moved value must be in
     * it and the module must read every one of them.
     *
     * A key that reaches one side but not the other is undefined at runtime and
     * the failure is quiet: a note panel says nothing, or the first paint and the
     * script disagree about the filters.
     */
    public function test_the_reader_configuration_carries_the_settings_and_strings(): void
    {
        $html = $this->get('/read/76/1')->assertOk()->getContent();

        $this->assertSame(
            1,
            preg_match("~data-reader-config='([^']+)'~", $html, $matches),
            'The reader page must carry its configuration attribute.',
        );

        $config = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);

        $this->assertIsArray($config);

        $settings = $config['settings'] ?? null;

        $this->assertIsArray($settings);

        foreach ([
            'mode', 'width', 'gap', 'zoom', 'background',
            'long_strip', 'fit_height', 'hide_tap_zones',
            'brightness', 'contrast', 'hue', 'invert', 'grayscale',
        ] as $key) {
            $this->assertArrayHasKey($key, $settings);
        }

        $strings = [
            'previousPage' => 'Previous page',
            'nextPage' => 'Next page',
            'filterTemporary' => 'Temporary, for this visit only.',
            'noteSaving' => 'Saving...',
            'noteFailed' => 'Could not save the note.',
            'noteSaved' => 'Saved.',
            'noteCleared' => 'Note cleared.',
        ];

        $this->assertSame($strings, $config['strings'] ?? null);

        $code = $this->readerCode();

        $this->assertStringContainsString('config.settings', $code);

        foreach (array_keys($strings) as $key) {
            $this->assertStringContainsString('config.strings.'.$key, $code);
        }
    }

    /**
     * The counter is rendered server side, so a reader with JavaScript blocked or
     * still loading sees the page they are on rather than a blank.
     */
    public function test_the_page_counter_is_rendered_without_script(): void
    {
        $html = $this->get('/read/76/1')->assertOk()->getContent();

        $this->assertStringContainsString('<span data-reader-current>1</span>', $html);
        $this->assertStringContainsString('<span data-reader-total>3</span>', $html);
    }

    /**
     * Guard for a bug the browser found and no other test could.
     *
     * The reader started its observer on window load, which waits for every eager
     * image. Asura stalled on one page, load never fired, and the reader rendered
     * its pages perfectly while the counter, the progress posts and the keyboard
     * were all dead. Nothing about the HTML looks wrong in a test, so the gate is
     * asserted against directly.
     */
    public function test_the_reader_does_not_wait_for_every_image_before_starting(): void
    {
        $html = $this->get('/read/76/1')->assertOk()->getContent();
        $code = $this->readerCode();

        $this->assertStringNotContainsString("addEventListener('load'", $html);
        $this->assertStringNotContainsString('onload', $html);
        $this->assertStringNotContainsString("addEventListener('load'", $code);
        $this->assertStringNotContainsString('onload', $code);
    }

    /**
     * The reader's behaviour is a built module now, not an inline block.
     *
     * The page used to carry the whole script between two tags. That block is
     * gone, and the only place the code can come from is the manifest entry, so
     * this asserts the new shape rather than a line count.
     */
    public function test_the_reader_loads_its_code_from_the_built_module(): void
    {
        $html = $this->get('/read/76/1')->assertOk()->getContent();
        $asset = basename($this->readerAsset());

        $this->assertMatchesRegularExpression(
            '~<script[^>]*\bsrc="[^"]*'.preg_quote($asset, '~').'"~',
            $html,
            'The reader page must reference the built module.',
        );

        preg_match_all('~<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>~s', $html, $blocks);

        $inline = implode("\n", $blocks[1]);

        $this->assertStringNotContainsString('readerConfig', $inline);
        $this->assertStringNotContainsString('sendBeacon', $inline);
    }

    /**
     * Page tracking must not depend on the page producing a frame.
     *
     * IntersectionObserver callbacks and requestAnimationFrame callbacks are
     * delivered by the rendering lifecycle. A document that is not being rendered
     * gets neither: a backgrounded tab is the everyday case, and the in-app
     * browser panel is the extreme one, where scrollY moved and the pages
     * rendered while the counter sat frozen. A scroll event arrives either way,
     * so that is the only signal the reader uses.
     *
     * Comments are stripped first, so this asserts about code. The file explains
     * at length why it avoids those two APIs, and naming them there would
     * otherwise make the test impossible to write honestly.
     */
    public function test_page_tracking_does_not_depend_on_a_frame(): void
    {
        $code = $this->readerCode();

        $this->assertStringNotContainsString('IntersectionObserver', $code);
        $this->assertStringNotContainsString('requestAnimationFrame', $code);
        $this->assertStringContainsString("addEventListener('scroll'", $code);
    }

    /**
     * The reader module with comments removed.
     *
     * Read from disk because the page loads the module through Vite and no
     * longer carries its code inline. Comments are stripped so the tests below
     * assert about code: the module explains at length which framework and
     * rendering APIs it avoids, and naming them in prose must not fail a test
     * that is about the executable lines.
     */
    private function readerCode(): string
    {
        $script = (string) file_get_contents(resource_path('js/reader.js'));

        /*
         * Block comments, then line comments. String literals are not protected,
         * which is fine: the reader's URLs contain "//" so a line holding one gets
         * its tail dropped, and a false positive there can only make the test
         * stricter, never looser.
         */
        $script = (string) preg_replace('~/\*.*?\*/~s', '', $script);
        $script = (string) preg_replace('~//[^\n]*~', '', $script);

        return trim($script);
    }

    /**
     * The built reader asset, named by Vite's manifest.
     *
     * `public/build` is generated by `npm run build` and is not tracked. The
     * page cannot load the module without it, so a missing entry is a failure
     * here rather than a silent pass on a stale build.
     */
    private function readerAsset(): string
    {
        $path = public_path('build/manifest.json');

        $this->assertFileExists($path);

        $manifest = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('resources/js/reader.js', $manifest);

        $entry = $manifest['resources/js/reader.js'];

        $this->assertIsArray($entry);
        $this->assertArrayHasKey('file', $entry);
        $this->assertFileExists(public_path('build/'.$entry['file']));

        return (string) $entry['file'];
    }

    /**
     * A reader on a phone has no keyboard, so paged mode is only usable if a tap
     * turns the page. The zones carry the chapter href and the script decides
     * whether to intercept, which the test pins by asserting both halves exist.
     */
    public function test_paged_mode_taps_turn_pages_and_fall_through_at_the_edges(): void
    {
        $code = $this->readerCode();

        $this->assertStringContainsString('function bindZone(', $code);
        $this->assertStringContainsString('function describeZones(', $code);
        $this->assertStringContainsString('state.pagedIndex >= total - 1', $code);
        $this->assertStringContainsString('state.pagedIndex <= 0', $code);
    }

    // Reader settings ------------------------------------------------------

    /**
     * The reader must render in whatever mode the user stored, so a reader who
     * switched to paged on the series page does not open a chapter to find it
     * scrolling again.
     */
    public function test_the_reader_renders_in_the_stored_mode(): void
    {
        $this->putJson('/read/76/settings', ['settings' => ['mode' => 'paged']])->assertOk();

        $html = $this->get('/read/76/1')->assertOk()->getContent();

        $this->assertStringContainsString('data-mode="paged"', $html);
    }

    public function test_the_reader_defaults_to_vertical(): void
    {
        $html = $this->get('/read/76/1')->assertOk()->getContent();

        $this->assertStringContainsString('data-mode="vertical"', $html);
    }

    /**
     * The resolved settings go out as CSS custom properties so the first paint is
     * correct before any script runs. This asserts the default is present.
     */
    public function test_resolved_settings_reach_the_stylesheet(): void
    {
        $html = $this->get('/read/76/1')->assertOk()->getContent();

        $this->assertStringContainsString('--reader-bg: #0a0a0a', $html);
        $this->assertStringContainsString('--reader-width: 100%', $html);
    }

    public function test_settings_can_be_stored(): void
    {
        $this->putJson('/read/76/settings', [
            'settings' => ['zoom' => 1.75, 'gap' => 12, 'background' => '#111111'],
        ])->assertOk();

        $this->assertDatabaseHas('series_states', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => 76,
        ]);

        $state = SeriesState::forUser($this->user->id)->where('suwayomi_manga_id', 76)->first();

        $this->assertSame(1.75, $state->reader_settings['zoom']);
        $this->assertSame('#111111', $state->reader_settings['background']);
    }

    /**
     * The mode lands in its own column so the series page can show and change it
     * without reading the settings JSON.
     */
    public function test_the_mode_is_stored_in_reading_mode(): void
    {
        $this->putJson('/read/76/settings', ['settings' => ['mode' => 'paged']])->assertOk();

        $this->assertDatabaseHas('series_states', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => 76,
            'reading_mode' => 'paged',
        ]);
    }

    /**
     * Storing one key must not erase the others. A reader who zooms after
     * choosing paged has not chosen vertical.
     */
    public function test_storing_one_setting_keeps_the_others(): void
    {
        $this->putJson('/read/76/settings', ['settings' => ['mode' => 'paged']])->assertOk();
        $this->putJson('/read/76/settings', ['settings' => ['zoom' => 2.0]])->assertOk();

        $resolved = app(ReaderSettings::class)->resolve($this->user, 76);

        $this->assertSame('paged', $resolved['mode']);
        $this->assertSame(2.0, $resolved['zoom']);
    }

    /**
     * Redirect rather than 401, because the caller is the reader page itself and a
     * redirect sends it to the login rather than showing it a bare status code.
     */
    public function test_settings_require_authentication(): void
    {
        auth()->logout();

        $this->put('/read/76/settings', ['settings' => ['zoom' => 2.0]])
            ->assertRedirect(route('login'));
    }

    public function test_settings_reject_an_unknown_mode(): void
    {
        $this->putJson('/read/76/settings', ['settings' => ['mode' => 'sideways']])->assertStatus(422);

        $this->assertDatabaseCount('series_states', 0);
    }

    /**
     * The request validator runs before the clamp, so an out of range zoom never
     * reaches storage and never has to be clamped on read.
     */
    public function test_settings_reject_an_out_of_range_value(): void
    {
        $this->putJson('/read/76/settings', ['settings' => ['zoom' => 99]])->assertStatus(422);
        $this->putJson('/read/76/settings', ['settings' => ['width' => 500]])->assertStatus(422);
        $this->putJson('/read/76/settings', ['settings' => ['gap' => -4]])->assertStatus(422);

        $this->assertDatabaseCount('series_states', 0);
    }

    public function test_settings_are_per_user(): void
    {
        $bob = User::factory()->create();

        $this->putJson('/read/76/settings', ['settings' => ['zoom' => 2.0]])->assertOk();

        $this->actingAs($bob);

        $html = $this->get('/read/76/1')->assertOk()->getContent();

        $this->assertStringContainsString('--reader-zoom: 1', $html);
    }

    // Reading mode on the series page ---------------------------------------

    public function test_the_series_page_switches_the_reading_mode(): void
    {
        $this->series()->call('setReadingMode', 'paged')->assertOk();

        $this->assertDatabaseHas('series_states', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => 76,
            'reading_mode' => 'paged',
        ]);
    }

    /**
     * The series page must show the mode the reader will actually open in, so
     * the button group is not a second opinion about a setting stored elsewhere.
     */
    public function test_the_series_page_shows_the_mode_the_reader_will_use(): void
    {
        $this->assertSame('vertical', $this->series()->instance()->readingSettings()['mode']);

        $this->series()->call('setReadingMode', 'paged');

        $this->assertSame('paged', $this->series()->instance()->readingSettings()['mode']);
    }

    /**
     * The upstream host must never appear in a reader image src, because the
     * proxy is what keeps the token off the browser and the referer off the source.
     */
    public function test_no_page_image_points_at_the_upstream_host(): void
    {
        $html = $this->get('/read/76/1')->assertOk()->getContent();

        preg_match_all('/<img[^>]+src="([^"]+)"/', (string) $html, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $src) {
            $this->assertStringNotContainsString('127.0.0.1', $src);
            $this->assertStringContainsString('/suwayomi/api/v1/', $src);
        }
    }

    public function test_opening_a_chapter_moves_the_series_pointer(): void
    {
        $this->get('/read/76/2')->assertOk();

        $this->assertDatabaseHas('series_states', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => 76,
            'last_read_chapter_id' => 2,
        ]);
    }

    public function test_it_links_to_the_neighbouring_chapters(): void
    {
        $html = $this->get('/read/76/2')->assertOk()->getContent();

        $this->assertStringContainsString('/read/76/1', $html);
        $this->assertStringContainsString('/read/76/3', $html);
    }

    public function test_the_first_chapter_has_no_previous_link(): void
    {
        $html = $this->get('/read/76/1')->assertOk()->getContent();

        $this->assertStringNotContainsString('aria-label="Previous chapter"', $html);
    }

    /**
     * A dead tap zone is a control that does nothing, so the last chapter gets no
     * next link either.
     */
    public function test_the_last_chapter_has_no_next_link(): void
    {
        $html = $this->get('/read/76/3')->assertOk()->getContent();

        $this->assertStringNotContainsString('aria-label="Next chapter"', $html);
    }

    // Progress -------------------------------------------------------------

    public function test_progress_is_recorded_from_a_json_body(): void
    {
        $this->postJson('/read/76/1/progress', ['page' => 2, 'total' => 3])->assertOk();

        $this->assertDatabaseHas('chapter_states', [
            'user_id' => $this->user->id,
            'suwayomi_chapter_id' => 1,
            'suwayomi_manga_id' => 76,
            'last_page_read' => 2,
        ]);
    }

    /**
     * sendBeacon cannot set a content type, so it posts a form. Refusing that
     * would lose the reader's place on exactly the page they navigated away from.
     */
    public function test_progress_is_recorded_from_a_beacon_form_post(): void
    {
        $this->post('/read/76/1/progress', ['page' => 2, 'total' => 3])
            ->assertOk()
            ->assertHeader('content-type', 'application/json');

        $this->assertSame(2, ChapterState::forUser($this->user->id)->first()?->last_page_read);
    }

    public function test_reaching_the_last_page_marks_the_chapter_read(): void
    {
        $this->postJson('/read/76/1/progress', ['page' => 2, 'total' => 3]);

        $this->assertTrue((bool) ChapterState::forUser($this->user->id)->first()?->is_read);
    }

    public function test_progress_never_moves_backwards(): void
    {
        $this->postJson('/read/76/1/progress', ['page' => 2, 'total' => 10]);
        $this->postJson('/read/76/1/progress', ['page' => 0, 'total' => 10]);

        $this->assertSame(2, ChapterState::forUser($this->user->id)->first()?->last_page_read);
    }

    public function test_a_negative_page_is_rejected(): void
    {
        $this->postJson('/read/76/1/progress', ['page' => -1, 'total' => 3])->assertStatus(422);

        $this->assertDatabaseCount('chapter_states', 0);
    }

    public function test_a_missing_page_is_rejected(): void
    {
        $this->postJson('/read/76/1/progress', ['total' => 3])->assertStatus(422);
    }

    /**
     * Without a page count there is nothing to compare against, so "read" cannot
     * be inferred and must not be guessed.
     */
    public function test_no_page_count_means_nothing_is_marked_read(): void
    {
        $this->postJson('/read/76/1/progress', ['page' => 2]);

        $chapter = ChapterState::forUser($this->user->id)->first();

        $this->assertSame(2, $chapter?->last_page_read);
        $this->assertFalse((bool) $chapter?->is_read);
    }

    public function test_the_series_page_speaks_spanish_when_selected(): void
    {
        app()->setLocale('es');

        $this->series()
            ->assertSee('Empezar a leer')
            ->assertSee('Capítulos')
            ->assertSee('Marcar todo como leído')
            ->assertSee('Más recientes')
            ->assertSee('Etiquetas');
    }

    public function test_the_reader_speaks_spanish_when_selected(): void
    {
        $this->withSession(['locale' => 'es'])
            ->get('/read/76/1')
            ->assertOk()
            ->assertSee('Ajustes del lector')
            ->assertSee('Página 1')
            ->assertSee('Capítulo siguiente')
            ->assertSee('Lectura &middot; Papyrus', false);
    }

    /**
     * The route sits inside the auth middleware group, so an unauthenticated post
     * is redirected before it reaches the controller. A 419 here would mean the
     * CSRF check ran first and the reader's beacon was being rejected.
     */
    public function test_progress_requires_authentication(): void
    {
        auth()->logout();

        $this->post('/read/76/1/progress', ['page' => 2, 'total' => 3])
            ->assertRedirect(route('login'));
    }
}
