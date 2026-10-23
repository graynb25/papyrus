<?php

use App\Local\LocalLibrary;
use App\Livewire\AdultContentPage;
use App\Models\ExtensionStore;
use App\Models\Source;
use App\Models\UserSource;
use App\Support\LanguageName;
use App\Support\Library;
use App\Support\SourceNotices;
use App\Suwayomi\ExtensionManager;
use App\Suwayomi\SourceCatalog;
use App\Suwayomi\SuwayomiException;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new #[Title('Sources')] class extends AdultContentPage
{
    public string $storeUrl = '';

    /**
     * Which slice of the catalogue to show.
     *
     * Installed is the default rather than everything, because the Keiyoushi
     * store carries 1,379 extensions and "everything" is a number, not a view.
     */
    #[Url(as: 'view', except: 'installed')]
    public string $view = 'installed';

    /**
     * The languages the reader has switched off.
     *
     * ## Why the list is what is left out and not what is wanted
     *
     * The catalogue the Keiyoushi store ships is one extension per language,
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

    /**
     * The source families the reader has open.
     *
     * ## Why a memory and not just a closed page
     *
     * Every group starts closed, because a group of sixty-one language
     * variants pushes the page down by more than a screen and the reader
     * asked for it that way. But a reader who opens MangaDex every time they
     * come here should not have to open it every time they come here, and
     * the choice is the reader's own, so it is remembered.
     *
     * It is remembered in the URL, the same way the catalogue's slice, the
     * content filter and the language choice are, because that is this
     * page's own seam for state a reader chose. A link to the page carries
     * the families open, and the reader's own back button carries it too.
     *
     * The key is the site's host, the same key the family is grouped by, so
     * the memory cannot name a family that is not on the page.
     *
     * @var array<int, string>
     */
    #[Url(as: 'open', except: '')]
    public array $openFamilies = [];

    public string $name = '';

    public int $page = 1;

    public const PER_PAGE = 50;

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
     * @var array<int, string>
     */
    public array $pendingInstall = [];

    /**
     * The rest of a bulk selection waiting behind the warning. Confirm installs
     * them together with the flagged ones, because that is the request the press
     * stood for; closing the dialog installs nothing.
     *
     * @var array<int, string>
     */
    public array $pendingClean = [];

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
         * the reader rather than to a list of what happens to be present. Keiyoushi
         * currently ships no NSFW extension among the installed set, so this
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
        $this->validate(['storeUrl' => ['required', 'string', 'url', 'max:500']]);

        try {
            $store = app(SourceCatalog::class)->addStore(trim($this->storeUrl));
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
    }

    public function removeStore(string $indexUrl): void
    {
        try {
            app(SourceCatalog::class)->removeStore($indexUrl);
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
        } catch (SuwayomiException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

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
        $count = app(SourceCatalog::class)->refreshExtensions();
        app(SourceCatalog::class)->syncStores();

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
            $holds = app(ExtensionManager::class)->extensionCount();
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
            $result = app(SourceCatalog::class)->syncAll();
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

        app(ExtensionManager::class)->trust(auth()->user(), $store);

        Flux::toast(
            text: __('You can now install extensions signed by this key.'),
            heading: __('Store trusted'),
            variant: 'success',
        );

        $this->loadCatalogueWhenTheServerHasNone();
    }

    /**
     * The second automatic load, for the reader who arrives at trust first.
     *
     * That shape is reachable from both directions: the store was added on a
     * machine whose fetch failed at add time, or it was mirrored earlier while
     * the server still held nothing (this checkout's own reader trusted
     * Keiyoushi days before its catalogue was ever fetched). Trusting is then
     * the first action left, so trust fetches, but only then. A server that
     * already holds the catalogue is left alone: re-reading more than a
     * thousand extension rows to announce a trust state spends upstream for
     * nothing, and the pressed button keeps doing its own full run.
     */
    private function loadCatalogueWhenTheServerHasNone(): void
    {
        try {
            $known = app(ExtensionManager::class)->extensionCount();
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

    /**
     * The site behaviour notice for one extension, or null.
     *
     * The kind comes from App\Support\SourceNotices, so the day a site's
     * behaviour changes there is one file to correct and the facts outlive the
     * wording; what a reader is told lives here with the page's other copy.
     *
     * @param  array<string, mixed>  $extension
     * @return array{icon: string, text: string}|null
     */
    public function noticeFor(array $extension): ?array
    {
        $kind = SourceNotices::kind((string) ($extension['name'] ?? ''));

        return $kind === null ? null : [
            'icon' => $kind === SourceNotices::BAN ? 'exclamation-triangle' : 'arrow-path',
            'text' => $kind === SourceNotices::BAN
                ? __('This site is known to block readers that search aggressively. Space searches out or pin it; expect long bans, not errors you can retry away.')
                : __('Searches from extensions here come back scrambled and pages can arrive broken. It does not ban: it sends you back to its own ad-laden site.'),
        ];
    }

    public function revokeTrust(int $storeId): void
    {
        $store = ExtensionStore::query()->find($storeId);

        if ($store === null) {
            return;
        }

        app(ExtensionManager::class)->revokeTrust(auth()->user(), $store);

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
            app(SourceCatalog::class)->syncSources();
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
        if (! $this->canInstallName($pkgName)) {
            return;
        }

        if ($this->noticeFor($this->rowFor($pkgName) ?? []) !== null) {
            $this->pendingInstall = [$pkgName];
            $this->pendingClean = [];

            $this->dispatch('open-install-warning');

            return;
        }

        $this->installNames([$pkgName]);
    }

    public function uninstall(string $pkgName): void
    {
        try {
            app(ExtensionManager::class)->setInstalled($pkgName, false);
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
        if (! $this->canUpdateName($pkgName)) {
            return;
        }

        try {
            app(ExtensionManager::class)->update($pkgName);
        } catch (SuwayomiException $e) {
            // Listed as well as toasted, on the same reasoning as the bulk
            // failures: the reader is about to wonder whether it moved.
            $this->failures = [$pkgName => $e->getMessage()];

            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        $this->failures = [];

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
        $targets = app(ExtensionManager::class)
            ->extensions(['has_update' => true, 'installed' => true], 500, 0)
            ->filter(fn (array $row): bool => $this->isTrusted($row))
            ->pluck('pkgName')
            ->all();

        if ($targets === []) {
            Flux::toast(
                text: __('Nothing has an update, or every store behind one is untrusted.'),
                heading: __('Nothing to update'),
                variant: 'info',
            );

            return;
        }

        $result = app(ExtensionManager::class)->updateMany($targets);

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
        $allowed = $this->installableFromSelection();

        $this->reset(['selected', 'failures']);

        if ($allowed === []) {
            return;
        }

        $flagged = $this->flaggedAmong($allowed);

        if ($flagged !== []) {
            $this->pendingInstall = $flagged;
            $this->pendingClean = array_values(array_diff($allowed, $flagged));

            $this->dispatch('open-install-warning');

            return;
        }

        $this->installNames($allowed);
    }

    /**
     * The press after the warning. The pending names install straight through with
     * no second guard: the ask already checked each row before arming, and asking
     * the same question twice on one press is how a warning becomes furniture.
     */
    public function installConfirmed(): void
    {
        $names = array_values(array_merge($this->pendingInstall, $this->pendingClean));

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
     * @param array<int, string> $names
     */
    private function installNames(array $names): void
    {
        if (count($names) === 1) {
            try {
                app(ExtensionManager::class)->setInstalled($names[0], true);
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

            return;
        }

        $result = app(ExtensionManager::class)->installMany($names);
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
     * The strip's views: the four slices of the extension catalogue, and after
     * them the Migrate view, which is not a slice but reads at the same width.
     *
     * Built here rather than inline in the template because the template needs a
     * plain PHP string to interpolate into a component tag's attribute. Blade
     * compiles `{{ }}` inside one but does not compile `@js()`, which is why the
     * earlier version of this page threw "Invalid or unexpected token" in Alpine
     * the moment any of these controls was clicked.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function views(): array
    {
        return [
            'installed' => (string) __('Installed'),
            'available' => (string) __('Available'),
            'updates' => (string) __('Updates'),
            'all' => (string) __('All'),
            /*
             * Last: the four above are one family (slices of the catalogue) and
             * their order is muscle memory. Migrate is another kind of thing the
             * strip reaches, and at the end it does not disturb the four.
             */
            'migrate' => (string) __('Migrate'),
        ];
    }

    /**
     * The Migrate view's rows: what the reader holds, per source.
     *
     * ## Why there is no catch here
     *
     * The lens deliberately does not throw when the server is unreachable: the
     * batch call records the failure and returns what it can, which is the same
     * measured choice that keeps a reader's 867 titles from becoming an error
     * page, and `sourceFailure()` hands the reason out. The reason and the rows
     * come back together, and the view draws exactly one of them.
     *
     * @return array{rows: \Illuminate\Support\Collection<int, array{source_id: string, display_name: string, lang: string, count: int, installed: bool, named: bool, icon: string|null}>, error: string|null}
     */
    #[Computed]
    public function holdings(): array
    {
        // One instance: the failure is a fact about the read that happened on it.
        $library = app(Library::class);
        $rows = $library->holdingsBySource(auth()->id());

        return ['rows' => $rows, 'error' => $library->sourceFailure()];
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
        return app(LocalLibrary::class)->visible();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function localRows(): array
    {
        return app(LocalLibrary::class)->rows();
    }

    /**
     * @return array{series: int, on_disk: int, missing: int, unscanned: int, folders: int}
     */
    #[Computed]
    public function localSummary(): array
    {
        return app(LocalLibrary::class)->summary();
    }

    /**
     * Installed extensions whose sources are not in the mirror yet.
     *
     * The picker is built from the local `Source` table, and nothing else on this page
     * reads the server's source list, so a source can be installed and browsable while the
     * picker cannot show it. Mirroring after Papyrus's own installs closes the common case,
     * but not the one that actually produced the report: Suwayomi has an extension
     * interface of its own, this page's footer tells readers to deal with Suwayomi
     * directly, and an extension installed there produces no event Papyrus ever sees.
     *
     * So the page also says what it knows is missing, which is the only half that can catch
     * an install Papyrus did not perform.
     *
     * Asked of the extension list rather than of the source count on purpose. Both are one
     * query, but `sources { totalCount }` measured a 185ms median against 29.8ms for the
     * filtered extension list, and the extension list names the extensions, so the notice
     * can say which ones rather than how many.
     *
     * Empty when the server cannot be asked. A notice claiming sources are missing, printed
     * because the server was unreachable, is a lie with a button on it.
     *
     * @return array<int, array{name: string, pkg: string}>
     */
    #[Computed]
    public function unmirroredExtensions(): array
    {
        try {
            $installed = app(ExtensionManager::class)->extensions(['installed' => true], 200, 0);
        } catch (SuwayomiException) {
            return [];
        }

        if ($installed->isEmpty()) {
            return [];
        }

        $mirrored = Source::query()
            ->installed()
            ->pluck('extension_pkg_name')
            ->filter()
            ->all();

        $missing = [];

        foreach ($installed as $extension) {
            $pkg = (string) ($extension['pkgName'] ?? '');

            if ($pkg === '' || in_array($pkg, $mirrored, true)) {
                continue;
            }

            /*
             * An adult rated extension the gate is holding back is not drift.
             *
             * The notice is about the mirror's lag: the server holds something
             * this page has not caught up with yet. While the gate is off the
             * extension is held back as well, and the count under the
             * catalogue already says how many are, so naming it here too would
             * be two notices about one extension saying different things. The
             * notice fires again the moment the gate is on, which is when the
             * lag starts to matter.
             */
            if (! $this->adultContentOn() && ($extension['contentWarning'] ?? null) === Source::NSFW) {
                continue;
            }

            $missing[] = ['name' => (string) ($extension['name'] ?? $pkg), 'pkg' => $pkg];
        }

        return $missing;
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
        $ids = $this->cardSourceIds($site);

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
        UserSource::setPinnedFor(auth()->id(), $this->cardSourceIds($site), false);

        unset($this->pinnedIds);

        Flux::toast(
            text: __('Search is back to every source that can run.'),
            heading: __('Pin cleared'),
            variant: 'info',
        );
    }

    /**
     * The sources behind one card, keyed the way {@see browseSites()} keys its
     * cards: the home_url's bare host, lowercased, and the local bucket for a
     * source with none. The pin toggles and the badge all ask through this one
     * derivation, so a card's write and its read can never disagree.
     *
     * @return array<int, int>
     */
    private function cardSourceIds(string $site): array
    {
        $host = $site === 'local' ? null : strtolower($site);

        return Source::query()
            ->installed()
            ->get()
            ->filter(fn (Source $source): bool => $this->hostOf($source->home_url) === $host)
            ->pluck('suwayomi_source_id')
            ->all();
    }

    /**
     * The sources this reader has pinned, as Suwayomi ids.
     *
     * One query rather than one per card, and the read lives on the model, so this
     * badge, the health badges and the fanout's rule are all reading the same rows.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function pinnedIds(): array
    {
        return UserSource::pinnedSourceIdsFor((int) auth()->id());
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
        $pinned = $this->pinnedIds;

        if ($pinned === []) {
            return false;
        }

        return array_intersect($this->cardSourceIds($site), $pinned) !== [];
    }

    /**
     * Installed sources grouped by the site they belong to, for the browse picker.
     *
     * Grouped by `home_url` for the same reason the health dashboard groups by it: 82
     * sources are really 16 extensions, and 61 of those 82 are MangaDex language variants.
     * A flat list would be one scanlation site followed by 61 rows of MangaDex, which is
     * the problem this grouping exists to solve rather than to describe.
     *
     * The group name prefers the site's own name over its host, because "Asura Scans"
     * reads better than "asurascans.com" and the host is shown underneath for anyone who
     * wants to know which domain they are about to trust.
     *
     * Only installed sources appear. A browse page 404s on an uninstalled one, so listing
     * it would offer a link that cannot work.
     *
     * @return array<int, array{site: string, host: string|null, sources: array<int, array<string, mixed>>}>
     */
    /**
     * The host to group a source under, or null when it has none worth grouping by.
     *
     * Parsed, not stripped. The first version removed the scheme with a regular expression and
     * took what was left as the host, which is fine until a `home_url` carries a path: measured
     * across the installed sources, `lhtranslation.net` is published as
     * `https://lhtranslation.net/manga/?m_orderby=views`, and stripping the scheme left
     *
     * ```
     * lhtranslation.net/manga/?m_orderby=views
     * ```
     *
     * as the group's host. That string was then printed under the site name, so a reader was
     * shown a query string as though it were a domain, and it was used as the grouping key,
     * which means the group a source lands in depends on a sort order in someone else's URL. An
     * extension that changed its default ordering would silently become a second group for the
     * same site.
     *
     * `parse_url` is the answer and the only reason this needed saying: a host is a component of
     * a URL, and asking for the component is different from deleting the parts you did not want.
     *
     * Null for a source with no `home_url`, and for one whose `home_url` is unparseable, since
     * both mean "no host to group by" and both get the local group rather than a group named
     * after a malformed string.
     */
    private function hostOf(?string $homeUrl): ?string
    {
        if ($homeUrl === null || trim($homeUrl) === '') {
            return null;
        }

        $host = parse_url(trim($homeUrl), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }

    #[Computed]
    public function browseSites(): array
    {
        /*
         * The adult rated sources are left out while the gate is off.
         *
         * The picker is a door: every card on it opens a source's own
         * catalogue, which is where that source's manga is. Offering a door
         * the app has decided not to open is a dead end dressed as a choice,
         * so the gate applies here as well as on the catalogue and on the
         * search.
         */
        $rows = Source::query()
            ->installed()
            ->when(
                ! $this->adultContentOn(),
                fn ($q) => $q->where('content_warning', '!=', Source::NSFW),
            )
            ->orderBy('display_name')
            ->get();

        $sites = [];

        foreach ($rows as $source) {
            $host = $this->hostOf($source->home_url);

            // The local source has no host and must still get a group of its own.
            $key = $host ?? 'local';

            if (! isset($sites[$key])) {
                $sites[$key] = [
                    'site' => $key === 'local'
                        ? (string) __('On this machine')
                        : $this->siteName($rows, $key),
                    'host' => $key === 'local' ? null : $key,
                    /*
                     * One icon for the site, taken from a variant that has one.
                     *
                     * `icon_url` has been stored since the catalogue was mirrored and was
                     * never rendered, which is most of why this list read as flat: fifty
                     * rows of a name and a count with nothing to tell them apart. It is a
                     * per extension path, so a site borrows the first one its variants
                     * offer. The later fill matters because extensions do not all ship an
                     * icon and the first variant of a site is not reliably the one that does.
                     */
                    'icon' => null,
                    'sources' => [],
                ];
            }

            if ($sites[$key]['icon'] === null && $source->icon_url !== null) {
                $sites[$key]['icon'] = (string) $source->icon_url;
            }

            $sites[$key]['sources'][] = [
                'id' => (int) $source->id,
                'name' => (string) $source->display_name,
                'lang' => (string) $source->lang,
                'latest' => (bool) $source->supports_latest,
                'icon' => $source->icon_url === null ? null : (string) $source->icon_url,
            ];
        }

        // Biggest first, so MangaDex's 61 languages sit last rather than filling the
        // first screen a reader sees.
        usort($sites, static fn (array $a, array $b): int => count($b['sources']) <=> count($a['sources']));

        return array_values($sites);
    }

    /**
     * The friendliest name for a site, from the source names that share its host.
     *
     * A group is keyed by host, so every source on it is the same site and their names
     * differ only by the language suffix. The suffix is stripped to recover the site name,
     * which is why MangaDex reads as "MangaDex" rather than "MangaDex (EN)".
     *
     * @param  \Illuminate\Support\Collection<int, Source>  $rows
     */
    private function siteName(\Illuminate\Support\Collection $rows, string $host): string
    {
        $first = $rows->filter(fn (Source $s): bool => $s->home_url !== null && str_contains((string) $s->home_url, $host))->first();

        $name = (string) ($first?->display_name ?? $host);
        $lang = (string) ($first?->lang ?? '');

        if ($lang !== '' && str_ends_with($name, ' ('.$lang.')')) {
            return substr($name, 0, -strlen(' ('.$lang.')'));
        }

        $stripped = (string) preg_replace('/\s*\(([A-Za-z]{2}(?:-[A-Za-z0-9]+)*)\)\s*$/', '', $name);

        return $stripped !== '' ? $stripped : $host;
    }

    /**
     * Ask Suwayomi to read the local folder again.
     *
     * Reports what came back rather than assuming it worked, because a rescan that found
     * nothing and one that failed look identical from the page otherwise.
     */
    public function rescanLocal(): void
    {
        $result = app(LocalLibrary::class)->rescan();

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
        return ExtensionStore::query()->orderBy('name')->get();
    }

    /**
     * @return array<int, int>
     */
    #[Computed]
    public function trustedIds(): array
    {
        return app(ExtensionManager::class)->trustedStoreIds(auth()->user());
    }

    /**
     * The catalogue as one read: the rows, the count, or why neither could be had.
     *
     * One try for both, because a failure here is one fact the page tells once; two
     * computeds catching independently would each hold half of it and need to agree
     * on the other half. `extensions()` and `total()` stay the names the page calls.
     * This is where SuwayomiException is caught so the page renders with the reason
     * instead of answering 500, which is what the sandbox run of 2026-10-15 measured:
     * the guest's Suwayomi was up and answering, and this page showed the app's 500
     * page while all 58 checks passed.
     *
     * @return array{extensions: Collection<int, array<string, mixed>>, total: int, error: string|null, hidden: int}
     */
    #[Computed]
    public function catalogue(): array
    {
        try {
            $extensions = app(ExtensionManager::class)->extensions(
                $this->filters(),
                self::PER_PAGE,
                ($this->page - 1) * self::PER_PAGE,
            );

            $total = app(ExtensionManager::class)->extensionCount($this->filters());

            /*
             * How many installed extensions the gate is holding back.
             *
             * The Installed slice hides the adult-rated ones while the gate is
             * off, so the count above is the count of what is listed and the
             * reader is told how many are not. Without this the page would say
             * "1 extension" on a server that holds two, which is the same lie
             * as an empty library reading as an empty one.
             *
             * It is a second count rather than a subtraction because the slice
             * with the gate on carries no rating filter at all, and there is
             * no second number to subtract from. Measured on 2026-10-21: the
             * count costs 25 to 38 ms against the live server.
             */
            $hidden = 0;

            if ($this->view === 'installed' && ! $this->adultContentOn()) {
                $hidden = app(ExtensionManager::class)->extensionCount([
                    'installed' => true,
                    'content' => [Source::NSFW],
                ]);
            }
        } catch (SuwayomiException $e) {
            return ['extensions' => new Collection(), 'total' => 0, 'error' => $e->getMessage(), 'hidden' => 0];
        }

        return ['extensions' => $extensions, 'total' => $total, 'error' => null, 'hidden' => $hidden];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function extensions(): Collection
    {
        return $this->catalogue['extensions'];
    }

    #[Computed]
    public function total(): int
    {
        return $this->catalogue['total'];
    }

    #[Computed]
    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / self::PER_PAGE));
    }

    /**
     * The languages the catalogue holds, with how many sources each one has.
     *
     * ## Why this asks the server again rather than reading the page's own rows
     *
     * Because the page's rows are one page of fifty out of a thousand, and a
     * language whose sources all sit on page nine would not be on the row of
     * pills at all. A reader who has switched a language off and then wants it
     * back has to be able to find it, and it has to be there before they
     * switch it off in the first place.
     *
     * The count is the number of sources in that language under the filters
     * the reader can see, so a pill reads "English 317" and the reader knows
     * what turning it on would give them. The language filter itself is left
     * out of the ask, so the pills do not disappear as the reader switches
     * them off.
     *
     * The name is the catalogue's own code read through the one map that
     * names language codes, so a pill says "Portuguese (Brazil)" rather than
     * "pt-BR" and the raw code is still on it for anyone who wants to match it
     * against the package name.
     *
     * @return array<int, array{code: string, name: string, count: int, on: bool}>
     */
    #[Computed]
    public function catalogueLanguages(): array
    {
        try {
            $languages = app(ExtensionManager::class)->extensionLanguages($this->filtersWithoutLanguage());
        } catch (SuwayomiException) {
            // A language list that cannot be read is an empty list, not a page
            // that answers 500. The catalogue below it is still readable, and
            // a reader who cannot switch a language off is not worse off than
            // a reader whose server is down.
            return [];
        }

        return array_map(fn (array $language): array => [
            'code' => $language['code'],
            'name' => LanguageName::name($language['code']),
            'count' => $language['count'],
            'on' => ! in_array($language['code'], $this->lang, true),
        ], $languages);
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

        $this->reset(['page', 'selected']);
    }

    /**
     * Remember that a source family is open, or that it is closed again.
     *
     * The open state itself is the browser's, and it changes the moment the
     * reader presses the summary, without a round trip. What the server
     * cannot see is that it happened, so the page tells it, and the server
     * owns the memory that renders the family open on the next load.
     *
     * The re-render that follows changes nothing on screen: the state the
     * server holds is the state the browser is already showing, so the
     * markup that comes back is the markup that is there.
     */
    public function rememberFamily(string $host, bool $open): void
    {
        $host = trim($host);

        if ($host === '') {
            return;
        }

        $this->openFamilies = $open
            ? array_values(array_unique([...$this->openFamilies, $host]))
            : array_values(array_diff($this->openFamilies, [$host]));
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
        $store = app(ExtensionManager::class)->storeFor($extension);

        return $store !== null && in_array($store->id, $this->trustedIds, true);
    }

    public function canInstallName(string $pkgName): bool
    {
        $row = $this->rowFor($pkgName);

        return $row !== null
            && ($row['isInstalled'] ?? false) !== true
            && $this->isTrusted($row);
    }

    /**
     * Whether this installed extension can be updated from here.
     *
     * Same three facts the row was rendered from, re-checked in the action: the
     * list predates the click, so the badge is not evidence.
     */
    public function canUpdateName(string $pkgName): bool
    {
        $row = $this->rowFor($pkgName);

        return $row !== null
            && ($row['isInstalled'] ?? false) === true
            && ($row['hasUpdate'] ?? false) === true
            && $this->isTrusted($row);
    }

    /**
     * The catalogue row for one package name, or null when the list predates it.
     *
     * Every install and update guard reads the row again for the same reason this
     * page re-checks trust: the rendered list is not evidence.
     *
     * @return array<string, mixed>|null
     */
    private function rowFor(string $pkgName): ?array
    {
        return $this->extensions->firstWhere('pkgName', $pkgName);
    }

    /**
     * The pressed names whose site the notice list covers, in the pressed order.
     *
     * @param array<int, string> $names
     * @return array<int, string>
     */
    private function flaggedAmong(array $names): array
    {
        return array_values(array_filter(
            $names,
            fn (string $pkgName): bool => $this->noticeFor($this->rowFor($pkgName) ?? []) !== null,
        ));
    }

    /**
     * One row per name the warning asks about, read from the list at render time
     * rather than captured at ask time, so the dialog states what the config says
     * now rather than what it said when the press dispatched.
     *
     * Names left out by a catalogue refresh between ask and render are carried by
     * the pages' pending state and installed on confirm, but they have nothing to
     * warn about once their notice is gone.
     *
     * @return array<int, array<string, string>>
     */
    public function warningRows(): array
    {
        $rows = [];

        foreach ($this->pendingInstall as $pkgName) {
            $row = $this->rowFor($pkgName);

            if ($row === null) {
                continue;
            }

            $notice = $this->noticeFor($row);

            if ($notice === null) {
                continue;
            }

            $rows[] = [
                'name' => (string) $row['name'],
                'icon' => $notice['icon'],
                'text' => $notice['text'],
            ];
        }

        return $rows;
    }

    /**
     * Trust is re-checked here, not just for the button. The list was rendered
     * before the user changed their mind, so the tick is not evidence.
     *
     * @return array<int, string>
     */
    private function installableFromSelection(): array
    {
        $available = $this->extensions
            ->filter(fn (array $row): bool => ($row['isInstalled'] ?? false) !== true && $this->isTrusted($row))
            ->pluck('pkgName')
            ->all();

        return array_values(array_intersect($this->selected, $available));
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        $filters = match ($this->view) {
            'installed' => ['installed' => true],
            'available' => ['installed' => false, 'obsolete' => false],
            'updates' => ['has_update' => true, 'installed' => true],
            default => [],
        };

        if (trim($this->name) !== '') {
            $filters['name'] = trim($this->name);
        }

        /*
         * The rating, in two different shapes for two different slices.
         *
         * The Installed slice carries no rating filter at all while the gate is
         * on: an extension is either on the server or it is not, and a rating
         * belongs to the reader rather than to a list of what happens to be
         * present. While the gate is off it carries the gate's own allow list,
         * because an adult-rated extension that is installed is one the reader
         * has not agreed to see, and the count of what is hidden is named
         * beside the list.
         *
         * The other three slices carry what the reader chose, narrowed by the
         * gate. The gate is applied in the query and not only here, so the
         * rating cannot come back through the address bar.
         */
        if ($this->view === 'installed') {
            $filters['content'] = $this->adultContentOn()
                ? []
                : [Source::SAFE, Source::MIXED];
        } else {
            $filters['content'] = $this->visibleRatings($this->content);
        }

        if ($this->lang !== []) {
            $filters['lang'] = $this->lang;
        }

        return $filters;
    }

    /**
     * The same filters, without the reader's language choice.
     *
     * For the one thing that has to answer without it: which languages there
     * are. A list of pills that was asked for the languages still in the
     * result would lose a language the moment the reader switched it off, and
     * a language that cannot be seen cannot be switched back on.
     *
     * @return array<string, mixed>
     */
    private function filtersWithoutLanguage(): array
    {
        $filters = $this->filters();

        unset($filters['lang']);

        return $filters;
    }
}; ?>

<div class="w-full space-y-8" x-on:open-install-warning.window="$dispatch('modal-show', { name: 'install-warning' })" x-on:close-install-warning.window="$dispatch('modal-close', { name: 'install-warning' })">
    <x-page.header>
        <x-slot:eyebrow>{{ __('Keiyoushi, on your terms.') }}</x-slot:eyebrow>
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

                Named extensions rather than a count, because "2 sources are missing" leaves
                the reader to guess which and a toast has already gone by the time they look
                for it. The button is the existing sync, not a new action: the difference is
                that it is now offered where the stale list is, instead of on another tab
                with a label that has to be understood first.
            --}}
            <flux:callout variant="secondary" icon="arrow-path" data-test="mirror-drift">
                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
                    @php($driftNames = implode(', ', array_column($this->unmirroredExtensions, 'name')))

                    <p class="text-sm">
                        {{
                            /*
                             * One string, pipe separated, then the count.
                             *
                             * trans_choice is ($key, $number, $replace), so a second message
                             * string lands in $replace and fails on an int. The plural form
                             * is the part after the pipe, which is how the rest of this page
                             * writes it.
                             */
                            trans_choice(
                                ':name is installed but not in the list below yet.|:names are installed but not in the list below yet.',
                                count($this->unmirroredExtensions),
                                ['name' => $driftNames, 'names' => $driftNames],
                            )
                        }}
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
                    <li class="rounded-xl border border-zinc-200 dark:border-white/10">
                        {{--
                            Every group starts closed, and the reader's own choice
                            is remembered.

                            The first version opened the largest one, which put MangaDex's 61
                            language variants on screen as the first thing a reader saw, on top of
                            a page that already lists 16 extensions and a stores table. The
                            reader asked for this closed, and they are right twice over: a reader
                            who wants MangaDex is reaching for a language in the first four pills
                            anyway, and one who wants all 61 can open it deliberately.

                            The summary row stays the way in, so closing a group costs nothing.
                            A single-language site already shows its one source as a pill and never
                            needs expanding at all.

                            And a reader who opens one every time they come here does not
                            have to open it every time they come here: the group is open
                            when the reader last left it open, and the page tells the
                            server so it can say so next time. The browser does the opening
                            itself, without a round trip, and the server is told after.
                        --}}
                        <details
                            class="group"
                            @if (in_array($group['host'] ?? 'local', $this->openFamilies, true)) open @endif
                            x-on:toggle="$wire.rememberFamily({{ Illuminate\Support\Js::from($group['host'] ?? 'local')->toHtml() }}, $el.open)"
                        >
                            {{--
                                The face of the card. Icon, name, host, count.

                                The icon is what this list never had. `icon_url` has been
                                stored on every source since the catalogue was mirrored and
                                was never drawn, so fifty tiles of a name and a count had
                                nothing to tell them apart and read as one long list however
                                they were arranged.

                                The chevron is not decoration. A summary that only grows when
                                clicked, and gives nothing else away, is a control the reader
                                has to try before they trust it, and this page is mostly made
                                of them.
                            --}}
                            <summary class="flex cursor-pointer list-none flex-col gap-3 p-4">
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

                                <div class="mt-auto flex items-center justify-between gap-2">
                                    @if ($this->isPinnedSite($group['host'] ?? 'local'))
                                        <flux:badge size="sm" color="zinc">{{ __('pinned by you') }}</flux:badge>
                                    @endif

                                    <flux:badge size="sm" color="zinc">
                                        {{ trans_choice('{1} :count source|[2,*] :count sources', count($group['sources']), ['count' => count($group['sources'])]) }}
                                    </flux:badge>

                                    <flux:icon
                                        name="chevron-down"
                                        class="size-4 text-zinc-400 transition group-open:rotate-180 dark:text-zinc-500"
                                    />
                                </div>
                            </summary>

                            <div class="flex flex-wrap gap-1.5 border-t border-zinc-200 px-4 py-3 dark:border-white/10">
                                @foreach ($group['sources'] as $one)
                                    <a
                                        href="{{ route('browse', ['source' => $one['id']]) }}"
                                        wire:navigate
                                        class="inline-flex items-center gap-1.5 rounded-full border border-zinc-200 px-2.5 py-1 text-xs text-zinc-600 transition hover:border-vermilion-400 hover:text-vermilion-700 dark:border-white/15 dark:text-zinc-300 dark:hover:border-vermilion-400 dark:hover:text-vermilion-300"
                                    >
                                        {{ $one['name'] }}

                                        @if ($one['latest'])
                                            <span class="text-[0.625rem] uppercase tracking-wide text-zinc-400 dark:text-zinc-500">
                                                {{ __('latest') }}
                                            </span>
                                        @endif
                                    </a>
                                @endforeach

                                {{--
                                    The pin lives on the open card, beside the variants it
                                    would cover, for the same reason the health page's is
                                    in its drill-down: the count is on the face the reader
                                    pressed, so a site with 61 variants is pinned by
                                    someone who saw 61.
                                --}}
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
                        </details>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <flux:callout variant="secondary" icon="information-circle">
        <div class="space-y-1 text-sm">
            <p>
                {{ __('Sources are Keiyoushi extensions running inside Suwayomi. Nothing is bundled: point Papyrus at a store, read its signing key, then trust it if you recognise the publisher.') }}
            </p>
            <p class="text-zinc-500 dark:text-zinc-400">
                {{ __('Trust is a gate in this interface, not an enforcement the Suwayomi API offers. It is here so that you read the key before installing, and so the cautious path is the default one.') }}
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
                        {{ __('The local folder is not visible from this machine. Suwayomi runs somewhere Papyrus cannot see its data directory, so what it is holding is not listed here.') }}
                    @endif
                </p>
            </div>

            <div class="flex items-center gap-2">
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
                                            title="{{ number_format($file['bytes'] / 1024, 1) }} KB"
                                        >
                                            {{ $file['name'] }}
                                        </span>
                                    @endforeach
                                </div>

                                @if ($row['metadata'] !== [])
                                    <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                        @foreach ($row['metadata'] as $field => $value)
                                            <div class="flex gap-1">
                                                <dt class="text-zinc-500 dark:text-zinc-400">{{ $field }}</dt>
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

            <div class="flex items-center gap-2">
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
                {{ __('The catalogue did not load itself: :why. Refresh catalogue above is the recovery for it.', ['why' => $loadProblem]) }}
            </flux:callout>
        @endif

        @if ($this->stores->isEmpty())
            <flux:callout variant="secondary" icon="circle-stack">
                {{ __('No store is registered yet. Add one below to begin.') }}
            </flux:callout>
        @else
            <ul class="space-y-3">
                @foreach ($this->stores as $store)
                    @php($isTrusted = in_array($store->id, $this->trustedIds, true))

                    <li>
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

        <form wire:submit="addStore" class="flex flex-wrap items-end gap-3">
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
                    {{ __('The index file itself, not the repository root. Keiyoushi publishes protobuf at index.pb.') }}
                </p>
            </div>

            <flux:button type="submit" variant="primary" icon="plus" wire:target="addStore" wire:loading.attr="disabled">
                {{ __('Add store') }}
            </flux:button>
        </form>
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
                Updates view exists at all: Keiyoushi ships updates constantly,
                and forty of them is not a queue a reader should walk.
            --}}
            @if ($view === 'updates' && $this->total > 0)
                <flux:button
                    size="sm"
                    variant="primary"
                    wire:click="updateAll"
                    wire:loading.attr="disabled"
                    icon="arrow-path"
                >
                    {{ __('Update all') }}
                </flux:button>
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
                {{ __('Each count is how many series in your library come from that source. Tapping a source opens the move flow with that source already picked.') }}
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
                                    title="{{ __(':count series', ['count' => number_format($row['count'])]) }}"
                                >
                                    {{ number_format($row['count']) }}
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

            "Show none" is not a flourish. Switching twenty-two languages off
            one at a time is twenty-two round trips and twenty-two renders of a
            list of a thousand rows, and a reader who reads two languages
            should not pay that. Switching every language off is one press, and
            switching one back on is a second.
        --}}
        @if ($view !== 'migrate' && $this->catalogueLanguages !== [])
            @php($stillOn = array_values(array_diff(array_column($this->catalogueLanguages, 'code'), $this->lang)))

            <div class="flex flex-wrap items-center gap-3">
                <flux:label>{{ __('Language') }}</flux:label>

                {{--
                    A wrapping row of pills rather than a button group.

                    A Flux button group is one row by design: it welds its
                    buttons into a single strip, which is right for four tabs
                    and wrong for twenty-three languages. The Content filter
                    above already solved this shape with a wrapping flex row,
                    and this is the same answer.
                --}}
                <div class="flex flex-wrap items-center gap-2">
                    @foreach ($this->catalogueLanguages as $language)
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
                {{ __('The server accepted the fetch and its catalogue holds no extensions yet. The list fills the moment the server has something to show, and Refresh catalogue above re-runs the fetch.') }}
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
                <flux:heading class="mb-1">{{ __('The extension list did not load') }}</flux:heading>
                <p class="text-sm">{{ $this->catalogue['error'] }}</p>
            </flux:callout>
        @elseif ($this->extensions->isEmpty())
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
            <ul class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
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
                            @elseif ($this->isTrusted($extension))
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
