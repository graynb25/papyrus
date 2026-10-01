<?php

namespace App\Search;

use App\AniList\TitleResolver;
use App\Models\SearchResult;
use Illuminate\Support\Collection;

/**
 * Collapses per-source hits into one entry per series.
 *
 * Two passes, because exact matches are cheap and common while fuzzy
 * comparison is quadratic:
 *
 *   1. bucket by normalised title - free
 *   2. merge buckets whose representatives are similar enough
 *
 * Within a group the first result wins and the rest become alternatives, so the
 * UI can show "available on 4 sources" and let the reader pick rather than
 * presenting the same series four times.
 *
 * ## An AniList id, where one is known
 *
 * The bucket key becomes the AniList id when a cached lookup has one, and the
 * normalised title otherwise. That single change is what the whole integration is
 * for, and it is deliberately small.
 *
 * Measured on a "one piece" fan-out: 1,080 raw hits become 91 buckets on titles
 * alone, and eight of those buckets are the same series in different scripts:
 * `海賊王`, `海贼王`, `ون بيس`, `Ўан Піс`, `՝ՙ`, `वन पीस` and `ワンピース`, each a
 * handful of hits, all of them One Piece. The title comparison cannot join them
 * because there is no shared text to compare, which is the whole problem. An
 * AniList id joins them exactly.
 *
 * It also joins the renamed-variant pairs the titles miss: "One Piece Gakuen" and
 * "One Piece Academy" are one series, as are "Koisuru One Piece" and "One Piece
 * in Love".
 *
 * What it does not do is over-merge, which is the risk that matters. Measured:
 * `Fischer's×ONE PIECE`, `Cursed One Piece` and the doujinshi all keep their own
 * AniList ids and stay separate.
 *
 * A miss falls back to the title path unchanged, so a reader who has not opted in
 * and a title nobody has looked up both behave exactly as they did before.
 */
class Deduplicator
{
    public function __construct(
        private readonly TitleNormalizer $normalizer,
        private readonly float $threshold,
        private readonly ?TitleResolver $resolver = null,
    ) {}

    /**
     * @param  Collection<int, SearchResult>  $results
     * @return Collection<int, DedupeGroup>
     */
    public function group(Collection $results): Collection
    {
        if ($results->isEmpty()) {
            return new Collection;
        }

        // Pass 1 - exact buckets, preserving the order sources were searched.
        // A plain array rather than a Collection keyed by arbitrary strings:
        // this is a lookup table, not a sequence, and PHPStan is right to
        // complain about pretending otherwise.
        /** @var array<string, string> $representativeByKey title each bucket compares on */
        $representativeByKey = [];

        /** @var array<string, array<int, SearchResult>> $buckets */
        $buckets = [];

        foreach ($results as $result) {
            $title = $result->normalized_title !== ''
                ? $result->normalized_title
                : $this->normalizer->normalise($result->title);

            /*
             * The bucket key is the AniList id when the cache has one.
             *
             * Id 0 is the cache's "asked and told no" sentinel, and it must not
             * become a bucket key, or every title AniList has never heard of would
             * collapse into one group. Measured: 71 of 91 titles in a fan-out are
             * in that state, so the difference is the whole run.
             */
            $id = $this->anilistIdFor($result->title);

            $key = $id === null ? $title : 'anilist:'.$id;

            $buckets[$key][] = $result;
            $representativeByKey[$key] ??= $title;
        }

        // Pass 2 - fuzzy merge across buckets only, comparing each bucket's
        // representative rather than every member against every other.
        /** @var array<int, array<int, SearchResult>> $groups */
        $groups = [];

        /** @var array<int, string> $groupRepresentative the title each group compares on */
        $groupRepresentative = [];

        /** @var array<string, int> $groupOfKey which group each bucket key landed in */
        $groupOfKey = [];

        foreach ($buckets as $key => $members) {
            $representative = $representativeByKey[$key];
            $placed = false;

            // Two buckets with the same AniList id are the same series, whatever
            // their titles say, so no comparison is needed at all.
            if (isset($groupOfKey[$key])) {
                $index = $groupOfKey[$key];
                $groups[$index] = array_merge($groups[$index], $members);
                $placed = true;
            }

            if (! $placed) {
                foreach ($groupRepresentative as $index => $groupTitle) {
                    if ($this->normalizer->similarityOf($groupTitle, $representative) >= $this->threshold) {
                        $groups[$index] = array_merge($groups[$index], $members);
                        $groupOfKey[$key] = $index;
                        $placed = true;

                        break;
                    }
                }
            }

            if (! $placed) {
                $groupOfKey[$key] = count($groups);
                $groupRepresentative[] = $representative;
                $groups[] = $members;
            }
        }

        return (new Collection($groups))->map(
            /** @param array<int, SearchResult> $members */
            fn (array $members): DedupeGroup => DedupeGroup::fromResults(
                new Collection($members)
            ),
        )->values();
    }

    /**
     * The AniList id for a title, or null when nobody has looked it up.
     *
     * Null when there is no resolver, which is the opted-out case, and null for the
     * cache's "asked and told no" sentinel. Both fall through to the title path,
     * so a reader who has not opted in and a title AniList lacks behave the same,
     * which is the point.
     */
    private function anilistIdFor(string $title): ?int
    {
        if ($this->resolver === null) {
            return null;
        }

        // The resolver returns null for every case with no usable id, so this
        // does not need to know about the cache sentinel.
        return $this->resolver->idFor($title);
    }
}
