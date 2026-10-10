<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `/ready`, the endpoint a packaged launcher polls before it starts the other three processes.
 *
 * ## What this has to get right
 *
 * The launcher asks three questions in sequence: is PHP serving, can I be used, and are my
 * migrations done. Getting an answer wrong in any direction is expensive in a way a normal
 * feature test does not exercise.
 *
 * **False ready is the dangerous one.** Report ready on an install with no app key and the
 * launcher starts the queue worker against it, and every job fails with a session decode error
 * that reads like a corrupt database.
 *
 * **False not-ready is the tedious one.** A probe that always says 503 teaches the launcher to
 * ignore it, and then it is worse than no probe.
 *
 * ## The two traps in it
 *
 * 1. **It must not run in the web middleware group.** `SESSION_DRIVER=database` means one
 *    session row per request. A once-a-second poll would write forever. The test asserts the
 *    route carries no middleware rather than trusting the comment.
 * 2. **It must answer on `REMOTE_ADDR`, not `$request->ip()`.** The second honours
 *    `X-Forwarded-For` for trusted proxies, so a remote client behind a misconfigured proxy
 *    could present itself as local and learn whether the app key is set.
 */
class ReadinessControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_healthy_install_reports_ready(): void
    {
        $response = $this->getJson('/ready');

        $response->assertOk();
        $response->assertJsonPath('status', 'ready');
        $response->assertJsonPath('checks.key', true);
        $response->assertJsonPath('checks.database', true);
        $response->assertJsonPath('checks.pending_migrations', 0);
    }

    /**
     * A key that is present but empty is the state a cloned `.env.example` leaves behind.
     *
     * Checking that the config key exists is not enough, and the difference between the two
     * checks is the whole test.
     */
    public function test_an_empty_app_key_is_not_ready(): void
    {
        Config::set('app.key', '');

        $response = $this->getJson('/ready');

        $response->assertStatus(503);
        $response->assertJsonPath('status', 'not ready');
        $response->assertJsonPath('checks.key', false);
    }

    /**
     * The base64 prefix with nothing after it is what an unset key looks like after casting.
     */
    public function test_a_bare_base64_prefix_is_not_a_key(): void
    {
        Config::set('app.key', 'base64:');

        $this->getJson('/ready')->assertStatus(503)->assertJsonPath('checks.key', false);
    }

    /**
     * A missing key and a working database are reported separately.
     *
     * A single boolean would leave a launcher guessing which half to fix, and the two failures
     * have completely different remedies.
     */
    public function test_each_check_is_reported_independently(): void
    {
        Config::set('app.key', '');

        $response = $this->getJson('/ready');

        $this->assertFalse($response->json('checks.key'));
        $this->assertTrue(
            $response->json('checks.database'),
            'one broken check must not make the others look broken too',
        );
    }

    /**
     * A launcher polls this to decide whether to run migrations, so the count has to be real.
     *
     * A missing `migrations` table is a fresh install and every file is pending. Reporting 0
     * there would have the launcher start the app against a schema that does not exist.
     */
    public function test_pending_migrations_are_counted(): void
    {
        DB::table('migrations')->delete();

        $response = $this->getJson('/ready');

        $response->assertStatus(503);
        $this->assertGreaterThan(
            0,
            $response->json('checks.pending_migrations'),
            'a cleared migrations table means everything is pending',
        );
    }

    public function test_one_unrun_migration_is_enough_to_be_not_ready(): void
    {
        DB::table('migrations')->orderByDesc('id')->limit(1)->delete();

        $this->getJson('/ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.pending_migrations', 1);
    }

    /**
     * The polling trap, asserted rather than assumed.
     *
     * If this ever moves into the web group this test fails, which is the point. A once-a-second
     * poll writing a session row every second is invisible in a test run and permanent on a
     * machine left open overnight.
     */
    public function test_it_runs_outside_the_web_middleware_group(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route): bool => $route->getName() === 'ready');

        $this->assertNotNull($route, 'the ready route should be registered');

        $this->assertSame(
            [],
            $route->gatherMiddleware(),
            'readiness must carry no middleware, or every poll writes a session row',
        );
    }

    /**
     * A probe poll must not create a session, which is what the group above would do.
     *
     * Asserted on the database rather than on the middleware list, so it holds even if someone
     * later adds middleware back that does not appear in `gatherMiddleware()`.
     */
    public function test_polling_it_creates_no_session_row(): void
    {
        $before = DB::table('sessions')->count();

        $this->getJson('/ready')->assertOk();
        $this->getJson('/ready')->assertOk();

        $this->assertSame(
            $before,
            DB::table('sessions')->count(),
            'a readiness poll must not write to the database',
        );
    }

    /**
     * `REMOTE_ADDR` and not the proxy-aware address.
     *
     * With a trusted proxy configured, `$request->ip()` returns whatever `X-Forwarded-For`
     * says, which a remote client controls. That would hand out the app key and migration state
     * to the internet on a public deployment.
     */
    public function test_a_forwarded_loopback_header_does_not_grant_access(): void
    {
        $this->getJson('/ready', [
            'X-Forwarded-For' => '127.0.0.1',
            'REMOTE_ADDR' => '203.0.113.9',
        ])->assertStatus(404);
    }

    /**
     * A remote caller gets 404 rather than 403, so the probe does not confirm it exists.
     */
    public function test_a_remote_caller_is_told_nothing_exists(): void
    {
        $response = $this->getJson('/ready', ['REMOTE_ADDR' => '203.0.113.9']);

        $response->assertStatus(404);
        $response->assertJsonMissingPath('checks');
    }

    /**
     * Both loopback families, because a Windows host can be listening on either.
     */
    public function test_both_loopback_families_are_accepted(): void
    {
        $this->getJson('/ready', ['REMOTE_ADDR' => '::1'])->assertOk();
        $this->getJson('/ready', ['REMOTE_ADDR' => '127.0.0.1'])->assertOk();
        $this->getJson('/ready', ['REMOTE_ADDR' => '127.0.0.53'])->assertOk();
    }

    /**
     * Laravel's own `/up` stays, and stays shallow.
     *
     * Two endpoints answering the same question differently is only a problem if they claim the
     * same purpose. This pins that `/up` still answers for PHP being alive, so a launcher can
     * use `/up` for liveness and `/ready` for readiness without one shadowing the other.
     */
    public function test_laravels_own_up_route_is_untouched(): void
    {
        $this->get('/up')->assertOk();
    }
}
