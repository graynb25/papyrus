<?php

namespace App\Search;

/**
 * One page of one source's own catalogue.
 *
 * ## Why this is not {@see SourceSearchOutcome}
 *
 * That class describes one source's answer to a *search*, and the health ledger depends on its
 * three states staying distinct: a source that returned nothing and a source that failed are
 * different facts, and a live but empty source is neither. Nothing here needs that
 * distinction, because a browse listing has no term to fail to match.
 *
 * It is also not able to carry what browse needs. `SourceSearchOutcome` slices to
 * `results_per_source`, which is 15 because it bounds a fan-out, and applying that to a browse
 * page threw away most of it: measured, MangaFire returned 50 rows on a page and 35 of them
 * were dropped, Manga Demon returned 56 and dropped 41, Atsumaru returned 40 and dropped 25.
 * A browse page is a page, not a sample of one, so the rows are kept whole.
 *
 * ## Why the end is carried rather than inferred
 *
 * `hasNextPage` comes back true for Asura Scans on every page measured, four pages deep, and
 * false for Akaza Scans on its first. So the signal is real and it is the only honest way to
 * know whether there is more, since "fewer rows than last time" is indistinguishable from a
 * source answering oddly. An endless source under endless scroll appends as the reader goes,
 * which is the behaviour they asked for, and it stops when they stop scrolling.
 */
final class BrowsePage
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function __construct(
        public readonly array $rows,
        public readonly bool $hasNextPage,
        public readonly ?string $error,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function of(array $rows, bool $hasNextPage): self
    {
        return new self($rows, $hasNextPage, null);
    }

    public static function failed(string $error): self
    {
        return new self([], false, mb_substr($error, 0, 500));
    }

    /**
     * Whether the request itself went wrong, as opposed to coming back with nothing.
     *
     * Named for the predicate rather than reusing `failed()` from the factory, because two
     * methods cannot share a name and PHPStan says so.
     */
    public function hasError(): bool
    {
        return $this->error !== null;
    }

    /**
     * Whether the source answered with nothing at all.
     *
     * Distinct from `failed()`. A source that loaded and held no more titles has run out, which
     * is the end of a scroll rather than something to apologise for.
     */
    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
