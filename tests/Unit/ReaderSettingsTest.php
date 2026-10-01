<?php

namespace Tests\Unit;

use App\Models\ReaderProfile;
use App\Models\SeriesState;
use App\Models\User;
use App\Support\ReaderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reader settings resolve in layers, and the interesting cases are all "except
 * this one". So the tests are about precedence and about values that arrive from
 * somewhere untrusted: a profile edited by hand, a series column left as JSON
 * null, a zoom value from an older version.
 */
class ReaderSettingsTest extends TestCase
{
    use RefreshDatabase;

    private ReaderSettings $settings;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = new ReaderSettings;
        $this->user = User::factory()->create();
    }

    public function test_defaults_are_vertical_and_fit_width(): void
    {
        $resolved = $this->settings->resolve($this->user, 76);

        $this->assertSame(ReaderSettings::MODE_VERTICAL, $resolved['mode']);
        $this->assertSame(100, $resolved['width']);
        $this->assertSame(1.0, $resolved['zoom']);
        $this->assertFalse($resolved['long_strip']);
    }

    public function test_a_user_with_no_state_gets_the_defaults(): void
    {
        // Resolution must not create rows as a side effect.
        $this->settings->resolve($this->user, 76);

        $this->assertDatabaseCount('series_states', 0);
        $this->assertDatabaseCount('reader_profiles', 0);
    }

    // Precedence ----------------------------------------------------------

    public function test_a_default_profile_overrides_the_defaults(): void
    {
        ReaderProfile::createFor($this->user->id, [
            'name' => 'Night',
            'is_default' => true,
            'settings' => ['background' => '#000000', 'zoom' => 1.5],
        ]);

        $resolved = $this->settings->resolve($this->user, 76);

        $this->assertSame('#000000', $resolved['background']);
        $this->assertSame(1.5, $resolved['zoom']);
        $this->assertSame(ReaderSettings::MODE_VERTICAL, $resolved['mode'], 'Untouched keys must survive.');
    }

    public function test_a_non_default_profile_is_ignored(): void
    {
        ReaderProfile::createFor($this->user->id, [
            'name' => 'Other',
            'is_default' => false,
            'settings' => ['zoom' => 2.5],
        ]);

        $this->assertSame(1.0, $this->settings->resolve($this->user, 76)['zoom']);
    }

    public function test_a_series_override_beats_the_profile(): void
    {
        ReaderProfile::createFor($this->user->id, [
            'name' => 'Night',
            'is_default' => true,
            'settings' => ['background' => '#000000'],
        ]);

        SeriesState::createFor($this->user->id, [
            'suwayomi_manga_id' => 76,
            'reader_settings' => ['background' => '#ffffff'],
        ]);

        $this->assertSame('#ffffff', $this->settings->resolve($this->user, 76)['background']);
    }

    public function test_the_reading_mode_column_overrides_the_settings_json(): void
    {
        // The series page changes the mode through this column, so it has to win
        // over whatever the JSON happens to say.
        SeriesState::createFor($this->user->id, [
            'suwayomi_manga_id' => 76,
            'reader_settings' => ['mode' => ReaderSettings::MODE_VERTICAL],
            'reading_mode' => ReaderSettings::MODE_PAGED,
        ]);

        $this->assertSame(ReaderSettings::MODE_PAGED, $this->settings->resolve($this->user, 76)['mode']);
    }

    /**
     * "No override" must fall through, not erase. A JSON null in a higher layer
     * that wiped a lower layer would silently reset a reader's night mode.
     */
    public function test_a_null_in_a_higher_layer_does_not_erase_a_lower_one(): void
    {
        ReaderProfile::createFor($this->user->id, [
            'name' => 'Night',
            'is_default' => true,
            'settings' => ['background' => '#000000'],
        ]);

        SeriesState::createFor($this->user->id, [
            'suwayomi_manga_id' => 76,
            'reader_settings' => ['background' => null],
        ]);

        $this->assertSame('#000000', $this->settings->resolve($this->user, 76)['background']);
    }

    public function test_clearing_an_override_falls_back_to_the_profile(): void
    {
        ReaderProfile::createFor($this->user->id, [
            'name' => 'Night',
            'is_default' => true,
            'settings' => ['background' => '#000000'],
        ]);

        $this->settings->setSeriesOverride($this->user, 76, ['background' => '#ffffff']);
        $this->assertSame('#ffffff', $this->settings->resolve($this->user, 76)['background']);

        $this->settings->clearSeriesOverride($this->user, 76);

        $this->assertSame('#000000', $this->settings->resolve($this->user, 76)['background']);
    }

    public function test_settings_are_per_user(): void
    {
        $bob = User::factory()->create();

        $this->settings->setSeriesOverride($this->user, 76, ['zoom' => 2.0]);

        $this->assertSame(2.0, $this->settings->resolve($this->user, 76)['zoom']);
        $this->assertSame(1.0, $this->settings->resolve($bob, 76)['zoom']);
    }

    // Clamping ------------------------------------------------------------

    public function test_an_unknown_mode_falls_back_to_vertical(): void
    {
        $resolved = $this->settings->clamp(['mode' => 'sideways']);

        $this->assertSame(ReaderSettings::MODE_VERTICAL, $resolved['mode']);
    }

    public function test_width_is_clamped_to_the_usable_range(): void
    {
        $this->assertSame(45, $this->settings->clamp(['width' => 5])['width']);
        $this->assertSame(100, $this->settings->clamp(['width' => 500])['width']);
        $this->assertSame(45, $this->settings->clamp(['width' => 'wide'])['width']);
    }

    public function test_zoom_is_clamped(): void
    {
        $this->assertSame(0.5, $this->settings->clamp(['zoom' => 0.01])['zoom']);
        $this->assertSame(3.0, $this->settings->clamp(['zoom' => 99])['zoom']);
    }

    public function test_gap_is_clamped(): void
    {
        $this->assertSame(0, $this->settings->clamp(['gap' => -10])['gap']);
        $this->assertSame(64, $this->settings->clamp(['gap' => 5000])['gap']);
    }

    /**
     * The background ends up in a style attribute, so an unrecognised value is
     * replaced rather than interpolated into CSS.
     */
    public function test_a_colour_that_is_not_a_colour_is_replaced(): void
    {
        $defaults = $this->settings->defaults();

        foreach ([
            'javascript:alert(1)',
            'url(http://evil.test/x)',
            '</style><script>',
            'expression(alert(1))',
            '#gg0000',
            '',
            null,
        ] as $hostile) {
            $this->assertSame(
                $defaults['background'],
                $this->settings->clamp(['background' => $hostile])['background'],
                'Accepted: '.var_export($hostile, true),
            );
        }
    }

    public function test_real_colour_forms_are_kept(): void
    {
        foreach (['#000', '#ffffff', '#0a0a0a', 'rgb(10, 10, 10)', 'rgba(0,0,0,0.5)', 'black', 'white'] as $colour) {
            $this->assertSame(
                $colour === 'white' ? 'white' : $colour,
                $this->settings->clamp(['background' => $colour])['background'],
            );
        }
    }

    public function test_booleans_are_coerced(): void
    {
        $clamped = $this->settings->clamp([
            'long_strip' => 1,
            'fit_height' => 'yes',
            'hide_tap_zones' => 0,
        ]);

        $this->assertTrue($clamped['long_strip']);
        $this->assertTrue($clamped['fit_height']);
        $this->assertFalse($clamped['hide_tap_zones']);
    }

    // Writing overrides ----------------------------------------------------

    public function test_writing_an_override_merges_rather_than_replaces(): void
    {
        $this->settings->setSeriesOverride($this->user, 76, ['zoom' => 2.0]);
        $this->settings->setSeriesOverride($this->user, 76, ['background' => '#111111']);

        $resolved = $this->settings->resolve($this->user, 76);

        $this->assertSame(2.0, $resolved['zoom'], 'The earlier override must survive.');
        $this->assertSame('#111111', $resolved['background']);
    }

    public function test_writing_an_override_works_with_no_existing_row(): void
    {
        // The reader page can be opened from a deep link before any series state
        // exists, so this must not depend on one.
        $this->settings->setSeriesOverride($this->user, 999, ['zoom' => 1.5]);

        $this->assertDatabaseHas('series_states', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => 999,
        ]);
    }

    public function test_clearing_with_no_existing_row_is_not_an_error(): void
    {
        $this->settings->clearSeriesOverride($this->user, 999);

        $this->assertDatabaseCount('series_states', 0);
    }

    public function test_an_override_is_still_clamped_on_read(): void
    {
        // A value written before the ranges existed, or edited in a profile by
        // hand, must still resolve to something usable.
        ReaderProfile::createFor($this->user->id, [
            'name' => 'Hand edited',
            'is_default' => true,
            'settings' => ['zoom' => 1000, 'width' => -5],
        ]);

        $resolved = $this->settings->resolve($this->user, 76);

        $this->assertSame(3.0, $resolved['zoom']);
        $this->assertSame(45, $resolved['width']);
    }
}
