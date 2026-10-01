<?php

namespace Tests\Unit\Search;

use App\Models\SearchResult;
use App\Search\DedupeGroup;
use App\Search\Deduplicator;
use App\Search\TitleNormalizer;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class DeduplicatorTest extends TestCase
{
    private Deduplicator $deduplicator;

    private TitleNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new TitleNormalizer;
        $this->deduplicator = new Deduplicator($this->normalizer, 0.95);
    }

    private function hit(string $title, string $source, string $warning = 'SAFE', int $mangaId = 1): SearchResult
    {
        return new SearchResult([
            'suwayomi_manga_id' => $mangaId,
            'suwayomi_source_id' => crc32($source),
            'title' => $title,
            'normalized_title' => $this->normalizer->normalise($title),
            'source_name' => $source,
            'content_warning' => $warning,
        ]);
    }

    public function test_it_groups_the_same_series_across_sources(): void
    {
        $groups = $this->deduplicator->group(new Collection([
            $this->hit('One Piece', 'Alpha Manga', 'SAFE', 1),
            $this->hit('ONE PIECE', 'Akaza Scans', 'SAFE', 2),
            $this->hit('[Viz] One Piece [EN]', 'Asura Scans', 'SAFE', 3),
        ]));

        $this->assertCount(1, $groups, 'Three decorated spellings are one series.');
        $this->assertSame(3, $groups->first()->sourceCount());
        $this->assertCount(2, $groups->first()->alternatives);
    }

    public function test_it_keeps_genuinely_different_series_apart(): void
    {
        $groups = $this->deduplicator->group(new Collection([
            $this->hit('One Piece', 'Alpha Manga', 'SAFE', 1),
            $this->hit('Attack on Titan', 'Akaza Scans', 'SAFE', 2),
            $this->hit('Berserk', 'Asura Scans', 'SAFE', 3),
        ]));

        $this->assertCount(3, $groups);
    }

    /**
     * Mihon does not deduplicate at all, so this is the behaviour that makes a
     * cross-source search better than a phone client rather than merely equal.
     */
    public function test_a_five_source_series_collapses_to_one_entry(): void
    {
        $results = new Collection;

        foreach (['Alpha', 'Akaza', 'Asura', 'Aqua', 'Aether'] as $i => $source) {
            $results->push($this->hit('Jujutsu Kaisen', $source, 'SAFE', $i + 1));
        }

        $groups = $this->deduplicator->group($results);

        $this->assertCount(1, $groups);
        $this->assertSame(5, $groups->first()->sourceCount());
    }

    public function test_it_handles_an_empty_result_set(): void
    {
        $this->assertTrue($this->deduplicator->group(new Collection)->isEmpty());
    }

    /**
     * Pinned to measured values from this normaliser:
     *   "One Piece" vs "One Piece G" => 0.9412
     *
     * That is the pair that sets the default threshold. At 0.92 the spinoff
     * folds into the parent, which is wrong; 0.95 keeps them apart.
     */
    public function test_the_threshold_separates_a_near_miss_from_a_variant(): void
    {
        $results = new Collection([
            $this->hit('One Piece', 'Alpha Manga', 'SAFE', 1),
            $this->hit('One Piece G', 'Akaza Scans', 'SAFE', 2),
        ]);

        $this->assertCount(1, (new Deduplicator($this->normalizer, 0.92))->group($results));
        $this->assertCount(2, (new Deduplicator($this->normalizer, 0.95))->group($results));
    }

    /**
     * A long-titled spinoff is rejected by the length-ratio guard outright,
     * so it stays separate at any threshold.
     */
    public function test_a_long_spinoff_is_never_merged(): void
    {
        $groups = (new Deduplicator($this->normalizer, 0.80))->group(new Collection([
            $this->hit('Attack on Titan', 'Alpha Manga', 'SAFE', 1),
            $this->hit('Attack on Titan: Junior High', 'Akaza Scans', 'SAFE', 2),
        ]));

        $this->assertCount(2, $groups);
        $this->assertSame(0.0, $this->normalizer->similarity(
            $this->normalizer->normalise('Attack on Titan'),
            $this->normalizer->normalise('Attack on Titan: Junior High'),
        ));
    }

    /**
     * Punctuation folding has to reach 1.0, not merely "close", or a title
     * decorated differently on every source becomes its own group.
     */
    public function test_punctuation_variants_merge_exactly(): void
    {
        $this->assertSame(1.0, $this->normalizer->similarity(
            $this->normalizer->normalise('Dr. Stone'),
            $this->normalizer->normalise('Dr Stone'),
        ));
    }

    public function test_the_group_reports_each_source_name_once(): void
    {
        $group = $this->deduplicator->group(new Collection([
            $this->hit('One Piece', 'Alpha Manga', 'SAFE', 1),
            $this->hit('One Piece', 'Alpha Manga', 'SAFE', 2),
        ]))->first();

        $this->assertSame(['Alpha Manga'], $group->sourceNames());
        $this->assertSame(2, $group->sourceCount());
    }

    /**
     * A series on one adult source and two safe ones is still adult, and the UI
     * has to gate on the strictest value in the group.
     */
    public function test_the_group_reports_the_strictest_content_warning(): void
    {
        $group = $this->deduplicator->group(new Collection([
            $this->hit('One Piece', 'Safe Source', 'SAFE', 1),
            $this->hit('ONE PIECE', 'Mixed Source', 'MIXED', 2),
        ]))->first();

        $this->assertSame(DedupeGroup::MIXED, $group->strictestContentWarning());
    }

    public function test_an_all_nsfw_group_reports_nsfw(): void
    {
        $group = $this->deduplicator->group(new Collection([
            $this->hit('Fate', 'Adult A', 'NSFW', 1),
            $this->hit('FATE', 'Adult B', 'NSFW', 2),
        ]))->first();

        $this->assertSame(DedupeGroup::NSFW, $group->strictestContentWarning());
    }

    public function test_a_single_result_group_has_no_alternatives(): void
    {
        $group = $this->deduplicator->group(new Collection([
            $this->hit('Berserk', 'Alpha Manga', 'SAFE', 1),
        ]))->first();

        $this->assertFalse($group->hasAlternatives());
        $this->assertSame(1, $group->sourceCount());
    }
}
