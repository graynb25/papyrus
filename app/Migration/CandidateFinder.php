<?php

namespace App\Migration;

use App\Models\Source;
use App\Search\SourceSearcher;
use App\Search\TitleNormalizer;
use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SuwayomiException;
use Illuminate\Support\Collection;

/**
 * Finds the same series on other sources, and says how sure it is.
 *
 * Everything here is measured against the live server, and each measurement is the
 * reason for a decision rather than a decoration on one.
 *
 * ## Search every source, then collapse by site
 *
 * Measured: 82 sources searching one term each is 21.9 seconds, p50 143ms, worst
 * source 1459ms, zero failures. Of the 199 hits, 72 score 0.90 or better, and those
 * 72 sit on **7 sites**. Sixty-one of them are MangaDex language variants of one
 * entry, which is the same fact the health dashboard is built on: 82 sources are
 * 15 sites.
 *
 * So candidates are grouped by `home_url` and one representative per site is kept.
 * Fetching all 72 would write 72 chapter lists into the server's database to present
 * the same decision.
 *
 * ## A search result is a stub, so ranking needs a fetch
 *
 * Measured: reading a shortlisted candidate with the cheap query gives
 * `author: null`, `chapters: 0` and `initialized: false` on 71 of 72. Those are
 * entries the server created from a search without asking the source anything, so
 * the signals a matcher needs are not there yet.
 *
 * After one `fetchMangaAndChapters` per site the same six sites read:
 *
 *   MangaFire      41 chapters  author "Life Ruined"                  the target
 *   Thunder Scans  39 chapters  author null
 *   Lagoon Scans   28 chapters  author "Life Ruined"
 *   MangaTaro      21 chapters  author "Isang, Life Ruined"
 *   Comix         210 chapters  author ""
 *   Atsumaru      333 chapters  author "Insaengmanghaesseum, Isang"
 *
 * which is enough to rank on, at 370ms to 1346ms each.
 *
 * ## Ordering, and why it is not a blended score
 *
 * Title similarity first, then how close the chapter count is, then author overlap.
 * Lexicographic rather than weighted because five of those six titles are identical
 * at 1.000, so the title barely discriminates and a weight on it would only add a
 * number to look at. The tiebreakers are what separate them.
 *
 * A blended score would also hide its own weights. Keeping the signals separate lets
 * the page show a reader why a candidate is where it is, which is the difference
 * between a ranking and an oracle.
 *
 * ## No tracker id
 *
 * `MangaType.trackRecords.trackerId` would be exact. Measured: `totalCount: 0`,
 * because it is only populated when a reader has logged into AniList or MAL, and on
 * a self-hosted install nobody has. Available, not usable as a key.
 */
class CandidateFinder
{
    public function __construct(
        private readonly SourceSearcher $searcher,
        private readonly SeriesGateway $series,
        private readonly TitleNormalizer $normalizer,
    ) {}

