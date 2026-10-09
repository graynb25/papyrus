<?php

namespace App\Suwayomi;

use App\Import\SeriesResolver;

/**
 * Finds a series in Suwayomi's catalogue, or asks a source for it.
 *
 * Separate from {@see SeriesGateway} because that class reads a series you have already
 * identified, and this one is what identifies it. An import starts from a `(source, url)`
 * pair that came out of somebody else's phone, and nothing downstream can work until
 * that pair is turned into a Suwayomi manga id.
 *
 * ## The two apps disagree about the slashes, and it is the join
 *
 * Measured on the live server. Suwayomi stores urls in whatever form its source's
 * extension produced, and Tachimanga stores the same series' url in whatever form *its*
 * copy of that extension produced, and the two are not always the same string:
 *
 * ```
 *   Thunder Scans   stored /comics/x/        backup /comics/x
 *   Asura Scans     stored /series/x/        backup /series/x
 *   Atsumaru        stored pCHXM             backup /pCHXM
 *   MangaTaro       stored {"id":"689466"}   backup /{"id":"689466","slug":...}
 * ```
 *
 * Some of that is a trailing slash, some a leading one, and all of it is the join. Asking
 * with the backup's own form returns nothing, including for a series that is definitively
 * in the catalogue, and it returns nothing for every series on a source whose extension
 * writes its urls without a leading slash. So {@see findBySourceAndUrl()} queries all four
 * combinations as a `likeAny` list: one round trip, no wildcard, and therefore no way for
 * one series' slug to match another's by being a prefix of it.
 *
 * This settles the slash but not the whole join. Four of the thirteen shared sources serve
 * a series on a url that has since *changed*: one now serves a numeric id where the backup
 * has a slug, one spells the slug differently, one appends a `#102` fragment, and one
 * replaced `/rg9jv` with a full slug. For those the url is not the identity any more and
 * something else has to find them. See {@see SeriesResolver}.
 *
 * ## `sourceId` is a LongString, so it crosses as a string
 *
 * The real source ids are past 2^53, up to 2,848,420,679,472,350,044. Sent as a JSON
 * number Suwayomi raises a validation error, and in PHP an int would round it. So the
 * filter value is always `(string) $sourceId`.
 *
 * A value past Java's Long ceiling, 9,223,372,036,854,775,807, throws a bare
 * `For input string: "..."` from inside the server with no useful context. Real source
 * ids are inside it, but a garbage one arriving here is a reader's malformed backup, so
 * it is refused with a sentence before it reaches Suwayomi.
 */
final class SeriesLocator
{
    /**
     * One query, both url forms.
     *
     * `likeAny` entries without a wildcard are exact equality against the list, which is
     * what makes this safe: four entries rather than one pattern, so a slug that is a
     * prefix of another series' slug cannot silently match it.
     */
    private const FIND_DOCUMENT = <<<'GQL'
        query ($source: LongString!, $forms: [String!]!) {
          mangas(filter: { sourceId: { equalTo: $source }, url: { likeAny: $forms } }, first: 5) {
            nodes { id title url realUrl sourceId }
            totalCount
          }
        }
        GQL;

    /**
     * The one way to bring a series the catalogue has never held into it.
     *
     * A mutation, which is the reason this lives in a locator rather than a read-only
     * gateway: a query would not do it. It persists what it returns, measured by asking
     * for the same series again afterwards and finding it for free.
     *
     * `page` is non-null in `FetchSourceMangaInput`, so omitting it is a validation error
     * rather than a default. `type` is explicit rather than left to the server's default,
     * because `LATEST` on a source that does not support it throws a bare Java exception.
     */
    private const FETCH_DOCUMENT = <<<'GQL'
        mutation ($input: FetchSourceMangaInput!) {
          fetchSourceManga(input: $input) {
            hasNextPage
            mangas { id title url realUrl sourceId }
          }
        }
        GQL;

    /** Java's Long ceiling, which is where a mangled source id stops being a number. */
    private const LONG_MAXIMUM = 9_223_372_036_854_775_807;

    /** @var list<string>|null Source ids with an extension installed, fetched once. */
    private ?array $installed = null;

    public function __construct(
        private readonly Gateway $gateway,
    ) {}

