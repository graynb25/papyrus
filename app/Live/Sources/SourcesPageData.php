<?php

namespace App\Live\Sources;

use App\Local\LocalLibrary;
use App\Models\ExtensionStore;
use App\Models\Source;
use App\Models\SourceHealth;
use App\Models\UserSource;
use App\Support\LanguageName;
use App\Support\Library;
use App\Suwayomi\ExtensionManager;
use App\Suwayomi\SuwayomiException;
use Illuminate\Support\Collection;

/**
 * The sources page's reads, kept out of the Livewire component.
 *
 * The component asks these on every render and on a few actions. Keeping them
 * here lets the query shapes, the page limit, the gate's hidden count, the
 * browse grouping and the empty states be exercised without mounting a
 * component, and leaves the component owning lifecycle, actions and the
 * pending install state.
 *
 * Reads that depend on another read take the value the component already
 * computed, so a render still makes one catalogue call and one language call
 * rather than one per helper. The order, limits and error catches are the
 * page's own.
 */
final class SourcesPageData
{
    /**
     * How many rows one catalogue page holds.
     *
     * 50 is the size the page has always used. The component aliases this
     * constant so its Blade and its actions cannot drift from the reads.
     */
    public const PER_PAGE = 50;

    public function __construct(
        private readonly ExtensionManager $extensions,
        private readonly Library $library,
        private readonly LocalLibrary $local,
        private readonly SourcesFormatting $formatting,
    ) {}

    /**
     * The registered extension stores, by name.
     *
     * @return Collection<int, ExtensionStore>
     */
    public function stores(): Collection
    {
        return ExtensionStore::query()->orderBy('name')->get();
    }

    /**
     * The store ids this reader has trusted.
     *
     * @return array<int, int>
     */
    public function trustedIds(): array
    {
        return $this->extensions->trustedStoreIds(auth()->user());
    }

    /**
     * How many extensions the server holds, for the automatic load's note.
     *
     * @throws SuwayomiException
     */
    public function extensionCount(): int
    {
        return $this->extensions->extensionCount();
    }

    /**
     * The catalogue as one read: the rows, the count, or why neither could be had.
     *
     * One try for both, because a failure here is one fact the page tells once; two
     * reads catching independently would each hold half of it and need to agree on
     * the other half. This is where SuwayomiException is caught so the page renders
     * with the reason instead of answering 500, which is what the sandbox run of
     * 2026-10-15 measured: the guest's Suwayomi was up and answering, and this page
     * showed the app's 500 page while all 58 checks passed.
     *
     * @param  array<string, mixed>  $filters
     * @return array{extensions: Collection<int, array<string, mixed>>, total: int, error: string|null, hidden: int}
     */
    public function catalogue(array $filters, string $view, int $page, bool $adultContentOn): array
    {
        try {
            $extensions = $this->extensions->extensions(
                $filters,
                self::PER_PAGE,
                ($page - 1) * self::PER_PAGE,
            );

            $total = $this->extensions->extensionCount($filters);

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

            if ($view === 'installed' && ! $adultContentOn) {
                $hidden = $this->extensions->extensionCount([
                    'installed' => true,
                    'content' => [Source::NSFW],
                ]);
            }
        } catch (SuwayomiException $e) {
            /** @var Collection<int, array<string, mixed>> $empty */
            $empty = new Collection;

            return ['extensions' => $empty, 'total' => 0, 'error' => $e->getMessage(), 'hidden' => 0];
        }

        return ['extensions' => $extensions, 'total' => $total, 'error' => null, 'hidden' => $hidden];
    }

    /**
     * The catalogue rows from the one catalogue read.
     *
     * @param  array{extensions: Collection<int, array<string, mixed>>, total: int, error: string|null, hidden: int}  $catalogue
     * @return Collection<int, array<string, mixed>>
     */
    public function extensions(array $catalogue): Collection
    {
        return $catalogue['extensions'];
    }

