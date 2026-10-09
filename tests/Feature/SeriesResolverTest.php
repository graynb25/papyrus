<?php

namespace Tests\Feature;

use App\Import\ImportedSeries;
use App\Import\SeriesResolver;
use App\Suwayomi\Gateway;
use App\Suwayomi\SeriesLocator;
use App\Suwayomi\SuwayomiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Resolving a backup's `(source, url)` pairs into Suwayomi manga ids.
 *
 * ## The three things this suite exists to hold
 *
 * **1. The url join is the trailing and leading slashes.** Every url Suwayomi stores and
 * every url the backup stores differ in at least one of them, and the difference is the
 * join. Measured on the live server across the sources a reader's library shares:
 *
 * ```
 *   Thunder Scans   stored /comics/x/        backup /comics/x
 *   Atsumaru        stored pCHXM             backup /pCHXM
 *   MangaTaro       stored {"id":"689466"}   backup /{"id":"689466","slug":...}
 * ```
 *
 * Asking with the backup's own form returns nothing, including for a series that is
 * definitively in the catalogue. Four exact combinations are sent, with no wildcard, so a
 * slug that is a prefix of another series' cannot match the wrong one.
 *
 * **2. A source this server has no extension for is not a lost series.** This reader's
 * library sits on 56 sources and this server has 13 of them installed. The other 43 cannot
 * be asked anything, and the sentence has to say that rather than reporting the series as
 * missing or unreachable, because "install the extension" is something a reader can do and
 * "could not be reached" teaches them their data is gone.
 *
 * **3. A dead source is asked once, not once per series.** A source whose site is gone
 * returns nothing rather than an error, so 1,277 series against one is 1,277 requests and
 * 1,277 timeouts. A transport failure is the signal that distinguishes a source that is
 * down from a series that is missing, and it is remembered once.
 */
class SeriesResolverTest extends TestCase
{
    use RefreshDatabase;

    /** The real Thunder Scans source id, which is past 2^53. */
    private const THUNDER_SCANS = '2848420679472350044';

    /**
     * Every payload sent to Suwayomi, in order.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sent = [];
    }

    /*
     * ---------------------------------------------------------------------------
     * The fake server.
     *
     * The harness owns the three queries that describe the *server's* state rather than
     * the behaviour a test is exercising: the login, which is always the same, and the
     * installed-source list, which the resolver asks before it looks anything up. When the
     * resolver gained that second query, every fake that did not answer it reported every
     * source as uninstalled and eight tests failed on a message about missing extensions
     * rather than on the thing they were about.
     *
     * A test that wants to vary the source list passes `$sources` here, rather than
     * answering the query inside its own closure, so there is one place that knows it.
     * ---------------------------------------------------------------------------
     */

    /**
     * @param  callable(array<string, mixed>): mixed  $answer  Handles manga lookups and source searches.
     * @param  array<int, array{id: string, installed: bool}>|null  $sources
     */
    private function resolver(callable $answer, ?array $sources = null): SeriesResolver
    {
        $sources ??= [['id' => self::THUNDER_SCANS, 'installed' => true]];

        Http::fake(function (Request $request) use ($answer, $sources) {
            $body = json_decode((string) $request->body(), true) ?: [];
            $this->sent[] = $body;

            $document = (string) ($body['query'] ?? '');

            // No closing paren in the needle: the document reads `login(input: $input)`.
            if (str_contains($document, 'login(input')) {
                return Http::response(['data' => ['login' => ['accessToken' => 'a-token']]]);
            }

            if ($this->isSourcesQuery($document)) {
                return Http::response(['data' => ['sources' => ['nodes' => array_map(
                    fn (array $source): array => [
                        'id' => $source['id'],
                        'extension' => [
                            'pkgName' => 'eu.kanade.tachiyomi.extension.test',
                            'isInstalled' => $source['installed'],
                        ],
                    ],
                    $sources,
                )]]]);
            }

            return $answer($body);
        });

        return new SeriesResolver(new SeriesLocator($this->app->make(Gateway::class)));
    }

    private function isSourcesQuery(string $document): bool
    {
        return str_contains($document, 'sources(first:');
    }

