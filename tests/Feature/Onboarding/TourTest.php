<?php

namespace Tests\Feature\Onboarding;

use App\Livewire\Onboarding\FirstRunTour;
use App\Models\ExtensionStore;
use App\Models\SeriesState;
use App\Models\User;
use App\Models\UserExtensionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TourTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => User::ROLE_INSTALL_OWNER]);
    }

    public function test_owner_on_an_empty_library_starts_at_the_first_step(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test(FirstRunTour::class, ['autoStart' => true, 'currentRoute' => 'library'])
            ->assertSet('visible', true)
            ->assertSet('step', 1)
            ->assertSee('Papyrus needs a source')
            ->assertSee('Step 1 of 9')
            ->assertSeeHtml('aria-modal="true"');

        $this->assertSame(1, $owner->fresh()->tour_step);
    }

    public function test_a_reader_does_not_start(): void
    {
        $reader = User::factory()->create();
        $this->actingAs($reader);

        Livewire::test(FirstRunTour::class, ['autoStart' => true, 'currentRoute' => 'library'])
            ->assertSet('visible', false)
            ->assertSet('step', 0);
    }

    public function test_an_existing_library_does_not_start(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        SeriesState::factory()->create([
            'user_id' => $owner->id,
            'in_library' => true,
        ]);

        Livewire::test(FirstRunTour::class, ['autoStart' => true, 'currentRoute' => 'library'])
            ->assertSet('visible', false)
            ->assertSet('step', 0);
    }

    public function test_a_completed_tour_does_not_start(): void
    {
        $owner = $this->owner();
        $owner->forceFill(['tour_completed_at' => now()])->save();
        $this->actingAs($owner);

        Livewire::test(FirstRunTour::class, ['autoStart' => true, 'currentRoute' => 'library'])
            ->assertSet('visible', false)
            ->assertSet('step', 0);
    }

    public function test_next_persists_the_step_and_moves_to_sources(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test(FirstRunTour::class, ['autoStart' => true, 'currentRoute' => 'library'])
            ->call('next')
            ->assertRedirect(route('sources'))
            ->assertSet('step', 2);

        $this->assertSame(2, $owner->fresh()->tour_step);
    }

    public function test_skip_records_completion(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test(FirstRunTour::class, ['autoStart' => true, 'currentRoute' => 'library'])
            ->call('skip')
            ->assertSet('visible', false)
            ->assertSet('step', 0)
            ->assertDispatched('tour-closed');

        $this->assertNotNull($owner->fresh()->tour_completed_at);
        $this->assertSame(0, $owner->fresh()->tour_step);
    }

    public function test_done_records_completion(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test(FirstRunTour::class, ['autoStart' => true, 'currentRoute' => 'series'])
            ->set('step', 9)
            ->set('visible', true)
            ->call('finish')
            ->assertSet('visible', false)
            ->assertDispatched('tour-closed');

        $this->assertNotNull($owner->fresh()->tour_completed_at);
    }

    public function test_a_store_that_is_not_trusted_continues_at_the_trust_step(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        ExtensionStore::query()->create([
            'index_url' => 'https://example.test/index.pb',
            'name' => 'Test Store',
        ]);

        Livewire::test(FirstRunTour::class, ['autoStart' => true, 'currentRoute' => 'library'])
            ->assertRedirect(route('sources'));

        $this->assertSame(4, $owner->fresh()->tour_step);
    }

    public function test_a_trusted_store_with_no_sources_continues_at_the_install_step(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        $store = ExtensionStore::query()->create([
            'index_url' => 'https://example.test/index.pb',
            'name' => 'Test Store',
        ]);

        UserExtensionStore::query()->create([
            'extension_store_id' => $store->id,
            'trusted_at' => now(),
        ]);

        Livewire::test(FirstRunTour::class, ['autoStart' => true, 'currentRoute' => 'library'])
            ->assertRedirect(route('sources'));

        $this->assertSame(5, $owner->fresh()->tour_step);
    }

    public function test_the_store_added_event_advances_only_on_its_step(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        $tour = Livewire::test(FirstRunTour::class, ['autoStart' => false, 'currentRoute' => 'sources'])
            ->set('step', 1)
            ->set('visible', true)
            ->call('onStoreAdded')
            ->assertSet('step', 1);

        $tour->set('step', 3)
            ->call('onStoreAdded')
            ->assertSet('step', 4);
    }

    public function test_the_library_added_event_advances_to_the_read_step(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test(FirstRunTour::class, ['autoStart' => false, 'currentRoute' => 'series'])
            ->set('step', 8)
            ->set('visible', true)
            ->call('onLibraryAdded')
            ->assertSet('step', 9);
    }

    public function test_back_from_the_series_returns_to_the_search_step(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test(FirstRunTour::class, ['autoStart' => false, 'currentRoute' => 'series'])
            ->set('step', 8)
            ->set('visible', true)
            ->call('back')
            ->assertRedirect(route('search'))
            ->assertSet('step', 6);
    }

    public function test_replay_starts_at_the_first_step_when_the_library_is_empty(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test('pages::settings.faq')
            ->call('replayTour')
            ->assertRedirect(route('library'));

        $this->assertSame(1, $owner->fresh()->tour_step);
        $this->assertNull($owner->fresh()->tour_completed_at);
    }

    public function test_replay_for_an_existing_library_starts_at_sources(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        SeriesState::factory()->create([
            'user_id' => $owner->id,
            'in_library' => true,
        ]);

        Livewire::test('pages::settings.faq')
            ->call('replayTour')
            ->assertRedirect(route('sources'));

        $this->assertSame(2, $owner->fresh()->tour_step);
    }

    public function test_the_replay_button_is_owner_only(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)
            ->get(route('settings.faq'))
            ->assertOk()
            ->assertSee('Replay the guided tour');

        $reader = User::factory()->create();

        $this->actingAs($reader)
            ->get(route('settings.faq'))
            ->assertOk()
            ->assertDontSee('Replay the guided tour');
    }
}