    /**
     * Ranked candidates for a series, best first.
     *
     * @param  array<string, mixed>  $current  the series as SeriesGateway::find returns it
     * @param  callable(int, int): void|null  $onProgress  called after each source
     *                                                     with searched and total, so a
     *                                                     page can say "searched 40 of 82
     *                                                     sources" rather than showing a
     *                                                     bare bar. The job throttles the
     *                                                     writes; this fires 82 times on
     *                                                     purpose so it can.
     * @return array{candidates: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function find(
        Source $from,
        array $current,
        ?callable $onProgress = null,
    ): array {
        $currentSourceId = (string) $from->suwayomi_source_id;

        $sources = Source::query()
            ->where('is_installed', true)
            ->where('suwayomi_source_id', '!=', $currentSourceId)
            ->orderBy('suwayomi_source_id')
            ->get();

        return $this->sweepAndRank($sources, $current, $onProgress);
    }

    /**
     * The same search, against one named source instead of every source.
     *
     * ## Why the bulk migration needs this
     *
     * A reader moving a whole shelf off one source onto another has already chosen both ends.
     * Sweeping all 82 sources per series would be 82 searches to answer a question with one
     * destination, so at 80 series that is 6,560 requests where 80 would do, and the ones the
     * reader did not ask about are requests made to sites that have nothing to do with the
     * decision.
     *
     * The ranking, the detail fetch and the candidate shape are the same code as
     * {@see find()}, deliberately. A bulk match and a wizard match that disagree would mean the
     * same series moving two ways depending on which door the reader came through, and the
     * per series migrator has to accept a bulk match as if the wizard had produced it, because
     * it verifies against `migration_searches` and nothing else.
     *
     * ## Why an uninstalled destination is refused rather than searched
     *
     * Because there is nothing to search. An uninstalled source is a row in the catalogue with
     * no server behind it, so the search would answer with a transport failure that looks like
     * "this source does not have your series", which is a very different thing to tell someone
     * about their library.
     *
     * @param  array<string, mixed>  $current
     * @param  callable(int, int): void|null  $onProgress
     * @return array{candidates: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function findOn(
        Source $from,
        array $current,
        Source $to,
        ?callable $onProgress = null,
    ): array {
        if ((string) $to->suwayomi_source_id === (string) $from->suwayomi_source_id) {
            return ['candidates' => [], 'meta' => ['sources_searched' => 0, 'sources_total' => 0, 'raw_hits' => 0, 'sites' => 0]];
        }

        if (! $to->is_installed) {
            return ['candidates' => [], 'meta' => ['sources_searched' => 0, 'sources_total' => 1, 'raw_hits' => 0, 'sites' => 0]];
        }

        return $this->sweepAndRank(new Collection([$to]), $current, $onProgress);
    }

    /**
     * Sweep the given sources, describe what they hold, and rank it.
     *
     * @param  Collection<int, Source>  $sources
     * @param  array<string, mixed>  $current
     * @param  callable(int, int): void|null  $onProgress
     * @return array{candidates: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    private function sweepAndRank(Collection $sources, array $current, ?callable $onProgress): array
    {
        $title = (string) $current['title'];
        $targetChapters = (int) ($current['chapters']['totalCount'] ?? 0);

        $minimum = (float) config('papyrus.migration.minimum_similarity', 0.75);

        $total = $sources->count();
        $searched = 0;
        $hits = 0;

        /*
         * `?->` is for object method calls, not for invoking a nullable callable, so
         * the null check is explicit here. Bound once because it runs per source,
         * which is 82 times for a full sweep.
         */
        $progress = static function (int $done, int $of) use ($onProgress): void {
            if ($onProgress !== null) {
                $onProgress($done, $of);
            }
        };

        /** @var array<string, array<int, array<string, mixed>>> every variant per site, best first */
        $bySite = [];

        foreach ($sources as $source) {
            $searched++;

            try {
                $outcome = $this->searcher->search($source, $title);
            } catch (SuwayomiException) {
                // A source that cannot be searched is a source with no candidates,
                // which is the same as a source that returned none.
                $progress($searched, $total);

                continue;
            }

            if ($outcome->isFailure()) {
                $progress($searched, $total);

                continue;
            }

            foreach ($outcome->results as $hit) {
                $hits++;

                $hitTitle = (string) ($hit['title'] ?? '');

                if ($hitTitle === '') {
                    continue;
                }

                $similarity = $this->normalizer->similarity($title, $hitTitle);

                if ($similarity < $minimum) {
                    continue;
                }

                $site = (string) ($source->home_url ?: $source->display_name);

                $bySite[$site][] = [
                    'manga_id' => (int) $hit['id'],
                    'site' => $site,
                    'source_id' => (string) $source->suwayomi_source_id,
                    'source_name' => (string) $source->display_name,
                    'source_lang' => (string) $source->lang,
                    'title' => $hitTitle,
                    'title_similarity' => round($similarity, 4),
                    'status' => 'unchecked',
                ];
            }

            $progress($searched, $total);
        }

        $this->fetchDetails($bySite, $current, $targetChapters);

