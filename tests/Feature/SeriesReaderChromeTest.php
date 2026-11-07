<?php

namespace Tests\Feature;

use App\Models\Download;
use App\Models\User;
use App\Support\ReaderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * Series header plus chapter list plus reader chrome, redesigned.
 *
 * Covers the redesign seams only: a cover-led hero with a clear primary Resume
 * action and grouped Library and Offline actions, a sticky chapter filter, rows
 * with a read check plus a Saved/Get/Queued pill plus a note affordance, and a
 * reader with an always-visible back link, hairline plus page counter, and calm
 * grouped settings in plain language. Settings clamping is pinned where the
 * reader renders it, because the browser only ever paints what the server
 * resolved.
 */
class SeriesReaderChromeTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        Http::fake(function (Request $request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
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
                $this->chapter(1, true),
                $this->chapter(2, false),
                $this->chapter(3, false),
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chapter(int $id, bool $downloaded): array
    {
        return [
            'id' => (string) $id,
            'name' => 'Chapter '.$id,
            'chapterNumber' => (float) $id,
            'sourceOrder' => $id,
            'uploadDate' => null,
            'pageCount' => -1,
            'isRead' => false,
            'isDownloaded' => $downloaded,
            'lastPageRead' => 0,
        ];
    }

    public function test_the_hero_names_the_resume_action_and_groups_library_and_offline(): void
    {
        $html = Livewire::test('pages::series', ['manga' => 76])->html();

        $this->assertStringContainsString('data-test="series-hero"', $html);
        $this->assertStringContainsString('data-test="series-actions"', $html);
        $this->assertStringContainsString('data-test="resume-action"', $html);
        $this->assertStringContainsString('Start reading', $html);
        $this->assertStringContainsString('data-test="library-group"', $html);
        $this->assertStringContainsString('data-test="offline-group"', $html);
        $this->assertStringContainsString('Library', $html);
        $this->assertStringContainsString('Offline', $html);
    }

    public function test_the_chapter_filter_is_sticky(): void
    {
        $html = Livewire::test('pages::series', ['manga' => 76])->html();

        $this->assertStringContainsString('data-test="chapter-filter"', $html);
        $this->assertStringContainsString('sticky', $html);
    }

    public function test_rows_carry_a_read_check_a_download_pill_and_a_note_button(): void
    {
        Download::createFor($this->user->id, [
            'suwayomi_chapter_id' => 2,
            'suwayomi_manga_id' => 76,
            'state' => Download::QUEUED,
        ]);

        $html = Livewire::test('pages::series', ['manga' => 76])->html();

        $this->assertStringContainsString('data-test="read-toggle"', $html);
        $this->assertStringContainsString('data-test="note-button"', $html);
        $this->assertStringContainsString('data-test="download-pill"', $html);
        $this->assertStringContainsString('data-state="saved"', $html);
        $this->assertStringContainsString('data-state="queued"', $html);
        $this->assertStringContainsString('data-state="get"', $html);
        $this->assertStringContainsString('Saved', $html);
        $this->assertStringContainsString('Queued', $html);
        $this->assertStringContainsString('Get', $html);
    }

    public function test_the_reader_keeps_an_always_visible_back_link_hairline_and_counter(): void
    {
        $html = $this->get('/read/76/1')->assertOk()->getContent();

        $this->assertStringContainsString('data-test="reader-topbar"', $html);
        $this->assertStringContainsString('data-test="reader-back"', $html);
        $this->assertStringContainsString('Back to the series', $html);
        $this->assertStringContainsString('/series/76', $html);
        $this->assertStringContainsString('reader-progress', $html);
        $this->assertStringContainsString('data-test="page-counter"', $html);
        $this->assertStringContainsString('data-reader-current', $html);
        $this->assertStringContainsString('data-reader-total', $html);
    }

    public function test_the_reader_groups_settings_in_plain_language(): void
    {
        $html = $this->get('/read/76/1')->assertOk()->getContent();

        $this->assertStringContainsString('data-test="settings-group-reading"', $html);
        $this->assertStringContainsString('data-test="settings-group-pages"', $html);
        $this->assertStringContainsString('data-test="settings-group-filters"', $html);
        $this->assertStringContainsString('Reading', $html);
        $this->assertStringContainsString('Pages', $html);
        $this->assertStringContainsString('Filters', $html);
        $this->assertStringContainsString('Colour filters', $html);
        $this->assertStringContainsString('Remember for this series', $html);
    }

    public function test_settings_still_clamp_where_the_reader_renders_them(): void
    {
        $settings = app(ReaderSettings::class);

        $this->assertSame(45, $settings->clamp(['width' => 5])['width']);
        $this->assertSame(100, $settings->clamp(['width' => 500])['width']);
        $this->assertSame(0.5, $settings->clamp(['zoom' => 0.01])['zoom']);
        $this->assertSame(3.0, $settings->clamp(['zoom' => 99])['zoom']);
        $this->assertSame(0, $settings->clamp(['gap' => -10])['gap']);
        $this->assertSame(0.2, $settings->clamp(['brightness' => 0.0])['brightness']);
        $this->assertSame(2.0, $settings->clamp(['brightness' => 99])['brightness']);

        $this->putJson('/read/76/settings', ['settings' => ['zoom' => 99]])->assertStatus(422);
        $this->putJson('/read/76/settings', ['settings' => ['width' => 500]])->assertStatus(422);
    }
}