    /**
     * The first payload that is neither the login nor the installed-sources query.
     *
     * Both run before any real work, so a helper that skipped only the login asserted on
     * the sources document and reported "the query must ask for every url form" against a
     * query that had nothing to do with urls.
     *
     * @return array<string, mixed>
     */
    private function firstQuery(): array
    {
        foreach ($this->sent as $payload) {
            $document = (string) ($payload['query'] ?? '');

            if ($document === '' || str_contains($document, 'login(input') || $this->isSourcesQuery($document)) {
                continue;
            }

            return $payload;
        }

        return [];
    }

    /**
     * How many sent documents contain a fragment.
     */
    private function countDocuments(string $fragment): int
    {
        return count(array_filter(
            $this->sent,
            fn (array $payload): bool => str_contains((string) ($payload['query'] ?? ''), $fragment),
        ));
    }

    private function series(string $url, string $title, int $backupId = 217, int $sourceId = 2_848_420_679_472_350_044): ImportedSeries
    {
        return new ImportedSeries(
            backupId: $backupId,
            sourceId: $sourceId,
            url: ImportedSeries::normaliseUrl($url),
            title: $title,
        );
    }

    /**
     * A catalogue that holds `$nodes`, with every source search coming back empty.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function catalogue(array $nodes): callable
    {
        return function (array $body) use ($nodes) {
            if (str_contains((string) $body['query'], 'fetchSourceManga')) {
                return Http::response(['data' => ['fetchSourceManga' => [
                    'hasNextPage' => false,
                    'mangas' => [],
                ]]]);
            }

            return Http::response(['data' => ['mangas' => [
                'nodes' => $nodes,
                'totalCount' => count($nodes),
            ]]]);
        };
    }

    /*
     * ---------------------------------------------------------------------------
     * Tier 1: the series is already in the catalogue.
     * ---------------------------------------------------------------------------
     */

    public function test_tier_one_resolves_from_the_catalogue(): void
    {
        $resolver = $this->resolver($this->catalogue([[
            'id' => '1768', 'title' => 'The Illegitimate Son', 'url' => '/comics/x/',
            'realUrl' => null, 'sourceId' => self::THUNDER_SCANS,
        ]]));

        $resolution = $resolver->resolve($this->series('/comics/x', 'The Illegitimate Son'));

        $this->assertTrue($resolution->resolved);
        $this->assertSame(1768, $resolution->suwayomiMangaId);
        $this->assertSame('tier 1', $resolution->tier);
        $this->assertSame(1, $resolver->counts()['from_catalogue']);
    }

    /**
     * The load-bearing case: one query carrying every url form, and no wildcard.
     */
    public function test_the_query_carries_every_url_form_because_the_apps_disagree(): void
    {
        $resolver = $this->resolver($this->catalogue([]));

        $resolver->resolve($this->series('/comics/x', 'A Series'));

        $payload = $this->firstQuery();

        $this->assertStringContainsString('likeAny', (string) ($payload['query'] ?? ''),
            'the tier 1 query must ask for every form at once');

        $forms = $payload['variables']['forms'];

        foreach (['/comics/x', '/comics/x/', 'comics/x', 'comics/x/'] as $expected) {
            $this->assertContains($expected, $forms, "missing the {$expected} form");
        }

        $this->assertCount(4, $forms);
    }

    /**
     * A url with no leading or trailing slash to normalise. Comix and MangaTaro serve
     * these, and they are a real shape in the measured backup.
     */
    public function test_a_bare_url_still_produces_forms_that_can_be_queried(): void
    {
        $resolver = $this->resolver($this->catalogue([]));

        $resolver->resolve($this->series('pCHXM', 'Level 1 Player'));

        $forms = $this->firstQuery()['variables']['forms'];

        $this->assertContains('pCHXM', $forms, 'the source stores it bare');
        $this->assertContains('pCHXM/', $forms);
        $this->assertContains('/pCHXM', $forms, 'the backup stores it with a leading slash');
        $this->assertContains('/pCHXM/', $forms);
    }