        return [
            'candidates' => $this->rank($bySite, $current),
            'meta' => [
                'sources_searched' => $searched,
                'sources_total' => $total,
                'raw_hits' => $hits,
                'sites' => count($bySite),
            ],
        ];
    }

    /**
     * Give each site's candidates their author and chapter count, keeping one per
     * site.
     *
     * ## Why a site can need more than one attempt, and why language decides which
     *
     * Measured, and this was a real bug twice over. Sources are swept in
     * `suwayomi_source_id` order, and MangaFire's JA variant has a lower id than its
     * EN variant, so a site whose variants tie on title similarity was represented by
     * whichever came first. That was the JA one, which answers "No chapters found",
     * and the reader lost the only exact chapter-count match available: MangaFire EN
     * at 41 against the series' 41. The ranker was working perfectly on a candidate
     * that had already been thrown away.
     *
     * So a variant that answers beats one that does not, and the way to arrange that
     * is to try the site's variants in order until one yields a chapter list. It costs
     * one more source fetch for a site that turns out to be a dead end, which measured
     * 370ms to 1346ms.
     *
     * Merely trying two variants was not enough either. MangaFire has seven, several
     * of them matching at 1.000, and both attempts were spent on the JA and PT
     * entries while EN, the one that answers, sat third. Verified directly:
     * MangaFire EN answers 41 chapters with a matching author, and the variant the
     * wizard picked answered "No chapters found".
     *
     * So a variant that answers beats one that does not, and the order to try them in
     * is the current series' language first. Language variants of one site are the
     * same series in different scripts, so a reader reading the EN edition wants the
     * EN entry of the destination rather than its PT one. That is a real signal, not
     * a tiebreak invented to make the numbers come out.
     *
     * ## Why the cap counts fetches rather than sites
     *
     * The fetch writes chapter rows into the server's database whether or not a reader
     * ever opens the series, so it is the expensive part and the cap belongs on it.
     * Counting sites instead would make a fallback attempt invisible to the budget.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $bySite  mutated in place to one entry per site
     * @param  array<string, mixed>  $current
     */
    private function fetchDetails(array &$bySite, array $current, int $targetChapters): void
    {
        $cap = (int) config('papyrus.migration.max_sites_to_fetch', 8);
        $attempts = (int) config('papyrus.migration.variants_per_site', 2);
        $currentLang = mb_strtolower((string) ($current['source']['lang'] ?? ''));

        /*
         * Sites in the order a reader would want them, so the fetch budget is spent
         * where it can change the answer rather than on whichever came first.
         */
        uasort($bySite, static function (array $a, array $b): int {
            return self::bestOf($b) <=> self::bestOf($a);
        });

        $targetAuthor = $this->authorTokens($current['author'] ?? null, $current['artist'] ?? null);

        $fetches = 0;

        foreach ($bySite as $site => $variants) {
            usort($variants, static function (array $a, array $b) use ($currentLang): int {
                return $b['title_similarity'] <=> $a['title_similarity']
                    ?: (mb_strtolower((string) $b['source_lang']) === $currentLang ? 1 : 0)
                    <=> (mb_strtolower((string) $a['source_lang']) === $currentLang ? 1 : 0);
            });

            $chosen = null;
            $note = null;
            $dead = 'no_chapters';

            foreach (array_slice($variants, 0, max(1, $attempts)) as $variant) {
                if ($fetches >= $cap) {
                    // Left unchecked rather than marked bad, so the page can say it
                    // did not look instead of implying it found nothing.
                    $bySite[$site] = [[
                        ...$variant,
                        'status' => 'unchecked',
                        'note' => 'Not looked at, to keep the search quick.',
                    ]];

                    continue 2;
                }

                $fetches++;
                $result = $this->describe($variant, $targetChapters, $targetAuthor);

                if ($result['candidate']['status'] === 'ready') {
                    $chosen = $result['candidate'];

                    break;
                }

                // Remember why, so a site whose every variant failed can say so.
                $note = $result['candidate']['note'] ?? null;
                $dead = $result['candidate']['status'];
            }

            $bySite[$site] = [$chosen ?? [
                ...$variants[0],
                'status' => $dead,
                'note' => $note ?? 'This source could not be read.',
            ]];

        }
    }

    /**
     * Fetch one variant and describe it.
     *
     * @param  array<string, mixed>  $variant
     * @param  array<int, string>  $targetAuthor
     * @return array{candidate: array<string, mixed>}
     */
    private function describe(array $variant, int $targetChapters, array $targetAuthor): array
    {
        try {
            $result = $this->series->refresh((int) $variant['manga_id']);
        } catch (SuwayomiException $e) {
            return ['candidate' => [...$variant, 'status' => 'unavailable', 'note' => $e->getMessage()]];
        }

        if ($result['series'] === null) {
            return ['candidate' => [...$variant, 'status' => 'unavailable', 'note' => 'The server no longer has this entry.']];
        }

        if ($result['error'] !== null) {
            /*
             * Measured: a MangaDex language variant answers "No chapters found" for a
             * series that exists, because that variant's listing yields nothing. The
             * entry is real and the chapters are not there, which is a dead end for a
             * migration rather than an error to hide.
             */
            return ['candidate' => [...$variant, 'status' => 'no_chapters', 'note' => 'This source lists no chapters for it.']];
        }

        $fetched = $result['series'];
        $chapters = (int) ($fetched['chapters']['totalCount'] ?? 0);
        $candidateTokens = $this->authorTokens($fetched['author'] ?? null, $fetched['artist'] ?? null);

        return ['candidate' => [
            ...$variant,
            'status' => 'ready',
            'chapters' => $chapters,
            'chapter_delta' => $targetChapters > 0 ? $chapters - $targetChapters : null,
            'chapter_proximity' => $this->proximity($chapters, $targetChapters),
            'author' => $fetched['author'] ?? null,
            'author_overlap' => $this->overlap($targetAuthor, $candidateTokens),
            'status_label' => $fetched['status'] ?? null,
        ]];
    }

    /**
     * The best title similarity in a site's variant list.
     *
     * @param  array<int, array<string, mixed>>  $variants
     */
    private static function bestOf(array $variants): float
    {
        $best = 0.0;

        foreach ($variants as $variant) {
            $best = max($best, (float) $variant['title_similarity']);
        }

        return $best;
    }

    /**
     * One candidate per site, ordered by title, then chapter proximity, then author
     * overlap.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $bySite
     * @param  array<string, mixed>  $current
     * @return array<int, array<string, mixed>>
     */
    private function rank(array $bySite, array $current): array
    {
        $sites = [];

        foreach ($bySite as $variants) {
            $sites[] = $variants[0];
        }

        usort($sites, static function (array $a, array $b): int {
            return $b['title_similarity'] <=> $a['title_similarity']
                ?: ($b['chapter_proximity'] ?? -1) <=> ($a['chapter_proximity'] ?? -1)
                ?: ($b['author_overlap'] ?? -1) <=> ($a['author_overlap'] ?? -1)
                ?: strcmp((string) $a['source_name'], (string) $b['source_name']);
        });

        return $sites;
    }

    /**
     * How close two chapter counts are, 0.0 to 1.0.
     *
     * Relative rather than absolute, because a series still being updated has a
     * different count on every source: measured 41 against 39 is the same series
     * eleven chapters behind, while 41 against 333 is a different entry entirely.
     * Dividing by the larger count turns both into a comparable number.
     */
    private function proximity(int $candidate, int $target): ?float
    {
        if ($candidate <= 0 || $target <= 0) {
            return null;
        }

        $delta = abs($candidate - $target);
        $scale = max($candidate, $target);

        return round(max(0.0, 1.0 - ($delta / $scale)), 4);
    }

    /**
     * Author agreement as overlapping tokens, 0.0 to 1.0.
     *
     * Token overlap against the **smaller** set, not the larger. Measured author
     * strings for one series: "Life Ruined", "Isang, Life Ruined",
     * "Insaengmanghaesseum, Isang" and "". A source that credits an extra person is
     * still crediting the same author, so a candidate naming more should score full
     * marks rather than being penalised for the extra.
     *
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    private function overlap(array $a, array $b): ?float
    {
        if ($a === [] || $b === []) {
            return null;
        }

        $shared = count(array_intersect($a, $b));

        return round($shared / min(count($a), count($b)), 4);
    }

    /**
     * Split an author and artist string into comparable tokens.
     *
     * @return array<int, string>
     */
    private function authorTokens(mixed $author, mixed $artist): array
    {
        $tokens = [];

        foreach ([$author, $artist] as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            // Separators measured in the wild: "Isang, Life Ruined". A comma, a
            // slash and " and " all appear across sources.
            foreach (preg_split('/[,;\/&]|\band\b|\bx\b|\bwith\b/iu', $value) ?: [] as $part) {
                $part = mb_strtolower(trim($part));

                if ($part === '') {
                    continue;
                }

                $tokens[] = $part;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * The series' own author and artist tokens, for a caller that wants to explain a
     * candidate's author agreement.
     *
     * @param  array<string, mixed>  $current
     * @return array<int, string>
     */
    public function authorTokensFor(array $current): array
    {
        return $this->authorTokens($current['author'] ?? null, $current['artist'] ?? null);
    }
}
