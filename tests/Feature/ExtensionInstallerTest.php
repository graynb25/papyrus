<?php

namespace Tests\Feature;

use App\Import\BackupSource;
use App\Import\ExtensionInstaller;
use App\Suwayomi\ExtensionManager;
use App\Suwayomi\Gateway;
use App\Suwayomi\SeriesLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bringing the extensions a backup needs up to what this server has.
 *
 * ## Why this suite exists, in one measurement
 *
 * The earlier version of this installer called `fetchExtensions`, on the reasonable reading
 * that fetching extensions installs them. It does not. Measured against the live server:
 *
 * ```
 * before: 18 installed packages
 * fetchExtensions returned: 1428 extensions
 * after:  18 installed packages
 * gained: 0
 * ```
 *
 * So the step that existed to make three quarters of a reader's library reachable had never
 * installed anything, and the ceiling was whatever the reader already had. The suite passed
 * throughout, because it asserted on **which query was sent** rather than on the server's state
 * changing. Every test below therefore asserts on state.
 *
 * ## The route that works
 *
 * `updateExtension(input: {id, patch: {install: true}})`, one package at a time, keyed by
 * pkgName. Measured live: 17 installed, asked for one, got 18, and nothing else moved.
 *
 * That is also strictly better than what it replaced. `FetchExtensionsInput` takes no filter, so
 * the old route installed 1,428 extensions against the 32 the library needed, which is not a
 * thing to do to a reader's server quietly. This asks for exactly what the backup names.
 */
class ExtensionInstallerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The packages this server has, by reference.
     *
     * Mutable because the install changes it, and the point of the second survey is that the
     * installer reads the state *after*. A fake that answered with the pre-install list would
     * let a broken re-survey pass.
     *
     * @var array<string, bool>
     */
    private array $installed = [];

    /**
     * Packages that `updateExtension` will refuse, with the message it refuses with.
     *
     * @var array<string, string>
     */
    private array $refuses = [];

    /**
     * Every payload sent to Suwayomi, in order.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $sent = [];

    private ?ExtensionInstaller $subject = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sent = [];
        $this->refuses = [];
    }

    /*
     * ---------------------------------------------------------------------------
     * The fake server.
     * ---------------------------------------------------------------------------
     */

    /**
     * The installer, built once.
     *
     * Memoised because `Http::fake()` appends a stub rather than replacing what is already
     * there, so faking twice runs both closures against every request and counts each one
     * twice. An import also builds exactly one installer, so a test that built two was
     * describing an arrangement that does not occur.
     */
    private function installer(): ExtensionInstaller
    {
        return $this->subject ??= $this->fake();
    }

    private function fake(): ExtensionInstaller
    {
        Http::fake(function (Request $request) {
            $body = json_decode((string) $request->body(), true) ?: [];
            $this->sent[] = $body;

            $document = (string) ($body['query'] ?? '');

            // No closing paren in the needle: the document reads `login(input: $input)`.
            if (str_contains($document, 'login(input')) {
                return Http::response(['data' => ['login' => ['accessToken' => 'a-token']]]);
            }

            // The install, one package per mutation.
            if (str_contains($document, 'updateExtension')) {
                $pkgName = (string) (($body['variables']['id'] ?? ''));

                if (isset($this->refuses[$pkgName])) {
                    return Http::response(['errors' => [['message' => $this->refuses[$pkgName]]]]);
                }

                $this->installed[$pkgName] = true;

                return Http::response(['data' => ['updateExtension' => ['clientMutationId' => null]]]);
            }

            if (str_contains($document, 'sources(first:')) {
                return Http::response(['data' => ['sources' => ['nodes' => array_map(
                    fn (string $package): array => [
                        'id' => (string) crc32($package),
                        'name' => $package,
                        'extension' => [
                            'pkgName' => $package,
                            'isInstalled' => $this->installed[$package] ?? false,
                        ],
                    ],
                    array_keys($this->installed),
                )]]]);
            }

            return Http::response(['data' => []]);
        });

        return new ExtensionInstaller(
            $this->app->make(ExtensionManager::class),
            new SeriesLocator($this->app->make(Gateway::class)),
        );
    }

    /**
     * @param  list<array{0: string, 1: int, 2: bool}>  $rows  Package, series count, obsolete.
     * @return Collection<int, BackupSource>
     */
    private function sources(array $rows): Collection
    {
        $id = 2_848_420_679_472_350_000;

        return new Collection(array_map(
            fn (array $row): BackupSource => new BackupSource(
                sourceId: $id++,
                package: $row[0],
                seriesCount: $row[1],
                obsolete: $row[2],
                name: $row[0],
            ),
            $rows,
        ));
    }

    /**
     * How many install mutations were sent.
     */
    private function installCalls(): int
    {
        return count(array_filter(
            $this->sent,
            fn (array $payload): bool => str_contains((string) ($payload['query'] ?? ''), 'updateExtension'),
        ));
    }

    /**
     * The packages an install asked for, in order.
     *
     * @return list<string>
     */
    private function installedTargets(): array
    {
        return array_values(array_map(
            fn (array $payload): string => (string) ($payload['variables']['id'] ?? ''),
            array_filter(
                $this->sent,
                fn (array $payload): bool => str_contains((string) ($payload['query'] ?? ''), 'updateExtension'),
            ),
        ));
    }

    /*
     * ---------------------------------------------------------------------------
     * Survey: what is missing, without changing anything.
     * ---------------------------------------------------------------------------
     */

    public function test_surveying_reports_the_gap_without_installing_anything(): void
    {
        $this->installed = ['eu.kanade.tachiyomi.extension.en.thunderscans' => true];

        $sources = $this->installer()->survey($this->sources([
            ['eu.kanade.tachiyomi.extension.en.thunderscans', 332, false],
            ['eu.kanade.tachiyomi.extension.en.sanascans', 83, false],
        ]));

        $this->assertTrue($sources[0]->isInstalled);
        $this->assertFalse($sources[1]->isInstalled);
        $this->assertSame(0, $this->installCalls(), 'surveying asks the server, it does not change it');
    }

    public function test_a_source_with_no_package_can_never_be_matched(): void
    {
        $this->installed = ['eu.kanade.tachiyomi.extension.en.thunderscans' => true];

        $sources = $this->installer()->survey(new Collection([
            new BackupSource(1, null, 17, name: 'Nameless'),
        ]));

        $this->assertFalse(
            $sources[0]->isInstalled,
            'no package means no way to name an extension, so it is missing rather than present',
        );
    }

    /*
     * ---------------------------------------------------------------------------
     * Install.
     * ---------------------------------------------------------------------------
     */

    public function test_it_installs_the_gap_and_reports_what_it_got(): void
    {
        $this->installed = ['eu.kanade.tachiyomi.extension.en.thunderscans' => true];

        $sources = $this->installer()->survey($this->sources([
            ['eu.kanade.tachiyomi.extension.en.thunderscans', 332, false],
            ['eu.kanade.tachiyomi.extension.en.sanascans', 83, false],
        ]));

        $result = $this->installer()->install($sources);

        $this->assertSame(1, $result['already']);
        $this->assertSame(1, $result['installed'], 'the one that was missing landed');
        $this->assertSame(0, $result['still_missing']);

        // Re-surveyed in place, so a caller reporting on the gap reads the same objects.
        $this->assertTrue($sources[1]->isInstalled);
    }

    public function test_it_asks_for_exactly_the_packages_it_is_missing_and_nothing_else(): void
    {
        /*
         * The property the old route could not have. `FetchExtensionsInput` takes no filter, so
         * the previous installer could only fetch all 1,428 extensions in the store or none of
         * them. Measured against the live server: 17 installed, asked for one, got 18.
         */
        $this->installed = ['eu.kanade.tachiyomi.extension.en.thunderscans' => true];

        $sources = $this->installer()->survey($this->sources([
            ['eu.kanade.tachiyomi.extension.en.thunderscans', 332, false],
            ['eu.kanade.tachiyomi.extension.en.sanascans', 83, false],
            ['eu.kanade.tachiyomi.extension.de.onepiece', 0, false],
        ]));

        $this->installer()->install($sources);

        $this->assertSame(
            ['eu.kanade.tachiyomi.extension.en.sanascans'],
            $this->installedTargets(),
            'one mutation for the one package that was missing. The zero-series source carries no series, so it is not asked for.',
        );
    }

    public function test_it_never_asks_for_the_whole_store(): void
    {
        $this->installed = [];

        $sources = $this->installer()->survey($this->sources([
            ['eu.kanade.tachiyomi.extension.en.thunderscans', 332, false],
        ]));

        $this->installer()->install($sources);

        $this->assertSame(1, $this->installCalls());

        $fetchCalls = array_filter(
            $this->sent,
            fn (array $payload): bool => str_contains((string) ($payload['query'] ?? ''), 'fetchExtensions'),
        );

        $this->assertCount(
            0,
            $fetchCalls,
            'fetchExtensions installs nothing, measured against the live server, so sending it was the original bug.',
        );
    }

    public function test_it_does_not_install_when_there_is_nothing_to_install(): void
    {
        $this->installed = ['eu.kanade.tachiyomi.extension.en.thunderscans' => true];

        $sources = $this->installer()->survey($this->sources([
            ['eu.kanade.tachiyomi.extension.en.thunderscans', 332, false],
        ]));

        $result = $this->installer()->install($sources);

        $this->assertSame(0, $result['installed']);
        $this->assertSame(0, $this->installCalls());
    }

    public function test_a_dry_run_reports_the_gap_and_touches_nothing(): void
    {
        $sources = $this->installer()->survey($this->sources([
            ['eu.kanade.tachiyomi.extension.en.sanascans', 83, false],
        ]));

        $result = $this->installer()->install($sources, dryRun: true);

        $this->assertSame(1, $result['attempted'], 'it says what it would have done');
        $this->assertSame(0, $result['installed']);
        $this->assertSame(0, $this->installCalls());
        $this->assertFalse($sources[0]->isInstalled, 'a dry run leaves the pre-install state visible');
    }

    public function test_a_dead_source_is_never_offered_for_install(): void
    {
        /*
         * Utoon carried 117 series on the real backup, more than most live sources, and no store
         * carries it. Asking anyway would spend a request to change nothing, and reporting it as
         * merely missing would send a reader hunting for an extension that does not exist.
         */
        $sources = $this->installer()->survey($this->sources([
            ['eu.kanade.tachiyomi.extension.en.utoon', 117, true],
        ]));

        $result = $this->installer()->install($sources);

        $this->assertSame(0, $this->installCalls());
        $this->assertSame(0, $result['attempted']);
        $this->assertSame(0, $result['installed']);
        $this->assertSame(117, $result['obsolete'], 'the 117 series are reported as unreachable, not as pending');
    }

    public function test_a_source_with_no_extension_row_is_reported_separately_from_a_dead_one(): void
    {
        $sources = $this->installer()->survey(new Collection([
            new BackupSource(1, null, 17, name: 'Nameless'),
            new BackupSource(2, 'eu.kanade.tachiyomi.extension.en.utoon', 117, obsolete: true),
        ]));

        $result = $this->installer()->install($sources);

        $this->assertSame(1, $result['still_missing'], 'no package and not obsolete is its own case');
        $this->assertSame(117, $result['obsolete']);
        $this->assertSame(0, $this->installCalls(), 'there is nothing here the server could be asked to install');
    }

    /*
     * ---------------------------------------------------------------------------
     * Partial failure.
     * ---------------------------------------------------------------------------
     */

    public function test_one_refused_package_does_not_abandon_the_others(): void
    {
        $this->refuses = ['eu.kanade.tachiyomi.extension.en.broken' => 'extension not found in any store'];

        $sources = $this->installer()->survey($this->sources([
            ['eu.kanade.tachiyomi.extension.en.thunderscans', 332, false],
            ['eu.kanade.tachiyomi.extension.en.broken', 40, false],
        ]));

        $result = $this->installer()->install($sources);

        /*
         * 31 of 32 installs and one refusal must not become 0 installed. The alternative is a
         * reader who loses 1,000 series because one package moved and they are told nothing.
         */
        $this->assertSame(1, $result['installed'], 'the package that worked still landed');
        $this->assertSame(
            ['eu.kanade.tachiyomi.extension.en.broken' => 'extension not found in any store'],
            $result['refused'],
        );
        $this->assertSame(1, $result['still_missing'], 'the one that refused is the one still missing');
        $this->assertTrue($sources[0]->isInstalled);
        $this->assertFalse($sources[1]->isInstalled);
    }

    public function test_a_refusal_is_described_rather_than_swallowed(): void
    {
        $this->assertSame('', ExtensionInstaller::describeRefusals([]), 'nothing refused says nothing');

        $one = ExtensionInstaller::describeRefusals([
            'eu.kanade.tachiyomi.extension.en.a' => 'no jar',
        ]);

        $this->assertStringContainsString('1 package could not be installed', $one);
        $this->assertStringContainsString('no jar', $one, 'the server said why and a reader needs it');
        $this->assertStringContainsString('eu.kanade.tachiyomi.extension.en.a', $one, 'and which package');

        $many = ExtensionInstaller::describeRefusals([
            'a' => 'x', 'b' => 'x', 'c' => 'x', 'd' => 'x', 'e' => 'x',
        ]);

        $this->assertStringContainsString('5 packages could not be installed', $many);
        $this->assertStringContainsString('and 2 more', $many, 'a long list is summarised, not dumped');
    }
}
