<?php

namespace Tests\Unit;

use App\Models\SearchResult;
use App\Search\DedupeGroup;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * A group can be asked whether a manga is one of its own.
 *
 * ## Why membership and not "is this the primary"
 *
 * A search streams. Results arrive one source at a time and the deduplicator recomputes the
 * grouping from scratch on every render, so which result is a group's primary is a ranking
 * and the ranking moves as more sources answer.
 *
 * A reader clicking a card is holding an id that was the primary a moment ago. If the lookup
 * that finds their group is keyed on "which group's primary is this id", a source that
 * answers afterwards is enough to make the answer empty, and the chooser opens with nothing
 * under its heading for no reason the reader can see.
 *
 * The ids inside a group do not move, so asking whether the id is one of them survives the
 * regrouping.
 */
class DedupeGroupMembershipTest extends TestCase
{
    private function row(int $mangaId, string $source): SearchResult
    {
        return new SearchResult([
            'suwayomi_manga_id' => $mangaId,
            'suwayomi_source_id' => crc32($source),
            'title' => 'Eternally Regressing Knight',
            'source_name' => $source,
        ]);
    }

    public function test_it_finds_the_primary(): void
    {
        $group = DedupeGroup::fromResults(new Collection([
            $this->row(1, 'Manga Demon (EN)'),
            $this->row(2, 'Asura Scans (EN)'),
        ]));

        $this->assertTrue($group->hasManga(1));
    }

    public function test_it_finds_an_alternative(): void
    {
        $group = DedupeGroup::fromResults(new Collection([
            $this->row(1, 'Manga Demon (EN)'),
            $this->row(2, 'Asura Scans (EN)'),
        ]));

        // This is the case the primary-keyed lookup got wrong.
        $this->assertTrue($group->hasManga(2));
    }

    public function test_it_rejects_a_manga_from_another_group(): void
    {
        $group = DedupeGroup::fromResults(new Collection([
            $this->row(1, 'Manga Demon (EN)'),
            $this->row(2, 'Asura Scans (EN)'),
        ]));

        $this->assertFalse($group->hasManga(9999));
    }

    public function test_membership_does_not_depend_on_which_source_won(): void
    {
        $rows = [
            $this->row(1, 'Manga Demon (EN)'),
            $this->row(2, 'Asura Scans (EN)'),
            $this->row(3, 'Lagoon Scans (EN)'),
        ];

        // The same three results in a different order make a different primary. Membership
        // has to hold for the same id either way, or it is only accidentally right.
        $asIs = DedupeGroup::fromResults(new Collection($rows));
        $reordered = DedupeGroup::fromResults(new Collection(array_reverse($rows)));

        $this->assertNotSame(
            $asIs->primary->suwayomi_manga_id,
            $reordered->primary->suwayomi_manga_id,
            'The two orderings should disagree about the primary, or this test proves nothing.'
        );

        foreach ([1, 2, 3] as $mangaId) {
            $this->assertTrue($asIs->hasManga($mangaId));
            $this->assertTrue(
                $reordered->hasManga($mangaId),
                "manga {$mangaId} left the group when the ranking changed."
            );
        }
    }

    public function test_a_single_source_group_still_answers(): void
    {
        $group = DedupeGroup::fromResults(new Collection([$this->row(1, 'Manga Demon (EN)')]));

        $this->assertTrue($group->hasManga(1));
        $this->assertFalse($group->hasManga(2));
    }
}
