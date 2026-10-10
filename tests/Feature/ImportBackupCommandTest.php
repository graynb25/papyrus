<?php

namespace Tests\Feature;

use App\Import\BackupImporter;
use App\Import\BackupReaders;
use App\Import\ExtensionInstaller;
use App\Import\SeriesResolver;
use App\Import\SeriesWriter;
use App\Import\Tachimanga\TachimangaReader;
use App\Models\ImportRun;
use App\Models\User;
use App\Support\ReadingState;
use App\Suwayomi\ExtensionManager;
use App\Suwayomi\Gateway;
use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SeriesLocator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\BuildsTachimangaBackups;
use Tests\TestCase;

/**
 * `import:run`, the command a reader actually types.
 *
 * ## Why the command is tested separately from the importer
 *
 * Because the report is the deliverable, not the writing. An import of 1,277 series where
 * 1,082 arrive and 195 sit on sources that no longer exist is a *correct* run, and the only
 * thing that makes it feel honest to the person who ran it is the last screen. Every test here
 * is about whether the sentences are right, because a run that does the right thing and says
 * the wrong thing has still misled someone.
 *
 * The writing itself is covered by {@see BackupImporterTest}, which has the fake server and
 * the fixture already.
 */
class ImportBackupCommandTest extends TestCase
{
    use BuildsTachimangaBackups;
    use RefreshDatabase;

    private const THUNDER_SCANS = '2848420679472350044';

    private const THUNDER_PACKAGE = 'eu.kanade.tachiyomi.extension.en.thunderscans';

    private User $user;

    private ?string $built = null;

    /** @var array<string, int> Url to manga id, for the catalogue this fake serves. */
    private array $catalogue = [self::FIRST => 1768];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        if ($this->built !== null && is_file($this->built)) {
            @unlink($this->built);
        }

