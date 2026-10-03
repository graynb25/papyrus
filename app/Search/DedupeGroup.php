<?php

namespace App\Search;

use App\Models\SearchResult;
use Illuminate\Support\Collection;

/**
 * One series, and every source it turned up on.
 *
 * @property SearchResult $primary
 * @property Collection<int, SearchResult> $alternatives
 * @property int $sourceCount
 */
final class DedupeGroup
{
    public const SAFE = 'SAFE';

    public const MIXED = 'MIXED';

    public const NSFW = 'NSFW';

    /**
     * @param  Collection<int, SearchResult>  $alternatives
     */
    private function __construct(
        public readonly SearchResult $primary,
        public readonly Collection $alternatives,
    ) {}

    /**
     * @param  Collection<int, SearchResult>  $results
     */
    public static function fromResults(Collection $results): self
    {
        $primary = $results->first();
        $alternatives = $results->slice(1)->values();

        return new self($primary, $alternatives);
    }

    public function sourceCount(): int
    {
        return $this->alternatives->count() + 1;
    }

    public function hasAlternatives(): bool
    {
        return $this->alternatives->isNotEmpty();
    }

    /**
     * Whether one of this group's results is this manga, primary or not.
     *
     * Membership rather than "is this the primary", because a group is recomputed from
     * scratch every time the page renders and the primary is a ranking, not an identity.
     * A search streams: the reader clicks a card while nine sources have answered, a tenth
     * arrives, the deduplicator ranks it above the one the reader clicked, and that result
     * is now an alternative. A lookup keyed on the primary then finds nothing at all, and
     * the chooser the reader just opened comes back empty.
     *
     * The manga ids in a group do not change as results stream in, so asking "is this id
     * one of ours" survives the regrouping that asking "is this our primary" does not.
     */
    public function hasManga(int $mangaId): bool
    {
        if ($this->primary->suwayomi_manga_id === $mangaId) {
            return true;
        }

        return $this->alternatives->contains(
            fn (SearchResult $row): bool => $row->suwayomi_manga_id === $mangaId
        );
    }

    /**
     * Distinct source names, primary first.
     *
     * @return array<int, string>
     */
    public function sourceNames(): array
    {
        return $this->alternatives
            ->pluck('source_name')
            ->push($this->primary->source_name)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Distinct names of the sources beyond the primary.
     *
     * Separate from slicing {@see sourceNames()}, because a source whose name
     * equals the primary's disappears from that slice entirely: the card then
     * read "+1 source:" and named nobody, which measured live on a group whose
     * second result came from a source sharing the primary's display name.
     *
     * @return array<int, string>
     */
    public function alternativeNames(): array
    {
        return $this->alternatives
            ->pluck('source_name')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Distinct author names across the group, primary first.
     *
     * Shown on the card because `author:` is checkable against this field and a reader
     * who filtered on it wants to see what survived. All of them rather than the
     * primary's alone, because a series on three sources often credits three different
     * people and showing one of them would quietly misattribute the other two.
     *
     * Sources that do not report an author contribute nothing rather than a blank, so a
     * group credited on one source out of four reads as one name and not as three plus
     * an em dash.
     *
     * @return array<int, string>
     */
    public function authorNames(): array
    {
        return $this->alternatives
            ->pluck('author')
            ->push($this->primary->author)
            ->filter(fn (?string $a): bool => $a !== null && trim($a) !== '')
            ->map(fn (string $a): string => trim($a))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The least explicit content rating in the group. A series on one NSFW
     * source and two safe ones is still NSFW, and the UI has to gate on the
     * strictest value present.
     */
    public function strictestContentWarning(): string
    {
        $rank = [self::SAFE => 0, self::MIXED => 1, self::NSFW => 2];

        // concat, not push: push mutates the alternatives collection in place,
        // which grew this group by one on every call and made any count read
        // after this one wrong. A getter has to leave the group as it found it.
        $strictest = $this->alternatives
            ->concat([$this->primary])
            ->max(fn (SearchResult $r): int => $rank[$r->content_warning] ?? 0);

        return match (true) {
            $strictest >= 2 => self::NSFW,
            $strictest === 1 => self::MIXED,
            default => self::SAFE,
        };
    }
}
