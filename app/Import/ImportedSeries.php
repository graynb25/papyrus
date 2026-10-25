<?php

namespace App\Import;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One series from another app's library, identified the only way that travels.
 *
 * ## The pair, not the id
 *
 * `Manga.id` in a Tachimanga backup is a local autoincrement: 217, then 9263, 9264,
 * continuing from wherever the reader last browsed. It means nothing outside that file.
 * The pair of {@see $sourceId} and {@see $url} does mean something, because source ids
 * are shared between Tachimanga and Suwayomi: `2848420679472350044` is Thunder Scans
 * in both, installed from the same extension package. That was measured, not assumed,
 * and it is the whole reason an import is a join rather than a 1,277 row search.
 *
 * ## Why url, not realUrl
 *
 * `real_url` reads better but is unusable as a key. Measured in this Suwayomi, of five
 * manga sampled on one source, `realUrl` was non-null on one and null on four. `url` is
 * the source-relative path and is populated on every row. A join on `real_url` would
 * match one series in five and report the rest as "not in the source", which is a
 * conclusion the caller would have no way to tell from a genuine miss.
 *
 * ## Why url is normalised
 *
 * The two apps disagree about the trailing slash. The backup stores
 * `/comics/hiding-a-logistics-center-in-the-apo` and Suwayomi stores
 * `/comics/the-illegitimate-son-became-a-monster-summuner/`. A join that returns zero
 * because of a slash looks exactly like a join with nothing to find, and this is the
 * kind of bug that survives review because the zero is indistinguishable from correct.
 * {@see normaliseUrl()} settles it once, here, so every caller gets the same key.
 *
 * ## Source ids are 64-bit and must stay in PHP
 *
 * 2,848,420,679,472,350,044 exceeds 2^53, so it cannot survive a round trip through
 * JavaScript as a number. PHP's integer is 64-bit and holds it exactly, and the
 * `sources` table stores it as `unsignedBigInteger`, so an int is correct here. The
 * trap is not this class: it is anything that JSON-encodes one of these into a page.
 */
final class ImportedSeries
{
    /**
     * @param  int  $backupId  Local to the backup file. Not portable.
     * @param  int  $sourceId  A source id shared with Suwayomi. Over 2^53, so never JSON-encode as a number.
     * @param  string  $url  Source-relative path. Already normalised by the reader.
     * @param  int|null  $backupResumeChapterId  The other app's local chapter id for the resume point.
     * @param  Collection<int, ImportedChapter>  $chapters
     * @param  Collection<int, ReadingDay>  $readingDays  Daily totals, empty when the format carries none.
     */
    public function __construct(
        public readonly int $backupId,
        public readonly int $sourceId,
        public readonly string $url,
        public readonly string $title,
        public readonly ?CarbonImmutable $addedAt = null,
        public readonly ?CarbonImmutable $lastReadAt = null,
        public readonly ?int $backupResumeChapterId = null,
        public readonly Collection $chapters = new Collection,
        public readonly Collection $readingDays = new Collection,
    ) {}

    /**
     * The key this series resolves by, as one comparable string.
     *
     * Built here rather than at each call site so the two halves cannot be joined in the
     * wrong order or with different normalisation somewhere.
     */
    public function identityKey(): string
    {
        return $this->sourceId.'|'.$this->url;
    }

    /**
     * Settle the trailing slash once, for both sides of the join.
     *
     * Leading and trailing slashes are stripped and exactly one leading slash put back,
     * so `/comics/x`, `comics/x` and `comics/x/` all produce the same key. Tachimanga
     * itself ships one of those trailing-slash variants, so this is normalising against
     * a format, not against hypothetical sloppiness.
     *
     * A query string or fragment is dropped: two apps can disagree about which tracking
     * parameters a source appends, and the path identifies the series. Anything else
     * would make the key depend on a source's analytics configuration.
     */
    public static function normaliseUrl(string $url): string
    {
        $path = strtok($url, '?#');

        // strtok returns false when the input was empty and the remaining token otherwise,
        // so `false` is the whole of the empty case here.
        if ($path === false) {
            return '/';
        }

        return '/'.trim($path, '/');
    }

    /**
     * Chapters marked read, which is the bulk of what a reader would miss without this.
     *
     * @return Collection<int, ImportedChapter>
     */
    public function readChapters(): Collection
    {
        return $this->chapters
            ->filter(fn (ImportedChapter $c): bool => $c->isRead)
            ->values();
    }
}
