<?php

namespace Tests\Feature\Onboarding;

use App\Onboarding\TourSteps;
use App\Support\OnboardingCopy;
use Tests\TestCase;

class TourCopyTest extends TestCase
{
    public function test_the_tour_has_nine_complete_steps(): void
    {
        $steps = TourSteps::forOwner();

        $this->assertCount(9, $steps);
        $this->assertSame(9, TourSteps::count());
        $this->assertSame(
            ['source-requirement', 'open-sources', 'add-store', 'trust-store', 'install-extension', 'search', 'open-result', 'add-to-library', 'read'],
            array_column($steps, 'key'),
        );
    }

    public function test_every_step_has_a_route_selector_and_valid_mode(): void
    {
        $routes = ['library', 'sources', 'search', 'series'];
        $selectors = [];

        foreach (TourSteps::forOwner() as $step) {
            $this->assertContains($step['route'], $routes);
            $this->assertStringStartsWith('[data-tour="', $step['selector']);
            $this->assertContains($step['mode'], [TourSteps::MODE_MODAL, TourSteps::MODE_INTERACTIVE]);
            $this->assertIsBool($step['next']);

            if ($step['advance'] !== null) {
                $this->assertStringStartsWith('tour-', $step['advance']);
            }

            $selectors[] = $step['selector'];
        }

        $this->assertSame($selectors, array_unique($selectors));
    }

    public function test_the_copy_carries_no_url(): void
    {
        foreach (TourSteps::forOwner() as $step) {
            $copy = $step['title'].' '.$step['body'];

            $this->assertStringNotContainsString('http', $copy);
            $this->assertStringNotContainsString('://', $copy);
        }
    }

    public function test_the_first_step_uses_the_library_sentence(): void
    {
        $first = TourSteps::at(1);

        $this->assertNotNull($first);
        $this->assertSame(__(OnboardingCopy::SOURCE_REQUIREMENT), $first['body']);
    }

    public function test_the_add_store_step_names_the_index_file_not_a_store(): void
    {
        $step = TourSteps::at(3);

        $this->assertNotNull($step);
        $this->assertStringContainsString('index.pb', $step['body']);
        $this->assertStringContainsString('index.min.json', $step['body']);
        $this->assertStringContainsString('does not ship or recommend', $step['body']);
    }

    public function test_at_returns_null_outside_the_range(): void
    {
        $this->assertNull(TourSteps::at(0));
        $this->assertNull(TourSteps::at(10));
    }
}
