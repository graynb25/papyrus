<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\User;
use App\Models\UserSource;
use App\Search\SearchFanout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A reader's pins are their search scope.
 *
 * `user_sources.pinned_at` has been in the schema since the preferences table was created and
 * it had no writer and no reader: the column was reserved for exactly this and nothing was
 * wired to it. Phase 4 is the wiring. The rule lands inside {@see SearchFanout::targets()},
 * the one seam the page's status count and the fanout already share, so the count a reader
 * sees and the run a reader waits through cannot disagree.
 *
 * ## Why the rule is not inside `runnableFor()`
 *
 * The `runnableFor()` scope is shared deliberately, its own comment says why: the migration
 * sweep wants every runnable source it can ask. Pins are a search choice. A pin filter under
 * the shared name would quietly narrow a whole shelf migration to a reader's shortlist, so
 * the discovery scope keeps meaning what it meant and the pin narrows only the search.
 */
class SourcePinsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_pins_narrow_the_run_to_the_pinned_sources(): void
    {
        [, $beta] = $this->sources('Alpha', 'Beta', 'Gamma');

        UserSource::setPinnedFor($this->user, [$beta->suwayomi_source_id], true);

        $this->assertSame(
            [$beta->suwayomi_source_id],
            $this->targetIds(),
            'A run behind a pin must ask the pinned sources and nothing else.',
        );
    }

    public function test_a_reader_with_no_pins_keeps_todays_run_shape(): void
    {
        [$alpha, $beta, $gamma] = $this->sources('Alpha', 'Beta', 'Gamma');

        $this->assertSame(
            [
                $alpha->suwayomi_source_id,
                $beta->suwayomi_source_id,
                $gamma->suwayomi_source_id,
            ],
            $this->targetIds(),
            'No pin rows anywhere must mean the query is what it has always been.',
        );
    }

    public function test_pins_are_per_reader(): void
    {
        [$alpha, $beta, $gamma] = $this->sources('Alpha', 'Beta', 'Gamma');
        $bob = User::factory()->create();

        UserSource::setPinnedFor($bob, [$beta->suwayomi_source_id], true);

        $this->assertSame(
            [
                $alpha->suwayomi_source_id,
                $beta->suwayomi_source_id,
                $gamma->suwayomi_source_id,
            ],
            $this->targetIds(),
            'Another reader\'s pins must not narrow this reader\'s search.',
        );

        $this->assertSame(
            [$beta->suwayomi_source_id],
            app(SearchFanout::class)->targets($bob, ['limit' => -1])->pluck('suwayomi_source_id')->all(),
            'The reader who pinned must be narrowed, not the reader beside them.',
        );
    }

    public function test_a_pin_cannot_resurrect_a_source_the_reader_switched_off(): void
    {
        [$alpha] = $this->sources('Alpha', 'Beta', 'Gamma');

        UserSource::setDisabledFor($this->user, [$alpha->suwayomi_source_id], true);
        UserSource::setPinnedFor($this->user, [$alpha->suwayomi_source_id], true);

        $this->assertSame(
            [],
            $this->targetIds(),
            'Off beats pinned: a disabled source stays out even when the reader pins it.',
        );
    }

    public function test_pinning_writes_the_row_and_unpinning_clears_the_value_only(): void
    {
        [$alpha] = $this->sources('Alpha');

        UserSource::setPinnedFor($this->user, [$alpha->suwayomi_source_id], true);

        $row = UserSource::forUser($this->user->id)->firstOrFail();
        $this->assertNotNull($row->pinned_at, 'Pinning must stamp the time, the column exists for it.');

        UserSource::setPinnedFor($this->user, [$alpha->suwayomi_source_id], false);
        $row->refresh();
        $this->assertNull($row->pinned_at, 'Unpinning clears the pin and leaves the other columns alone.');

        UserSource::setPinnedFor($this->user, [$alpha->suwayomi_source_id], false);
        $this->assertSame(
            1,
            UserSource::forUser($this->user->id)->count(),
            'Unpinning a source with no pin row must not create one.',
        );
    }

    public function test_a_pin_clear_names_only_its_own_reader(): void
    {
        [$beta] = $this->sources('Alpha', 'Beta');
        $bob = User::factory()->create();

        UserSource::setPinnedFor($bob, [$beta->suwayomi_source_id], true);

        UserSource::setPinnedFor($this->user, [$beta->suwayomi_source_id], false);

        $this->assertSame(
            1,
            UserSource::forUser($bob->id)->whereNotNull('pinned_at')->count(),
            'One reader\'s unpin must not reach another reader\'s row.',
        );
    }

    public function test_the_pin_read_names_only_its_reader(): void
    {
        [, $beta, $gamma] = $this->sources('Alpha', 'Beta', 'Gamma');
        $bob = User::factory()->create();

        UserSource::setPinnedFor($this->user, [$beta->suwayomi_source_id], true);
        UserSource::setPinnedFor($bob, [$gamma->suwayomi_source_id], true);

        $this->assertSame([$beta->suwayomi_source_id], UserSource::pinnedSourceIdsFor($this->user));
        $this->assertSame([$gamma->suwayomi_source_id], UserSource::pinnedSourceIdsFor($bob));
    }

    public function test_scope_for_says_what_runs_and_what_waits(): void
    {
        [$alpha, $beta, $gamma] = $this->sources('Alpha', 'Beta', 'Gamma');

        UserSource::setPinnedFor($this->user, [$beta->suwayomi_source_id], true);

        $scope = app(SearchFanout::class)->scopeFor($this->user, ['limit' => -1]);

        $this->assertSame([$beta->suwayomi_source_id], $scope['searched']->pluck('suwayomi_source_id')->all());
        $this->assertSame(
            [$alpha->suwayomi_source_id, $gamma->suwayomi_source_id],
            $scope['skipped']->pluck('suwayomi_source_id')->all(),
            'The skipped list must name the runnable ones the pins left out, not the dead ones.',
        );
    }

    public function test_scope_for_names_nothing_when_nothing_is_pinned(): void
    {
        $this->sources('Alpha', 'Beta', 'Gamma');

        $scope = app(SearchFanout::class)->scopeFor($this->user, ['limit' => -1]);

        $this->assertSame(3, $scope['searched']->count());
        $this->assertCount(0, $scope['skipped'], 'A reader without pins has nobody to be named.');
    }

    /**
     * The reader's sources, distinct sites with real-shaped ids.
     *
     * @return array<int, Source>
     */
    private function sources(string ...$names): array
    {
        $made = [];

        foreach (array_values($names) as $i => $name) {
            $made[] = Source::create([
                'suwayomi_source_id' => 8513894820944573933 + $i,
                'name' => $name,
                'display_name' => $name,
                'extension_pkg_name' => 'eu.kanade.tachiyomi.extension.en.pkg'.$i,
                'lang' => 'en',
                'content_warning' => Source::SAFE,

                // One site per name, so the pin helpers have something to group.
                'home_url' => 'https://pkg'.$i.'.test',
                'is_installed' => true,
                'supports_latest' => true,
            ]);
        }

        return $made;
    }

    /**
     * The whole run, in the order the fanout answers it.
     *
     * `limit: -1` is the documented unlimited reading of the cap, so the order that comes
     * back is the eligible set's own name order.
     *
     * @return array<int, int>
     */
    private function targetIds(): array
    {
        return app(SearchFanout::class)->targets($this->user, ['limit' => -1])
            ->pluck('suwayomi_source_id')
            ->all();
    }
}
