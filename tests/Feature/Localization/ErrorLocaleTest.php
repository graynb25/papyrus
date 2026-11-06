<?php

namespace Tests\Feature\Localization;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ErrorLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unmatched_url_renders_the_spanish_404_page(): void
    {
        $this->withSession(['locale' => 'es'])
            ->get('/a-page-that-does-not-exist')
            ->assertNotFound()
            ->assertSee('lang="es"', false)
            ->assertSee('Página no encontrada')
            ->assertSee('Esta estantería no existe.');
    }

    public function test_a_404_raised_by_a_route_renders_the_spanish_page(): void
    {
        $request = $this->request('/library', ['locale' => 'es']);

        $response = app(ExceptionHandler::class)->render($request, new NotFoundHttpException);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('lang="es"', (string) $response->getContent());
        $this->assertStringContainsString('Página no encontrada', (string) $response->getContent());
    }

    public function test_a_404_json_response_is_left_to_laravel(): void
    {
        $response = $this->withSession(['locale' => 'es'])
            ->getJson('/a-page-that-does-not-exist');

        $response->assertNotFound();
        $response->assertHeader('content-type', 'application/json');
    }

    public function test_a_server_error_renders_the_spanish_500_page(): void
    {
        config(['app.debug' => false]);

        $request = $this->request('/library', ['locale' => 'es']);

        $response = app(ExceptionHandler::class)->render($request, new RuntimeException('boom'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('lang="es"', (string) $response->getContent());
        $this->assertStringContainsString('Algo se rompió', (string) $response->getContent());
        $this->assertStringContainsString('Una página se desprendió de su encuadernación.', (string) $response->getContent());
    }

    public function test_a_500_json_response_is_left_to_laravel(): void
    {
        config(['app.debug' => false]);

        $request = $this->request('/library', ['locale' => 'es'], 'application/json');

        $response = app(ExceptionHandler::class)->render($request, new RuntimeException('boom'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    /**
     * @param  array<string, string>  $session
     */
    private function request(string $uri, array $session, string $accept = 'text/html'): Request
    {
        $request = Request::create($uri, 'GET');
        $request->headers->set('Accept', $accept);
        $request->setLaravelSession(app('session')->driver());

        foreach ($session as $key => $value) {
            $request->session()->put($key, $value);
        }

        $this->app->instance('request', $request);

        return $request;
    }
}