        parent::tearDown();
    }

    /*
     * ---------------------------------------------------------------------------
     * The fake server and the wiring.
     * ---------------------------------------------------------------------------
     */

    /**
     * @param  array<string, int>  $catalogue
     * @param  list<string>  $installedPackages  Packages this server has extensions for.
     */
    private function fake(array $catalogue, array $installedPackages = [self::THUNDER_PACKAGE]): void
    {
        $this->catalogue = $catalogue;

        Http::fake(function (Request $request) use ($installedPackages) {
            $body = json_decode((string) $request->body(), true) ?: [];
            $document = (string) ($body['query'] ?? '');

            if (str_contains($document, 'login(input')) {
                return Http::response(['data' => ['login' => ['accessToken' => 'a-token']]]);
            }

            if (str_contains($document, 'sources(first:')) {
                $nodes = [];

                foreach ($installedPackages as $package) {
                    $nodes[] = [
                        'id' => self::THUNDER_SCANS,
                        'name' => $package,
                        'extension' => ['pkgName' => $package, 'isInstalled' => true],
                    ];
                }

                return Http::response(['data' => ['sources' => ['nodes' => $nodes]]]);
            }

            // The install, one package per mutation. This is the route that works.
            if (str_contains($document, 'updateExtension')) {
                $pkgName = (string) (($body['variables']['id'] ?? ''));

                $this->installed[$pkgName] = true;

                return Http::response(['data' => ['updateExtension' => ['clientMutationId' => null]]]);
            }

            if (str_contains($document, 'fetchExtensions')) {
                return Http::response(['data' => ['fetchExtensions' => ['extensions' => []]]]);
            }

            if (str_contains($document, 'query Series')) {
                $mangaId = (int) ($body['variables']['id'] ?? 0);

                return Http::response(['data' => ['manga' => [
                    'id' => $mangaId,
                    'title' => 'A Series',
                    'url' => '/comics/x/',
                    'realUrl' => null,
                    'source' => ['id' => self::THUNDER_SCANS, 'name' => 'Thunder', 'lang' => 'en'],
                    'initialized' => true,
                    'chaptersLastFetchedAt' => 1_700_000_000_000,
                    'chapters' => [
                        'totalCount' => 2,
                        'nodes' => [
                            $this->serverChapter($mangaId * 100 + 1, 0, 1),
                            $this->serverChapter($mangaId * 100 + 2, 1, 2),
                        ],
                    ],
                ]]]);
            }

            $hit = null;

            foreach ((array) ($body['variables']['forms'] ?? []) as $form) {
                if (isset($this->catalogue[$form])) {
                    $hit = $this->catalogue[$form];

                    break;
                }
            }

            return Http::response(['data' => ['mangas' => [
                'nodes' => $hit === null ? [] : [[
                    'id' => (string) $hit,
                    'title' => 'A Series',
                    'url' => '/comics/x/',
                    'realUrl' => null,
                    'sourceId' => self::THUNDER_SCANS,
                ]],
                'totalCount' => $hit === null ? 0 : 1,
            ]]]);
        });

        // Built here rather than resolved from the container, so the test's own catalogue
        // reaches the resolver. The container's importer would be wired to a real server.
        $locator = new SeriesLocator($this->app->make(Gateway::class));

        $this->app->instance(BackupImporter::class, new BackupImporter(
            readers: new BackupReaders([$this->app->make(TachimangaReader::class)]),
            installer: new ExtensionInstaller($this->app->make(ExtensionManager::class), $locator),
            resolver: new SeriesResolver($locator),
            writer: new SeriesWriter($this->app->make(ReadingState::class), $this->app->make(SeriesGateway::class)),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function serverChapter(int $id, int $sourceOrder, int $number): array
    {
        return [
            'id' => $id,
            'name' => "Chapter $number",
            'chapterNumber' => $number,
            'scanlator' => null,
            'uploadDate' => 0,
            'pageCount' => 20,
            'isRead' => false,
            'isDownloaded' => false,
            'lastPageRead' => 0,
            'sourceOrder' => $sourceOrder,
        ];
    }

    private const FIRST = '/comics/hiding-a-logistics-center-in-the-apo';

    private const SECOND = '/comics/the-berserkers-second-playthrough';

    private function backup(): string
    {
        return $this->built = $this->buildTachimangaBackup();
    }

    /**
     * The one run this command wrote, read the way the command reads it.
     *
     * Through `forUser()` because nobody is authenticated here, and a command run from a shell
     * has nobody authenticated either. That is the whole point of this suite, so asserting
     * through the global scope would be asserting about a situation that cannot occur.
     */
    private function theRun(): ImportRun
    {
        return $this->latestRun();
    }

    /**
     * The most recent run this user made.
     *
     * The latest rather than the only, because a resumability test runs the command twice on
     * purpose. `sole()` on that is a failure saying "2 records were found", which reads as a
     * bug in the import rather than as the test having done what it meant to.
     */
    private function latestRun(): ImportRun
    {
        return ImportRun::query()->forUser($this->user)->latest('id')->firstOrFail();
    }

    /*
     * ---------------------------------------------------------------------------
     * The report.
     * ---------------------------------------------------------------------------
     */

    public function test_a_clean_run_says_what_came_across(): void
    {
        $this->fake([self::FIRST => 1768, self::SECOND => 1769]);

        $this->artisan('import:run', ['path' => $this->backup()])
            ->expectsOutputToContain('Imported 2 of 2 series (100%)')
            ->assertSuccessful();

        $this->assertSame(ImportRun::COMPLETE, $this->theRun()->state);
    }

    public function test_it_says_what_did_not_come_across_and_what_to_do_about_it(): void
    {
        $this->fake([self::FIRST => 1768]);

        $this->artisan('import:run', ['path' => $this->backup()])
            ->expectsOutputToContain('Imported 1 of 2 series (50%)')
            ->expectsOutputToContain('1 series was asked about and is not at that address on its source')
            ->assertSuccessful();
    }

    public function test_it_names_the_sources_that_no_longer_exist(): void
    {
        $this->withUnreachableSources = true;
        $this->fake([], installedPackages: []);

        $this->artisan('import:run', ['path' => $this->backup()])
            ->expectsOutputToContain('These sources no longer exist')
            // The source is named, not just counted. A reader told "117 series are missing"
            // cannot act; one told it was Utoon recognises the loss.
            ->expectsOutputToContain('Utoon no longer exists')
            ->assertSuccessful();
    }

    public function test_it_says_a_second_run_has_nothing_to_do(): void
    {
        $catalogue = [self::FIRST => 1768, self::SECOND => 1769];

        $this->fake($catalogue);
        $this->artisan('import:run', ['path' => $this->backup()])->assertSuccessful();

        $this->fake($catalogue);

        // Printed rather than asserted, because the previous version of this test asserted
        // "Nothing to do" and passed while the command printed something else entirely. The
        // bug was real: the sentence claimed the whole library matched when the run had only
        // looked at three series. Printing is what found it.
        $out = new BufferedOutput;
        $this->app->make(Kernel::class)->call('import:run', ['path' => $this->backup()], $out);

        $printed = $out->fetch();

        $this->assertStringContainsString('Nothing to do', $printed);

        // And the count is about this run, not the whole library.
        $this->assertStringContainsString('2 series already match', $printed);
        $this->assertStringNotContainsString(
            'not all 2',
            $printed,
            'the run looked at the whole library here, so there is no "the rest" to mention',
        );
    }

    public function test_a_partial_run_does_not_claim_the_whole_library_matched(): void
    {
        /*
         * The bug this holds. `--limit=1` on a fresh import applied one series, so a later
         * limited run saw one unchanged series against a library of 2 and printed "All 2 series
         * already match what is here". That is a claim about a series it never looked at, and
         * it is what stops a reader re-running the command.
         */
        $catalogue = [self::FIRST => 1768, self::SECOND => 1769];

        $this->fake($catalogue);
        $this->artisan('import:run', ['path' => $this->backup()])->assertSuccessful();

        $this->fake($catalogue);
        $this->artisan('import:run', ['path' => $this->backup(), '--limit' => 1])->assertSuccessful();

        $out = new BufferedOutput;
        $this->app->make(Kernel::class)
            ->call('import:run', ['path' => $this->backup(), '--limit' => 1], $out);

        $printed = $out->fetch();

        $this->assertStringContainsString('Nothing to do', $printed);
        $this->assertStringContainsString(
            '1 series already matches this backup',
            $printed,
            'the count is what this run skipped',
        );
        $this->assertStringContainsString(
            'not all 2',
            $printed,
            'and it says so, because 2 series is the library and 1 is what was checked',
        );
    }

    public function test_it_refuses_a_missing_file_without_creating_a_run(): void
    {
        $this->fake([self::FIRST => 1768]);

        $this->artisan('import:run', ['path' => 'no-such-file.tmb'])
            ->expectsOutputToContain('There is no file at')
            ->assertFailed();

        $this->assertSame(0, ImportRun::query()->count(), 'a file that does not exist is not an attempt');
    }

    public function test_it_refuses_a_file_it_cannot_read_and_records_why(): void
    {
        $this->fake([self::FIRST => 1768]);
        $this->version = 4;

        $this->artisan('import:run', ['path' => $this->backup()])
            ->expectsOutputToContain('Nothing was imported. The attempt was recorded.')
            ->assertFailed();

        $this->assertSame(ImportRun::REFUSED, $this->theRun()->state);
    }

    public function test_a_dry_run_reports_the_file_and_writes_nothing(): void
    {
        $this->fake([self::FIRST => 1768]);

        $this->artisan('import:run', ['path' => $this->backup(), '--dry-run' => true])
            ->expectsOutputToContain('Nothing was imported')
            ->assertSuccessful();

        $this->assertSame(0, ImportRun::query()->count(), 'a dry run is not an attempt and leaves no run');
    }

    public function test_a_limited_run_says_how_to_carry_on(): void
    {
        $this->fake([self::FIRST => 1768, self::SECOND => 1769]);

        $this->artisan('import:run', ['path' => $this->backup(), '--limit' => 1])
            // A short fragment, not the whole sentence. Symfony wraps console output to the
            // terminal width, so a needle that spans a wrap point never matches no matter
            // what the command prints. That is worth knowing before writing a test against a
            // long sentence: the failure looks like the command is silent.
            ->expectsOutputToContain('Stopping after 1 series')
            ->assertSuccessful();

        $this->assertSame(1, $this->theRun()->applied);

        /*
         * And the run really is resumable, which is what the warning tells the reader to do.
         * A second run without the limit finishes the job rather than starting over.
         */
        $this->artisan('import:run', ['path' => $this->backup()])->assertSuccessful();

        /*
         * Each run writes the series it had not already written. `applied` is per run, not
         * cumulative, so the resume shows up as one written and one skipped rather than as two
         * written. Asserting a cumulative total here would be asserting a number the schema
         * does not store.
         */
        $this->assertSame(1, $this->latestRun()->applied, 'the one the first run had not reached');
        $this->assertSame(1, $this->latestRun()->unchanged, 'and it did not redo the other');

        $this->assertSame(
            2,
            ImportRun::query()->forUser($this->user)->sum('applied'),
            'two series across both runs, which is the whole library',
        );
    }

    public function test_no_install_leaves_the_extensions_alone(): void
    {
        $this->fake([], installedPackages: []);

        $this->artisan('import:run', ['path' => $this->backup(), '--no-install' => true])
            ->assertSuccessful();

        /*
         * Asserting on the mutation that installs, not on the one that used to.
         *
         * This test used to assert `fetchExtensions` was absent, which is a check on a route
         * that installs nothing and so was passing while the real install never happened. The
         * needle is `updateExtension`, which is the mutation that changes the server.
         */
        Http::assertSent(fn (Request $r): bool => str_contains(
            (string) (json_decode((string) $r->body(), true)['query'] ?? ''),
            'updateExtension',
        ) === false);

        // The gap is still recorded, so a later run knows what to retry.
        $this->assertGreaterThan(
            0,
            $this->latestRun()->sources_missing,
            'nothing was installed, so the sources that were missing are still missing and must be recorded as such',
        );
    }

    public function test_it_imports_into_the_user_it_is_told_to(): void
    {
        $other = User::factory()->create();
        $this->fake([self::FIRST => 1768]);

        $this->artisan('import:run', [
            'path' => $this->backup(),
            '--user' => $other->getKey(),
        ])->assertSuccessful();

        /*
         * Read through `forUser($other)`, because that is the only reader that finds it: the
         * run belongs to the user the command was told, and the default user must have none.
         */
        $this->assertSame(
            $other->getKey(),
            ImportRun::query()->forUser($other)->sole()->user_id,
            'a command run from a shell has no session, so the user must be explicit',
        );

        $this->assertSame(
            0,
            ImportRun::query()->forUser($this->user)->count(),
            'and the first user on the server must not have been written to',
        );
    }
}
