<?php

namespace App\Import;

/**
 * What became of one series from a backup: found, or not, and why not.
 *
 * An import that reports "1,277 imported" when it resolved 1,204 of them is lying by
 * omission. So resolution is not a nullable int, it is one of these, and the unresolved
 * case always carries a reason a reader could act on.
 *
 * ## The tiers, and what each one costs
 *
 * **Tier 1 is free.** The series is already in Suwayomi's catalogue, matched on
 * `(source, url)`. Measured 20 to 91ms per query against a live server.
 *
 * **Tier 2 costs a live upstream request.** `fetchSourceManga` is a mutation that asks the
 * source site for the series and persists what it returns. Measured 520ms median across
 * seven samples, so a 1,277 series library is about eleven minutes of upstream calls if
 * every series needs it.
 *
 * The number that matters is that **tier 2 is self-healing**: after the mutation, asking
 * for the same series again finds it in the catalogue for free. So a second import of the
 * same backup is almost entirely tier 1, which is the difference between an import that
 * takes eleven minutes and one that takes seconds.
 *
 * ## An unresolved series is never dropped
 *
 * A source that has shut down answers with nothing, repeatedly, and the only honest
 * outcome is to say so. {@see self::unresolved()} exists so that a reader sees "73 series
 * could not be found, 68 because the source has gone offline" rather than a library that
 * silently shrank.
 */
final class Resolution
{
    private function __construct(
        public readonly bool $resolved,
        public readonly ?int $suwayomiMangaId,
        public readonly ?string $title,
        public readonly ?string $reason,
        public readonly string $tier,
        public readonly int $matches,
    ) {}

    /**
     * Found in the catalogue, or put there by asking the source.
     *
     * @param  array{id: int, title: string, matches: int}  $found
     */
    public static function fromTier(int|string $tier, array $found): self
    {
        return new self(
            resolved: true,
            suwayomiMangaId: $found['id'],
            title: $found['title'],
            reason: null,
            tier: is_int($tier) ? 'tier '.$tier : $tier,
            matches: (int) $found['matches'],
        );
    }

    /**
     * @param  string  $reason  A sentence naming what was tried and what stopped it.
     */
    public static function unresolved(string $reason): self
    {
        return new self(
            resolved: false,
            suwayomiMangaId: null,
            title: null,
            reason: $reason,
            tier: 'unresolved',
            matches: 0,
        );
    }

    /**
     * The source holds more than one series on this slug.
     *
     * Deliberately unresolved. Silently taking the first would file a reader's history
     * against whichever of two series happens to come back first, and which one is theirs
     * is a question the data cannot answer.
     */
    public static function ambiguous(string $reason): self
    {
        return new self(
            resolved: false,
            suwayomiMangaId: null,
            title: null,
            reason: $reason,
            tier: 'ambiguous',
            matches: 0,
        );
    }
}
