<?php

namespace Tests\Feature\Localization;

use App\Livewire\Onboarding\FirstRunTour;
use App\Models\Source;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class MultiLocalePagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function locales(): array
    {
        return [
            'French' => ['fr', 'fr'],
            'Brazilian Portuguese' => ['pt_BR', 'pt-BR'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => 'token-1',
                    'refreshToken' => 'r',
                ]]]);
            }

            if (str_contains($query, 'fetchChapterPages')) {
                return Http::response(['data' => ['fetchChapterPages' => [
                    'chapter' => ['id' => '1', 'name' => 'Chapter 1', 'pageCount' => 1],
                    'pages' => ['/api/v1/manga/76/chapter/1/page/0'],
                ]]]);
            }

            if (str_contains($query, 'fetchSourceManga')) {
                return Http::response(['data' => ['fetchSourceManga' => [
                    'hasNextPage' => false,
                    'mangas' => [],
                ]]]);
            }

            if (str_contains($query, 'query Downloads')) {
                return Http::response(['data' => ['downloadStatus' => [
                    'state' => 'STOPPED',
                    'queue' => [],
                ]]]);
            }

            if (str_contains($query, 'query Extensions')) {
                return Http::response(['data' => ['extensions' => [
                    'totalCount' => 0,
                    'nodes' => [],
                ]]]);
            }

            if (str_contains($query, 'query Stores')) {
                return Http::response(['data' => ['extensionStores' => ['nodes' => []]]]);
            }

            if (str_contains($query, 'query Sources')) {
                return Http::response(['data' => ['sources' => [
                    'totalCount' => 0,
                    'nodes' => [],
                ]]]);
            }

            if (str_contains($query, 'query HealthProbe')) {
                return Http::response(['data' => ['sources' => ['totalCount' => 0]]]);
            }

            if (str_contains($query, 'query Many')
                || str_contains($query, 'query ManyWithPages')
                || str_contains($query, 'mangas(')) {
                return Http::response(['data' => ['mangas' => ['nodes' => []]]]);
            }

            if (str_contains($query, 'manga(')) {
                return Http::response(['data' => ['manga' => $this->manga()]]);
            }

            return Http::response(['data' => []]);
        });
    }

    private function source(): Source
    {
        return Source::query()->create([
            'suwayomi_source_id' => 4242,
            'name' => 'Test Scans',
            'display_name' => 'Test Scans (EN)',
            'extension_pkg_name' => 'com.example.testscans',
            'lang' => 'en',
            'home_url' => 'https://testscans.test',
            'content_warning' => Source::SAFE,
            'is_installed' => true,
            'supports_latest' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function manga(): array
    {
        return [
            'id' => '76',
            'title' => 'Test Series',
            'url' => '/series/x',
            'realUrl' => 'https://testscans.test/series/x',
            'thumbnailUrl' => '/api/v1/manga/76/thumbnail',
            'status' => 'ONGOING',
            'author' => 'Author',
            'artist' => 'Artist',
            'genre' => ['Action'],
            'description' => 'Description',
            'initialized' => true,
            'unreadCount' => 1,
            'updateStrategy' => 'ALWAYS_UPDATE',
            'chaptersLastFetchedAt' => '1790776498',
            'source' => ['id' => '4242', 'name' => 'Test Scans', 'lang' => 'en', 'contentWarning' => 'SAFE'],
            'chapters' => ['totalCount' => 1, 'nodes' => [[
                'id' => '1',
                'name' => 'Chapter 1',
                'chapterNumber' => 1,
                'scanlator' => null,
                'uploadDate' => '1767000000000',
                'pageCount' => 1,
                'isRead' => false,
                'lastPageRead' => 0,
                'sourceOrder' => 1,
            ]]],
        ];
    }

    #[DataProvider('locales')]
    public function test_the_login_page_speaks_the_locale(string $locale, string $htmlLang): void
    {
        $this->withSession(['locale' => $locale])
            ->get('/login')
            ->assertOk()
            ->assertSee('lang="'.$htmlLang.'"', false)
            ->assertSee(trans('Log in', [], $locale).' - Papyrus')
            ->assertSee(trans('Email address', [], $locale))
            ->assertSee(trans('Password', [], $locale));
    }

    #[DataProvider('locales')]
    public function test_the_library_page_speaks_the_locale(string $locale, string $htmlLang): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['locale' => $locale])
            ->get('/library')
            ->assertOk()
            ->assertSee('lang="'.$htmlLang.'"', false)
            ->assertSee(trans('Library', [], $locale).' - Papyrus')
            ->assertSee(trans('Sources', [], $locale))
            ->assertSee(trans('Good reading.', [], $locale));
    }

    #[DataProvider('locales')]
    public function test_the_search_and_browse_pages_speak_the_locale(string $locale, string $htmlLang): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['locale' => $locale])
            ->get('/search')
            ->assertOk()
            ->assertSee('lang="'.$htmlLang.'"', false)
            ->assertSee(trans('Search', [], $locale).' - Papyrus')
            ->assertSee(trans('Every source, one question.', [], $locale));

        $this->actingAs($user)
            ->withSession(['locale' => $locale])
            ->get(route('browse', $this->source()))
            ->assertOk()
            ->assertSee('lang="'.$htmlLang.'"', false)
            ->assertSee(trans('Browse :source', ['source' => 'Test Scans (EN)'], $locale).' - Papyrus');
    }

    #[DataProvider('locales')]
    public function test_the_series_and_reader_pages_speak_the_locale(string $locale, string $htmlLang): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['locale' => $locale])
            ->get('/series/76')
            ->assertOk()
            ->assertSee('lang="'.$htmlLang.'"', false)
            ->assertSee(trans('Chapters', [], $locale));

        $this->actingAs($user)
            ->withSession(['locale' => $locale])
            ->get('/read/76/1')
            ->assertOk()
            ->assertSee('lang="'.$htmlLang.'"', false)
            ->assertSee(trans('Reading', [], $locale).' &middot; Papyrus', false)
            ->assertSee(trans('Reader settings', [], $locale));
    }

    #[DataProvider('locales')]
    public function test_the_downloads_insights_sources_and_health_pages_speak_the_locale(string $locale, string $htmlLang): void
    {
        $user = User::factory()->create();

        foreach ([
            '/downloads' => trans('Downloads', [], $locale),
            '/insights' => trans('Insights', [], $locale),
            '/sources' => trans('Sources', [], $locale),
            '/health' => trans('Health', [], $locale),
        ] as $uri => $title) {
            $this->actingAs($user)
                ->withSession(['locale' => $locale])
                ->get($uri)
                ->assertOk()
                ->assertSee('lang="'.$htmlLang.'"', false)
                ->assertSee($title.' - Papyrus');
        }
    }

    #[DataProvider('locales')]
    public function test_the_profile_page_speaks_the_locale(string $locale, string $htmlLang): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['locale' => $locale])
            ->get('/settings/profile')
            ->assertOk()
            ->assertSee('lang="'.$htmlLang.'"', false)
            ->assertSee(trans('Profile settings', [], $locale).' - Papyrus');
    }

    #[DataProvider('locales')]
    public function test_the_tour_speaks_the_locale(string $locale, string $htmlLang): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INSTALL_OWNER]);
        $owner->refresh();

        $this->actingAs($owner);
        app()->setLocale($locale);

        Livewire::test(FirstRunTour::class, ['autoStart' => true, 'currentRoute' => 'library'])
            ->assertSee(trans('Papyrus needs a source', [], $locale))
            ->assertSee(trans('Step :step of :total', ['step' => 1, 'total' => 9], $locale));
    }

    #[DataProvider('locales')]
    public function test_the_error_pages_speak_the_locale(string $locale, string $htmlLang): void
    {
        foreach ([
            403 => [new HttpException(403), trans('Forbidden', [], $locale)],
            404 => [new NotFoundHttpException, trans('Page not found', [], $locale)],
            429 => [new HttpException(429), trans('Too many requests', [], $locale)],
            503 => [new HttpException(503), trans('Temporarily unavailable', [], $locale)],
        ] as $status => [$exception, $title]) {
            $request = $this->request('/library', $locale);
            $response = app(ExceptionHandler::class)->render($request, $exception);

            $content = html_entity_decode((string) $response->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $this->assertSame($status, $response->getStatusCode());
            $this->assertStringContainsString('lang="'.$htmlLang.'"', $content);
            $this->assertStringContainsString($title, $content);
        }

        config(['app.debug' => false]);

        $request = $this->request('/library', $locale);
        $response = app(ExceptionHandler::class)->render($request, new RuntimeException('boom'));
        $content = html_entity_decode((string) $response->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('lang="'.$htmlLang.'"', $content);
        $this->assertStringContainsString(trans('Something broke', [], $locale), $content);
    }

    private function request(string $uri, string $locale): HttpRequest
    {
        $request = HttpRequest::create($uri, 'GET');
        $request->headers->set('Accept', 'text/html');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put('locale', $locale);

        $this->app->instance('request', $request);

        return $request;
    }
}