    /**
     * The catalogue count from the one catalogue read.
     *
     * @param  array{extensions: Collection<int, array<string, mixed>>, total: int, error: string|null, hidden: int}  $catalogue
     */
    public function total(array $catalogue): int
    {
        return $catalogue['total'];
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
     * @param  array<string, mixed>  $filtersWithoutLanguage
     * @param  array<int, string>  $lang
     * @return array<int, array{code: string, name: string, count: int, on: bool}>
     */
    public function catalogueLanguages(array $filtersWithoutLanguage, array $lang): array
    {
        try {
            $languages = $this->extensions->extensionLanguages($filtersWithoutLanguage);
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
            'on' => ! in_array($language['code'], $lang, true),
        ], $languages);
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
     * @return array{rows: Collection<int, array{source_id: string, display_name: string, lang: string, count: int, installed: bool, named: bool, icon: string|null}>, error: string|null}
     */
    public function holdings(): array
    {
        $rows = $this->library->holdingsBySource(auth()->user());

        return ['rows' => $rows, 'error' => $this->library->sourceFailure()];
    }

    /**
     * Whether the local source's folder can be read from this machine at all.
     *
     * Not decoration. The path is Suwayomi's data directory, so on any deployment where
     * the two are not on the same machine it does not exist, and a browse view that showed
     * an empty list would read as "you have no local manga".
     */
    public function localVisible(): bool
    {
        return $this->local->visible();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function localRows(): array
    {
        return $this->local->rows();
    }

    /**
     * @return array{series: int, on_disk: int, missing: int, unscanned: int, folders: int}
     */
    public function localSummary(): array
    {
        return $this->local->summary();
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
    public function unmirroredExtensions(bool $adultContentOn): array
    {
        try {
            $installed = $this->extensions->extensions(['installed' => true], 200, 0);
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
            if (! $adultContentOn && ($extension['contentWarning'] ?? null) === Source::NSFW) {
                continue;
            }

            $missing[] = ['name' => (string) ($extension['name'] ?? $pkg), 'pkg' => $pkg];
        }

        return $missing;
    }

    /**
     * The sources this reader has pinned, as Suwayomi ids.
     *
     * One query rather than one per card, and the read lives on the model, so this
     * badge, the health badges and the fanout's rule are all reading the same rows.
     * The component keeps the Livewire computed so one render still asks once and
     * an action that writes a pin can drop it.
     *
     * @return array<int, int>
     */
    public function pinnedIds(): array
    {
        return UserSource::pinnedSourceIdsFor((int) auth()->id());
    }

    /**
     * The sources behind one card, keyed the way {@see browseSites()} keys its
     * cards: the home_url's bare host, lowercased, and the local bucket for a
     * source with none. The pin toggles and the badge all ask through this one
     * derivation, so a card's write and its read can never disagree.
     *
     * @return array<int, int>
     */
    public function cardSourceIds(string $site): array
    {
        $host = $site === 'local' ? null : strtolower($site);

        return Source::query()
            ->installed()
            ->get()
            ->filter(fn (Source $source): bool => $this->formatting->hostOf($source->home_url) === $host)
            ->pluck('suwayomi_source_id')
            ->all();
    }

    /**
     * Whether any installed source behind one card is pinned.
     *
     * Any, not every, on the same reading the health page records for its badge:
     * pins do not retire rows, so any pinned variant behind a card means the
     * reader pinned that site. The pinned ids are passed in so the caller's one
     * read per render is the one this decision uses.
     *
     * @param  array<int, int>  $pinnedIds
     */
    public function isPinnedSite(string $site, array $pinnedIds): bool
    {
        if ($pinnedIds === []) {
            return false;
        }

        return array_intersect($this->cardSourceIds($site), $pinnedIds) !== [];
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
     * @return array<int, array{site: string, host: string|null, health_site: string, sources: array<int, array<string, mixed>>, icon: string|null, health: array{state: string, label: string}|null}>
     */
    public function browseSites(bool $adultContentOn): array
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
                ! $adultContentOn,
                fn ($q) => $q->where('content_warning', '!=', Source::NSFW),
            )
            ->orderBy('display_name')
            ->get();

        /** @var array<string, array{site: string, host: string|null, health_site: string, sources: array<int, array<string, mixed>>, icon: string|null}> $sites */
        $sites = [];

        foreach ($rows as $source) {
            $host = $this->formatting->hostOf($source->home_url);

            // The local source has no host and must still get a group of its own.
            $key = $host ?? 'local';

            if (! isset($sites[$key])) {
                $sites[$key] = [
                    'site' => $key === 'local'
                        ? (string) __('On this machine')
                        : $this->formatting->siteName($rows, $key),
                    'host' => $key === 'local' ? null : $key,
                    /*
                     * The health page's own key for this site: the raw
                     * `home_url`, or `unknown` for a source that publishes
                     * none. It groups on that value rather than on the host,
                     * so sending the host lands on nothing.
                     */
                    'health_site' => $source->home_url === null || $source->home_url === ''
                        ? 'unknown'
                        : (string) $source->home_url,
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
                'source_id' => (int) $source->suwayomi_source_id,
                'name' => (string) $source->display_name,
                'lang' => (string) $source->lang,
                'latest' => (bool) $source->supports_latest,
                'icon' => $source->icon_url === null ? null : (string) $source->icon_url,
            ];
        }

        /** @var array<int, array{site: string, host: string|null, health_site: string, sources: array<int, array<string, mixed>>, icon: string|null, health: array{state: string, label: string}|null}> $groups */
        $groups = [];

        foreach ($sites as $site) {
            $ids = array_values(array_unique(array_map(
                fn (array $row): int => (int) ($row['source_id'] ?? 0),
                $site['sources'],
            )));

            $healthRows = SourceHealth::query()->whereIn('source_id', $ids)->get();
            $health = null;

            if (! $healthRows->isEmpty()) {
                $state = 'healthy';

                foreach ($healthRows as $row) {
                    if ((bool) $row->is_dead || (int) $row->consecutive_failures >= 2) {
                        $state = 'not-answering';

                        break;
                    }
                }

                $health = [
                    'state' => $state,
                    'label' => $state === 'healthy' ? __('Healthy') : __('Not answering'),
                ];
            }

            $groups[] = [
                'site' => $site['site'],
                'host' => $site['host'],
                'health_site' => $site['health_site'],
                'icon' => $site['icon'],
                'sources' => $site['sources'],
                'health' => $health,
            ];
        }

        // Biggest first, so MangaDex's 61 languages sit last rather than filling the
        // first screen a reader sees.
        usort($groups, static fn (array $a, array $b): int => count($b['sources']) <=> count($a['sources']));

        return $groups;
    }
}
