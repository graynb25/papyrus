<?php

namespace App\AniList;

use App\Jobs\ResolveAniListTitles;
use App\Models\AniListMedia;
use App\Search\TitleNormalizer;

/**
 * Answers "what does AniList call this title", from the cache when it can.
 *
 * ## Two halves on purpose
 *
 * `idFor()` reads and never writes, because it is called from the render path. A
 * search page that made a third-party request per group would be unusable at the
 * measured 30 requests a minute, so the render path is a table lookup and nothing
 * else.
 *
 * `resolve()` writes, and is called only from {@see ResolveAniListTitles}.
 * That split is the reason this is not one method, and it is why the deduplicator
 * takes a resolver it can trust to be free.
 *
 * ## A refusal is never cached
 *
 * If AniList rate limits us, nothing is written. A cached "no match" from a 429 would
 * outlive the rate limit by forever and would quietly remove a series from the
 * deduplication for every later search.
 */
class TitleResolver
{
    public function __construct(
        private readonly TitleNormalizer $normalizer,
    ) {}

    /**
     * The AniList id for a title, or null when there is not one to give.
     *
     * One meaning only, which took a test to establish. Null covers all three
     * cases: nobody has looked it up, AniList has no such title, and the title is
     * empty. Returning 0 for the last two made the caller guard, and the guard is
     * where the next mistake would have been.
     *
     * Cheap enough for a render path: one indexed lookup, no network.
     */
    public function idFor(string $title): ?int
    {
        $normalized = $this->normalizer->normalise($title);

        if ($normalized === '') {
            return null;
        }

        $id = AniListMedia::query()
            ->forTitle($normalized)
            ->value('anilist_id');

        if ($id === null || (int) $id === 0) {
            return null;
        }

        return (int) $id;
    }

    /**
     * The ids for a set of titles, in one query.
     *
     * A search with 91 groups asking this 91 times is the same mistake the library
     * page made once already, and it is a per-render cost rather than a one-off.
     *
     * @param  array<int, string>  $titles
     * @return array<string, int> normalised title to AniList id
     */
    public function idsFor(array $titles): array
    {
        $normalized = [];

        foreach ($titles as $title) {
            $key = $this->normalizer->normalise($title);

            if ($key !== '') {
                $normalized[$key] = $title;
            }
        }

        if ($normalized === []) {
            return [];
        }

        $rows = AniListMedia::query()
            ->whereIn('normalized_title', array_keys($normalized))
            ->pluck('anilist_id', 'normalized_title');

        $map = [];

        foreach ($rows as $key => $id) {
            $map[(string) $key] = (int) $id;
        }

        return $map;
    }

    /**
     * The cached media for a title, if anybody has looked it up.
     */
    public function mediaFor(string $title): ?AniListMedia
    {
        $normalized = $this->normalizer->normalise($title);

        if ($normalized === '') {
            return null;
        }

        return AniListMedia::query()->forTitle($normalized)->first();
    }

    /**
     * Titles in a set that nobody has looked up yet.
     *
     * @param  array<int, string>  $titles
     * @return array<string, string> normalised title to the title to ask about
     */
    public function missing(array $titles): array
    {
        $wanted = [];

        foreach ($titles as $title) {
            $key = $this->normalizer->normalise($title);

            if ($key !== '') {
                $wanted[$key] = $title;
            }
        }

        if ($wanted === []) {
            return [];
        }

        $known = AniListMedia::query()
            ->whereIn('normalized_title', array_keys($wanted))
            ->pluck('normalized_title')
            ->all();

        return array_diff_key($wanted, array_flip(array_map('strval', $known)));
    }

    /**
     * Ask AniList about a title and record the answer.
     *
     * Returns null when AniList has no such title, and **writes a row saying so**, so
     * the next search of the same title does not ask again. A genuine absence is
     * worth remembering: measured, 71 of 91 titles in a fan-out have no AniList entry
     * and most of them are doujinshi and anthologies that never will.
     *
     * @throws AniListRefusedException when AniList refused, in which case nothing is
     *                                 written at all
     */
    public function resolve(string $title): ?AniListMedia
    {
        $normalized = $this->normalizer->normalise($title);

        if ($normalized === '') {
            return null;
        }

        /*
         * The cache first, always.
         *
         * Idempotence rather than an optimisation, and a test caught its absence:
         * asking a second time for a title already resolved issued another
         * request. The job filters with missing() so a fan-out never hit it, which
         * is exactly why it survived into the code. At 30 requests a minute a
         * repeated lookup is not a rounding error.
         */
        $known = AniListMedia::query()->forTitle($normalized)->first();

        if ($known !== null) {
            return $known->anilist_id === 0 ? null : $known;
        }

        $media = $this->client()->lookup($title);

        if ($media === null) {
            // Recorded as a sentinel row with id 0, so "asked and told no" is
            // distinguishable from "not asked yet" by a single indexed lookup.
            AniListMedia::query()->updateOrCreate(
                ['normalized_title' => $normalized],
                [
                    'anilist_id' => 0,
                    'looked_up_at' => now(),
                ],
            );

            return null;
        }

        $staff = [];

        foreach (($media['staff']['edges'] ?? []) as $edge) {
            $name = $edge['node']['name']['full'] ?? null;

            if (is_string($name) && $name !== '') {
                $staff[] = ['role' => (string) ($edge['role'] ?? ''), 'name' => $name];
            }
        }

        $row = AniListMedia::query()->updateOrCreate(
            ['normalized_title' => $normalized],
            [
                'anilist_id' => (int) $media['id'],
                'title_romaji' => $media['title']['romaji'] ?? null,
                'title_english' => $media['title']['english'] ?? null,
                'title_native' => $media['title']['native'] ?? null,
                'synonyms' => array_values(array_filter(
                    array_map('strval', $media['synonyms'] ?? []),
                    static fn (string $name): bool => trim($name) !== '',
                )),
                'is_adult' => (bool) ($media['isAdult'] ?? false),
                'format' => isset($media['format']) ? (string) $media['format'] : null,
                'country_of_origin' => isset($media['countryOfOrigin']) ? (string) $media['countryOfOrigin'] : null,
                'status' => isset($media['status']) ? (string) $media['status'] : null,
                'chapters' => isset($media['chapters']) ? (int) $media['chapters'] : null,
                'staff' => $staff,
                'looked_up_at' => now(),
            ],
        );

        return $row;
    }

    /**
     * How many of a set of titles are already answered.
     *
     * For the progress a reader sees while a run's titles are being resolved.
     *
     * @param  array<int, string>  $titles
     * @return array{total: int, answered: int}
     */
    public function progress(array $titles): array
    {
        $wanted = [];

        foreach ($titles as $title) {
            $key = $this->normalizer->normalise($title);

            if ($key !== '') {
                $wanted[$key] = $title;
            }
        }

        $total = count($wanted);

        if ($total === 0) {
            return ['total' => 0, 'answered' => 0];
        }

        $answered = AniListMedia::query()
            ->whereIn('normalized_title', array_keys($wanted))
            ->count();

        return ['total' => $total, 'answered' => $answered];
    }

    private function client(): Client
    {
        return app(Client::class);
    }
}
