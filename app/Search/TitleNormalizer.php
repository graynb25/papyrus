<?php

namespace App\Search;

/**
 * Reduces a source's title to a comparable form.
 *
 * The same series arrives wildly decorated across sources:
 *
 *   "One Piece"              "One Piece"              "[Viz] One Piece [EN]"
 *   "ONE PIECE"              "One Piece (1999)"       "One Piece -raw"
 *   "Jujutsu Kaisen"         "Jujutsu-kaisen"         "JUJUTSU KAISEN 0"
 *
 * Folding those together is what makes cross-source search useful; without it
 * a series available on five sources looks like five unrelated hits, which is
 * exactly what Mihon does today.
 */
final class TitleNormalizer
{
    /**
     * Decorations that carry no identity: scanlation groups, year, language,
     * release-format tags. Stripped wholesale, including their contents.
     */
    private const BRACKETED = '/[\[\(\{【（][^\]\)\}】）]*[\]\)\}】）]/u';

    /**
     * Trailing format tags that distinguish a re-upload from the real thing.
     * Kept as a separate pass because they often follow a separator.
     */
    private const FORMAT_TAGS = '/\b(raw|scan|scans|digital|official|release|webtoon|webtoons|manhwa|manhwas|manga|hd|complete)\b/u';

    /**
     * Punctuation and separators. Removed last, after decorations, so that
     * "One Piece -raw" and "One Piece" collapse to the same thing.
     */
    private const PUNCTUATION = '/[\p{P}\p{Z}\p{S}]+/u';

    /**
     * The length-ratio floor.
     *
     * 0.6, and measured rather than chosen. See {@see self::similarityOf()} for the
     * arithmetic that makes this the right place for a single floor.
     */
    private const LENGTH_RATIO_FLOOR = 0.6;

    public function normalise(string $title): string
    {
        $value = trim($title);

        if ($value === '') {
            return '';
        }

        // Full-width to half-width, then decompose accents so "Shōnen" and
        // "Shonen" agree. The combining marks are dropped below.
        $value = mb_convert_kana($value, 'as', 'UTF-8');
        $value = \Normalizer::normalize($value, \Normalizer::FORM_KD);

        // Diacritics go before the lowercase pass so the fold is complete.
        $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;

        // [Viz], (1999), {English}, （字幕） and friends.
        $previous = null;
        while ($previous !== $value) {
            $previous = $value;
            $value = preg_replace(self::BRACKETED, ' ', $value) ?? $value;
        }

        $value = mb_strtolower($value, 'UTF-8');

        $value = preg_replace(self::FORMAT_TAGS, ' ', $value) ?? $value;
        $value = preg_replace(self::PUNCTUATION, '', $value) ?? $value;

        return $value;
    }

    /**
     * How alike two titles are, 0.0 to 1.0. Normalises both, so raw titles are safe
     * to pass.
     *
     * This is the method to reach for. It is split from
     * {@see self::similarityOf()} because the distinction is easy to get wrong: this
     * used to compare the strings it was given as they arrived, and a caller passing
     * two raw titles measured an identical pair at 0.943 rather than 1.000, because
     * the curly apostrophe in "The Berserker’s Second Playthrough" was still in the
     * string. That put a real candidate below the 0.95 dedupe threshold for no
     * reason, and a live probe noticed it where no test had.
     */
    public function similarity(string $a, string $b): float
    {
        return $this->similarityOf($this->normalise($a), $this->normalise($b));
    }

    /**
     * How alike two already-normalised strings are, 0.0 to 1.0.
     *
     * For callers holding normalised values already, such as the deduplicator, whose
     * group representatives are normalised once on the way in. Normalising again on
     * every pair of a 1,080-hit run would be wasted work.
     *
     * ## The length guard, and one thing it does not do
     *
     * `similar_text` alone rates a short title contained in a long one highly,
     * because almost all of the short string matched, so a guard refuses pairs whose
     * lengths are wildly different.
     *
     * A migration ranker looks like it wants a lower floor, since a subtitle variant
     * is exactly the candidate a reader would want shown. Measured, it does not get
     * one: for a title that is a prefix of the other, `similar_text` scores
     * `2r/(1+r)` where r is the length ratio, and that reaches 0.75 only at r = 0.6.
     * So the two floors coincide exactly where the argument for a lower one applies,
     * and any pair the guard zeroes here would have been rejected by the similarity
     * threshold anyway. There is no second floor, and an earlier version of this file
     * had one, which was a knob that changed nothing.
     */
    public function similarityOf(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        // A very short title differing by one character is a different series,
        // not a variant. Guarding the length ratio stops "xxx" matching
        // everything short.
        $ratio = min(mb_strlen($a), mb_strlen($b)) / max(mb_strlen($a), mb_strlen($b));

        if ($ratio < self::LENGTH_RATIO_FLOOR) {
            return 0.0;
        }

        similar_text($a, $b, $percent);

        return $percent / 100;
    }

    public function isSameSeries(string $a, string $b, float $threshold): bool
    {
        return $this->similarity($a, $b) >= $threshold;
    }
}
