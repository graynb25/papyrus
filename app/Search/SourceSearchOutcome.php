<?php

namespace App\Search;

use App\Models\SearchSourceResult;

/**
 * What one source did during one search.
 *
 * "Returned nothing" and "failed" are different states and must stay different.
 * A live dead source (Aqua Manga) returns zero results with no error at all, so
 * collapsing empty into ok would make a broken source indistinguishable from a
 * source with nothing matching - and the health ledger depends on telling them
 * apart.
 */
final class SourceSearchOutcome
{
    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function __construct(
        public readonly string $status,
        public readonly array $results,
        public readonly int $durationMs,
        public readonly ?string $error = null,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    public static function ok(array $results, int $durationMs): self
    {
        return new self(
            $results === [] ? SearchSourceResult::EMPTY : SearchSourceResult::OK,
            $results,
            $durationMs,
        );
    }

    public static function timeout(int $durationMs): self
    {
        return new self(
            SearchSourceResult::TIMEOUT,
            [],
            $durationMs,
            'Exceeded the per-source time budget.',
        );
    }

    public static function error(string $error, int $durationMs): self
    {
        return new self(
            SearchSourceResult::ERROR,
            [],
            $durationMs,
            mb_substr($error, 0, 500),
        );
    }

    public function count(): int
    {
        return count($this->results);
    }

    /**
     * A zero-result response is weak evidence, not proof the source is dead -
     * the term may simply not match. Only errors and timeouts count against it.
     */
    public function isFailure(): bool
    {
        return $this->status === SearchSourceResult::ERROR
            || $this->status === SearchSourceResult::TIMEOUT;
    }
}