    /**
     * A wildcard would match both forms too, and would also match a series whose slug
     * starts with this one. Two exact entries per side cannot.
     */
    public function test_no_wildcard_is_used_because_a_prefix_would_over_match(): void
    {
        $resolver = $this->resolver($this->catalogue([]));

        $resolver->resolve($this->series('/comics/one-piece', 'One Piece'));

        foreach ($this->firstQuery()['variables']['forms'] as $form) {
            $this->assertStringNotContainsString('%', $form, 'a wildcard would match a longer slug too');
            $this->assertStringNotContainsString('*', $form);
        }
    }

    /**
     * The LongString stays a string. Sent as a JSON number Suwayomi rejects it, and in
     * JavaScript it would be rounded before it ever got that far.
     */
    public function test_the_source_id_crosses_as_a_string(): void
    {
        $resolver = $this->resolver($this->catalogue([]));

        $resolver->resolve($this->series('/comics/x', 'A Series'));

        $source = $this->firstQuery()['variables']['source'];

        $this->assertIsString($source);
        $this->assertSame(self::THUNDER_SCANS, $source);
        $this->assertGreaterThan(2 ** 53, (float) $source, 'and it is genuinely past 2^53');
    }

    /*
     * ---------------------------------------------------------------------------
     * Tier 2: ask the source.
     * ---------------------------------------------------------------------------
     */

    public function test_tier_two_is_only_reached_when_tier_one_finds_nothing(): void
    {
        $mutationsSeen = 0;

        $resolver = $this->resolver(function (array $body) use (&$mutationsSeen) {
            if (str_contains((string) $body['query'], 'fetchSourceManga')) {
                $mutationsSeen++;

                return Http::response(['data' => ['fetchSourceManga' => [
                    'hasNextPage' => false,
                    'mangas' => [['id' => '9001', 'title' => 'A Series', 'url' => '/comics/x/']],
                ]]]);
            }

            // The mutation persists the series, so the second lookup finds it.
            $found = $mutationsSeen > 0 ? [[
                'id' => '9001', 'title' => 'A Series', 'url' => '/comics/x/',
                'realUrl' => null, 'sourceId' => self::THUNDER_SCANS,
            ]] : [];

            return Http::response(['data' => ['mangas' => ['nodes' => $found, 'totalCount' => count($found)]]]);
        });

        $resolution = $resolver->resolve($this->series('/comics/x', 'A Series'));

        $this->assertTrue($resolution->resolved);
        $this->assertSame('tier 2', $resolution->tier);
        $this->assertSame(9001, $resolution->suwayomiMangaId);
        $this->assertSame(1, $mutationsSeen);
        $this->assertSame(1, $resolver->counts()['from_source']);
        $this->assertSame(0, $resolver->counts()['from_catalogue']);
    }

    public function test_a_title_hit_on_a_different_url_is_not_a_match(): void
    {
        $resolver = $this->resolver(function (array $body) {
            if (str_contains((string) $body['query'], 'fetchSourceManga')) {
                // The source returns a series with a similar title but a different url.
                return Http::response(['data' => ['fetchSourceManga' => [
                    'hasNextPage' => false,
                    'mangas' => [['id' => '9001', 'title' => 'A Series', 'url' => '/comics/some-other-series/']],
                ]]]);
            }

            return Http::response(['data' => ['mangas' => ['nodes' => [], 'totalCount' => 0]]]);
        });

        $resolution = $resolver->resolve($this->series('/comics/x', 'A Series'));

        $this->assertFalse($resolution->resolved);
        $this->assertStringContainsString('not at this url', $resolution->reason);
        $this->assertSame(0, $resolver->counts()['from_source']);
    }

    /*
     * ---------------------------------------------------------------------------
     * The two reasons a series cannot be resolved, kept apart.
     * ---------------------------------------------------------------------------
     */

