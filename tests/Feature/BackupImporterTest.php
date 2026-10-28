<?php

namespace Tests\Feature;

use App\Import\BackupImporter;
use App\Import\BackupReader;
use App\Import\BackupReaders;
use App\Import\ExtensionInstaller;
use App\Import\ImportRefused;
use App\Import\SeriesResolver;
use App\Import\SeriesWriter;
use App\Import\Tachibk\TachibkReader;
use App\Import\Tachimanga\TachimangaReader;
use App\Models\ChapterState;
use App\Models\ImportedReadingDay;
use App\Models\ImportedSeries as Ledger;
use App\Models\ImportedSource;
use App\Models\ImportRun;
use App\Models\SeriesState;
use App\Models\User;
use App\Support\ReadingState;
use App\Suwayomi\ExtensionManager;
use App\Suwayomi\Gateway;
use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SeriesLocator;
use App\Suwayomi\SourceCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsTachibkBackups;
use Tests\Support\BuildsTachimangaBackups;
use Tests\TestCase;

/**
 * The whole import, from a file on disk to a library.
 *
 * ## What this suite is for
 *
 * The three phases before it each have a suite, and each of those runs one part in isolation.
 * This one runs them together against a real archive, because the failures that matter most in
 * an import are the ones only the sequence produces:
 *
 * 1. **A second run does nothing.** The ledger exists so that re-importing applies only the
 *    delta. A reader who runs this twice must not get their library written twice.
 * 2. **An interrupted run resumes.** 1,277 series is eleven minutes of requests, and a run
 *    that stops half way must continue rather than start over.
 * 3. **The numbers add up.** Every series lands in exactly one bucket and the buckets sum to
 *    the library size. An earlier version counted ambiguous resolutions as both not-found
 *    and ambiguous, and reported 1,290 accounted for out of 1,277.
 * 4. **A refusal leaves a run behind.** "It did nothing" and "it worked" are different
 *    outcomes, and the first is the one a log file loses.
 *
 * ## The fixture's two shelf series
 *
 * `Hiding a Logistics Center` carries four chapters and the Thunder Scans source id; `The
 * Berserker's Second Playthrough` carries one chapter and the same source. The second exists
 * because a real library has series with no chapters and an import must not assume otherwise.
 */
class BackupImporterTest extends TestCase
{
    use BuildsTachibkBackups;
    use BuildsTachimangaBackups;
    use RefreshDatabase;

    /** The real Thunder Scans source id, which is past 2^53. */
    private const THUNDER_SCANS = '2848420679472350044';

    private const THUNDER_PACKAGE = 'eu.kanade.tachiyomi.extension.en.thunderscans';

    /** The url the reader normalises the first shelf series to. */
    private const FIRST = '/comics/hiding-a-logistics-center-in-the-apo';

    /** The url the reader normalises the second shelf series to. */
    private const SECOND = '/comics/the-berserkers-second-playthrough';

    private User $user;

    private ?string $built = null;

    /** @var list<string> Every progress line the importer emitted, in order. */
    private array $lines = [];

    /**
     * Manga ids whose series read answers an unreadable error.
     *
     * The measured real message was "Could not find environment bundle", which is a source
     * extension failing to ship its JS bundle. The entry exists, the series is real, and the
     * answer is a transport fault rather than a fact about the library, which is why it needs
     * its own outcome and not one of the resolution tiers.
     *
     * @var array<int, int>
     */
    private array $seriesThatThrow = [];

    /**
     * The manga this server's catalogue holds, keyed by the backup's normalised url.
     *
     * @var array<string, int>
     */
    private array $catalogue = [];

    /**
     * Source id to package, for sources this server has an extension installed for.
     *
     * Keyed by source id because that is what a source node must carry: the resolver looks a
     * source up by id, and the fake has to hand back that same id. An earlier version of this
     * fake stored the package as the key and used `array_search` to recover the id, which
     * turned `2848420679472350044` into `0`. Every lookup then missed and the suite failed on
     * "0 resolved" rather than on the fake, which is a hard way to learn anything.
     *
     * @var array<string, string>
     */
    private array $installed = [self::THUNDER_SCANS => self::THUNDER_PACKAGE];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->lines = [];
        $this->catalogue = [];
        $this->installed = [self::THUNDER_SCANS => self::THUNDER_PACKAGE];
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
     * The fake server.
     * ---------------------------------------------------------------------------
     */

