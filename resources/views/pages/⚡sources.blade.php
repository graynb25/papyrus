<?php

use App\Models\ExtensionStore;
use App\Models\Source;
use App\Suwayomi\ExtensionManager;
use App\Local\LocalLibrary;
use App\Suwayomi\SourceCatalog;
use App\Suwayomi\SuwayomiException;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Sources')] class extends Component {
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
     * @var array<int, string>
     */
    #[Url(as: 'content', except: '')]
    public array $content = [Source::SAFE, Source::MIXED];

    public string $name = '';

    public int $page = 1;

    public const PER_PAGE = 50;

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
         */
        $this->content = $this->view === 'installed'
            ? []
            : ($this->content !== [] ? $this->content : [Source::SAFE, Source::MIXED]);
    }

    public function updatedView(): void
    {
        $this->reset(['page', 'selected', 'failures']);

        // The Installed tab has no content rating to filter by: an extension is
        // either on the server or it is not. NSFW gating belongs to the reader,
        // not to a list of what is technically present.
        $this->content = $this->view === 'installed' ? [] : [Source::SAFE, Source::MIXED];
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
     * Without this the list is whatever the server last fetched, which after a
     * restart is nothing at all.
     */
    public function refresh(): void
    {
        try {
            $count = app(SourceCatalog::class)->refreshExtensions();
            app(SourceCatalog::class)->syncStores();
        } catch (SuwayomiException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        $this->reset(['page', 'selected']);

        Flux::toast(
            text: trans_choice('{1} :count extension available|[2,*] :count extensions available', $count, ['count' => $count]),
            heading: __('Stores refreshed'),
            variant: 'success',
        );
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

    public function install(string $pkgName): void
    {
        if (! $this->canInstallName($pkgName)) {
            return;
        }

        try {
            app(ExtensionManager::class)->setInstalled($pkgName, true);
        } catch (SuwayomiException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        $this->failures = [];

        Flux::toast(text: __('Sync sources to make it searchable.'), heading: __('Installed'), variant: 'success');
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

        Flux::toast(text: __('Its source stops being searchable.'), heading: __('Uninstalled'), variant: 'success');
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

    public function installSelected(): void
    {
        $allowed = $this->installableFromSelection();

        $this->reset(['selected', 'failures']);

        if ($allowed === []) {
            return;
        }

        $result = app(ExtensionManager::class)->installMany($allowed);
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

        Flux::toast(
            text: __(':count extensions added. Sync sources to start searching them.', [
                'count' => count($result['installed']),
            ]),
            heading: __('Installed'),
            variant: 'success',
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
     * The four views the catalogue can be sliced by.
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
        ];
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
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function extensions(): Collection
    {
        return app(ExtensionManager::class)->extensions(
            $this->filters(),
            self::PER_PAGE,
            ($this->page - 1) * self::PER_PAGE,
        );
    }

    #[Computed]
    public function total(): int
    {
        return app(ExtensionManager::class)->extensionCount($this->filters());
    }

    #[Computed]
    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / self::PER_PAGE));
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
        $row = $this->extensions->firstWhere('pkgName', $pkgName);

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
        $row = $this->extensions->firstWhere('pkgName', $pkgName);

        return $row !== null
            && ($row['isInstalled'] ?? false) === true
            && ($row['hasUpdate'] ?? false) === true
            && $this->isTrusted($row);
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

        if ($this->content !== []) {
            $filters['content'] = $this->content;
        }

        return $filters;
    }
}; ?>

<div class="w-full space-y-8">
    <header class="relative">
        <div class="hero-glow" aria-hidden="true"></div>

        <div class="relative">
            <p class="serif-italic text-base text-zinc-500 dark:text-zinc-400">{{ __('Keiyoushi, on your terms.') }}</p>
            <flux:heading level="1" size="xl" class="mt-1 font-display font-semibold tracking-tight">{{ __('Sources') }}</flux:heading>
        </div>
    </header>

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

                                    <flux:button
                                        size="sm"
                                        color="red"
                                        wire:click="removeStore({{ Illuminate\Support\Js::from($store->index_url) }})"
                                        wire:confirm="{{ __('Remove this store from the server? Its trust records go too.') }}"
                                    >
                                        {{ __('Remove') }}
                                    </flux:button>
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
                    placeholder="https://github.com/keiyoushi/extensions/raw/repo/index.pb"
                    icon="link"
                    required
                />

                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('The index file itself, not the repository root. Keiyoushi publishes protobuf at index.pb.') }}
                </p>
            </div>

            <flux:button type="submit" variant="primary" icon="plus">
                {{ __('Add store') }}
            </flux:button>
        </form>
    </section>

    {{-- Extensions ------------------------------------------------------ --}}
    <section class="space-y-4">
        <flux:heading level="2">{{ __('Extensions') }}</flux:heading>

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

            <flux:input
                wire:model.live.debounce.400ms="name"
                placeholder="{{ __('Filter by name') }}"
                icon="funnel"
                class="max-w-xs"
            />

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

        @if ($view !== 'installed')
            <div class="flex flex-wrap items-center gap-3">
                <flux:label>{{ __('Content') }}</flux:label>

                {{--
                    checkbox.group is a wrapper, so the individual checkboxes
                    nest inside it. Repeating the group per option renders bare
                    label text with no control.
                --}}
                <flux:checkbox.group wire:model.live="content" variant="pills">
                    <flux:checkbox value="{{ Source::SAFE }}" label="{{ __('Safe') }}" />
                    <flux:checkbox value="{{ Source::MIXED }}" label="{{ __('Mixed') }}" />
                    <flux:checkbox value="{{ Source::NSFW }}" label="{{ __('NSFW') }}" />
                </flux:checkbox.group>

                <span class="text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('NSFW extensions are hidden unless you opt in.') }}
                </span>
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

        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            {{ trans_choice('{1} :count extension|[2,*] :count extensions', $this->total, ['count' => Number::format($this->total)]) }}
        </p>

        @if ($this->extensions->isEmpty())
            <flux:callout variant="secondary" icon="magnifying-glass">
                @if ($this->stores->isEmpty())
                    {{ __('Add a store first, then refresh the catalogue.') }}
                @elseif ($view === 'installed')
                    {{ __('Nothing installed yet. Open the Available tab and install a source to get started.') }}
                @elseif ($view === 'updates')
                    {{ __('Nothing to update. Every installed extension is on the version its store ships.') }}
                @else
                    {{ __('Nothing matches. Try a shorter name or widen the content filter.') }}
                @endif
            </flux:callout>
        @else
            <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-white/10 dark:border-white/10">
                @foreach ($this->extensions as $extension)
                    @php($installed = ($extension['isInstalled'] ?? false) === true)

                    <li class="flex flex-wrap items-center gap-3 px-4 py-3">
                        @if ($installed)
                            <span class="size-[1.125rem] shrink-0"></span>
                        @else
                            <flux:checkbox
                                wire:model="selected"
                                :value="$extension['pkgName']"
                                class="shrink-0"
                            />
                        @endif

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{ $extension['name'] }}</span>

                                @if (($extension['lang'] ?? null) !== null)
                                    <flux:badge size="sm" color="zinc">{{ $extension['lang'] }}</flux:badge>
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

                            <p class="mt-0.5 truncate font-mono text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $extension['pkgName'] }}

                                @if (($extension['versionName'] ?? null) !== null)
                                    <span class="font-sans">{{ $extension['versionName'] }}</span>
                                @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
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

                                <flux:button
                                    size="sm"
                                    wire:click="uninstall({{ Illuminate\Support\Js::from($extension['pkgName']) }})"
                                    wire:confirm="{{ __('Uninstall this extension? Its source stops being searchable.') }}"
                                >
                                    {{ __('Uninstall') }}
                                </flux:button>
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
    </section>
</div>