    /**
     * A source with no extension here is the constraint, and it is actionable.
     */
    public function test_a_source_this_server_lacks_is_reported_as_missing_not_lost(): void
    {
        // Thunder Scans is installed; the series below is on a source that is not.
        $resolver = $this->resolver(
            $this->catalogue([]),
            [['id' => self::THUNDER_SCANS, 'installed' => true]],
        );

        $absent = $resolver->resolve(new ImportedSeries(
            backupId: 1,
            sourceId: 555_555_555_555_555_555,
            url: '/manga/gone',
            title: 'Gone',
        ));

        $this->assertFalse($absent->resolved);
        $this->assertStringContainsString('does not have this series', $absent->reason);
        $this->assertStringContainsString('Installing that extension', $absent->reason);
        $this->assertStringContainsString('has gone missing', $absent->reason);

        $this->assertSame(1, $resolver->counts()['sources_missing']);
        $this->assertSame(0, $resolver->counts()['sources_unavailable'],
            'a missing extension is not an unreachable source');

        // And nothing was asked of a source that does not exist here.
        $this->assertSame(0, $this->countDocuments('fetchSourceManga'));
    }

    /**
     * An installed source without the series is a different thing: the site changed its
     * slug, so the url is no longer the identity. Suggesting an extension would be wrong.
     */
    public function test_an_installed_source_without_the_series_is_not_a_missing_extension(): void
    {
        $resolver = $this->resolver($this->catalogue([]));

        $resolution = $resolver->resolve($this->series('/manga/moved', 'A Series', 1));

        $this->assertFalse($resolution->resolved);
        $this->assertSame(0, $resolver->counts()['sources_missing'],
            'the extension is present, so suggesting it would be wrong');
        $this->assertSame(1, $resolver->counts()['not_found']);
    }

    /**
     * A source that answered and has nothing is not a source that is down. Treating them
     * alike either hangs on a dead source or skips a live one.
     */
    public function test_a_source_that_answers_with_nothing_is_not_marked_unavailable(): void
    {
        $resolver = $this->resolver($this->catalogue([]));

        $first = $resolver->resolve($this->series('/comics/gone', 'Gone One', 1));
        $second = $resolver->resolve($this->series('/comics/here', 'Here One', 2));

        $this->assertFalse($first->resolved);
        $this->assertFalse($second->resolved);
        $this->assertSame(0, $resolver->counts()['sources_unavailable'],
            'a source that answered stays live, so another series on it is still tried');

        $this->assertSame(2, $this->countDocuments('fetchSourceManga'));
    }

    public function test_a_transport_failure_marks_the_source_unavailable_once(): void
    {
        $resolver = $this->resolver(function (array $body) {
            if (str_contains((string) $body['query'], 'fetchSourceManga')) {
                throw SuwayomiException::unavailable('the source timed out');
            }

            return Http::response(['data' => ['mangas' => ['nodes' => [], 'totalCount' => 0]]]);
        });

        $first = $resolver->resolve($this->series('/comics/a', 'A', 1));
        $second = $resolver->resolve($this->series('/comics/b', 'B', 2));
        $third = $resolver->resolve($this->series('/comics/c', 'C', 3));

        $this->assertFalse($first->resolved);
        $this->assertFalse($second->resolved);
        $this->assertFalse($third->resolved);
        $this->assertStringContainsString('could not reach', $second->reason);
        $this->assertSame(1, $resolver->counts()['sources_unavailable']);

        // 1,277 series against a dead source must not be 1,277 requests.
        $this->assertSame(1, $this->countDocuments('fetchSourceManga'));
    }

    /*
     * ---------------------------------------------------------------------------
     * Refusals that are about the id rather than the series.
     * ---------------------------------------------------------------------------
     */

    /**
     * The two refusals are separate, and only one of them was covered until a mutation
     * check found the gap. Removing the non-numeric check left every test green, because
     * the Long-ceiling check beside it still caught the only id being tried.
     */
    public function test_a_source_id_that_is_not_a_number_at_all_is_refused(): void
    {
        $locator = new SeriesLocator($this->app->make(Gateway::class));

        foreach (['', 'abc', '12abc', '-2848420679472350044', '2848420679472350044.0'] as $id) {
            $this->assertFalse(
                $locator->sourceIdIsPlausible($id),
                'source id '.var_export($id, true).' is not something the server can be asked about'
            );
        }

        Http::fake(['*' => Http::response(['data' => ['mangas' => ['nodes' => [], 'totalCount' => 0]]])]);

        $this->assertNull($locator->findBySourceAndUrl('abc', '/comics/x'));
        $this->assertSame([], $locator->searchSource('abc', 'A Series'));

        Http::assertNothingSent('a source id that is not a number must not reach Suwayomi');
    }