    /**
     * @param  array<string, int>  $catalogue  Url to manga id.
     * @param  array<int, BackupReader>|null  $readers  Null means the tachimanga reader alone.
     */
    private function importer(array $catalogue = [], ?array $readers = null): BackupImporter
    {
        $this->catalogue = $catalogue;

        Http::fake(function (Request $request) {
            $body = json_decode((string) $request->body(), true) ?: [];
            $document = (string) ($body['query'] ?? '');

            // No closing paren in the needle: the document reads `login(input: $input)`.
            if (str_contains($document, 'login(input')) {
                return Http::response(['data' => ['login' => ['accessToken' => 'a-token']]]);
            }

            if (str_contains($document, 'sources(first:')) {
                $nodes = [];

                foreach ($this->installed as $sourceId => $package) {
                    $nodes[] = [
                        'id' => (string) $sourceId,
                        'name' => $package,
                        'extension' => ['pkgName' => $package, 'isInstalled' => true],
                    ];
                }

                return Http::response(['data' => ['sources' => ['nodes' => $nodes]]]);
            }

            // The store fetch. The installer installs from here, never from the backup's jars.
            // The install, one package per mutation. This is the route that works.
            if (str_contains($document, 'updateExtension')) {
                $pkgName = (string) (($body['variables']['id'] ?? ''));

                $this->installed[$pkgName] = true;

                return Http::response(['data' => ['updateExtension' => ['clientMutationId' => null]]]);
            }

            if (str_contains($document, 'fetchExtensions')) {
                return Http::response(['data' => ['fetchExtensions' => ['extensions' => []]]]);
            }

            // Tier 2: ask the source. This fixture never needs it, because a catalogue hit is
            // tier 1 and a miss is a genuine absence, so the source is never reached.
            if (str_contains($document, 'fetchSourceManga')) {
                return Http::response(['data' => ['fetchSourceManga' => [
                    'hasNextPage' => false,
                    'mangas' => [],
                ]]]);
            }

            /*
             * The series read, chapters included.
             *
             * Two chapters per manga with `sourceOrder` 0 and 1, which is what the fixture's
             * first series pairs against. Suwayomi needs `fetchChapters` run before a series
             * will report any chapters at all, and a resolved series with none fetched reads
             * exactly like a source that has none. Answering the read is what stops the
             * importer deciding the reader's read state has nowhere to go.
             *
             * The second shelf series has one backup chapter and this gives it two, which is
             * the realistic case: a source that has published more since the backup was taken.
             * The extra is not read, so it must not be touched.
             */
            if (str_contains($document, 'query Series')) {
                $mangaId = (int) ($body['variables']['id'] ?? 0);

                if (in_array($mangaId, $this->seriesThatThrow, true)) {
                    // A GraphQL error on HTTP 200, which is the shape Suwayomi actually uses.
                    return Http::response(['errors' => [[
                        'message' => 'Could not find environment bundle',
                    ]]]);
                }

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

            // Tier 1: the catalogue lookup, which carries every url form as a variable.
            $forms = (array) ($body['variables']['forms'] ?? []);
            $hit = null;

            foreach ($forms as $form) {
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

        $locator = new SeriesLocator($this->app->make(Gateway::class));

        $importer = new BackupImporter(
            readers: new BackupReaders($readers ?? [$this->app->make(TachimangaReader::class)]),
            installer: new ExtensionInstaller($this->app->make(ExtensionManager::class), $locator),
            resolver: new SeriesResolver($locator),
            writer: new SeriesWriter($this->app->make(ReadingState::class), $this->app->make(SeriesGateway::class)),
            extensions: $this->app->make(ExtensionManager::class),
            catalog: $this->app->make(SourceCatalog::class),
        );

        $importer->onProgress(function (string $line): void {
            $this->lines[] = $line;
        });

        return $importer;
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

    private function backup(): string
    {
        return $this->built = $this->buildTachimangaBackup();
    }

    /**
     * The counters from the run that has happened, so a test can assert on them after the
     * fact instead of holding the return value across a second importer.
     *
     * @return array<string, int>
     */
    private function firstRun(): array
    {
        $run = ImportRun::query()->latest('id')->firstOrFail();

        return [
            'applied' => $run->applied,
            'unchanged' => $run->unchanged,
            'resolved' => $run->resolved,
        ];
    }

    /*
     * ---------------------------------------------------------------------------
     * A whole run.
     * ---------------------------------------------------------------------------
     */

    public function test_it_imports_a_backup_and_files_what_it_could_not(): void
    {
        $result = $this->importer([self::FIRST => 1768])->import($this->user, $this->backup());

        $this->assertSame(2, $result->count('library_series'));
        $this->assertSame(1, $result->count('resolved'), 'one series is in the catalogue and the other is not');
        $this->assertSame(1, $result->count('from_catalogue'));
        $this->assertSame(1, $result->count('not_found'));
        $this->assertSame(1, $result->count('applied'));

        $run = ImportRun::sole();

        $this->assertSame(ImportRun::COMPLETE, $run->state);
        $this->assertSame('tachimanga', $run->format);
        $this->assertSame(2, $run->library_series);
        $this->assertSame(4, $run->series_in_file, 'the browse cache is counted in the file total');
        $this->assertSame(1, $run->resolved);
        $this->assertSame(1, $run->applied);
        $this->assertSame(1, $run->not_found);
        $this->assertGreaterThan(0, $run->read_chapters_applied, 'the reader had read chapters and they arrived');
    }

    public function test_the_buckets_add_up_to_the_library(): void
    {
        $result = $this->importer([self::FIRST => 1768])->import($this->user, $this->backup());

        /*
         * Measured on the real backup, this is the check that matters: an import reporting
         * 1,082 imported for a library of 1,277 is lying by omission unless every remaining
         * series is in a named bucket. An earlier version double-counted, so this summed to
         * more than the library and the error read as arithmetic rather than as a bug.
         */
        $this->assertSame(
            $result->count('library_series'),
            $result->count('resolved') + $result->count('not_found') + $result->count('ambiguous')
                + $result->count('missing_source') + $result->count('unreachable_source') + $result->count('unchanged'),
        );
    }

    public function test_a_series_whose_source_is_absent_is_filed_as_missing_not_not_found(): void
    {
        $this->installed = []; // this server has nothing at all

        $result = $this->importer()->import($this->user, $this->backup());

        $this->assertSame(2, $result->count('missing_source'));
        $this->assertSame(0, $result->count('not_found'), 'nothing was asked, so nothing was found missing');
        $this->assertSame(2, Ledger::query()->where('state', Ledger::MISSING_SOURCE)->count());

        $row = Ledger::query()->where('state', Ledger::MISSING_SOURCE)->first();
        $this->assertStringContainsString('does not have', (string) $row->reason);
        $this->assertNull($row->fingerprint, 'never resolved means no fingerprint to compare against');
    }

    public function test_the_ledger_is_written_for_everything_the_run_looked_at(): void
    {
        $this->importer([self::FIRST => 1768])->import($this->user, $this->backup());

        $this->assertSame(2, Ledger::query()->count(), 'a series that did not resolve is still filed');

        $this->assertNotNull(
            Ledger::query()->where('state', Ledger::RESOLVED)->sole()->fingerprint,
            'a resolved series carries the fingerprint a later run compares against',
        );
    }

    public function test_read_state_arrives_on_the_series_the_catalogue_had(): void
    {
        $this->importer([self::FIRST => 1768])->import($this->user, $this->backup());

        /*
         * The fixture marks chapters 1 and 2 of the first series read, the second bookmarked.
         * Those become rows against manga 1768, so the check is that the read state landed on
         * the id the resolver produced rather than on the backup's own chapter ids, which are
         * meaningless here.
         */
        $read = DB::table('chapter_states')->where('user_id', $this->user->id)->where('is_read', true)->count();

        $this->assertSame(2, $read);
        $this->assertSame(
            [1, 2],
            DB::table('chapter_states')
                ->where('user_id', $this->user->id)
                ->where('is_read', true)
                ->orderBy('id')
                ->pluck('suwayomi_chapter_id')
                ->map(fn ($id): int => $id - 1768 * 100)
                ->all(),
            'the two read chapters are the server chapters the backup numbered 0 and 1',
        );

        $this->assertSame(
            1,
            DB::table('chapter_states')->where('user_id', $this->user->id)->where('is_bookmarked', true)->count(),
        );
    }

    public function test_the_browse_cache_is_never_imported(): void
    {
        $this->importer()->import($this->user, $this->backup());

        $this->assertSame(
            0,
            DB::table('series_states')->where('user_id', $this->user->id)->count(),
            '132,185 rows of browse cache in the real file, two here, and none are a library',
        );

        $this->assertSame(2, Ledger::query()->count());
    }

    /*
     * ---------------------------------------------------------------------------
     * Filing what has no source behind it.
     * ---------------------------------------------------------------------------
     */

    public function test_a_series_whose_source_is_absent_is_filed_by_title_alone(): void
    {
        /*
         * The choice a reader makes about a source that has shut down is rarely "throw the
         * titles away". Measured on the real backup: 195 series on 7 dead sources, 15% of a
         * library. A library that quietly lost them teaches the reader the import was lossy;
         * one that shows them greyed out teaches them what happened.
         */
        $this->installed = []; // this server has no extension for anything

        $this->importer()->import($this->user, $this->backup());

        $filed = SeriesState::forUser($this->user)
            ->whereNull('suwayomi_manga_id')
            ->get();

        $this->assertCount(2, $filed, 'both shelf series are filed');
        $this->assertSame(
            ['Hiding a Logistics Center', "The Berserker's Second Playthrough"],
            $filed->pluck('title')->sort()->values()->all(),
            'the titles are what a reader recognises, and they come from the backup',
        );

        foreach ($filed as $state) {
            $this->assertTrue($state->in_library, 'filed means visible, not filed and hidden');
            $this->assertNotNull(
                $state->unavailable_reason,
                'a reader who sees a title they cannot open deserves to be told why',
            );
        }
    }

    public function test_a_series_the_source_answered_for_is_not_filed(): void
    {
        /*
         * The other unresolved case. The source was asked and did not have the series at that
         * url, which usually means the url moved. Filing it would put a title in a library
         * that opens onto nothing at the wrong address, and matching it safely is exactly what
         * the resolver refuses to do.
         */
        $this->importer([self::FIRST => 1768])->import($this->user, $this->backup());

        $this->assertSame(
            0,
            SeriesState::forUser($this->user)->whereNull('suwayomi_manga_id')->count(),
            'one series was not at its address, and that is not the same as having no source',
        );
    }

    public function test_a_resolved_series_is_never_filed_without_a_source(): void
    {
        $this->importer([self::FIRST => 1768, self::SECOND => 1769])->import($this->user, $this->backup());

        $this->assertSame(
            0,
            SeriesState::forUser($this->user)->whereNull('suwayomi_manga_id')->count(),
        );

        $this->assertSame(
            2,
            SeriesState::forUser($this->user)->whereNotNull('suwayomi_manga_id')->count(),
            'and the two that resolved have their server ids',
        );
    }

    public function test_a_second_run_does_not_file_the_same_series_twice(): void
    {
        $this->installed = [];

        $this->importer()->import($this->user, $this->backup());

        $first = SeriesState::forUser($this->user)->whereNull('suwayomi_manga_id')->pluck('id')->sort()->all();

        $this->importer()->import($this->user, $this->backup());

        $second = SeriesState::forUser($this->user)->whereNull('suwayomi_manga_id')->pluck('id')->sort()->all();

        /*
         * Unresolved series are retried every run, on purpose, because a source can come back.
         * That is the opposite hazard: a retry that re-files produces duplicate titles in a
         * reader's library on every run of the command, and grows.
         */
        $this->assertSame($first, $second, 'the same rows, not new ones');
        $this->assertCount(2, $second);
    }

    public function test_the_ledger_keeps_which_row_a_series_was_filed_as(): void
    {
        $this->installed = [];

        $this->importer()->import($this->user, $this->backup());

        $ledger = Ledger::forUser($this->user)->whereNotNull('series_state_id')->get();

        $this->assertCount(2, $ledger, 'one per filed series, which is what makes filing idempotent');

        foreach ($ledger as $row) {
            $this->assertNotNull(
                SeriesState::forUser($this->user)->find($row->series_state_id),
                'and the id points at a row this reader owns',
            );
        }
    }

    /*
     * ---------------------------------------------------------------------------
     * Re-import: the delta.
     * ---------------------------------------------------------------------------
     */

    public function test_force_re_applies_what_a_plain_run_would_skip(): void
    {
        $catalogue = [self::FIRST => 1768];

        $this->importer($catalogue)->import($this->user, $this->backup());

        /*
         * The recovery this flag exists for, stated as the ledger sees it: the fingerprint is
         * unchanged because the *file* has not changed, so the series reads as already written
         * even though an earlier version of the importer wrote it wrongly.
         */
        $skipped = $this->importer($catalogue)->import($this->user, $this->backup());

        $this->assertSame(0, $skipped->count('applied'));

        $forced = $this->importer($catalogue)->import($this->user, $this->backup(), force: true);

        $this->assertSame(
            1,
            $forced->count('applied'),
            'the same fingerprint, applied anyway, because the reader asked',
        );
        $this->assertSame(
            0,
            $forced->count('unchanged'),
            'and nothing is reported as skipped when the point was not to skip it',
        );
    }

    public function test_force_never_clears_a_read_chapter(): void
    {
        $catalogue = [self::FIRST => 1768];

        $this->importer($catalogue)->import($this->user, $this->backup());

        $before = ChapterState::query()->where('is_read', true)->count();

        $this->importer($catalogue)->import($this->user, $this->backup(), force: true);

        $this->assertSame(
            $before,
            ChapterState::query()->where('is_read', true)->count(),
            'every write is forward only, so forcing can add and cannot take away',
        );
    }

    public function test_a_series_whose_source_throws_does_not_end_the_run(): void
    {
        $catalogue = [self::FIRST => 1768, self::SECOND => 1769];

        /*
         * The shape of the real failure: one source whose extension answers with something
         * unreadable, in the middle of a walk over a thousand series.
         */
        $this->seriesThatThrow = [1769];

        $result = $this->importer($catalogue)->import($this->user, $this->backup());

        $this->assertSame(1, $result->count('applied'), 'the series that could be read was');
        $this->assertSame(
            1,
            $result->count('threw'),
            'and the one that raised is counted separately from the ones that did not resolve',
        );

        $threw = Ledger::query()->where('state', Ledger::THREW)->firstOrFail();

        $this->assertNotNull($threw->reason, 'with the reason it raised, for the report');
        $this->assertNull(
            $threw->fingerprint,
            'and no fingerprint, because a null one is what makes the next run try again',
        );
    }

    public function test_the_run_is_finished_even_when_a_series_threw(): void
    {
        $this->seriesThatThrow = [1769];

        $this->importer([self::FIRST => 1768, self::SECOND => 1769])->import($this->user, $this->backup());

        $run = ImportRun::query()->latest('id')->firstOrFail();

        $this->assertSame(ImportRun::COMPLETE, $run->state, 'not left reading running');
        $this->assertNotNull($run->finished_at);
    }

    public function test_a_second_run_of_the_same_backup_applies_nothing(): void
    {
        $catalogue = [self::FIRST => 1768];

        $first = $this->importer($catalogue)->import($this->user, $this->backup());

        $this->assertSame(1, $first->count('applied'));
        $this->assertSame(0, $first->count('unchanged'));

        $second = $this->importer($catalogue)->import($this->user, $this->backup());

        $this->assertSame(0, $second->count('applied'), 'an unchanged fingerprint means nothing to write');
        $this->assertSame(
            1,
            $second->count('unchanged'),
            'the resolved series is skipped: its fingerprint still matches',
        );
        $this->assertSame(
            1,
            $second->count('not_found'),
            'the series that was never resolved is asked again, because a source can come back',
        );

        $this->assertSame(2, Ledger::query()->count(), 'a second run does not file the same series twice');
        $this->assertSame(2, ImportRun::query()->count(), 'but it is a second run, and is kept as one');
    }

    public function test_local_reading_does_not_trigger_a_rewrite(): void
    {
        $catalogue = [self::FIRST => 1768, self::SECOND => 1769];

        $this->importer($catalogue)->import($this->user, $this->backup());

        /*
         * The reader reads a chapter here, on this server, that the backup knows nothing
         * about: the second shelf series has one chapter in the backup and the fake server
         * offers two, so this is a chapter that exists only here. Marking a chapter the backup
         * already had read would prove nothing, since the run would be skipping it anyway.
         */
        $this->app->make(ReadingState::class)->markRead($this->user, 1769, 1769 * 100 + 2, true);

        $this->assertSame(
            4,
            DB::table('chapter_states')->where('user_id', $this->user->id)->where('is_read', true)->count(),
            'three came from the backup, two for the first series and one for the second, and one was read here',
        );

        $second = $this->importer($catalogue)->import($this->user, $this->backup());

        /*
         * The fingerprint is of the backup, so local progress does not invalidate it.
         *
         * The alternative would be worse than a needless write: a reader who reads every day
         * would have every re-import rewrite every series they have read, and the fingerprint
         * would be measuring the wrong thing. What a re-import is for is the *other* app
         * changing, which is the next test.
         */
        $this->assertSame(0, $second->count('applied'));
        $this->assertSame(2, $second->count('unchanged'), 'both series still match the backup they were written from');

        // Unchanged means unwritten, so it brings no chapter rows with it.
        $this->assertSame(0, $second->count('read_chapters'));
        $this->assertSame(
            4,
            DB::table('chapter_states')->where('user_id', $this->user->id)->where('is_read', true)->count(),
            'nothing was removed. A run that writes nothing must not also clear anything.',
        );
    }

    public function test_a_changed_backup_is_applied_where_it_differs(): void
    {
        $catalogue = [self::FIRST => 1768, self::SECOND => 1769];

        $this->importer($catalogue)->import($this->user, $this->backup());

        $this->assertSame(2, $this->firstRun()['applied']);

        /*
         * A newer backup. The fixture's chapter read timestamps are the knob, and they are in
         * the fingerprint, so changing one is a real delta rather than a simulated one.
         */
        /*
         * Both series' chapters go from read to unread. This is what a reader who reset their
         * progress on the other app looks like, and it is the case the delta exists for.
         */
        $this->unreadManga = [217 => true, 9265 => true];

        $second = $this->importer($catalogue)->import($this->user, $this->backup());

        $this->assertSame(2, $second->count('applied'), 'both series changed, so both are rewritten');
        $this->assertSame(0, $second->count('unchanged'));
    }

    public function test_one_changed_series_is_applied_and_its_neighbour_is_not(): void
    {
        $catalogue = [self::FIRST => 1768, self::SECOND => 1769];

        $this->importer($catalogue)->import($this->user, $this->backup());

        // Identical backup: nothing moves.
        $second = $this->importer($catalogue)->import($this->user, $this->backup());
        $this->assertSame(0, $second->count('applied'), 'an identical backup changes nothing');

        // Only manga 9265, the second shelf series, now says its chapters were never read.
        $this->unreadManga = [9265 => true];

        $third = $this->importer($catalogue)->import($this->user, $this->backup());

        $this->assertSame(1, $third->count('applied'), 'the series whose chapters changed');
        $this->assertSame(1, $third->count('unchanged'), 'and the one whose did not, because the delta is per series');
    }

    /*
     * ---------------------------------------------------------------------------
     * Reading time carried from the other app.
     * ---------------------------------------------------------------------------
     */

    public function test_imported_reading_time_lands_in_its_own_ledger(): void
    {
        $path = $this->backup();

        $this->importer([self::FIRST => 1768, self::SECOND => 1769])->import($this->user, $path);

        $rows = ImportedReadingDay::forUser($this->user)
            ->orderBy('suwayomi_manga_id')
            ->orderBy('day')
            ->get();

        $this->assertCount(3, $rows, 'one History day for the first series and two daily rows for the second');

        $first = $rows->where('suwayomi_manga_id', 1768)->values();
        $second = $rows->where('suwayomi_manga_id', 1769)->values();

        $this->assertSame(360, $first[0]->seconds);
        $this->assertSame(
            CarbonImmutable::createFromTimestamp(1_798_301_351)->toDateString(),
            $first[0]->day,
            'a lifetime History total is dated by its last read',
        );

        $this->assertSame([100, 250], $second->pluck('seconds')->all());
        $this->assertSame(['2025-09-20', '2025-09-21'], $second->pluck('day')->all());

        $this->assertSame(
            hash_file('sha256', $path),
            $rows->first()->backup_key,
            'the ledger is keyed by the file itself, not its name',
        );

        $this->assertSame(0, DB::table('reading_sessions')->count(), 'importing time is not reading it here');
    }

    public function test_reimporting_the_same_file_replaces_its_days_rather_than_doubling_them(): void
    {
        $path = $this->backup();
        $catalogue = [self::FIRST => 1768, self::SECOND => 1769];

        $this->importer($catalogue)->import($this->user, $path);

        // Forced, because the identical file is otherwise correctly skipped by the delta.
        $this->importer($catalogue)->import($this->user, $path, force: true);

        $this->assertSame(
            3,
            ImportedReadingDay::forUser($this->user)->count(),
            'the same three days, not a second copy of each',
        );
        $this->assertSame(710, (int) ImportedReadingDay::forUser($this->user)->sum('seconds'));
    }

    public function test_a_limited_run_records_time_only_for_what_it_applied(): void
    {
        $this->importer([self::FIRST => 1768, self::SECOND => 1769])
            ->import($this->user, $this->backup(), limit: 1);

        $this->assertSame(
            [1768],
            ImportedReadingDay::forUser($this->user)->distinct()->pluck('suwayomi_manga_id')->all(),
            'the series the run never reached has no imported days yet',
        );
    }

    public function test_a_series_whose_source_came_back_is_picked_up_rather_than_left_behind(): void
    {
        $this->installed = [];

        $this->importer()->import($this->user, $this->backup());

        $this->assertSame(2, Ledger::query()->where('state', Ledger::MISSING_SOURCE)->count());

        /*
         * The source is installed now. Both halves matter: without the source the resolver
         * short-circuits before asking anything, and without the catalogue entries there is
         * nothing to find. The fingerprint was null, so both series count as changed.
         */
        $this->installed = [self::THUNDER_SCANS => self::THUNDER_PACKAGE];

        $second = $this->importer([self::FIRST => 1768, self::SECOND => 1769])->import($this->user, $this->backup());

        $this->assertSame(2, $second->count('applied'), 'a source coming back brings its series with it');
        $this->assertSame(0, Ledger::query()->where('state', Ledger::MISSING_SOURCE)->count());
    }

    /*
     * ---------------------------------------------------------------------------
     * Interruption.
     * ---------------------------------------------------------------------------
     */

    public function test_a_limited_run_leaves_the_rest_for_the_next_one(): void
    {
        $catalogue = [self::FIRST => 1768, self::SECOND => 1769];

        $this->importer($catalogue)->import($this->user, $this->backup(), limit: 1);

        $this->assertSame(1, Ledger::query()->count(), 'only what it was asked for was filed');

        $second = $this->importer($catalogue)->import($this->user, $this->backup());

        $this->assertSame(
            1,
            $second->count('applied'),
            'the second run does the one that was left, and does not redo the one it already did',
        );
        $this->assertSame(1, $second->count('unchanged'));
        $this->assertSame(2, Ledger::query()->count());
    }

    /*
     * ---------------------------------------------------------------------------
     * The shelves the file carries.
     * ---------------------------------------------------------------------------
     *
     * The file links its shelf members through CategoryManga, and the measured real file
     * carries counts the user can name: Youtube and Snowflakes. A member lands either on
     * the shelf the import created or, when the file carried it without a source, on the
     * local row the filing pass made. Both halves go through the library's own filing
     * operation, {@see \App\Support\Library::attach()} and
     * {@see \App\Support\Library::attachState()}, so the shelves the import builds are
     * shelves the library page can also drive.
     */

    public function test_the_shelves_the_file_carries_are_recreated_and_the_members_landed(): void
    {
        $this->importer([self::FIRST => 1768])->import($this->user, $this->backup());

        /*
         * The file carries the name with a trailing space, and nothing trims a
         * shelf a reader cannot type into their own sheet. The landing trims it
         * before creating, so what arrives here is what a rename field would have
         * produced.
         */
        $this->assertSame(
            ['Youtube', 'Snowflakes'],
            DB::table('categories')->where('user_id', $this->user->id)->orderBy('id')->pluck('name')->all(),
        );

        $this->assertSame(
            1,
            DB::table('category_series')->where('user_id', $this->user->id)->count(),
            'Youtube carries one member and Snowflakes carries none, which is the file saying so',
        );

        $state = SeriesState::forUser($this->user)->where('suwayomi_manga_id', 1768)->sole();

        $this->assertSame(
            [$state->default_category_id],
            DB::table('category_series')->where('user_id', $this->user->id)->pluck('category_id')->all(),
            'the landed member carries the shelf it landed in as its default, the first shelf wins shape',
        );

        $run = ImportRun::sole();

        $this->assertSame(2, $run->categories_in_file);
        $this->assertSame(1, $run->category_members_placed);
        $this->assertSame(0, $run->category_members_skipped);

        $this->assertSame(
            ['Youtube'],
            Ledger::forUser($this->user)->where('suwayomi_manga_id', 1768)->sole()->categories,
            'the ledger row keeps the claim the file made, and not the shelf state as it later stands',
        );

        $this->assertContains('categories: Youtube: 1 of 1 members landed', $this->lines);
        $this->assertContains('categories: Snowflakes: 0 of 0 members landed', $this->lines);
    }

    public function test_a_series_filed_without_a_source_is_filed_onto_its_shelf(): void
    {
        $this->installed = []; // the source is not installed here, so both series get filed by title alone

        $this->importer()->import($this->user, $this->backup());

        $hiding = SeriesState::forUser($this->user)
            ->whereNull('suwayomi_manga_id')
            ->where('title', 'Hiding a Logistics Center')
            ->sole();

        $shelf = DB::table('categories')->where('user_id', $this->user->id)->where('name', 'Youtube')->first();
        $this->assertNotNull($shelf, 'the shelf outlived its members failing to resolve');

        $this->assertSame(
            [$hiding->id],
            DB::table('category_series')
                ->where('user_id', $this->user->id)
                ->where('category_id', $shelf->id)
                ->pluck('series_state_id')->all(),
            'the shelf carries the member through the local row the filing pass made',
        );

        $run = ImportRun::sole();

        $this->assertSame(1, $run->category_members_placed);
        $this->assertSame(0, $run->category_members_skipped);

        $this->assertSame(
            ['Youtube'],
            Ledger::forUser($this->user)->where('source_url', self::FIRST)->sole()->categories,
        );
    }

    public function test_a_member_the_run_never_reached_is_skipped_with_its_reason_said(): void
    {
        $this->withSecondCategoryMembership = true;

        $this->importer([self::FIRST => 1768])->import($this->user, $this->backup(), limit: 1);

        $this->assertContains(
            'categories: Snowflakes: 0 of 1 members landed, skipped: 1 this run never reached its series',
            $this->lines,
        );

        $run = ImportRun::sole();

        $this->assertSame(1, $run->category_members_placed, 'the first member landed, since the first series the run walks is the first shelf member');
        $this->assertSame(1, $run->category_members_skipped);

        $this->assertSame(1, Ledger::query()->count(), 'only the series the limit allowed has a row, so the skipped member has none to stamp');
    }

    public function test_a_series_that_threw_is_stamped_and_skipped_with_its_reason_said(): void
    {
        /*
         * Backup stamping happens before the landing, so a row that raised still
         * carries what the file says about where it belongs. What it does not
         * carry is a place to land: it has no resolution behind it, so there is no
         * server row to go looking for one on.
         */
        $this->catalogue = [self::FIRST => 1768, self::SECOND => 1769];
        $this->seriesThatThrow = [1768];

        $this->importer($this->catalogue)->import($this->user, $this->backup());

        $threw = Ledger::query()->where('state', Ledger::THREW)->sole();

        $this->assertSame(['Youtube'], $threw->categories, 'the claim the file made survives even on a row that raised');

        $run = ImportRun::sole();

        $this->assertSame(0, $run->category_members_placed);
        $this->assertSame(1, $run->category_members_skipped);

        $this->assertContains(
            'categories: Youtube: 0 of 1 members landed, skipped: 1 its local row is gone',
            $this->lines,
        );
    }

    public function test_a_second_run_does_not_grow_a_shelf(): void
    {
        $catalogue = [self::FIRST => 1768];

        $this->importer($catalogue)->import($this->user, $this->backup());

        $firstCount = DB::table('category_series')->where('user_id', $this->user->id)->count();

        $this->importer($catalogue)->import($this->user, $this->backup());

        $this->assertSame(
            $firstCount,
            DB::table('category_series')->where('user_id', $this->user->id)->count(),
            'a landed member is landed once, however many runs name it',
        );

        $this->assertSame(1, count(Ledger::forUser($this->user)->where('suwayomi_manga_id', 1768)->sole()->categories), 'and the stamp does not accumulate a name per run');
    }

    public function test_a_file_format_that_carries_no_shelves_leaves_the_run_counters_at_null(): void
    {
        /*
         * `.tachibk` answers "0 categories", and the run row has to say the same
         * thing in SQL a report can read. Not zero: a zero would claim that every
         * member found a shelf, and the truth is that this format has no shelves to
         * ask about.
         */
        $this->importer([], [$this->app->make(TachimangaReader::class), $this->app->make(TachibkReader::class)])
            ->import($this->user, $this->buildTachibkBackup());

        $run = ImportRun::sole();

        $this->assertNull($run->categories_in_file);
        $this->assertNull($run->category_members_placed);
        $this->assertNull($run->category_members_skipped);

        $this->assertSame(
            0,
            DB::table('categories')->where('user_id', $this->user->id)->count(),
            'a format without shelves invents none',
        );

        $this->assertSame(0, Ledger::query()->whereNotNull('categories')->count(), 'and no row is stamped with a name its file never said');

        $this->assertFalse((bool) array_filter($this->lines, fn (string $line): bool => str_starts_with($line, 'categories: ')), 'and the progress says nothing about shelves it never had');
    }

    /*
     * ---------------------------------------------------------------------------
     * Refusals.
     * ---------------------------------------------------------------------------
     */

    public function test_a_refused_file_leaves_a_run_saying_so(): void
    {
        $this->version = 4;
        $built = $this->backup();

        try {
            $this->importer()->import($this->user, $built);
            $this->fail('an unknown version must be refused');
        } catch (ImportRefused) {
            // Expected.
        }

        $run = ImportRun::sole();

        $this->assertSame(ImportRun::REFUSED, $run->state);
        $this->assertStringContainsString('version 4', (string) $run->refusal_reason);
        $this->assertNotNull($run->finished_at, 'a refusal is finished, not left running');
        $this->assertSame(0, Ledger::query()->count(), 'and it wrote nothing');
    }

    public function test_a_file_no_reader_recognises_is_refused_by_name(): void
    {
        $path = $this->temporaryFile('papyrus-not-a-backup');
        file_put_contents($path, 'this is not a backup');

        try {
            $this->importer()->import($this->user, $path);
            $this->fail('a file no reader recognises must be refused');
        } catch (ImportRefused $e) {
            $this->assertStringContainsString('recognises', $e->getMessage());
        } finally {
            @unlink($path);
        }

        $this->assertSame(ImportRun::REFUSED, ImportRun::sole()->state);
    }

    /*
     * ---------------------------------------------------------------------------
     * Sources and the report.
     * ---------------------------------------------------------------------------
     */

    public function test_sources_are_recorded_so_a_later_run_can_say_still_gone(): void
    {
        $this->withUnreachableSources = true;

        $this->importer()->import($this->user, $this->backup());

        $source = ImportedSource::query()
            ->where('pkg_name', 'eu.kanade.tachiyomi.extension.en.utoon')
            ->sole();

        $this->assertTrue($source->obsolete);
        $this->assertSame('Utoon', $source->name);
        $this->assertStringContainsString('no longer exists', $source->summary());
    }

    public function test_a_missing_source_counts_sources_and_series_separately(): void
    {
        $this->withUnreachableSources = true;
        $this->installed = [];

        $this->importer()->import($this->user, $this->backup());

        $run = ImportRun::sole();

        /*
         * Three sources carry the four shelf series, so the two numbers answer different
         * questions and neither can stand in for the other: installing them recovers four
         * series, and there are two things to install.
         *
         * Two rather than three because one of them, Utoon, is marked obsolete. It is counted
         * in the series total and not in the installable total, which is the distinction the
         * whole two-column split exists to make.
         */
        $this->assertSame(4, $run->series_on_missing_sources);
        $this->assertSame(2, $run->sources_missing);
        $this->assertGreaterThan($run->sources_missing, $run->series_on_missing_sources);
    }

    public function test_it_says_what_it_is_doing_as_it_goes(): void
    {
        $this->importer([self::FIRST => 1768])->import($this->user, $this->backup());

        $this->assertNotEmpty($this->lines, 'a run this long that prints nothing looks hung');

        $extensions = array_values(array_filter($this->lines, fn (string $l): bool => str_contains($l, 'extensions:')));
        $this->assertCount(1, $extensions);
        $this->assertStringContainsString('already here', $extensions[0]);

        $progress = array_values(array_filter($this->lines, fn (string $l): bool => str_contains($l, 'series,')));
        $this->assertNotEmpty($progress);
        $this->assertStringContainsString('2 of 2 series', (string) end($progress));
    }
}
