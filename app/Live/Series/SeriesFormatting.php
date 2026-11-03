<?php

namespace App\Live\Series;

/**
 * The series page's pure rules, kept out of the Livewire component.
 *
 * Everything here is a function of its arguments: the scanlator name a source
 * sent, the chapter and series ids a link is built from, and the paging
 * arithmetic the list and footer share. No query and no reader state belongs
 * here, which is what lets the rules be exercised without mounting a page.
 */
final class SeriesFormatting
{
    /**
     * A chapter's scanlator, or null when the name is not a name.
     *
     * Measured: one source sends a zero-width space as its scanlator, which
     * `filled()` counts as present. It produced a fourth group button labelled
     * with nothing, and a chapter row with an invisible name. Whitespace-only
     * names are the same shape.
     *
     * @param  array<string, mixed>  $chapter
     */
    public function scanlatorOf(array $chapter): ?string
    {
        $name = $chapter['scanlator'] ?? null;

        if (! is_string($name)) {
            return null;
        }

        $name = trim($name);

        if (preg_match('/^[\p{Z}\s\x{200B}-\x{200D}\x{FEFF}\x{2060}]*$/u', $name) === 1) {
            return null;
        }

        return $name;
    }

    /**
     * Scanlators present in a chapter list, for the exclusion control.
     *
     * The falsy test matches the collection's `filter()` the page used before
     * this moved: an absent name is dropped, and so is the string "0", which is
     * not a name any source has sent.
     *
     * @param  array<int, array<string, mixed>>  $chapters
     * @return array<int, string>
     */
    public function scanlators(array $chapters): array
    {
        $names = [];

        foreach ($chapters as $chapter) {
            $name = $this->scanlatorOf($chapter);

            if ($name) {
                $names[$name] = $name;
            }
        }

        $names = array_values($names);
        sort($names);

        return $names;
    }

    /**
     * The reader route for one chapter.
     */
    public function chapterUrl(int $manga, int $chapterId): string
    {
        return route('reader.show', ['manga' => $manga, 'chapter' => $chapterId]);
    }

    /**
     * The rows one page of the chapter list holds.
     *
     * @param  array<int, array<string, mixed>>  $chapters
     * @return array<int, array<string, mixed>>
     */
    public function pageSlice(array $chapters, int $page, int $perPage): array
    {
        return array_slice($chapters, ($page - 1) * $perPage, $perPage);
    }

    /**
     * The page range currently shown, for the footer.
     *
     * @return array{0: int, 1: int}
     */
    public function visibleRange(int $visibleCount, int $page, int $perPage): array
    {
        $from = $visibleCount === 0 ? 0 : ($page - 1) * $perPage + 1;

        return [$from, min($from + $perPage - 1, $visibleCount)];
    }
}
