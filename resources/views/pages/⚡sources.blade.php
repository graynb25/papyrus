<?php

use App\Live\Sources\SourcesFilter;
use App\Live\Sources\SourcesFormatting;
use App\Live\Sources\SourcesPageData;
use App\Live\Sources\SourcesRules;
use App\Livewire\AdultContentPage;
use App\Local\LocalLibrary;
use App\Models\ExtensionStore;
use App\Models\Source;
use App\Models\UserSource;
use App\Support\LanguageName;
use App\Suwayomi\ExtensionManager;
use App\Suwayomi\SourceCatalog;
use App\Suwayomi\SuwayomiException;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

new class extends AdultContentPage
{
    /** The page's reads, kept out of this component so their shapes can be tested directly. */
    private SourcesPageData $data;

    /** The catalogue filter pipeline, shared with its direct tests. */
    private SourcesFilter $filter;

    /** The page's pure formatting rules, shared with its direct tests. */
    private SourcesFormatting $formatting;

    /** The per-row and selection rules, shared with their direct tests. */
    private SourcesRules $rules;

    /** The store and catalogue writes behind the store and sync actions. */
    private SourceCatalog $sourceCatalog;

    /** The extension trust, install, uninstall and update writes. */
    private ExtensionManager $extensionManager;

    /** The local folder rescan. */
    private LocalLibrary $localLibrary;

    public function boot(
        SourcesPageData $data,
        SourcesFilter $filter,
        SourcesFormatting $formatting,
        SourcesRules $rules,
        SourceCatalog $sourceCatalog,
        ExtensionManager $extensionManager,
        LocalLibrary $localLibrary,
    ): void {
        $this->data = $data;
        $this->filter = $filter;
        $this->formatting = $formatting;
        $this->rules = $rules;
        $this->sourceCatalog = $sourceCatalog;
        $this->extensionManager = $extensionManager;
        $this->localLibrary = $localLibrary;
    }

    public string $storeUrl = '';

    /**
     * Which slice of the catalogue to show.
     *
     * Installed is the default rather than everything, because a community store
     * carries 1,379 extensions and "everything" is a number, not a view.
     */
    #[Url(as: 'view', except: 'installed')]
    public string $view = 'installed';

    /**
     * The languages the reader has switched off.
     *
     * ## Why the list is what is left out and not what is wanted
     *
     * The catalogue a community store ships is one extension per language,
     * and it held 1,443 of them across 24 languages on 2026-10-21, so a reader
     * who reads two languages is looking at twenty-two they have no use for.
     *
     * An allow list would have to name every language it wanted, which means
     * the URL carries the whole catalogue's worth of codes and the filter has
     * to be rewritten every time the catalogue grows one. A list of what is
     * left out carries one code for the one language a reader does not want,
     * and the default (nothing left out) is the page as it was before.
     *
     * It is also the only shape in which "no language at all" can be said.
     * An allow list of nothing and an absent filter are the same empty array,
     * and only one of them can mean "nothing".
     *
     * The codes are the catalogue's own, with its own casing, because the
     * server's match is case sensitive: `notIn ["pt-BR"]` takes the available
     * set from 1,055 to 976 and `notIn ["pt-br"]` takes it to 1,055.
     *
     * @var array<int, string>
     */
    #[Url(as: 'lang', except: '')]
    public array $lang = [];

    public bool $showAllLanguages = false;

    public string $name = '';

    public int $page = 1;

    public const PER_PAGE = SourcesPageData::PER_PAGE;

    /**
     * Why the catalogue did not load itself, when it did not.
     *
     * Toasts vanish and the store form is easy to leave; this is state on the
     * page, so the failure and its recovery stay readable while the reader is
     * deciding whether to trust the store they just added. Null once any load,
     * automatic or pressed, has succeeded again.
     */
    public ?string $loadProblem = null;

    /**
     * Whether the automatic load's fetch was accepted while the catalogue still
     * holds nothing for it.
     *
     * Measured live on 2026-10-17: the server answers its fetchExtensions call
     * before the fetch itself has populated anything, so a reply of zero is
     * not an answer yet, and the render riding on that zero reads as a silent
     * failure. Set only after a short wait and one fresh count read, and
     * retired through autoloadNoteStillHolds the moment the list is real.
     */
    public bool $autoloadStillEmpty = false;

    /**
     * Package names ticked for a bulk install. Cleared after every action, since
     * a stale selection would install something the user has since unticked.
     *
     * @var array<int, string>
     */
    public array $selected = [];

    /**
     * Install failures from the last bulk action, rendered inline.
     *
     * Kept as component state rather than pushed through a toast, because a
     * per-package reason is the one thing a user cannot act on from memory.
     *
     * @var array<string, string>
     */
    public array $failures = [];

    /**
     * The names the install warning is open on: the ones the notice list covers.
     *
     * Armed by an install press and emptied by both answers to the dialog, so it
     * never sits armed while the dialog is closed.
     *
     * Locked because it is the answer the confirm button acts on. A public
     * Livewire property is client supplied unless it is locked, and the one
     * thing this array has to be is the list this page armed, not a list a
     * request wrote. A forged value here would skip the row and trust checks
     * the press made, which is the whole gate this page exists to hold.
     *
     * @var array<int, string>
     */
    #[Locked]
    public array $pendingInstall = [];

    /**
     * The rest of a bulk selection waiting behind the warning. Confirm installs
     * them together with the flagged ones, because that is the request the press
     * stood for; closing the dialog installs nothing.
     *
     * Locked for the same reason as `pendingInstall`: it carries the rest of
     * the answered selection into the confirm press.
     *
     * @var array<int, string>
     */
    #[Locked]
    public array $pendingClean = [];

    /**
     * The browser tab follows the selected language.
     *
     * `#[Title]` is a PHP attribute and cannot call `__()`.
     */
    public function render(): \Illuminate\View\View
    {
        return $this->view()->title(__('Sources'));
    }

    public function mount(): void
    {
        /*
         * The content default is settled here rather than left on the property,
         * and it has to run in both directions.
         *
         * Forward: with #[Url(as: 'content', except: '')] a fresh load carrying
         * only ?view=available has no content parameter, so Livewire hydrates the
         * array property from the absent value and the declared default is
         * discarded. Measured live: the Available tab reported 1,364 extensions
         * instead of the 999 that SAFE and MIXED actually match, because the
         * NSFW sources were never filtered out.
         *
         * Backward: the Installed tab must carry no content filter at all. An
         * extension is either on the server or it is not, and a rating belongs to
         * the reader rather than to a list of what happens to be present. The store measured
         * here currently ships no NSFW extension among the installed set, so this
         * hides nothing today, which is exactly why it needs a test rather than
         * a look.
         *
         * And the gate, which is the third thing this line decides. An address
         * carrying content[]=NSFW is not a confirmation: measured on 2026-10-21 it
         * put 41 adult-rated extensions on the first page of this catalogue with
         * nothing asked, because Livewire merges the array in the address over
         * the declared one and NSFW replaced SAFE. So while the gate is off the
         * rating is not in the address either, and the page says so where the
         * rating used to be.
         */
        $this->content = $this->view === 'installed'
            ? []
            : $this->visibleRatings(
                $this->content !== [] ? $this->content : [Source::SAFE, Source::MIXED],
            );

        /*
         * The languages left out, cleaned of anything that is not a code.
         *
         * A URL can carry `?lang[]=` with nothing after it, and Laravel reads
         * that as a list holding one empty string. Left alone it would reach
         * the server as `notIn [""]`, which matches no extension and would
         * empty the list for a reason the reader never gave. A code that is
         * not a code is not a language, so it is dropped here rather than
         * sent.
         */
        $this->lang = array_values(array_filter(
            $this->lang,
            fn ($code): bool => is_string($code) && trim($code) !== '',
        ));
    }

    public function updatedView(): void
    {
        $this->reset(['page', 'selected', 'failures']);

        // The Installed tab has no content rating to filter by: an extension is
        // either on the server or it is not. NSFW gating belongs to the reader,
        // not to a list of what is technically present.
        $this->content = $this->view === 'installed'
            ? []
            : $this->visibleRatings([Source::SAFE, Source::MIXED]);
    }

    public function addStore(): void
    {
        /*
         * The control is hidden from readers below, but a Livewire method is callable
         * from the browser console. The gate is the rule; the hidden control is only the
         * courtesy that keeps a reader from trying.
         */
        Gate::authorize('manage-install');

        $this->validate(['storeUrl' => ['required', 'string', 'url', 'max:500']]);

        try {
            $store = $this->sourceCatalog->addStore(trim($this->storeUrl));
        } catch (SuwayomiException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        $this->storeUrl = '';

        /*
         * The catalogue loads itself here rather than waiting for a press the
         * reader cannot know exists: measured in the round that produced F26's
         * onboarding requirements, a store added and the list stayed empty until
         * Refresh catalogue was found by hunting. This is the same body the
         * button runs, not a second one. When the server cannot answer, the
         * store and the trust flow both stand, and the page says what failed
         * under the button rather than in a toast that is gone in four seconds.
         */
        try {
            $this->settleAutoload();
            $this->loadProblem = null;
        } catch (SuwayomiException $e) {
            $this->loadProblem = $e->getMessage();
        }

        Flux::toast(
            text: __('Read its signing key below, then decide whether to trust it.'),
            heading: __('Store added: :name', ['name' => $store->name]),
            variant: 'success',
        );

        $this->dispatch('tour-store-added');
    }

    public function removeStore(string $indexUrl): void
    {
        Gate::authorize('manage-install');

        try {
            $this->sourceCatalog->removeStore($indexUrl);
        } catch (SuwayomiException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        Flux::toast(
            text: __('Its trust records went with it.'),
            heading: __('Store removed'),
            variant: 'success',
        );
    }

    /**
     * Ask the server to re-read every store, then mirror the result.
     *
     * Without this the list is whatever the server last fetched: a server that
     * has never fetched holds nothing at all, while a cached catalogue
     * survives a restart, measured on 2026-10-17 with v2.4.2366.
     */
    public function refresh(): void
    {
        try {
            $count = $this->loadCatalogue();
        } catch (SuwayomiException) {
            Flux::toast(
                text: __('Papyrus could not refresh the catalogue. Try again.'),
                heading: __('Catalogue not refreshed'),
                variant: 'danger',
            );

            return;
        }

        $this->reset(['page', 'selected']);
        $this->loadProblem = null;
        $this->autoloadStillEmpty = false;

        Flux::toast(
            text: trans_choice('{1} :count extension available|[2,*] :count extensions available', $count, ['count' => $count]),
            heading: __('Stores refreshed'),
            variant: 'success',
        );
    }

    /**
     * The fetch and the store mirror as one run, so whatever path reaches for
     * them reaches for the same one.
     *
     * Extracted from refresh() rather than duplicated, because an automatic
     * load that differed subtly from the pressed one would be its own finding
     * waiting to happen. The comment above refresh() is what this run means:
     * after a restart the server's list is nothing at all, so a store added
     * after one would otherwise hang there with nothing to show for itself.
     */
    private function loadCatalogue(): int
    {
        $count = $this->sourceCatalog->refreshExtensions();
        $this->sourceCatalog->syncStores();

        return $count;
    }

    /**
     * Take an automatic load's outcome and settle what the page says over it.
     *
     * A catalogue that arrived is an ended question. A reply that counted
     * nothing is not: upstream answers its fetch call before the fetch itself
     * has populated anything, measured live on 2026-10-17 when a cold trust
     * answered zero and the same server held 1,434 a minute later. So the
     * zero gets a short wait rather than the reader's render, and the truth
     * about whether anything landed is judged at render time by
     * autoloadNoteStillHolds, fresh against the server, because the reply and
     * the population are separate events upstream and a single committed
     * read cannot know which side of the fetch it fell on. The press paths
     * keep their own toasts and never reach this.
     */
    private function settleAutoload(): void
    {
        $count = $this->loadCatalogue();

        if ($count > 0) {
            $this->autoloadStillEmpty = false;

            return;
        }

        sleep(2);

        $this->autoloadStillEmpty = true;
    }

    /**
     * Whether the accepted-but-unpopulated fetch's note still belongs on the
     * page.
     *
     * Read afresh on every render while it stands, because the fetch
     * populates the server behind Livewire's back, and a reader's next look
     * must retire the note the moment there is something to look at instead
     * of waiting for an action that clears it. Unread keeps it standing, on
     * the same reasoning settleAutoload records.
     */
    public function autoloadNoteStillHolds(): bool
    {
        if ($this->autoloadStillEmpty === false) {
            return false;
        }

        try {
            $holds = $this->data->extensionCount();
        } catch (SuwayomiException) {
            return true;
        }

        if ($holds > 0) {
            $this->autoloadStillEmpty = false;

            return false;
        }

        return true;
    }

    /**
     * Mirror the server's store list and source catalogue into our tables.
     *
     * Installing an extension changes what the server offers but not what we
     * know about, so this is what turns "installed" into "searchable".
     */
    public function syncSources(): void
    {
        try {
            $result = $this->sourceCatalog->syncAll();
        } catch (SuwayomiException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        $this->reset(['page', 'selected']);

        Flux::toast(
            text: __(':count sources mirrored from :stores stores.', [
                'count' => $result['sources'],
                'stores' => $result['stores'],
            ]),
            heading: __('Sources synced'),
            variant: 'success',
        );
    }

    public function trust(int $storeId): void
    {
        $store = ExtensionStore::query()->find($storeId);

        if ($store === null) {
            return;
        }

        $this->extensionManager->trust(auth()->user(), $store);

        Flux::toast(
            text: __('You can now install extensions signed by this key.'),
            heading: __('Store trusted'),
            variant: 'success',
        );

        $this->loadCatalogueWhenTheServerHasNone();

        $this->dispatch('tour-store-trusted');
    }

    /**
     * The second automatic load, for the reader who arrives at trust first.
     *
     * That shape is reachable from both directions: the store was added on a
     * machine whose fetch failed at add time, or it was mirrored earlier while
     * the server still held nothing (this checkout's own reader trusted
     * a community store days before its catalogue was ever fetched). Trusting is then
     * the first action left, so trust fetches, but only then. A server that
     * already holds the catalogue is left alone: re-reading more than a
     * thousand extension rows to announce a trust state spends upstream for
     * nothing, and the pressed button keeps doing its own full run.
     */
    private function loadCatalogueWhenTheServerHasNone(): void
    {
        try {
            $known = $this->data->extensionCount();
        } catch (SuwayomiException) {
            // Unread is treated as empty rather than as "skip": the load's own
            // failure handling reports what it could not do, so the reader is
            // never left with a silent skip and a page that shows fewer rows
            // than the server would give.
            $known = 0;
        }

        if ($known > 0) {
            return;
        }

        try {
            $this->settleAutoload();
            $this->loadProblem = null;
        } catch (SuwayomiException $e) {
            $this->loadProblem = $e->getMessage();
        }
    }

    public function revokeTrust(int $storeId): void
    {
        $store = ExtensionStore::query()->find($storeId);

        if ($store === null) {
            return;
        }

        $this->extensionManager->revokeTrust(auth()->user(), $store);

        Flux::toast(
            text: __('Installed extensions stay installed. This publisher is no longer trusted for new ones.'),
            heading: __('Trust withdrawn'),
            variant: 'info',
        );
    }

    /**
     * Re-mirror the source catalogue after changing what is installed.
     *
     * Installing an extension changes what the server offers and not what we know about,
     * and the browse picker is built from the mirror. Left to a second manual press the
     * picker stayed behind for as long as the reader did not know to press it, which is
     * what happened: two extensions installed at 11:43 and 11:44, and the mirror's newest
     * row was still from 23:15 the night before, so the picker showed 82 sources where
     * the server had 84.
     *
     * Returns whether the mirror now agrees. The install itself has already succeeded by
     * the time this runs, so the caller reports the two facts separately rather than
     * reporting a failed install. Swallowing the failure would leave a working extension
     * that the picker will not show, with nothing on screen to explain why.
     *
     * `syncSources()` rather than `syncAll()`, because a store's index has not moved.
     * Measured at 125ms against 1030ms for the pair, and an install is an action the
     * reader has already waited seconds for.
     */
    private function mirrorSources(): bool
    {
        try {
            $this->sourceCatalog->syncSources();
        } catch (SuwayomiException) {
            return false;
        }

        return true;
    }

    /**
     * Install one extension, and ask first when its site is on the notice list.
     *
     * Owner, 2026-10-17: the press on one of those sources is where the warning
     * belongs, not only the card above it. The ask arms the pending name and opens
     * the dialog with the facts the config already tracks; a name without a notice
     * keeps its single press, because a dialog on everything would teach readers
     * to click past warnings.
     */
    public function install(string $pkgName): void
    {
        /*
         * The control is hidden from readers below, but a Livewire method is callable
         * from the browser console. Installing runs a signed artefact in the shared
         * Suwayomi process, so it belongs to the install owner whichever way the press
         * reaches the method.
         */
        Gate::authorize('manage-install');

        if (! $this->rules->canInstallName($pkgName, $this->extensions, $this->trustedIds)) {
            return;
        }

        if ($this->formatting->noticeFor($this->rules->rowFor($pkgName, $this->extensions) ?? []) !== null) {
            $this->pendingInstall = [$pkgName];
            $this->pendingClean = [];

            $this->dispatch('open-install-warning');

            return;
        }

        $this->installNames([$pkgName]);
    }

    public function uninstall(string $pkgName): void
    {
        /*
         * An extension is shared by every reader of this install. In `shared` or
         * `public` mode a second account removing one costs another reader their
         * sources, so removal belongs to the install owner. The trust check cannot
         * cover this: it is a decision about installing, not about taking something
         * away from everybody.
         */
        Gate::authorize('manage-install');

        try {
            $this->extensionManager->setInstalled($pkgName, false);
        } catch (SuwayomiException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        $this->failures = [];

        /*
         * Mirrored on the way out as well as on the way in.
         *
         * An uninstall that leaves the source in the mirror offers a browse link that 404s,
         * which is the failure the picker is supposed to be impossible to have.
         */
        $mirrored = $this->mirrorSources();

        Flux::toast(
            text: $mirrored
                ? __('Its source is gone from the list.')
                : __('Uninstalled, but the source list could not be refreshed. Press Sync sources.'),
            heading: __('Uninstalled'),
            variant: $mirrored ? 'success' : 'info',
        );
    }

    /**
     * Update one extension to the version its store ships.
     *
     * The badge the page already draws proved nothing more was wired: a reader
     * seeing "update" had no control anywhere that acted on it, so the only route
     * was the server's own WebUI. Trust is checked here for the same reason it is
     * checked before install: an update is a new signed artefact about to run.
     */
    public function update(string $pkgName): void
    {
        if (! $this->rules->canUpdateName($pkgName, $this->extensions, $this->trustedIds)) {
            return;
        }

        try {
            $this->extensionManager->update($pkgName);
        } catch (SuwayomiException $e) {
            // Listed as well as toasted, on the same reasoning as the bulk
            // failures: the reader is about to wonder whether it moved.
            $this->failures = [$pkgName => $e->getMessage()];

            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        $this->failures = [];
        $this->forgetCatalogue();

        Flux::toast(
            text: __('Its source now runs the version the store ships.'),
            heading: __('Updated'),
            variant: 'success',
        );
    }

    /**
     * Update every extension the server reports as out of date.
     *
     * The list is asked for fresh rather than taken from the page, because the
     * page shows fifty rows and the update set does not end where a page does.
     * Failures are collected and rendered inline, on the same reasoning as the
     * bulk install: "some worked" is not an acceptable answer to how many.
     */
    public function updateAll(): void
    {
        /*
         * Read the whole update set, not one page of it.
         *
         * The old 500 row cap was invisible in the button's promise. A reader can
         * install more than 500 extensions from a community store, and "update all"
         * that silently stops at a page boundary is the same kind of confident
         * wrong answer as a stale row. The bound is ten pages so a server that
         * ignores the offset cannot keep this loop alive forever; five thousand
         * rows is more than the measured catalogue holds.
         */
        $targets = [];
        $offset = 0;
        $pageSize = 500;

        for ($page = 0; $page < 10; $page++) {
            $rows = $this->extensionManager->extensions(
                ['has_update' => true, 'installed' => true],
                $pageSize,
                $offset,
            );

            foreach ($rows as $row) {
                if ($this->rules->isTrusted($row, $this->trustedIds)) {
                    $targets[] = (string) $row['pkgName'];
                }
            }

            if ($rows->count() < $pageSize) {
                break;
            }

            $offset += $pageSize;
        }

        $targets = array_values(array_unique($targets));

        if ($targets === []) {
            Flux::toast(
                text: __('Nothing has an update, or every store behind one is untrusted.'),
                heading: __('Nothing to update'),
                variant: 'info',
            );

            return;
        }

        $result = $this->extensionManager->updateMany($targets);

        $this->forgetCatalogue();
        $this->failures = $result['failed'];

        if ($this->failures !== []) {
            Flux::toast(
                text: trans_choice(
                    '{1} :count updated, :failed failed.|[2,*] :count updated, :failed failed.',
                    count($result['updated']),
                    ['count' => count($result['updated']), 'failed' => count($this->failures)],
                ),
                heading: __('Some updates failed'),
                variant: 'danger',
            );

            return;
        }

        Flux::toast(
            text: trans_choice(
                '{1} :count extension updated.|[2,*] :count extensions updated.',
                count($result['updated']),
                ['count' => count($result['updated'])],
            ),
            heading: __('Updated'),
            variant: 'success',
        );
    }

    /**
     * Install everything the selection chose, and ask first when the notice list
     * covers any of it. One ask for the whole selection, in the one dialog the
     * page carries. Confirming installs the flagged ones along with their clean
     * companions, because that is the request the press stood for; closing the
     * dialog installs nothing.
     */
    public function installSelected(): void
    {
        Gate::authorize('manage-install');

        $allowed = $this->rules->installableFromSelection($this->selected, $this->extensions, $this->trustedIds);

        $this->reset(['selected', 'failures']);

        if ($allowed === []) {
            return;
        }

        $flagged = $this->rules->flaggedAmong($allowed, $this->extensions);

        if ($flagged !== []) {
            $this->pendingInstall = $flagged;
            $this->pendingClean = array_values(array_diff($allowed, $flagged));

            $this->dispatch('open-install-warning');

            return;
        }

        $this->installNames($allowed);
    }

    /**
     * The press after the warning. The ask already checked each row before arming,
     * so the dialog is not asked a second time; asking the same question twice on
     * one press is how a warning becomes furniture.
     *
     * The gate and the per-name checks are still re-run here and in `installNames()`,
     * because the pending arrays travel through the browser and the ask happened in
     * an earlier request. That second pass is the rule; the ask is the courtesy.
     */
    public function installConfirmed(): void
    {
        Gate::authorize('manage-install');

        $names = array_values(array_filter(
            array_merge($this->pendingInstall, $this->pendingClean),
            fn (string $pkgName): bool => $this->rules->canInstallName($pkgName, $this->extensions, $this->trustedIds),
        ));

        $this->reset(['pendingInstall', 'pendingClean']);

        $this->dispatch('close-install-warning');

        if ($names === []) {
            return;
        }

        $this->installNames($names);
    }

    /**
     * The dialog closed without its button. Nothing installs, and the pending
     * names go so no later request carries them along.
     */
    public function closeInstallWarning(): void
    {
        $this->reset(['pendingInstall', 'pendingClean']);
    }

    /**
     * The install itself, with no ask in front of it. A single name keeps the
     * single press's copy; more than one keeps the bulk count, because a toast
     * that says one extension for two names would read as a lie.
     *
     * The gate and the per-name checks are repeated here rather than trusted from
     * the caller, because every install path ends at this method and a future
     * caller must not be able to skip them. `canInstallName()` reads the row and
     * the reader's current trust, so a name whose store was distrusted between
     * the press and this call installs nothing.
     *
     * @param  array<int, string>  $names
     */
    private function installNames(array $names): void
    {
        Gate::authorize('manage-install');

        $names = array_values(array_filter(
            $names,
            fn (string $pkgName): bool => $this->rules->canInstallName($pkgName, $this->extensions, $this->trustedIds),
        ));

        if ($names === []) {
            return;
        }

        if (count($names) === 1) {
            try {
                $this->extensionManager->setInstalled($names[0], true);
            } catch (SuwayomiException $e) {
                Flux::toast(text: $e->getMessage(), variant: 'danger');

                return;
            }

            $this->failures = [];

            $mirrored = $this->mirrorSources();

            Flux::toast(
                text: $mirrored
                    ? __('It is browsable now.')
                    : __('Installed, but the source list could not be refreshed. Press Sync sources.'),
                heading: __('Installed'),
                variant: $mirrored ? 'success' : 'info',
            );

            $this->dispatch('tour-extension-installed');

            return;
        }

        $result = $this->extensionManager->installMany($names);
        $this->failures = $result['failed'];

        if ($this->failures !== []) {
            Flux::toast(
                text: trans_choice(
                    '{1} :count installed, :failed failed.|[2,*] :count installed, :failed failed.',
                    count($result['installed']),
                    ['count' => count($result['installed']), 'failed' => count($this->failures)],
                ),
                heading: __('Some installs failed'),
                variant: 'danger',
            );

            return;
        }

        /*
         * Once, into a variable. The first version of this called the helper inside the toast
         * text and again for the variant, which is two extra full catalogue writes on every
         * bulk install and a toast whose wording and colour could disagree.
         */
        $mirrored = $this->mirrorSources();
        $added = count($result['installed']);

        Flux::toast(
            text: $mirrored
                ? trans_choice(
                    '{1} :count extension added and browsable.|[2,*] :count extensions added and browsable.',
                    $added,
                    ['count' => $added],
                )
                : trans_choice(
                    '{1} :count extension added, but the source list could not be refreshed. Press Sync sources.|[2,*] :count extensions added, but the source list could not be refreshed. Press Sync sources.',
                    $added,
                    ['count' => $added],
                ),
            heading: __('Installed'),
            variant: $mirrored ? 'success' : 'info',
        );

        $this->dispatch('tour-extension-installed');
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    /**
     * The strip's views, kept as a delegate because the action guard reads them.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function views(): array
    {
        return $this->formatting->views();
    }

    /**
     * The Migrate view's rows: what the reader holds, per source.
     *
     * @return array{rows: \Illuminate\Support\Collection<int, array{source_id: string, display_name: string, lang: string, count: int, installed: bool, named: bool, icon: string|null}>, error: string|null}
     */
    #[Computed]
    public function holdings(): array
    {
        return $this->data->holdings();
    }

    /**
     * Whether the local source's folder can be read from this machine at all.
     *
     * Not decoration. The path is Suwayomi's data directory, so on any deployment where
     * the two are not on the same machine it does not exist, and a browse view that showed
     * an empty list would read as "you have no local manga".
     */
    #[Computed]
    public function localVisible(): bool
    {
        return $this->data->localVisible();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function localRows(): array
    {
        return $this->data->localRows();
    }

    /**
     * @return array{series: int, on_disk: int, missing: int, unscanned: int, folders: int}
     */
    #[Computed]
    public function localSummary(): array
    {
        return $this->data->localSummary();
    }

    /**
     * Installed extensions whose sources are not in the mirror yet.
     *
     * @return array<int, array{name: string, pkg: string}>
     */
    #[Computed]
    public function unmirroredExtensions(): array
    {
        return $this->data->unmirroredExtensions($this->adultContentOn());
    }

    /**
     * Pin every installed source on one site card, for this reader's searches.
     *
     * The mirror of the health page's `pin()`, in the seat a reader reaches first:
     * this page is where the sites are laid out, and the card already shows the
     * count of variants the pin would cover, so acting here needs no second page.
     *
     * The card's key is the bare host ({@see browseSites()} groups by `hostOf()`
     * and lowercases it), so the members of a card are derived from that same
     * helper via {@see cardSourceIds()}: a write and its badge must read the same
     * set, and a raw `home_url` comparison would never equal the key the card
     * actually sends.
     *
     * Installed only, deliberately, and the health drill-down is not: this page
     * pins what this page displays, and the fanout's rule makes the distinction
     * harmless anyway, because only runnable sources are ever asked whatever the
     * pin rows say.
     */
    public function pinSite(string $site): void
    {
        $ids = $this->data->cardSourceIds($site);

        if ($ids === []) {
            return;
        }

        UserSource::setPinnedFor(auth()->id(), $ids, true);

        unset($this->pinnedIds);

        Flux::toast(
            text: trans_choice(
                '{1} Search asks this one variant alone while the pin stands.|[2,*] Search asks the :count variants of this site alone while the pin stands.',
                count($ids),
                ['count' => count($ids)],
            ),
            heading: __('Pinned for search'),
            variant: 'success',
        );
    }

    public function unpinSite(string $site): void
    {
        UserSource::setPinnedFor(auth()->id(), $this->data->cardSourceIds($site), false);

        unset($this->pinnedIds);

        Flux::toast(
            text: __('Search is back to every source that can run.'),
            heading: __('Pin cleared'),
            variant: 'info',
        );
    }

    /**
     * The sources this reader has pinned, as Suwayomi ids.
     *
     * One query rather than one per card. The Livewire computed stays so one render
     * asks once and an action that writes a pin can drop it; the read itself lives on
     * the data class.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function pinnedIds(): array
    {
        return $this->data->pinnedIds();
    }

    /**
     * Whether any installed source behind one card is pinned.
     *
     * Any, not every, on the same reading the health page records for its badge:
     * pins do not retire rows, so any pinned variant behind a card means the
     * reader pinned that site.
     */
    public function isPinnedSite(string $site): bool
    {
        return $this->data->isPinnedSite($site, $this->pinnedIds);
    }

    /**
     * Installed sources grouped by the site they belong to, for the browse picker.
     *
     * @return array<int, array{site: string, host: string|null, health_site: string, sources: array<int, array<string, mixed>>, icon: string|null, health: array{state: string, label: string}|null}>
     */
    #[Computed]
    public function browseSites(): array
    {
        return $this->data->browseSites($this->adultContentOn());
    }

    /**
     * Ask Suwayomi to read the local folder again.
     *
     * Reports what came back rather than assuming it worked, because a rescan that found
     * nothing and one that failed look identical from the page otherwise.
     */
    public function rescanLocal(): void
    {
        $result = $this->localLibrary->rescan();

        // Both computeds are dropped because the folder and the server's listing are the
        // two halves of the view and one of them has just changed.
        unset($this->localRows, $this->localSummary);

        Flux::toast(
            text: $result['ok']
                ? trans_choice(
                    'The server read :count series from the folder.',
                    $result['series'],
                    ['count' => $result['series']],
                )
                : $result['message'],
            heading: $result['ok'] ? __('Folder read') : __('The folder was not read'),
            variant: $result['ok'] ? 'success' : 'danger',
        );
    }

    public function setView(string $view): void
    {
        // Guarded, because the URL carries the value and a hand-edited link
        // should not be able to reach a view the method has no branch for.
        if (array_key_exists($view, $this->views)) {
            $this->view = $view;
        }
    }

    /**
     * @return Collection<int, ExtensionStore>
     */
    #[Computed]
    public function stores(): Collection
    {
        return $this->data->stores();
    }

    /**
     * @return array<int, int>
     */
    #[Computed]
    public function trustedIds(): array
    {
        return $this->data->trustedIds();
    }

    /**
     * The catalogue as one read: the rows, the count, or why neither could be had.
     *
     * @return array{extensions: Collection<int, array<string, mixed>>, total: int, error: string|null, hidden: int}
     */
    #[Computed]
    public function catalogue(): array
    {
        return $this->data->catalogue(
            $this->filters(),
            $this->view,
            $this->page,
            $this->adultContentOn(),
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function extensions(): Collection
    {
        return $this->data->extensions($this->catalogue);
    }

    #[Computed]
    public function total(): int
    {
        return $this->data->total($this->catalogue);
    }

    #[Computed]
    public function lastPage(): int
    {
        return $this->formatting->lastPage($this->total, self::PER_PAGE);
    }

    /**
     * How many installed extensions the server says have an update.
     *
     * Read only on the views that offer the bulk action, so the Available and All
     * tabs do not pay for a count they never show.
     */
    #[Computed]
    public function updateCount(): int
    {
        return $this->data->updateCount();
    }

    /**
     * Drop the catalogue values a mutation made stale.
     *
     * The single update used to read `$this->extensions` for its trust check,
     * which cached the rows before the mutation. The render in the same request
     * then drew the old `hasUpdate` flag, and the button only disappeared on the
     * next Livewire request. Forgetting the computed values makes the same
     * response read the server again.
     */
    private function forgetCatalogue(): void
    {
        unset($this->catalogue, $this->extensions, $this->total, $this->lastPage, $this->updateCount);
    }

    /**
     * The languages the catalogue holds, with how many sources each one has.
     *
     * @return array<int, array{code: string, name: string, count: int, on: bool}>
     */
    #[Computed]
    public function catalogueLanguages(): array
    {
        return $this->data->catalogueLanguages($this->filtersWithoutLanguage(), $this->lang);
    }

    /**
     * Switch one language off, or back on.
     *
     * The one place the choice is made, so the pill and the filter cannot
     * disagree: the pill asks for a language, and the filter is built from
     * the same list the pill reads.
     */
    public function toggleLanguage(string $code): void
    {
        $code = trim($code);

        if ($code === '') {
            return;
        }

        $this->lang = in_array($code, $this->lang, true)
            ? array_values(array_diff($this->lang, [$code]))
            : [...$this->lang, $code];

        $this->showAllLanguages = false;
        $this->reset(['page', 'selected']);
    }

    /**
     * Put every language back.
     *
     * The way back from a reader who has switched off more than they meant
     * to, and the way to a list of every language again.
     */
    public function showEveryLanguage(): void
    {
        $this->lang = [];
        $this->showAllLanguages = false;

        $this->reset(['page', 'selected']);
    }

    public function toggleLanguageRow(): void
    {
        $this->showAllLanguages = ! $this->showAllLanguages;
    }

    /**
     * Leave every language out.
     *
     * Not a flourish: it is how a reader who reads two languages gets to
     * those two. Switching twenty-two languages off one at a time is
     * twenty-two round trips to the server and twenty-two renders of a list
     * of a thousand rows, and the reader who wants English and nothing else
     * should not pay for it.
     *
     * The languages of the view the reader is on are added to the ones they
     * had already left out, and not swapped for them, because a reader who
     * left Japanese out on the Available tab and then came to the Installed
     * tab did not change their mind about Japanese.
     */
    public function showNoLanguage(): void
    {
        $this->lang = array_values(array_unique([
            ...$this->lang,
            ...array_map(fn (array $language): string => $language['code'], $this->catalogueLanguages),
        ]));
        $this->showAllLanguages = false;

        $this->reset(['page', 'selected']);
    }

    /**
     * Whether this extension's publisher has been trusted by this user.
     *
     * Used for the lock icon. The refusal itself is enforced in the action, so
     * hiding the control is presentation rather than the security boundary.
     */
    public function isTrusted(array $extension): bool
    {
        return $this->rules->isTrusted($extension, $this->trustedIds);
    }

    /**
     * One row per name the warning asks about.
     *
     * @return array<int, array<string, string>>
     */
    public function warningRows(): array
    {
        return $this->rules->warningRows($this->pendingInstall, $this->extensions);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return $this->filter->filters(
            $this->view,
            $this->name,
            $this->lang,
            $this->visibleRatings($this->content),
            $this->adultContentOn(),
        );
    }

    /**
     * The same filters, without the reader's language choice.
     *
     * @return array<string, mixed>
     */
    private function filtersWithoutLanguage(): array
    {
        return $this->filter->filtersWithoutLanguage(
            $this->view,
            $this->name,
            $this->lang,
            $this->visibleRatings($this->content),
            $this->adultContentOn(),
        );
    }
}; ?>

<div class="w-full space-y-8" x-on:open-install-warning.window="$dispatch('modal-show', { name: 'install-warning' })" x-on:close-install-warning.window="$dispatch('modal-close', { name: 'install-warning' })">
    <x-page.header data-tour="sources-page" back="{{ route('library') }}" :back-label="__('Back to your library')">
        <x-slot:eyebrow>{{ __('Sources, on your terms.') }}</x-slot:eyebrow>
        <x-slot:title>{{ __('Sources') }}</x-slot:title>
    </x-page.header>

    {{--
        Moving a whole source.

        Here because this is where a reader is already thinking about which sources they use,
        and because an unlinked feature is not a feature. The per series wizard is reached from
        a series page and does one series; this one is reached from here and does the shelf.

        A link rather than a button that does the work: choosing two sources is a decision with
        no sensible default, and this page is already long.
    --}}
    <div class="flex justify-end">
        <flux:button
            size="sm"
            variant="outline"
            icon="arrow-right-circle"
            href="{{ route('migrate.sources') }}"
            wire:navigate
        >
            {{ __('Move a whole source') }}
        </flux:button>
    </div>

    {{--
        Browse by source.

        This is the door search cannot be. Search fans out to 40 targets out of 82,
        ordered by name, and 61 of the 82 are MangaDex language variants, so the cut is
        alphabetical: MangaFire, MangaTaro, Thunder Scans and Weeb Central were never
        searched at all. A reader who wants one of those has no way in from search.

        Grouped by site because 82 sources are really 16, for the same reason the health
        dashboard groups by host. Biggest group last, so the 61 MangaDex languages do not
        fill the first screen.
    --}}
    <section class="space-y-3">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <flux:heading level="2" size="lg" class="font-display font-semibold">
                {{ __('Browse a source') }}
            </flux:heading>

            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Popular and latest, straight from one source.') }}
            </p>
        </div>

        @if ($this->unmirroredExtensions !== [])
            {{--
                The picker is about to be wrong, so say so above it rather than in a toast.

                A count rather than a comma-separated name dump: the action is the same for
                every missing extension, and the full list makes the source picker unreadable.
                The button is the existing sync, offered beside the notice.
            --}}
            <flux:callout variant="secondary" icon="arrow-path" data-test="mirror-drift">
                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
                    <p class="text-sm">
                        {{ trans_choice(
                            '{1} :count installed extension is missing from the source list below.|[2,*] :count installed extensions are missing from the source list below.',
                            count($this->unmirroredExtensions),
                            ['count' => Number::format(count($this->unmirroredExtensions))],
                        ) }}
                    </p>

                    <flux:button
                        size="sm"
                        wire:click="syncSources"
                        wire:loading.attr="disabled"
                        data-test="mirror-drift-sync"
                    >
                        {{ __('Sync sources') }}
                    </flux:button>
                </div>
            </flux:callout>
        @endif

        @if ($this->browseSites === [])
            <flux:callout>
                <p class="text-sm">
                    {{ __('No installed sources yet. Sync the catalogue and they will appear here.') }}
                </p>
            </flux:callout>
        @else
            {{--
                Cards, three to five to a row.

                The same grid the library, browse and search pages use, at the same
                breakpoints, so the sources page finally reads as the same application as
                the rest of it. It was the only long list on the site still laid out one
                item per full-width row, and it sat next to a second one, which is what
                made this page taller than anything it had to say.
            --}}
            <ul class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                @foreach ($this->browseSites as $group)
                    <li data-test="source-family-card" class="rounded-xl border border-zinc-200 dark:border-white/10">
                        <article class="flex h-full flex-col p-4">
                            <div class="flex items-start gap-3">
                                <x-source.icon :path="$group['icon']" size="size-9 sm:size-11" />

                                <div class="min-w-0 flex-1">
                                    <span class="block break-words font-medium leading-snug line-clamp-2">{{ $group['site'] }}</span>

                                    @if ($group['host'] !== null)
                                        <span class="mt-0.5 block truncate font-mono text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $group['host'] }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <flux:badge size="sm" color="zinc">
                                    {{ trans_choice('{1} :count source|[2,*] :count sources', count($group['sources']), ['count' => count($group['sources'])]) }}
                                </flux:badge>

                                @if ($this->isPinnedSite($group['host'] ?? 'local'))
                                    <flux:badge size="sm" color="zinc">{{ __('pinned by you') }}</flux:badge>
                                @endif

                                @if (($group['health']['label'] ?? null) !== null)
                                    <span
                                        data-test="source-health-pill"
                                        data-state="{{ $group['health']['state'] ?? 'healthy' }}"
                                        @class([
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-[0.625rem] font-medium',
                                            'bg-moss-600 text-white dark:bg-moss-500 dark:text-white' => ($group['health']['state'] ?? 'healthy') === 'healthy',
                                            'bg-vermilion-600 text-white dark:bg-vermilion-500 dark:text-white' => ($group['health']['state'] ?? 'healthy') !== 'healthy',
                                        ])
                                    >
                                        <span class="drop-shadow-[0_1px_1px_rgba(0,0,0,0.7)]">
                                            {{ $group['health']['label'] }}
                                        </span>
                                    </span>
                                @endif
                            </div>

                            {{--
                                Twelve flags are what the card holds. MangaDot
                                has 108, so the rest are counted and the count
                                opens the health page's own site drilldown
                                rather than pretending the card holds every
                                flag.
                            --}}
                            <div class="mt-3 flex flex-wrap gap-1.5" role="group" aria-label="{{ __('Browse :site sources', ['site' => $group['site']]) }}">
                                @foreach (array_slice($group['sources'], 0, 12) as $one)
                                    @php($flagRegion = LanguageName::flagRegion($one['lang']))

                                    <a
                                        href="{{ route('browse', ['source' => $one['id']]) }}"
                                        wire:navigate
                                        data-source-language="{{ $one['lang'] }}"
                                        @if ($flagRegion !== null) data-source-flag="{{ $flagRegion }}" @endif
                                        aria-label="{{ __('Browse :source', ['source' => $one['name']]) }}"
                                        title="{{ $one['name'] }}"
                                        class="inline-flex size-8 shrink-0 items-center justify-center rounded-md border border-zinc-200 transition hover:border-vermilion-400 hover:bg-vermilion-50 dark:border-white/15 dark:hover:border-vermilion-400 dark:hover:bg-vermilion-950"
                                    >
                                        @if ($flagRegion !== null)
                                            <span aria-hidden="true" class="language-flag language-flag-{{ $flagRegion }}"></span>
                                        @else
                                            <flux:icon name="globe-alt" class="size-4 text-zinc-500" aria-hidden="true" />
                                        @endif
                                    </a>
                                @endforeach

                                @if (count($group['sources']) > 12)
                                    <a
                                        href="{{ route('health', ['site' => $group['health_site']]) }}"
                                        wire:navigate
                                        aria-label="{{ __('Open source health for :site', ['site' => $group['site']]) }}"
                                        title="{{ __('Open source health for :site', ['site' => $group['site']]) }}"
                                        class="inline-flex h-8 shrink-0 items-center justify-center rounded-md border border-zinc-200 px-2 text-xs font-medium tabular-nums text-zinc-600 transition hover:border-vermilion-400 hover:bg-vermilion-50 dark:border-white/15 dark:text-zinc-300 dark:hover:border-vermilion-400 dark:hover:bg-vermilion-950"
                                    >+{{ count($group['sources']) - 12 }}</a>
                                @endif
                            </div>

                            <div class="mt-auto flex flex-wrap items-center gap-2 pt-3">
                                @if ($this->isPinnedSite($group['host'] ?? 'local'))
                                    <flux:button size="sm" wire:click="unpinSite('{{ $group['host'] ?? 'local' }}')">
                                        {{ __('Search every site again') }}
                                    </flux:button>
                                @else
                                    @php($pinAction = 'pinSite('.Illuminate\Support\Js::from($group['host'] ?? 'local')->toHtml().')')

                                    <x-confirm
                                        :action="$pinAction"
                                        :title="__('Search only this site while its pin stands?')"
                                        :body="trans_choice(
                                            'Search asks this one variant alone until the pin is cleared, and every other site waits out of the way.|[2,*] Search asks the :count variants of this site alone until the pin is cleared, and every other site waits out of the way.',
                                            count($group['sources']),
                                            ['count' => Number::format(count($group['sources']))],
                                        )"
                                        :confirm="__('Pin')"
                                    >
                                        <flux:button size="sm">
                                            {{ __('Pin for search') }}
                                        </flux:button>
                                    </x-confirm>
                                @endif
                            </div>
                        </article>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <flux:callout variant="secondary" icon="information-circle">
        <div class="space-y-1 text-sm">
            <p>
                {{ __('Papyrus uses sources to find manga. Add a store, compare its signing key with the publisher\'s, then trust it before installing its extensions.') }}
            </p>
            <p class="text-zinc-500 dark:text-zinc-400">
                {{ __('Every store signs its extensions with a key. Compare the key above against the one the publisher has published; if they match, trust it, and the store\'s extensions become installable.') }}
            </p>
        </div>
    </flux:callout>

    {{--
        Local source.

        Suwayomi's local source has no upload API at all: measured, every GET on
        `/api/v1/...` answers with the 4,484-byte WebUI catch-all and every other method
        a 404. A file dropped into its folder *is* discovered, so the folder is the
        interface and this reads it rather than writing to it.

        The view pairs the folder with what Suwayomi is holding because they disagree,
        and the disagreement is the information. Measured, manga 2899 is listed while the
        only file left is its ComicInfo.xml, and there is no delete mutation to clear it.
        Showing either side alone would be lying about the other.
    --}}
    <section class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading level="2">{{ __('Local source') }}</flux:heading>

                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    @if ($this->localVisible)
                        {{ __('One folder per series, each holding a CBZ and optionally a ComicInfo.xml. Suwayomi reads the folder when told to, so drop a file in and rescan.') }}
                    @else
                        {{ __('Papyrus cannot read this local folder. It may be on another computer or unavailable to this install, so its files are not listed here.') }}
                    @endif
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if ($this->localVisible)
                    <flux:button
                        size="sm"
                        wire:click="rescanLocal"
                        wire:loading.attr="disabled"
                        icon="arrow-path"
                    >
                        {{ __('Read the folder again') }}
                    </flux:button>
                @endif

                <flux:button size="sm" variant="ghost" wire:click="refresh" wire:loading.attr="disabled" icon="arrows-right-left">
                    {{ __('Sync sources') }}
                </flux:button>
            </div>
        </div>

        @if ($this->localVisible)
            @php($local = $this->localRows)
            @php($localSummary = $this->localSummary)

            <flux:card class="space-y-4 p-4">
                @if ($local === [])
                    <flux:callout variant="secondary" icon="folder-open">
                        {{ __('The folder is empty. One folder per series, each holding a CBZ.') }}
                    </flux:callout>
                @else
                    @if ($localSummary['missing'] > 0 || $localSummary['unscanned'] > 0)
                        {{ trans_choice(
                            'The folder and the server disagree about :count series. Both are listed, with what each one knows.',
                            $localSummary['missing'] + $localSummary['unscanned'],
                            ['count' => $localSummary['missing'] + $localSummary['unscanned']],
                        ) }}
                    @endif

                    <ul class="space-y-3">
                        @foreach ($local as $row)
                            <li class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-800">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <div class="min-w-0">
                                        <span class="text-sm font-medium">
                                            {{ $row['title'] ?? $row['folder'] }}
                                        </span>

                                        <span class="ms-2 text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $row['folder'] }}
                                        </span>
                                    </div>

                                    <div class="flex items-center gap-2 text-xs">
                                        @if ($row['on_disk'] && $row['known_to_server'])
                                            <span class="text-emerald-600 dark:text-emerald-400">{{ __('On disk and read') }}</span>
                                        @elseif ($row['on_disk'])
                                            <span class="text-amber-600 dark:text-amber-400">{{ __('On disk, not read yet') }}</span>
                                        @else
                                            <span class="text-red-600 dark:text-red-400">{{ __('Listed by the server, folder gone') }}</span>
                                        @endif

                                        @if ($row['manga_id'] !== null)
                                            <a
                                                href="{{ route('series', ['manga' => $row['manga_id']]) }}"
                                                wire:navigate
                                                class="text-vermilion-600 hover:underline dark:text-vermilion-400"
                                            >
                                                {{ __('Open') }}
                                            </a>
                                        @endif
                                    </div>
                                </div>

                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    @if ($row['has_archive'])
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ __('CBZ present') }}
                                        </span>
                                    @elseif ($row['on_disk'])
                                        <span class="text-xs text-amber-600 dark:text-amber-400">
                                            {{ __('No CBZ in the folder') }}
                                        </span>
                                    @endif

                                    @if ($row['has_comic_info'])
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ __('ComicInfo.xml') }}
                                        </span>
                                    @endif

                                    @foreach ($row['files'] as $file)
                                        <span
                                            @class([
                                                'rounded px-1.5 py-0.5 text-xs',
                                                'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => $file['kind'] === 'other',
                                            ])
                                            title="{{ Number::format($file['bytes'] / 1024, precision: 1) }} KB"
                                        >
                                            {{ $file['name'] }}
                                        </span>
                                    @endforeach
                                </div>

                                @if ($row['metadata'] !== [])
                                    <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                        @foreach ($row['metadata'] as $field => $value)
                                            <div class="flex gap-1">
                                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __($field) }}</dt>
                                                <dd>{{ $value }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </flux:card>
        @endif
    </section>

    {{-- Stores --------------------------------------------------------- --}}
    <section class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <flux:heading level="2">{{ __('Stores') }}</flux:heading>

            <div class="flex flex-wrap items-center gap-2">
                <flux:button size="sm" wire:click="refresh" wire:loading.attr="disabled" icon="arrow-path">
                    {{ __('Refresh catalogue') }}
                </flux:button>

                <flux:button size="sm" wire:click="syncSources" wire:loading.attr="disabled" icon="arrows-right-left">
                    {{ __('Sync sources') }}
                </flux:button>
            </div>
        </div>

        @if ($loadProblem !== null)
            {{--
                The failure of the automatic load, and the recovery, in the
                place the reader is looking: toasts vanish and this is where
                their eyes already are while they read a signing key.
            --}}
            <flux:callout variant="warning" icon="exclamation-triangle">
                {{ __('Papyrus could not load the catalogue. Refresh catalogue above to try again.') }}
            </flux:callout>
        @endif

        @if ($this->stores->isEmpty())
            <flux:callout variant="secondary" icon="circle-stack">
                @can('manage-install')
                    {{ __('No store is registered yet. Add one below to begin.') }}
                @else
                    {{ __('No store is registered yet. The person who owns this server can add one.') }}
                @endcan
            </flux:callout>
        @else
            <ul class="space-y-3">
                @foreach ($this->stores as $store)
                    @php($isTrusted = in_array($store->id, $this->trustedIds, true))

                    <li data-tour="store-card">
                        <flux:card class="gap-3 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium">{{ $store->name }}</span>

                                        <flux:badge size="sm" :color="$store->isProtobuf() ? 'zinc' : 'orange'">
                                            {{ $store->isProtobuf() ? __('protobuf') : __('legacy JSON') }}
                                        </flux:badge>

                                        @if ($isTrusted)
                                            <flux:badge size="sm" color="green" icon="check">{{ __('trusted') }}</flux:badge>
                                        @endif
                                    </div>

                                    <p class="mt-1 text-xs break-all text-zinc-500 dark:text-zinc-400">
                                        {{ $store->index_url }}
                                    </p>
                                </div>

                                <div class="flex shrink-0 items-center gap-2">
                                    @if ($isTrusted)
                                        <flux:button size="sm" wire:click="revokeTrust({{ $store->id }})">
                                            {{ __('Withdraw trust') }}
                                        </flux:button>
                                    @else
                                        <flux:button size="sm" variant="primary" wire:click="trust({{ $store->id }})">
                                            {{ __('Trust this key') }}
                                        </flux:button>
                                    @endif

                                    @can('manage-install')
                                        @php($removeStoreAction = 'removeStore('.Illuminate\Support\Js::from($store->index_url)->toHtml().')')

                                        <x-confirm
                                            :action="$removeStoreAction"
                                            :title="__('Remove this store from the server?')"
                                            :body="__('Its trust records go too.')"
                                            :confirm="__('Remove')"
                                        >
                                            <flux:button size="sm" color="red">
                                                {{ __('Remove') }}
                                            </flux:button>
                                        </x-confirm>
                                    @endcan
                                </div>
                            </div>

                            {{--
                                The signing key is the whole point of the trust
                                step, so it is shown in full, in a monospace font,
                                selectable, and able to break. A truncated
                                fingerprint cannot be compared against whatever
                                the publisher says elsewhere.
                            --}}
                            <div class="rounded-md bg-zinc-50 p-3 dark:bg-white/5">
                                <p class="text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">
                                    {{ __('Signing key') }}
                                </p>

                                @if ($store->signing_key)
                                    <p class="mt-1 font-mono text-xs break-all select-all">{{ $store->signing_key }}</p>
                                @else
                                    <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                        {{ __('This store publishes no signing key.') }}
                                    </p>
                                @endif

                                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    @if ($store->last_synced_at)
                                        {{ __('Last mirrored :when.', ['when' => $store->last_synced_at->diffForHumans()]) }}
                                    @else
                                        {{ __('Never mirrored from the server.') }}
                                    @endif
                                </p>
                            </div>
                        </flux:card>
                    </li>
                @endforeach
            </ul>
        @endif

        @can('manage-install')
            <form wire:submit="addStore" data-tour="add-store" class="flex flex-wrap items-end gap-3">
                <div class="min-w-72 flex-1">
                    <flux:input
                        wire:model="storeUrl"
                        :label="__('Add a store')"
                        placeholder=""
                        icon="link"
                        required
                    />

                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('Papyrus does not ship with any store. Search for an extension repository you trust, paste its index URL here, and compare its signing key before you trust it.') }}
                    </p>

                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('The index file itself, not the repository root. It is usually named index.pb or index.min.json.') }}
                    </p>
                </div>

                <flux:button type="submit" variant="primary" icon="plus" wire:target="addStore" wire:loading.attr="disabled">
                    {{ __('Add store') }}
                </flux:button>
            </form>
        @endcan
    </section>

    {{-- Extensions ------------------------------------------------------ --}}
    <section class="space-y-4">
        <flux:heading level="2">{{ $view === 'migrate' ? __('Move your series') : __('Extensions') }}</flux:heading>

        <div class="flex flex-wrap items-center justify-between gap-4">
            {{--
                A button group rather than a tab component: this version of Flux
                ships no tabs, and a segmented button strip reads the same way to
                someone who is choosing between four lists.
            --}}
            <flux:button.group>
                @foreach ([
                    'installed' => __('Installed'),
                    'available' => __('Available'),
                    'updates' => __('Updates'),
                    'all' => __('All'),
                    'migrate' => __('Migrate'),
                ] as $key => $label)
                    <flux:button
                        :variant="$view === $key ? 'primary' : 'outline'"
                        wire:click="setView('{{ $key }}')"
                        aria-current="{{ $view === $key ? 'page' : 'false' }}"
                        :data-tour="$key === 'available' ? 'available' : null"
                    >
                        {{ $label }}
                    </flux:button>
                @endforeach
            </flux:button.group>

            {{--
                The name filter belongs to the catalogue, so it stands down on the
                Migrate view: a filter that does nothing to the list under it is
                a control a reader would distrust.
            --}}
            @if ($view !== 'migrate')
                <flux:input
                    wire:model.live.debounce.400ms="name"
                    placeholder="{{ __('Filter by name') }}"
                    aria-label="{{ __('Filter by name') }}"
                    icon="funnel"
                    class="max-w-xs"
                />
            @endif

            {{--
                One press for the whole update set, which is the reason the
                Updates view exists at all: a community store ships updates constantly,
                and forty of them is not a queue a reader should walk.

                It is also offered from Installed, because that is where a reader
                sees the source list they are actually using. The Updates tab
                already knows its own total; Installed asks for the count so the
                button appears without moving tabs first.
            --}}
            @if (in_array($view, ['installed', 'updates'], true))
                @php($updates = $view === 'updates' ? $this->total : $this->updateCount)

                @if ($updates > 0)
                    <flux:button
                        size="sm"
                        variant="primary"
                        wire:click="updateAll"
                        wire:loading.attr="disabled"
                        icon="arrow-path"
                        data-test="update-all"
                    >
                        {{ __('Update all') }}
                        <span class="ms-1 text-xs tabular-nums opacity-80">{{ Number::format($updates) }}</span>
                    </flux:button>
                @endif
            @endif
        </div>

        @if ($view === 'migrate')
            {{--
                IMG_1179's third strip segment. The rows come from the one tally
                seam the move page's leaving list already reads, so the two pages
                cannot disagree about what the reader holds: one method, two
                renderings.
            --}}
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('The number is how many of your series come from that source. Tap one to start moving them.') }}
            </p>

            @if ($this->holdings['error'] !== null)
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:heading level="3" class="mb-2">{{ __('The library could not be read') }}</flux:heading>

                    <p class="text-sm opacity-80">{{ $this->holdings['error'] }}</p>
                </flux:callout>
            @elseif ($this->holdings['rows']->isEmpty())
                <flux:callout variant="secondary" icon="information-circle">
                    <flux:heading level="3" class="mb-2">{{ __('Nothing to move yet') }}</flux:heading>

                    <p class="text-sm">{{ __('Your library holds no series on this server yet, so there is nothing to move.') }}</p>
                </flux:callout>
            @else
                <ul class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-zinc-900">
                    @foreach ($this->holdings['rows'] as $row)
                        {{--
                            Step 0's verdict: the row is labelled rather than presenting a
                            numeral as a name, and the id rides in the row's detail
                            attribute, where a reader who wants it can still find it.
                        --}}
                        @php($unnamed = $row['named'] === false)

                        <li>
                            <a
                                href="{{ route('migrate.sources', ['compose' => $row['source_id']]) }}"
                                wire:navigate
                                @if ($unnamed) title="{{ __('Source :id', ['id' => $row['source_id']]) }}" @endif
                                class="flex items-center gap-3 px-4 py-3 transition hover:bg-zinc-50 dark:hover:bg-white/[0.04]"
                            >
                                <x-source.icon :path="$row['icon']" size="size-9" />

                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-zinc-800 dark:text-white">
                                        {{ $unnamed ? __('An unnamed source') : $row['display_name'] }}
                                    </span>

                                    @if ($row['lang'] !== '')
                                        <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $row['lang'] }}</span>
                                    @endif
                                </span>

                                <span
                                    class="tabular-nums text-sm text-zinc-500 dark:text-zinc-400"
                                    title="{{ __(':count series', ['count' => Number::format($row['count'])]) }}"
                                >
                                    {{ Number::format($row['count']) }}
                                </span>

                                <flux:icon name="chevron-right" class="size-4 shrink-0 text-zinc-400" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        @else
            {{-- The four catalogue slices. Nothing in here may surface on the Migrate view. --}}

        @if ($view !== 'installed')
            <div class="flex flex-wrap items-center gap-3">
                <flux:label>{{ __('Content') }}</flux:label>

                {{--
                    checkbox.group is a wrapper, so the individual checkboxes
                    nest inside it. Repeating the group per option renders bare
                    label text with no control.

                    The NSFW option is only offered when the gate is on. The
                    rating is a permission and not a scope, and an option that is
                    offered and then does nothing is a control that lies, so
                    the gate's own control sits beside the filter instead.
                --}}
                <flux:checkbox.group wire:model.live="content" variant="pills">
                    <flux:checkbox value="{{ Source::SAFE }}" label="{{ __('Safe') }}" />
                    <flux:checkbox value="{{ Source::MIXED }}" label="{{ __('Mixed') }}" />
                    @if ($this->adultContentOn())
                        <flux:checkbox value="{{ Source::NSFW }}" label="{{ __('NSFW') }}" />
                    @endif
                </flux:checkbox.group>
            </div>
        @endif

        {{--
            The gate, on every slice of this page.

            Its own row because the Installed slice has no Content filter at
            all, and the gate is not a filter: it says whether the rating is in
            scope, and the reader may want to say that from any slice.
        --}}
        <div class="flex flex-wrap items-center gap-3">
            <x-adult-content.gate :enabled="$this->adultContentOn()" />
        </div>

        {{--
            The language selector.

            The owner's ask, 2026-10-19: "add to the UI/UX pass the option to
            select the installable sources by language. Since some sources have
            other languages, it would be good if in the source it could show a
            flag or a language pill, that you can check and uncheck for the
            language source you want to use."

            A row of pills under the Content filter, which is the shape the
            owner chose when the seat was put to them, and the shape the Content
            filter already is: one control, every value visible at once, and
            nothing hidden behind a dropdown.

            A pill says the language and how many sources are in it, because a
            language with three sources in it and a language with three hundred
            are not the same thing to a reader deciding where to look. The
            count is the number under the filters the reader can see, so it
            answers "what does turning this on give me".

            The pill carries the catalogue's own code as its title, because the
            code is what the package name carries and a reader matching a
            source they know by hand needs the code, not the name of the
            language.

            Every language is in the page. At desktop width the row wraps and
            shows all of them, because there is room. Below the lg breakpoint
            the first two are the strip, the rest wait behind Show all, and
            the toggle itself is hidden at desktop width where there is nothing
            left to reveal.

            "Show none" is not a flourish. Switching twenty-two languages off
            one at a time is twenty-two round trips and twenty-two renders of a
            list of a thousand rows, and a reader who reads two languages
            should not pay that. Switching every language off is one press, and
            switching one back on is a second.
        --}}
        @if ($view !== 'migrate' && $this->catalogueLanguages !== [])
            @php($stillOn = array_values(array_diff(array_column($this->catalogueLanguages, 'code'), $this->lang)))
            @php($languageLimit = 2)
            @php($collapsedLanguages = count($this->catalogueLanguages) > $languageLimit && ! $this->showAllLanguages)

            <div class="flex flex-wrap items-center gap-3" data-test="language-strip">
                <flux:label>{{ __('Language') }}</flux:label>

                <span class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400">
                    {{ trans_choice('{1} :count language|[2,*] :count languages', count($this->catalogueLanguages), ['count' => count($this->catalogueLanguages)]) }}
                </span>

                @if (count($this->catalogueLanguages) > $languageLimit)
                    <flux:button size="sm" variant="ghost" wire:click="toggleLanguageRow" data-test="language-strip-toggle">
                        {{ $this->showAllLanguages ? __('Show fewer languages') : __('Show all languages') }}
                    </flux:button>
                @endif

                <div class="w-full max-w-full">
                    <div @class([
                        'overflow-x-auto pb-1 [mask-image:linear-gradient(to_right,black_90%,transparent)]' => $collapsedLanguages,
                    ])>
                        <div @class([
                            'flex items-center gap-1.5',
                            'min-w-max' => $collapsedLanguages,
                            'flex-wrap' => ! $collapsedLanguages,
                        ])>
                    @foreach ($this->catalogueLanguages as $index => $language)
                        @if ($collapsedLanguages && $index >= $languageLimit)
                            @continue
                        @endif
                        <flux:button
                            size="sm"
                            :variant="$language['on'] ? 'outline' : 'ghost'"
                            class="rounded-full!"
                            wire:click="toggleLanguage({{ Illuminate\Support\Js::from($language['code'])->toHtml() }})"
                            aria-pressed="{{ $language['on'] ? 'true' : 'false' }}"
                            title="{{ $language['code'] }}"
                            aria-label="{{ trans_choice(
                                ':language, :count source|:language, :count sources',
                                $language['count'],
                                [
                                    'language' => $language['name'],
                                    'count' => Number::format($language['count']),
                                ],
                            ) }}"
                        >
                            <span @class(['line-through' => ! $language['on']])>{{ $language['name'] }}</span>

                            <span class="text-xs text-zinc-500 dark:text-zinc-400 tabular-nums">
                                {{ Number::format($language['count']) }}
                            </span>
                        </flux:button>
                    @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <span class="text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Switch a language off to leave its sources out of the list. The choice stays when you come back.') }}
                </span>

                @if ($stillOn !== [])
                    <flux:button size="sm" variant="ghost" wire:click="showNoLanguage">
                        {{ __('Show none') }}
                    </flux:button>
                @endif

                @if ($this->lang !== [])
                    <flux:button size="sm" variant="ghost" wire:click="showEveryLanguage">
                        {{ __('Show every language') }}
                    </flux:button>
                @endif
            </div>
        @endif

        {{--
            Every extension in a store inherits that store's trust, so with one
            untrusted store this page is 999 rows of lock icons. The gate stays,
            but the way past it has to be obvious, so it is named here rather than
            left for the reader to infer from a wall of padlocks.
        --}}
        @if ($this->stores->isNotEmpty() && $this->trustedIds === [])
            <flux:callout variant="primary" icon="lock-closed">
                <flux:heading level="3" class="mb-1">{{ __('Nothing is installable until you trust a store') }}</flux:heading>
                <p class="text-sm">
                    {{ trans_choice(
                        '{1} :count store is registered. Compare its signing key above against the one its publisher has published, then trust it.|[2,*] :count stores are registered. Compare their signing keys above against the ones their publishers have published, then trust them.',
                        $this->stores->count(),
                        ['count' => $this->stores->count()],
                    ) }}
                </p>
            </flux:callout>
        @endif

        @can('manage-install')
            @if ($this->selected !== [])
                <flux:callout variant="primary" icon="check">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <span class="text-sm">
                            {{ trans_choice('{1} :count selected|[2,*] :count selected', count($this->selected), ['count' => count($this->selected)]) }}
                        </span>

                        <div class="flex items-center gap-2">
                            <flux:button size="sm" wire:click="$set('selected', [])">{{ __('Clear') }}</flux:button>

                            <flux:button size="sm" variant="primary" wire:click="installSelected" icon="arrow-down-tray">
                                {{ __('Install selected') }}
                            </flux:button>
                        </div>
                    </div>
                </flux:callout>
            @endif
        @endcan

        {{--
            Install failures are listed rather than toasted. The user is about to
            act on "it says installed", and a package that silently did not
            install is worse than a visible failure.
        --}}
        @if ($this->failures !== [])
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:heading level="3" class="mb-2">{{ __('These did not install') }}</flux:heading>

                <ul class="space-y-2 text-sm">
                    @foreach ($this->failures as $pkg => $reason)
                        <li>
                            <span class="font-mono text-xs">{{ $pkg }}</span>
                            <span class="block text-xs opacity-80">{{ $reason }}</span>
                        </li>
                    @endforeach
                </ul>
            </flux:callout>
        @endif

        @if ($this->catalogue['error'] === null)
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ trans_choice('{1} :count extension|[2,*] :count extensions', $this->total, ['count' => Number::format($this->total)]) }}
            </p>
        @endif

        @if ($this->catalogue['error'] === null && $this->catalogue['hidden'] > 0)
            {{--
                What the gate is holding back, named.

                The count above is the count of what is listed. Without this
                line the page would read "1 extension" on a server that holds
                two, which is the same lie as an empty library reading as an
                empty one.
            --}}
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ trans_choice(
                    '{1} :count of the installed extensions is marked NSFW and is hidden.|[2,*] :count of the installed extensions are marked NSFW and are hidden.',
                    $this->catalogue['hidden'],
                    ['count' => Number::format($this->catalogue['hidden'])],
                ) }}
            </p>
        @endif

        @if ($this->catalogue['error'] === null && $this->autoloadNoteStillHolds())
            {{--
                The automatic load's reply was accepted upstream but named zero,
                which reads on the count above exactly like a failure. This
                line is the distinction the reader cannot know otherwise, and
                autoloadNoteStillHolds judges it fresh each render so a filled
                catalogue retires it on the next look.
            --}}
            <flux:callout variant="secondary" icon="arrow-path">
                {{ __('The catalogue was refreshed, but this store has no extensions to show yet. Refresh catalogue above to check again.') }}
            </flux:callout>
        @endif

        @if ($this->catalogue['error'] !== null)
            {{--
                One read serves the count above and the list below, so a failure is
                one fact told once. Drawing an empty catalogue over an unreadable
                server would be a lie twice: "0 extensions" is the same lie as
                "Nothing installed yet", with a number on it.
            --}}
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:heading class="mb-1">{{ __('The extension list could not be loaded') }}</flux:heading>
                <p class="text-sm">{{ __('Refresh catalogue above to try again.') }}</p>
            </flux:callout>
        @elseif ($this->extensions->isEmpty())
            {{--
                The automatic load runs inside addStore or trust, so the request
                that fetches the catalogue is also the request that fills this
                slot. Livewire's own loading state is therefore the only honest
                progress there is: it is shown for as long as the request is in
                flight, and the result arrives in the same response. A count in
                the middle of it would be invented.
            --}}
            <div wire:loading.remove wire:target="addStore,trust,refresh">
                <flux:callout variant="secondary" icon="magnifying-glass">
                    @if ($this->stores->isEmpty())
                        {{ __('Add a store first, then refresh the catalogue.') }}
                    @elseif ($view === 'installed')
                        @if ($this->catalogue['hidden'] > 0)
                            {{--
                                Not "nothing installed". What is installed is there and
                                the gate is holding it back, and saying the wrong one
                                of those sends the reader off to install a source they
                                already have.
                            --}}
                            {{ trans_choice(
                                '{1} The :count extension installed is marked NSFW and is hidden until you turn adult content on.|[2,*] The :count extensions installed are marked NSFW and are hidden until you turn adult content on.',
                                $this->catalogue['hidden'],
                                ['count' => Number::format($this->catalogue['hidden'])],
                            ) }}
                        @else
                            {{ __('Nothing installed yet. Open the Available tab and install a source to get started.') }}
                        @endif
                    @elseif ($view === 'updates')
                        {{ __('Nothing to update. Every installed extension is on the version its store ships.') }}
                    @elseif ($this->lang !== [])
                        {{-- The reader's own choice, named as their own choice. --}}
                        {{ __('Every language is switched off, so there is nothing to list. Switch one on, or put them all back.') }}
                    @else
                        {{ __('Nothing matches. Try a shorter name or widen the content filter.') }}
                    @endif
                </flux:callout>
            </div>

            @if ($view === 'available')
                <div wire:loading wire:target="addStore,trust,refresh">
                    <flux:callout variant="secondary" icon="arrow-path">
                        {{ __('Loading the catalogue...') }}
                    </flux:callout>
                </div>
            @endif
        @else
            @if ($view === 'available')
                <p class="mb-2 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('These are the extensions this store offers. Install one to start reading.') }}
                </p>
            @endif
            {{--
                Cards, three to five to a row, on the same breakpoints as the library,
                browse and search grids. This was the second full-width list on this page,
                which is what made the page taller than it had anything to say, and every
                row was a name, a badge and a package name with nothing to look at.
            --}}
            <ul
                @if ($view === 'available') data-tour="available-grid" @endif
                class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5"
            >
                @foreach ($this->extensions as $extension)
                    @php($installed = ($extension['isInstalled'] ?? false) === true)

                    {{--
                        `iconUrl` has been in this query's selection set since the
                        catalogue was first mirrored. Drawn here for the first time.

                        The checkbox moves into the corner. In a full-width row a spacer
                        held the column still for the installed case, and in a card there
                        is no shared column to hold and the hole read as something
                        missing.
                    --}}
                    <li class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-4 dark:border-white/10">
                        <div class="flex items-start gap-3">
                            <x-source.icon :path="$extension['iconUrl'] ?? null" size="size-9 sm:size-10" />

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{ $extension['name'] }}</span>

                                    @if (($extension['lang'] ?? null) !== null)
                                        {{--
                                            The language, named rather than coded.

                                            The code is the catalogue's own and it is
                                            what the package name carries, so it
                                            stays on the badge's title for anyone
                                            matching a source they know by hand.
                                            The name is what a reader thinks in, and
                                            the selector above says the same thing
                                            for the same language, so the two
                                            cannot be read as two different
                                            languages.
                                        --}}
                                        <flux:badge
                                            size="sm"
                                            color="zinc"
                                            title="{{ $extension['lang'] }}"
                                        >{{ LanguageName::name((string) $extension['lang']) }}</flux:badge>
                                    @endif

                                    @if (($extension['contentWarning'] ?? null) === Source::NSFW)
                                        <flux:badge size="sm" color="red" icon="exclamation-triangle">{{ __('NSFW') }}</flux:badge>
                                    @endif

                                    @if ($extension['hasUpdate'] ?? false)
                                        <flux:badge size="sm" color="orange" icon="arrow-up">{{ __('update') }}</flux:badge>
                                    @endif

                                    @if ($extension['isObsolete'] ?? false)
                                        <flux:badge size="sm" color="zinc">{{ __('obsolete') }}</flux:badge>
                                    @endif
                                </div>

                                {{--
                                    The version leads because it is the part a reader actually
                                    compares, and the package name wraps rather than ellipses: it is
                                    the card's identity, and cutting an identity short says nothing
                                    instead. break-all because the id is a dot lattice with no
                                    word boundaries to break on.
                                --}}
                                <p class="mt-1 font-mono text-xs text-zinc-500 dark:text-zinc-400">
                                    @if (($extension['versionName'] ?? null) !== null)
                                        <span class="font-sans">{{ $extension['versionName'] }}</span>
                                    @endif

                                    <span class="break-all">{{ $extension['pkgName'] }}</span>
                                </p>

                            </div>

                            @unless ($installed)
                                @can('manage-install')
                                    {{--
                                        The tick that selects an extension for a bulk
                                        install, named so a screen reader says what
                                        it is for. The house's shape for a selection
                                        is "Select <thing>" (the library's own bulk
                                        checkbox says "Select <title>"), and here the
                                        selection is for an install, so it says that.
                                    --}}
                                    <flux:checkbox
                                        wire:model="selected"
                                        :value="$extension['pkgName']"
                                        class="shrink-0"
                                        aria-label="{{ __('Select :name to install', ['name' => $extension['name']]) }}"
                                    />
                                @endcan
                            @endunless
                        </div>

                        {{--
                            `mt-auto` so the buttons line up across a row of cards that do
                            not all carry the same amount of text, and `flex-wrap` because
                            "Update" and "Uninstall" do not both fit two columns on a
                            phone.
                        --}}
                        <div class="mt-auto flex flex-wrap items-center gap-2 pt-1">
                            @if ($installed)
                                @if (($extension['hasUpdate'] ?? false) && $this->isTrusted($extension))
                                    <flux:button
                                        size="sm"
                                        variant="primary"
                                        wire:click="update({{ Illuminate\Support\Js::from($extension['pkgName']) }})"
                                        wire:loading.attr="disabled"
                                        icon="arrow-up-circle"
                                    >
                                        {{ __('Update') }}
                                    </flux:button>
                                @elseif (($extension['hasUpdate'] ?? false))
                                    {{-- Say why rather than showing a control that cannot work. --}}
                                    <flux:tooltip :content="__('Trust this extension’s store before updating it.')">
                                        <flux:icon name="lock-closed" class="text-zinc-400" />
                                    </flux:tooltip>
                                @endif

                                @can('manage-install')
                                    @php($uninstallAction = 'uninstall('.Illuminate\Support\Js::from($extension['pkgName'])->toHtml().')')

                                    <x-confirm
                                        :action="$uninstallAction"
                                        :title="__('Uninstall this extension?')"
                                        :body="__('Its source stops being searchable.')"
                                        :confirm="__('Uninstall')"
                                    >
                                        <flux:button size="sm">
                                            {{ __('Uninstall') }}
                                        </flux:button>
                                    </x-confirm>
                                @endcan
                            @else
                                @can('manage-install')
                                    @if ($this->isTrusted($extension))
                                        <flux:button
                                            size="sm"
                                            variant="primary"
                                            wire:click="install({{ Illuminate\Support\Js::from($extension['pkgName']) }})"
                                            wire:loading.attr="disabled"
                                            icon="arrow-down-tray"
                                        >
                                            {{ __('Install') }}
                                        </flux:button>
                                    @else
                                        {{-- Say why rather than showing a control that cannot work. --}}
                                        <flux:tooltip :content="__('Trust this extension’s store before installing it.')">
                                            <flux:icon name="lock-closed" class="text-zinc-400" />
                                        </flux:tooltip>
                                    @endif
                                @endcan
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="flex items-center justify-between gap-4">
                {{--
                    :disabled rather than @disabled. The directive form is for
                    plain HTML elements and corrupts a component tag into invalid
                    PHP, so the attribute binding is the correct spelling here.
                --}}
                <flux:button size="sm" wire:click="previousPage" :disabled="$page <= 1" icon="chevron-left">
                    {{ __('Previous') }}
                </flux:button>

                <span class="text-sm tabular-nums text-zinc-500 dark:text-zinc-400">
                    {{ __('Page :page of :last', ['page' => $page, 'last' => $this->lastPage]) }}
                </span>

                <flux:button size="sm" wire:click="nextPage" :disabled="$page >= $this->lastPage" icon="chevron-right">
                    {{ __('Next') }}
                </flux:button>
            </div>
        @endif
        @endif
    </section>

    {{--
        The install warning.

        One dialog for the whole page rather than one per card, for the reason the source
        chooser in ⚡search.blade.php carries: the dialog belongs to the press, not to the
        card, and the rows it names are whichever flagged names that press carried.

        Opened from the response rather than from the click, which is the race the chooser's
        comment records in the other direction: the Livewire round trip that arms the pending
        names has to land before anything opens on them, so the root div re-dispatches Flux's
        own `modal-show` when the actions dispatch `open-install-warning`. The confirm is a
        plain wire button rather than `flux:modal.close`, so it runs as one request that
        installs, clears the pending names and dispatches `close-install-warning`; going
        through Flux's close would race that clear against a second wire call reading the
        same names. Every other way out of the dialog (cancel, esc, backdrop, the x) fires
        `@close`, which empties the pending names with nothing installed.

        Owner, 2026-10-17: the press on one of these sources is where the warning belongs.
        The rows read the site's sentences straight from the config through noticeFor(): the one
        place the conduct copy lives, since the owner had the notice block removed from the cards
        (2026-10-17) after it warped the layout beside it. Confirming installs the pending names.
    --}}
    <flux:modal
        name="install-warning"
        class="max-w-md"
        @close="closeInstallWarning"
    >
        <div class="flex items-start gap-4">
            <span
                class="mt-0.5 flex size-10 flex-none items-center justify-center rounded-xl bg-vermilion-600/10 text-vermilion-600 dark:bg-vermilion-500/15 dark:text-vermilion-400"
                aria-hidden="true"
            >
                <flux:icon name="exclamation-triangle" class="size-5" />
            </span>

            <div class="min-w-0">
                @if ($this->pendingInstall !== [])
                    <flux:heading size="lg">
                        @if (count($this->pendingInstall) > 1)
                            {{ __('These sources are tricky and risky') }}
                        @else
                            {{ __('This source is tricky and risky') }}
                        @endif
                    </flux:heading>
                @endif

                <div class="mt-2 space-y-2.5">
                    @foreach ($this->warningRows() as $row)
                        <p class="flex items-start gap-2 text-sm leading-5 text-zinc-600 dark:text-zinc-400">
                            <flux:icon name="{{ $row['icon'] }}" class="mt-0.5 size-4 shrink-0" />

                            <span>
                                <strong class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $row['name'] }}</strong>

                                {{ $row['text'] }}
                            </span>
                        </p>
                    @endforeach
                </div>

                @if ($this->pendingClean !== [])
                    <flux:subheading class="mt-3">
                        {{ trans_choice(
                            'The install carries the :count other selected extension with it.|The install carries the :count other selected extensions with it.',
                            count($this->pendingClean),
                            ['count' => count($this->pendingClean)],
                        ) }}
                    </flux:subheading>
                @endif

                <flux:subheading class="mt-3">
                    {{ __('Nothing has installed yet. It installs on the next press, and cancelling leaves everything as it is.') }}
                </flux:subheading>
            </div>
        </div>

        <div class="mt-7 flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Never mind') }}</flux:button>
            </flux:modal.close>

            <flux:button
                variant="danger"
                wire:click="installConfirmed"
                wire:loading.attr="disabled"
            >
                {{ __('Install anyway') }}
            </flux:button>
        </div>
    </flux:modal>
</div>
