<?php

namespace Tests\Feature;

use App\Exceptions\MissingOwnerException;
use App\Models\ChapterState;
use App\Models\SeriesState;
use App\Models\User;
use App\Support\Deployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S4 / AD-2: tenancy is structural, not a convention.
 *
 * The scope fails closed, so a missing auth context can never surface one
 * user's rows as another's. Every bypass must be explicit.
 */
class TenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_rows_are_scoped_to_the_authenticated_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $alicesSeries = SeriesState::factory()->create(['user_id' => $alice->id]);
        $bobsSeries = SeriesState::factory()->create(['user_id' => $bob->id]);

        $this->actingAs($alice);
        $this->assertSame(1, SeriesState::count());
        $this->assertTrue(SeriesState::whereKey($alicesSeries->id)->exists());

        $this->actingAs($bob);
        $this->assertSame(1, SeriesState::count());
        $this->assertTrue(SeriesState::whereKey($bobsSeries->id)->exists());
    }

    public function test_the_scope_fails_closed_without_an_authenticated_user(): void
    {
        $user = User::factory()->create();
        SeriesState::factory()->create(['user_id' => $user->id]);

        // No actingAs. The scope matches nothing rather than everything.
        $this->assertSame(0, SeriesState::count());
    }

    public function test_for_user_targets_a_user_without_changing_auth(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        SeriesState::factory()->create(['user_id' => $alice->id]);
        SeriesState::factory()->create(['user_id' => $bob->id]);

        $this->actingAs($alice);

        $this->assertSame(1, SeriesState::forUser($bob)->count());
    }

    public function test_user_id_is_filled_automatically_on_create(): void
    {
        $alice = User::factory()->create();

        $this->actingAs($alice);

        $series = SeriesState::create(['suwayomi_manga_id' => 4242]);

        $this->assertSame($alice->id, $series->user_id);
    }

    /**
     * user_id is not mass-assignable, so request input can never write a row on
     * someone else's behalf. createFor is the explicit way to do it anyway.
     */
    public function test_user_id_cannot_be_mass_assigned(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice);

        $series = SeriesState::create([
            'suwayomi_manga_id' => 1,
            'user_id' => $bob->id,
        ]);

        $this->assertSame($alice->id, $series->user_id);
    }

    public function test_create_for_sets_an_explicit_owner(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $series = SeriesState::createFor($bob, ['suwayomi_manga_id' => 99]);

        $this->assertSame($bob->id, $series->user_id);

        $this->actingAs($alice);
        $this->assertSame(0, SeriesState::count());

        $this->actingAs($bob);
        $this->assertSame(1, SeriesState::count());
    }

    public function test_creating_without_an_owner_fails_with_a_clear_error(): void
    {
        $this->expectException(MissingOwnerException::class);
        $this->expectExceptionMessageMatches('/createFor/');

        SeriesState::create(['suwayomi_manga_id' => 1]);
    }

    public function test_chapter_state_is_scoped_too(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $series = SeriesState::factory()->create(['user_id' => $alice->id]);

        ChapterState::createFor($alice, [
            'suwayomi_chapter_id' => 1,
            'suwayomi_manga_id' => $series->suwayomi_manga_id,
        ]);

        ChapterState::createFor($bob, [
            'suwayomi_chapter_id' => 1,
            'suwayomi_manga_id' => $series->suwayomi_manga_id,
        ]);

        $this->actingAs($alice);
        $this->assertSame(1, ChapterState::count());

        $this->actingAs($bob);
        $this->assertSame(1, ChapterState::count());
    }

    public function test_relations_respect_tenancy(): void
    {
        $alice = User::factory()->create();

        $series = SeriesState::factory()->create(['user_id' => $alice->id]);

        ChapterState::createFor($alice, [
            'suwayomi_chapter_id' => 10,
            'suwayomi_manga_id' => $series->suwayomi_manga_id,
        ]);

        $this->actingAs($alice);

        $this->assertCount(1, $series->chapters);
    }

    public function test_deployment_mode_is_configuration_not_schema(): void
    {
        config(['papyrus.deployment_mode' => Deployment::SINGLE]);

        $deployment = app(Deployment::class);

        $this->assertFalse($deployment->allowsRegistration());

        config(['papyrus.deployment_mode' => Deployment::SHARED]);

        $this->assertTrue($deployment->allowsRegistration());
    }
}
