<?php

namespace App\Import;

use App\Suwayomi\ExtensionManager;
use App\Suwayomi\SeriesLocator;
use Illuminate\Support\Collection;

/**
 * Brings the extensions a backup names up to what this server has.
 *
 * ## Why this is part of the import and not a prompt
 *
 * Measured on a real backup: 1,277 series across 52 sources, and this server had extensions
 * for **13**. So three quarters of the sources could not be asked anything at all, and no
 * amount of join cleverness reaches them. An import that leaves that to the reader is an import
 * that silently delivers a fifth of the library, which is why this runs before resolution
 * rather than after it.
 *
 * ## How it installs, and the route that was wrong for a long time
 *
 * Two routes were measured against the live server.
 *
 * **`installExternalExtension`, which takes a file.** The archive carries jars for 52 of the 52
 * library sources, so this route would reach 1,260 of 1,277 series at the exact versions the
 * reader had. Six multipart request shapes were tried, including the one the GraphQL spec
 * describes, and all were refused. Not solved.
 *
 * **`fetchExtensions`, which was assumed to install and does not.** The earlier version of this
 * class called it, on the reasonable reading that fetching extensions installs them. Measured:
 *
 * ```
 * before: 18 installed packages
 * fetchExtensions returned: 1428 extensions
 * after:  18 installed packages
 * gained: 0
 * ```
 *
 * It refreshes the catalogue and installs nothing. So the step that existed to make three
 * quarters of the library reachable had never installed anything, and the ceiling was whatever
 * the reader already had. Nothing failed and the report said `installed: 0`, which is exactly
 * the shape of a bug that survives a test suite, because the suite asserted on the query being
 * sent rather than on the server's state changing.
 *
 * **`updateExtension` with `patch: {install: true}`, one package at a time.** This is the route
 * that works, and the app already implemented it in {@see ExtensionManager::installMany()}.
 * Measured against the live server: 17 installed packages, asked for one, got 18. Nothing else
 * moved.
 *
 * The consequence is better than what it replaced. `FetchExtensionsInput` takes no filter, so
 * the store route installed the whole catalogue or none of it: 1,428 extensions against the 32
 * the library actually needed, which is not a thing to do to a reader's server quietly. This
 * route asks for exactly the packages the backup names, so the cost is 32 requests and the
 * installed count is 32. The earlier docblock in this file called that a known limitation; it
 * was an avoidable one.
 *
 * ## What it does not do
 *
 * It does not install an extension marked obsolete. Those sources are gone, their sites have
 * shut down, and the reader's own app had already marked them: 7 of them, holding 178 series.
 * That is reported, not retried, and no amount of installing would bring them back.
 */
final class ExtensionInstaller
{
    public function __construct(
        private readonly ExtensionManager $extensions,
        private readonly SeriesLocator $locator,
    ) {}

    /**
     * Mark every source on the backup as installed or not, without changing anything.
     *
     * Separate from {@see self::install()} because a reader wants to see what is missing before
     * agreeing to install anything, and because the resolver needs the same answer for its
     * messages.
     *
     * @param  Collection<int, BackupSource>  $sources
     * @return Collection<int, BackupSource> The same collection, marked in place.
     */
    public function survey(Collection $sources): Collection
    {
        $installed = $this->locator->installedPackages();

        foreach ($sources as $source) {
            $source->isInstalled = $source->package !== null
                && in_array($source->package, $installed, true);
        }

        return $sources;
    }

    /**
     * Install what the backup needs and this server lacks.
     *
     * Takes the collection by reference in effect, because {@see self::survey()} marks the
     * sources in place. That is the point: after this returns, every source in the caller's
     * collection carries the truth about this server, and a caller reporting "32 sources are
     * still missing" reads that off the same objects rather than a second list that could
     * disagree with the first.
     *
     * ## Failures are counted, not thrown
     *
     * One package that will not install, out of 32, must not abandon the other 31 or lose the
     * 1,000-odd series they carry. {@see ExtensionManager::installMany()} returns what landed
     * and what did not with each failure's own message, and this records the count so the
     * report can say "30 installed, 2 refused" rather than either silently succeeding or
     * stopping.
     *
     * @param  Collection<int, BackupSource>  $sources  Already surveyed, so the caller knows the gap.
     * @return array{installed: int, refused: array<string, string>, already: int, attempted: int, still_missing: int, obsolete: int}
     */
    public function install(Collection $sources, bool $dryRun = false): array
    {
        /*
         * Only sources that carry series are installed.
         *
         * The backup names 279 sources in total and 52 of them carry shelf series, but
         * `ImportedBackup::$sources` is already filtered to the shelf, so this only excludes the
         * zero-count case that a source row can still hold. Installing an extension for a source
         * no series sit on costs a request and leaves the reader with an extension they did not
         * ask for, on a server where every installed extension is scanned on every search.
         */
        $wanted = $sources
            ->filter(
                fn (BackupSource $s): bool => $s->seriesCount > 0
                    && ! $s->isInstalled
                    && $s->isRecoverable()
                    && $s->package !== null,
            )
            ->map(fn (BackupSource $s): string => $s->package)
            ->unique()
            ->values()
            ->all();

        $already = $sources->filter(fn (BackupSource $s): bool => $s->isInstalled)->count();

        $result = [
            'installed' => 0,
            'refused' => [],
            'already' => $already,
            'attempted' => count($wanted),
            'still_missing' => $sources
                ->reject(fn (BackupSource $s): bool => $s->isInstalled || $s->obsolete)
                ->count(),
            'obsolete' => $sources->where('obsolete', true)->sum(fn (BackupSource $s): int => $s->seriesCount),
        ];

        if ($dryRun || $wanted === []) {
            return $result;
        }

        $installed = $this->extensions->installMany($wanted);

        /*
         * Invalidate before re-surveying.
         *
         * The locator caches the installed list because a resolution pass asks it once per
         * series, and 1,277 series against a fresh query each time is 1,277 queries to learn
         * the same thing. But the install just changed the thing being cached, so without this
         * the survey below returns the pre-install answer and the run reports `installed: 0`
         * having installed the lot. Nothing throws in that case, which is what makes it worth
         * a test.
         */
        $this->locator->forgetInstalled();

        $this->survey($sources);

        $result['installed'] = count($installed['installed']);
        $result['refused'] = $installed['failed'];

        // What is still missing is what did not land, which is not the same as what was asked for.
        $result['still_missing'] = $sources
            ->reject(fn (BackupSource $s): bool => $s->isInstalled || $s->obsolete)
            ->count();

        return $result;
    }

    /**
     * The packages that could not be installed, as a sentence for a report.
     *
     * @param  array<string, string>  $refused
     */
    public static function describeRefusals(array $refused): string
    {
        if ($refused === []) {
            return '';
        }

        $reasons = array_values(array_unique(array_values($refused)));
        $shown = array_slice(array_keys($refused), 0, 3);

        return sprintf(
            '%d package%s could not be installed (%s). %s',
            count($refused),
            count($refused) === 1 ? '' : 's',
            implode('; ', array_slice($reasons, 0, 2)),
            count($refused) > 3
                ? sprintf(
                    'Affected: %s and %d more.',
                    implode(', ', $shown),
                    count($refused) - 3,
                )
                : 'Affected: '.implode(', ', $shown).'.',
        );
    }
}