    /**
     * Past Java's `Long`, which the server answers with a bare `For input string`.
     *
     * Checked on the locator, because a PHP int cannot exceed that ceiling:
     * `PHP_INT_MAX` and `Long.MAX_VALUE` are the same number, so the only way past it is a
     * string, which is exactly what a mangled backup would carry.
     */
    public function test_a_source_id_past_java_long_is_refused_rather_than_sent(): void
    {
        $locator = new SeriesLocator($this->app->make(Gateway::class));

        $this->assertTrue($locator->sourceIdIsPlausible(2_848_420_679_472_350_044),
            'a real source id is past 2^53 and still inside Long');
        $this->assertFalse($locator->sourceIdIsPlausible('99999999999999999999999'));

        Http::fake(['*' => Http::response(['data' => ['mangas' => ['nodes' => [], 'totalCount' => 0]]])]);

        $this->assertNull($locator->findBySourceAndUrl('99999999999999999999999', '/comics/x'));
    }

    /*
     * ---------------------------------------------------------------------------
     * Refusals that are about ambiguity, and the shape of the report.
     * ---------------------------------------------------------------------------
     */

    public function test_two_series_on_one_url_is_ambiguous_rather_than_guessed(): void
    {
        $resolver = $this->resolver($this->catalogue([
            ['id' => '1', 'title' => 'One', 'url' => '/comics/x/', 'realUrl' => null, 'sourceId' => self::THUNDER_SCANS],
            ['id' => '2', 'title' => 'Two', 'url' => '/comics/x/', 'realUrl' => null, 'sourceId' => self::THUNDER_SCANS],
        ]));

        $resolution = $resolver->resolve($this->series('/comics/x', 'A'));

        $this->assertFalse($resolution->resolved);
        $this->assertSame('ambiguous', $resolution->tier);
        $this->assertNull($resolution->suwayomiMangaId, "which of two is the reader's is not answerable");
        $this->assertSame(0, $resolver->counts()['from_catalogue']);
    }

    public function test_counts_separate_where_each_series_came_from(): void
    {
        $resolver = $this->resolver(function (array $body) {
            if (str_contains((string) $body['query'], 'fetchSourceManga')) {
                return Http::response(['data' => ['fetchSourceManga' => ['hasNextPage' => false, 'mangas' => []]]]);
            }

            // Only the series on /comics/known is in the catalogue.
            $forms = $body['variables']['forms'] ?? [];
            $found = in_array('/comics/known', $forms, true) || in_array('/comics/known/', $forms, true)
                ? [['id' => '10', 'title' => 'Known', 'url' => '/comics/known/', 'realUrl' => null, 'sourceId' => self::THUNDER_SCANS]]
                : [];

            return Http::response(['data' => ['mangas' => ['nodes' => $found, 'totalCount' => count($found)]]]);
        });

        $resolver->resolveAll(new Collection([
            $this->series('/comics/known', 'Known', 1),
            $this->series('/comics/gone', 'Gone', 2),
        ]));

        $counts = $resolver->counts();

        $this->assertSame(1, $counts['from_catalogue']);
        $this->assertSame(0, $counts['from_source']);
        $this->assertSame(1, $counts['not_found']);
        $this->assertSame(1, $counts['resolved']);
        $this->assertCount(1, $resolver->unresolved());
    }

    /**
     * The resolver reads the drafted table, so it must never write to the real one.
     */
    public function test_resolving_writes_nothing_to_this_application(): void
    {
        $resolver = $this->resolver($this->catalogue([]));

        $tables = ['series_states', 'chapter_states', 'categories', 'category_series'];
        $count = fn (string $table): int => DB::table($table)->count();

        $before = array_combine($tables, array_map($count, $tables));

        $resolver->resolveAll(new Collection([
            $this->series('/comics/a', 'A', 1),
            $this->series('/comics/b', 'B', 2),
        ]));

        $this->assertSame(
            $before,
            array_combine($tables, array_map($count, $tables)),
            'phase 2 identifies series and must not file any of them'
        );
    }
}
