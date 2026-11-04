<?php

namespace App\Livewire\Onboarding;

use App\Models\ExtensionStore;
use App\Models\SeriesState;
use App\Models\Source;
use App\Models\User;
use App\Models\UserExtensionStore;
use App\Onboarding\TourSteps;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The first-run tour's server state.
 *
 * ## Why the step lives in the database
 *
 * A tour that resets when the reader closes the browser is a tour they have to
 * finish in one sitting. `tour_step` is the resume point and
 * `tour_completed_at` covers Done and Skip, because both mean "do not open this
 * on its own again." Replay clears both.
 *
 * ## Why the component does not auto-start everywhere
 *
 * The library is the page that says Papyrus ships with no sources. Starting the
 * tour in Settings, or on every page after a redirect, would interrupt a reader
 * who is already doing something. The layout passes `autoStart` only for the
 * library route. A saved step still resumes when the reader returns to the
 * page that step belongs to.
 *
 * ## Why only the install owner
 *
 * Adding a store, trusting its key and installing an extension are all behind
 * `manage-install`. A reader account cannot do them, so a tour that pointed at
 * those controls would be a tour of buttons that are not there. Reader
 * onboarding is a separate, shorter problem.
 *
 * @phpstan-import-type Step from \App\Onboarding\TourSteps
 */
class FirstRunTour extends Component
{
    /** The 1-based step, or zero when the tour is not running. */
    public int $step = 0;

    /** Whether the card and spotlight should render on this page. */
    public bool $visible = false;

    /** The current route name, passed by the layout. */
    public ?string $routeName = null;

    public function mount(bool $autoStart = false, ?string $currentRoute = null): void
    {
        $this->routeName = $currentRoute;

        $user = auth()->user();

        if (! $user instanceof User || ! $user->isInstallOwner()) {
            return;
        }

        if ($user->tour_completed_at !== null) {
            return;
        }

        if ($user->tour_step > 0) {
            $this->step = (int) $user->tour_step;

            /*
             * The result card is about to leave the search page for a series.
             * The step after it lives on that series page, so a saved step 7
             * that arrives on a series continues at 8 rather than pointing at a
             * result that is no longer on screen.
             */
            if ($this->step === 7 && $this->routeName === 'series') {
                $this->step = 8;
                $this->persist();
            }

            $this->visible = $this->matchesRoute();

            return;
        }

        if (! $autoStart) {
            return;
        }

        /*
         * An empty library is the only state that needs the full walk. A reader
         * who already has data is not a first-run reader, even if this account
         * has never seen the tour.
         */
        if (SeriesState::query()->where('in_library', true)->exists()) {
            return;
        }

        if (! ExtensionStore::query()->exists()) {
            $this->step = 1;
            $this->visible = true;
            $this->persist();

            return;
        }

        /*
         * A store may already be registered but untrusted, or trusted with no
         * extension installed. Start where the reader actually is instead of
         * asking them to add a store they already have.
         */
        if (! UserExtensionStore::query()->whereNotNull('trusted_at')->exists()) {
            $this->step = 4;
            $this->persist();
            $this->redirectRoute('sources', navigate: true);

            return;
        }

        if (! Source::query()->exists()) {
            $this->step = 5;
            $this->persist();
            $this->redirectRoute('sources', navigate: true);

            return;
        }

        $this->step = 6;
        $this->persist();
        $this->redirectRoute('search', navigate: true);
    }

    /**
     * @return Step|null
     */
    public function currentStep(): ?array
    {
        return TourSteps::at($this->step);
    }

    public function next(): void
    {
        if (! $this->visible) {
            return;
        }

        $next = $this->step + 1;

        if ($next > TourSteps::count()) {
            $this->finish();

            return;
        }

        $this->step = $next;
        $this->persist();
        $this->goToStepRoute();
    }

    public function back(): void
    {
        if (! $this->visible || $this->step <= 1) {
            return;
        }

        /*
         * Once the reader is on the series, the search results are gone. Back
         * returns to the search box rather than to a result card that no longer
         * exists. The same target then works for steps 7, 8 and 9.
         */
        $this->step = $this->step >= 7 ? 6 : $this->step - 1;
        $this->persist();
        $this->goToStepRoute();
    }

    public function skip(): void
    {
        $this->complete();
    }

    public function finish(): void
    {
        $this->complete();
    }

    #[On(TourSteps::STORE_ADDED)]
    public function onStoreAdded(): void
    {
        $this->advanceFrom(TourSteps::STORE_ADDED);
    }

    #[On(TourSteps::STORE_TRUSTED)]
    public function onStoreTrusted(): void
    {
        $this->advanceFrom(TourSteps::STORE_TRUSTED);
    }

    #[On(TourSteps::EXTENSION_INSTALLED)]
    public function onExtensionInstalled(): void
    {
        $this->advanceFrom(TourSteps::EXTENSION_INSTALLED);
    }

    #[On(TourSteps::SEARCH_COMPLETED)]
    public function onSearchCompleted(): void
    {
        $this->advanceFrom(TourSteps::SEARCH_COMPLETED);
    }

    #[On(TourSteps::LIBRARY_ADDED)]
    public function onLibraryAdded(): void
    {
        $this->advanceFrom(TourSteps::LIBRARY_ADDED);
    }

    public function render(): View
    {
        return view('livewire.onboarding.first-run-tour', [
            'current' => $this->currentStep(),
            'total' => TourSteps::count(),
        ]);
    }

    private function advanceFrom(string $event): void
    {
        if (! $this->visible) {
            return;
        }

        $step = $this->currentStep();

        if ($step === null || $step['advance'] !== $event) {
            return;
        }

        $this->next();
    }

    private function matchesRoute(): bool
    {
        $step = $this->currentStep();

        if ($step === null) {
            return false;
        }

        return $this->routeName === null || $step['route'] === $this->routeName;
    }

    private function goToStepRoute(): void
    {
        $step = $this->currentStep();

        if ($step === null) {
            return;
        }

        if ($this->routeName !== null && $step['route'] !== $this->routeName) {
            $this->redirectRoute($step['route'], navigate: true);
        }
    }

    private function persist(): void
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $user->forceFill(['tour_step' => $this->step])->save();
        }
    }

    private function complete(): void
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $user->forceFill([
                'tour_step' => 0,
                'tour_completed_at' => now(),
            ])->save();
        }

        $this->step = 0;
        $this->visible = false;
        $this->dispatch('tour-closed');
    }
}
