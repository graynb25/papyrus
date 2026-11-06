<?php

namespace Tests\Feature\Localization;

use App\Models\ReadingSession;
use App\Models\Source;
use App\Models\SourceHealth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SpanishResearchPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        Http::fake(function (Request $request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => 'token-1',
                    'refreshToken' => 'r',
                ]]]);
            }

            if (str_contains($query, 'query HealthProbe')) {
                return Http::response(['data' => ['sources' => ['totalCount' => 0]]]);
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

            if (str_contains($query, 'fetchSourceManga')) {
                return Http::response(['data' => ['fetchSourceManga' => [
                    'hasNextPage' => false,
                    'mangas' => [],
                ]]]);
            }

            if (str_contains($query, 'query Many')
                || str_contains($query, 'query ManyWithPages')
                || str_contains($query, 'mangas(')) {
                return Http::response(['data' => ['mangas' => ['nodes' => []]]]);
            }

            return Http::response(['data' => []]);
        });
    }

    private function browseSource(): Source
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

    public function test_the_search_page_speaks_spanish(): void
    {
        $this->actingAs($this->user)
            ->withSession(['locale' => 'es'])
            ->get('/search')
            ->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee('Buscar - Papyrus')
            ->assertSee('Todas las fuentes, una sola pregunta.')
            ->assertSee('Buscar en todas las fuentes')
            ->assertSee('Añade un término de filtro.')
            ->assertSee('No hay ninguna fuente instalada con la que buscar.');
    }

    public function test_the_browse_page_speaks_spanish(): void
    {
        $this->actingAs($this->user)
            ->withSession(['locale' => 'es'])
            ->get(route('browse', $this->browseSource()))
            ->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee('Explorar Test Scans (EN) - Papyrus')
            ->assertSee('Populares')
            ->assertSee('Últimos capítulos disponibles')
            ->assertSee('No hay nada que mostrar')
            ->assertSee('Esta fuente no devolvió ningún título.');
    }

    public function test_the_downloads_page_speaks_spanish(): void
    {
        $this->actingAs($this->user)
            ->withSession(['locale' => 'es'])
            ->get('/downloads')
            ->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee('Descargas - Papyrus')
            ->assertSee('Leyendo por adelantado, en silencio.')
            ->assertSee('Todavía no hay descargas')
            ->assertSee('Pon una en cola desde cualquier página de serie.');
    }

    public function test_the_insights_page_speaks_spanish(): void
    {
        $this->actingAs($this->user)
            ->withSession(['locale' => 'es'])
            ->get('/insights')
            ->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee('Estadísticas - Papyrus')
            ->assertSee('Todo lo que lees, contado.')
            ->assertSee('Tiempo de lectura')
            ->assertSee('Todo el tiempo')
            ->assertSee('Todavía no hay lectura registrada.');

        ReadingSession::factory()->create([
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => 76,
            'suwayomi_chapter_id' => 1,
            'started_at' => '2026-11-02 10:00:00',
            'last_active_at' => '2026-11-02 10:05:00',
            'ended_at' => '2026-11-02 10:05:00',
            'read_seconds' => 300,
            'pages_turned' => 5,
        ]);

        $this->actingAs($this->user)
            ->withSession(['locale' => 'es'])
            ->get('/insights?since=2026-11-01')
            ->assertOk()
            ->assertSee('desde el 1 de noviembre de 2026');
    }

    public function test_the_sources_page_speaks_spanish(): void
    {
        $this->actingAs($this->user)
            ->withSession(['locale' => 'es'])
            ->get('/sources')
            ->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee('Fuentes - Papyrus')
            ->assertSee('Fuentes, a tu manera.')
            ->assertSee('Todavía no hay ninguna tienda registrada.')
            ->assertSee('Todavía no hay fuentes instaladas.');
    }

    public function test_the_health_page_speaks_spanish(): void
    {
        $this->actingAs($this->user)
            ->withSession(['locale' => 'es'])
            ->get('/health')
            ->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee('Salud - Papyrus')
            ->assertSee('Todas las fuentes, vigiladas.')
            ->assertSee('Salud de las fuentes')
            ->assertSee('Salud muestra lo que Papyrus ha visto mientras buscaba.')
            ->assertSee('Ningún sitio coincide. Haz una búsqueda para empezar a llenar el registro.');
    }

    public function test_the_static_health_summary_keys_render_at_zero_one_and_two(): void
    {
        app()->setLocale('es');

        $expected = [
            '{1} :count dead|[2,*] :count dead' => [
                '0 sin servicio',
                '1 sin servicio',
                '2 sin servicio',
            ],
            '{1} :count failing|[2,*] :count failing' => [
                '0 con fallos',
                '1 con fallos',
                '2 con fallos',
            ],
            '{1} :count going quiet|[2,*] :count going quiet' => [
                '0 en silencio',
                '1 en silencio',
                '2 en silencio',
            ],
        ];

        foreach ($expected as $key => $lines) {
            foreach ([0, 1, 2] as $index => $count) {
                $this->assertSame(
                    $lines[$index],
                    trim(trans_choice($key, $count, ['count' => (string) $count])),
                    "health summary key failed at count {$count}: {$key}",
                );
            }
        }
    }

    public function test_the_health_site_summary_uses_the_static_keys(): void
    {
        $site = 'https://health.test';

        $states = [
            ['en', ['is_dead' => true]],
            ['es', ['consecutive_failures' => 2]],
            ['pt', ['consecutive_failures' => 0, 'consecutive_empty' => 20]],
        ];

        foreach ($states as $index => [$lang, $health]) {
            $source = Source::query()->create([
                'suwayomi_source_id' => 9000 + $index,
                'name' => 'Health '.$lang,
                'display_name' => 'Health '.$lang,
                'extension_pkg_name' => 'com.example.health'.$index,
                'lang' => $lang,
                'home_url' => $site,
                'content_warning' => Source::SAFE,
                'is_installed' => true,
            ]);

            SourceHealth::query()->create(array_merge([
                'source_id' => $source->suwayomi_source_id,
                'name' => $source->name,
                'lang' => $source->lang,
                'consecutive_failures' => 0,
                'consecutive_empty' => 0,
                'total_successes' => 0,
                'total_failures' => 0,
                'is_dead' => false,
            ], $health));
        }

        Livewire::actingAs($this->user);

        app()->setLocale('es');

        $this->assertSame(
            '3 variantes, 1 sin servicio, 1 con fallos, 1 en silencio',
            Livewire::test('pages::health')->instance()->siteSummary($site),
        );
    }
}