    /**
     * The manga row for a `(source, url)` pair, or null if this server has no such series.
     *
     * `matches` above 1 means the source holds two series on the same slug. It is not
     * silently resolved: the caller gets the first and the count, because "which of these
     * two is the reader's" is a question the data cannot answer and the reader can.
     *
     * @param  string|int  $sourceId  A source id shared with Suwayomi. Over 2^53.
     * @param  string  $canonicalUrl  The url as {@see ImportedSeries::normaliseUrl()} produces it.
     * @return array{id: int, title: string, url: string, realUrl: ?string, sourceId: string, totalCount: int, matches: int}|null
     */
    public function findBySourceAndUrl(string|int $sourceId, string $canonicalUrl): ?array
    {
        $source = $this->sourceIdAsString($sourceId);

        if ($source === null) {
            return null;
        }

        /*
         * Every leading and trailing slash combination, because the sources disagree on
         * both. Measured across the 13 sources this reader's library shares with this
         * server:
         *
         *   source stores  /comics/x/     backup stores  /comics/x        (Thunder Scans)
         *   source stores  /pCHXM         backup stores  /pCHXM           (Atsumaru)
         *   source stores  {"id":"689"}    backup stores  /{"id":"689"}     (MangaTaro)
         *   source stores  /series/x/     backup stores  /series/x        (Asura Scans)
         *
         * Four combinations, no wildcards, so a slug that is a prefix of another series'
         * still cannot match the wrong one. An earlier version sent only two of these and
         * so missed every source that stores a url without a leading slash.
         */
        $bare = trim($canonicalUrl, '/');
        $forms = $bare === '' ? ['/'] : ['/'.$bare, '/'.$bare.'/', $bare, $bare.'/'];

        $result = $this->gateway->execute(self::FIND_DOCUMENT, [
            'source' => $source,
            'forms' => array_values(array_unique($forms)),
        ]);

        $nodes = $result['mangas']['nodes'] ?? [];

        if ($nodes === []) {
            return null;
        }

        $first = $nodes[0];

        return [
            'id' => (int) $first['id'],
            'title' => (string) ($first['title'] ?? ''),
            'url' => (string) ($first['url'] ?? ''),
            'realUrl' => isset($first['realUrl']) ? (string) $first['realUrl'] : null,
            'sourceId' => (string) ($first['sourceId'] ?? $source),
            'totalCount' => (int) ($result['mangas']['totalCount'] ?? count($nodes)),
            'matches' => count($nodes),
        ];
    }

    /**
     * Ask a source for a series by title, which puts it in the catalogue if the source has it.
     *
     * @return array<int, array{id: int, title: string, url: string}>
     */
    public function searchSource(string|int $sourceId, string $query): array
    {
        $source = $this->sourceIdAsString($sourceId);

        if ($source === null) {
            return [];
        }

        $result = $this->gateway->execute(self::FETCH_DOCUMENT, [
            'input' => [
                'source' => $source,
                'query' => $query,
                'type' => 'SEARCH',
                'page' => 1,
            ],
        ]);

        $nodes = $result['fetchSourceManga']['mangas'] ?? [];

        return array_values(array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => (string) ($row['title'] ?? ''),
            'url' => (string) ($row['url'] ?? ''),
        ], $nodes));
    }

    /**
     * Whether a source id can be asked about at all, without querying.
     *
     * Used to distinguish "this backup names a source that cannot exist" from "this source
     * has no such series", which are different messages to a reader.
     */
    public function sourceIdIsPlausible(string|int $sourceId): bool
    {
        return $this->sourceIdAsString($sourceId) !== null;
    }

    /**
     * Whether this server has the extension for a source, by id.
     *
     * ## This is the constraint that decides an import
     *
     * Measured on a reader's real 1,277 series library: they sit on **56** sources, and
     * this server has extensions installed for **13** of them. The other 43 hold the
     * largest single group of series, 117 on one source alone. So an import is bounded by
     * which extensions are installed, not by how good the join is, and the difference
     * between the two failures is the difference between a reader's data being gone and a
     * reader needing to install an extension.
     *
     * The two apps share a source-id *space*, which is not the same as sharing a set of
     * sources. Thunder Scans is 2848420679472350044 in both, and that fact says nothing
     * about the other 55.
     *
     * @return bool|null True or false, or null when the id cannot be a source at all.
     */
    public function sourceIsInstalled(string|int $sourceId): ?bool
    {
        $source = $this->sourceIdAsString($sourceId);

        if ($source === null) {
            return null;
        }

        $installed = $this->installedSources();

        return in_array($source, $installed, true);
    }

    /**
     * The source ids this server has extensions for, fetched once per locator.
     *
     * A run asks this once per series and there are 1,277 series, so it is cached. The
     * cache lives for the request rather than in the app's cache because a source being
     * installed mid-import is not a thing a reader does, and a stale answer would be
     * wrong for the whole run in a way a fresh one would not.
     *
     * @return list<string>
     */
    private function installedSources(): array
    {
        if ($this->installed !== null) {
            return $this->installed;
        }

        $result = $this->gateway->execute(
            '{ sources(first: 400) { nodes { id extension { pkgName isInstalled } } } }'
        );

        $this->installed = [];

        foreach ($result['sources']['nodes'] ?? [] as $node) {
            // `isInstalled` is on the extension, not on the source. `SourceType` has no
            // such field, so asking for one is a validation error that reads as an empty
            // catalogue and makes every source look uninstalled.
            if ($node['extension']['isInstalled'] ?? false) {
                $this->installed[] = (string) $node['id'];
            }
        }

        return $this->installed;
    }

    /**
     * The source id as a string, or null when Suwayomi could not be asked about it.
     *
     * Two refusals, both of which would otherwise surface as a server-side throw with no
     * context: a non-numeric id, and one past Java's Long ceiling where the server replies
     * `For input string` against a mangled backup.
     */
    private function sourceIdAsString(string|int $sourceId): ?string
    {
        $source = (string) $sourceId;

        // ctype_digit is false for an empty string, so only the one check is needed.
        if (! ctype_digit($source)) {
            return null;
        }

        return bccomp($source, (string) self::LONG_MAXIMUM) > 0 ? null : $source;
    }
}
