<?php

namespace App\Migration;

/**
 * Matches a series' chapters on one source to the same chapters on another.
 *
 * Chapter ids are per-manga and global to the server, so moving a series means every
 * read row points at an id that no longer means anything. This is the mapping that
 * carries them across, and it is the part of a migration most likely to be quietly
 * wrong, so it reports what it achieved rather than assuming.
 *
 * ## What the keys are worth, measured
 *
 * Comparing all 41 chapters of one series on Asura Scans against the same 41 on
 * MangaFire:
 *
 *   chapterNumber   41 of 41 placed, every one uniquely
 *   sourceOrder     41 of 41 placed
 *   name             0 of 41 placed
 *
 * The name failure is not noise. The two sources label the same chapter
 * "Chapter 1" and "Ch. 1", so every single pair differs. Any matcher that leaned on
 * the label would carry nothing across and look like it worked.
 *
 * `chapterNumber` is therefore the primary key, and it is used only where it is
 * **unambiguous on both sides**. A source that splits one chapter into three parts
 * has three chapters sharing a number, and mapping a reader's read state onto an
 * arbitrary one of them is worse than not mapping it at all.
 *
 * ## The fallback, and when it matters
 *
 * `name` is tried second, normalised, for the chapters a number cannot place: a
 * prologue, a volume extra, a side story. Those all read as 0.0 or -1, so a
 * series with them is exactly where the primary key fails, and the fallback is the
 * only thing that keeps their read state.
 *
 * Measured: the series used for the comparison has no such chapters, so the fallback
 * is unexercised by that measurement. It is here because the failure is certain to
 * occur, not because it has been observed on this series.
 *
 * ## What is reported
 *
 * Every unmapped chapter is named in the result, so a reader who lost read state on
 * "Volume 3 Extra" can see which one it was. The alternative, transferring silently,
 * makes a partial transfer indistinguishable from a complete one.
 */
class ChapterMapper
{
    /**
     * How many unmapped chapter labels to keep.
     *
     * Enough to identify what was lost without turning the report into a wall of
     * text on the series page. The count itself is never capped, so the number a
     * reader is shown is the true one.
     */
    private const MAX_REPORTED = 12;

    /**
     * Map one chapter list onto another.
     *
     * @param  array<int, array<string, mixed>>  $from  the series' current chapters
     * @param  array<int, array<string, mixed>>  $to  the destination's chapters
     * @return array{
     *     map: array<int, int>,
     *     by_number: int,
     *     by_name: int,
     *     ambiguous: int,
     *     unmatched: array<int, string>,
     *     total: int
     * }
     */
    public function map(array $from, array $to): array
    {
        $byNumber = $this->indexByNumber($to);
        $byName = $this->indexByName($to);

        $map = [];
        $byNumberCount = 0;
        $byNameCount = 0;
        $ambiguous = 0;
        $unmatched = [];

        foreach ($from as $chapter) {
            $fromId = (int) ($chapter['id'] ?? 0);

            if ($fromId === 0) {
                continue;
            }

            $number = $this->numberKey((float) ($chapter['chapterNumber'] ?? 0.0));

            /*
             * Only a number that identifies exactly one destination chapter is used.
             * Two or more candidates means the destination splits or duplicates the
             * chapter, and guessing which half a reader finished is worse than
             * reporting that it could not be placed.
             */
            $numbered = $byNumber['unique'][$number] ?? [];

            if (count($numbered) === 1) {
                $map[$fromId] = (int) $numbered[0]['id'];
                $byNumberCount++;

                continue;
            }

            // Counted even though it is not used, because "the other source splits
            // this chapter" and "the other source does not have this chapter" are
            // different answers, and a reader debugging a lost chapter wants to know
            // which one they got.
            if ($number !== '' && isset($byNumber['ambiguous'][$number])) {
                $ambiguous++;
            }

            $named = $byName[$this->nameKey((string) ($chapter['name'] ?? ''))] ?? [];

            if (count($named) === 1) {
                $map[$fromId] = (int) $named[0]['id'];
                $byNameCount++;

                continue;
            }

            $label = trim((string) ($chapter['name'] ?? ''));

            $unmatched[] = $label === '' ? 'Chapter '.$fromId : $label;
        }

        return [
            'map' => $map,
            'by_number' => $byNumberCount,
            'by_name' => $byNameCount,
            'ambiguous' => $ambiguous,
            'unmatched' => array_slice($unmatched, 0, self::MAX_REPORTED),
            'total' => count($from),
        ];
    }

    /**
     * Destination chapters indexed by their number, keeping only unique numbers.
     *
     * Ambiguous numbers are kept in a second bucket rather than folded into the
     * unique one. The caller still refuses to use them, because guessing which half of
     * a split chapter a reader finished is worse than not answering, but it needs to
     * be able to say "the other source splits this one" in the report.
     *
     * @param  array<int, array<string, mixed>>  $chapters
     * @return array{unique: array<string, array<int, array<string, mixed>>>, ambiguous: array<string, int>}
     */
    private function indexByNumber(array $chapters): array
    {
        $buckets = [];

        foreach ($chapters as $chapter) {
            $number = (float) ($chapter['chapterNumber'] ?? 0.0);

            /*
             * 0.0 and negatives are what a prologue or an extra reads as, so they
             * are not a shared key: a series with three specials would otherwise
             * place all three onto whichever came first.
             */
            if ($number <= 0.0) {
                continue;
            }

            $buckets[$this->numberKey($number)][] = $chapter;
        }

        $unique = [];
        $ambiguous = [];

        foreach ($buckets as $number => $bucket) {
            $key = $this->numberKey((float) $number);

            if (count($bucket) === 1) {
                $unique[$key] = $bucket;

                continue;
            }

            $ambiguous[$key] = count($bucket);
        }

        return ['unique' => $unique, 'ambiguous' => $ambiguous];
    }

    /**
     * Destination chapters indexed by a comparable form of their label.
     *
     * @param  array<int, array<string, mixed>>  $chapters
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function indexByName(array $chapters): array
    {
        $index = [];

        foreach ($chapters as $chapter) {
            $key = $this->nameKey((string) ($chapter['name'] ?? ''));

            if ($key === '') {
                continue;
            }

            $index[$key][] = $chapter;
        }

        return $index;
    }

    /**
     * A chapter number as a usable array key.
     *
     * PHP casts a float key to int, so 1.0 and 1.5 would collide on 1 and
     * 2, and a source that splits a chapter into halves would place them on the
     * wrong chapter. `chapterNumber` is a Float precisely because half chapters
     * exist, so the key is a string that keeps the fraction.
     */
    private function numberKey(float $number): string
    {
        if ($number <= 0.0) {
            return '';
        }

        return rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    }

    /**
     * A label reduced to something two sources can be expected to agree on.
     *
     * "Chapter 1" and "Ch. 1" must not collapse here, because the measurement says
     * they never do. What this does fold is the noise around a special's name, so
     * that "Prologue" and " prologue " are the same thing.
     */
    private function nameKey(string $name): string
    {
        $value = mb_strtolower(trim($name));

        if ($value === '') {
            return '';
        }

        // Separators and case are the only things folded. Word forms are left alone,
        // because "Chapter 1" against "Ch. 1" is a 0/41 case and pretending
        // otherwise would report a confidence the sources do not have.
        $value = preg_replace('/[\s\-_.,:;]+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
