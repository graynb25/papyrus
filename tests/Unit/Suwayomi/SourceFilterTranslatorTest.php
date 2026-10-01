<?php

namespace Tests\Unit\Suwayomi;

use App\Suwayomi\SourceFilterTranslator;
use PHPUnit\Framework\TestCase;

/**
 * C11, verified against the live schema: FilterChangeInput requires
 * `position: Int!` and none of the eight filter members carries a key, so `name`
 * is the only stable identity. GroupFilter's `groupChange` is a single nested
 * change, not a list.
 */
class SourceFilterTranslatorTest extends TestCase
{
    private SourceFilterTranslator $translator;

    /**
     * A realistic list, including a group - Alpha Manga and Asura Scans both
     * serve grouped filters on the live instance.
     *
     * @return array<int, array<string, mixed>>
     */
    private function filters(): array
    {
        return [
            ['__typename' => 'HeaderFilter', 'name' => 'Filters'],
            [
                '__typename' => 'GroupFilter',
                'name' => 'Status',
                'filters' => [
                    ['__typename' => 'SelectFilter', 'name' => 'status', 'values' => ['All', 'Ongoing'], 'default' => 0],
                    ['__typename' => 'CheckBoxFilter', 'name' => 'completed', 'default' => false],
                ],
            ],
            ['__typename' => 'SelectFilter', 'name' => 'genre', 'values' => ['All', 'Action'], 'default' => 0],
            ['__typename' => 'SortFilter', 'name' => 'sort', 'values' => ['Title', 'Views'], 'default' => ['index' => 0, 'ascending' => true]],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->translator = new SourceFilterTranslator;
    }

    public function test_it_addresses_top_level_filters_by_position(): void
    {
        $addresses = $this->translator->addresses($this->filters());

        $this->assertSame(0, $addresses['Filters']['position']);
        $this->assertNull($addresses['Filters']['inner']);

        // genre is index 2 at the top level.
        $this->assertSame(2, $addresses['genre']['position']);
        $this->assertNull($addresses['genre']['inner']);
    }

    public function test_it_addresses_group_children_by_nested_position(): void
    {
        $addresses = $this->translator->addresses($this->filters());

        // Both children live inside the group at position 1.
        $this->assertSame(1, $addresses['status']['position']);
        $this->assertSame(0, $addresses['status']['inner']);

        $this->assertSame(1, $addresses['completed']['position']);
        $this->assertSame(1, $addresses['completed']['inner']);
    }

    public function test_a_top_level_select_filter_becomes_select_state(): void
    {
        $changes = $this->translator->toChanges($this->filters(), ['genre' => 1]);

        $this->assertSame([['position' => 2, 'selectState' => 1]], $changes);
    }

    public function test_a_group_child_becomes_a_nested_group_change(): void
    {
        $changes = $this->translator->toChanges($this->filters(), ['completed' => true]);

        $this->assertSame([
            ['position' => 1, 'groupChange' => ['position' => 1, 'checkBoxState' => true]],
        ], $changes);
    }

    public function test_several_group_children_share_the_outer_position(): void
    {
        $changes = $this->translator->toChanges($this->filters(), [
            'status' => 1,
            'completed' => true,
        ]);

        $this->assertCount(2, $changes);

        foreach ($changes as $change) {
            $this->assertSame(1, $change['position']);
            $this->assertArrayHasKey('groupChange', $change);
        }

        $this->assertSame(0, $changes[0]['groupChange']['position']);
        $this->assertSame(1, $changes[0]['groupChange']['selectState'] ?? null);

        $this->assertSame(1, $changes[1]['groupChange']['position']);
        $this->assertTrue($changes[1]['groupChange']['checkBoxState'] ?? false);
    }

    public function test_a_sort_filter_becomes_sort_state(): void
    {
        $changes = $this->translator->toChanges($this->filters(), [
            'sort' => ['index' => 1, 'ascending' => false],
        ]);

        $this->assertSame([
            ['position' => 3, 'sortState' => ['index' => 1, 'ascending' => false]],
        ], $changes);
    }

    public function test_unknown_filter_names_are_dropped_not_guessed(): void
    {
        // Sending a position the server did not expect silently retargets a
        // different filter, so unknown names must be discarded.
        $changes = $this->translator->toChanges($this->filters(), ['nonexistent' => 3]);

        $this->assertSame([], $changes);
    }

    /**
     * The core failure mode: a saved search remembers a position, the source
     * reorders its filters, and the write lands on the wrong control. Resolving
     * by name each time is what prevents that.
     */
    public function test_positions_resolve_against_the_current_list_not_a_stale_one(): void
    {
        $filters = $this->filters();
        $before = $this->translator->nameToPosition($filters);
        $this->assertSame(2, $before['genre']);

        // A header is inserted at the front, shifting everything down by one.
        array_unshift($filters, ['__typename' => 'HeaderFilter', 'name' => 'New Section']);

        $after = $this->translator->nameToPosition($filters);

        $this->assertSame(3, $after['genre'], 'genre moved, and the lookup must follow it');

        $changes = $this->translator->toChanges($filters, ['genre' => 1]);
        $this->assertSame([['position' => 3, 'selectState' => 1]], $changes);
    }

    public function test_it_skips_entries_with_no_name(): void
    {
        $addresses = $this->translator->addresses([
            ['__typename' => 'SelectFilter', 'name' => '', 'values' => [], 'default' => 0],
            ['__typename' => 'SelectFilter', 'name' => 'genre', 'values' => [], 'default' => 0],
        ]);

        $this->assertArrayNotHasKey('', $addresses);
        $this->assertArrayHasKey('genre', $addresses);
    }
}
