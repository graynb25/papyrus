<?php

namespace Tests\Feature\Localization;

use App\Livewire\Onboarding\FirstRunTour;
use App\Models\User;
use App\Onboarding\TourSteps;
use App\Support\OnboardingCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SpanishTourTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_nine_step_contract_survives_in_spanish(): void
    {
        app()->setLocale('es');

        $steps = TourSteps::forOwner();

        $this->assertCount(9, $steps);
        $this->assertSame(
            ['source-requirement', 'open-sources', 'add-store', 'trust-store', 'install-extension', 'search', 'open-result', 'add-to-library', 'read'],
            array_column($steps, 'key'),
        );
    }

    public function test_every_tour_title_and_body_is_spanish(): void
    {
        app()->setLocale('es');

        $steps = TourSteps::forOwner();

        $this->assertSame('Papyrus necesita una fuente', $steps[0]['title']);
        $this->assertSame(__(OnboardingCopy::SOURCE_REQUIREMENT), $steps[0]['body']);
        $this->assertStringContainsString('Papyrus lee de fuentes', $steps[0]['body']);

        $this->assertSame('Añade una tienda de confianza', $steps[2]['title']);
        $this->assertStringContainsString('index.pb', $steps[2]['body']);
        $this->assertStringContainsString('index.min.json', $steps[2]['body']);
        $this->assertStringContainsString('no incluye ni recomienda', $steps[2]['body']);

        $this->assertSame('Lee un capítulo', $steps[8]['title']);
        $this->assertStringContainsString('El lector guarda tu posición', $steps[8]['body']);
    }

    public function test_the_first_run_tour_renders_the_spanish_first_step(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INSTALL_OWNER]);
        $owner->refresh();

        $this->actingAs($owner);
        app()->setLocale('es');

        Livewire::test(FirstRunTour::class, ['autoStart' => true, 'currentRoute' => 'library'])
            ->assertSet('visible', true)
            ->assertSet('step', 1)
            ->assertSee('Papyrus necesita una fuente')
            ->assertSee('Paso 1 de 9')
            ->assertSee('Primeros pasos');
    }
}
