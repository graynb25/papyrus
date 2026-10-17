<?php

namespace App\Migration;

/**
 * What one move may carry, as the reader answered it.
 *
 * Phase 2: the dialog sits between the press and the move, and this is what
 * travels from it to the migrator. Chapters is read state, shelves is the
 * category memberships, tracking is telling AniList, and remove downloads is
 * clearing the pages the old home holds on disk.
 *
 * The safe set is not the maximal set. It is exactly the move this page has
 * always made: chapters and shelves carried, nothing said to AniList, downloads
 * kept where they are, because a copy the reader made still holds their place
 * and the download centre is where deleting it already lives. That is why
 * {@see defaults()} exists rather than leaving callers to type four booleans,
 * and why every migrator call from before this class existed keeps its meaning.
 */
final readonly class MigrationSettings
{
    public function __construct(
        public bool $carryChapters = true,
        public bool $carryShelves = true,
        public bool $tellTracking = false,
        public bool $removeDownloads = false,
    ) {}

    /** The unparameterized move, which is also every move made before this existed. */
    public static function defaults(): self
    {
        return new self;
    }
}
