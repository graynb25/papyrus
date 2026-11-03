<?php

namespace Tests\Unit\Search;

use App\Live\Search\SearchQuery;
use App\Search\FilterVocabulary;
use App\Search\QueryParser;
use Tests\TestCase;

/**
 * The search page's query parsing and vocabulary, exercised without mounting.
 *
 * The cases are the parser's contract as the page reads it: the hint and labels,
 * plain text, named terms, quoted values, unrecognised labels left as text, empty
 * values, repeated labels and every projection the Blade uses.
 */
class SearchQueryTest extends TestCase
{
    private SearchQuery $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->query = new SearchQuery(app(FilterVocabulary::class), app(QueryParser::class));
    }

    public function test_the_hint_names_the_terms_the_vocabulary_ships(): void
    {
        $hint = $this->query->filterHint();

        $this->assertSame(app(FilterVocabulary::class)->termsHint(), $hint);
        $this->assertStringContainsString('Genre', $hint);
        $this->assertStringContainsString('Status', $hint);
        $this->assertStringContainsString('Sort', $hint);
        $this->assertStringNotContainsString('Author', $hint);
    }

    public function test_a_label_is_named_and_an_unknown_one_falls_back_to_itself(): void
    {
        $this->assertSame('Genre', $this->query->filterLabel('genre'));
        $this->assertSame('Status', $this->query->filterLabel('status'));
        $this->assertSame('Sort', $this->query->filterLabel('sort'));
        $this->assertSame('edition', $this->query->filterLabel('edition'));
    }

    public function test_the_parsed_shape_is_the_parsers_own(): void
    {
        $parsed = $this->query->parsed('status:ongoing lantern');

        $this->assertSame(['text', 'filters', 'ignored', 'empty'], array_keys($parsed));
        $this->assertSame('lantern', $parsed['text']);
        $this->assertSame(['status' => 'ongoing'], $parsed['filters']);
        $this->assertSame([], $parsed['ignored']);
        $this->assertSame([], $parsed['empty']);
    }

    public function test_plain_text_stays_plain_text(): void
    {
        $this->assertSame('paper lantern', $this->query->parsedText('paper lantern'));
        $this->assertSame([], $this->query->parsedFilters('paper lantern'));
    }

    public function test_a_quoted_value_keeps_its_spaces_and_leaves_the_rest_as_text(): void
    {
        $this->assertSame(['genre' => 'slice of life'], $this->query->parsedFilters('genre:"slice of life" lantern'));
        $this->assertSame('lantern', $this->query->parsedText('genre:"slice of life" lantern'));
    }

    public function test_an_unrecognised_label_stays_text_and_is_named(): void
    {
        $this->assertSame([], $this->query->parsedFilters('edition:deluxe arc'));
        $this->assertSame(['edition:deluxe'], $this->query->ignoredLabels('edition:deluxe arc'));
        $this->assertSame('edition:deluxe arc', $this->query->parsedText('edition:deluxe arc'));
    }

    public function test_an_empty_value_is_reported_and_the_rest_still_searches(): void
    {
        $parsed = $this->query->parsed('status: lantern');

        $this->assertSame(['status'], $parsed['empty']);
        $this->assertSame([], $parsed['filters']);
        $this->assertSame('lantern', $parsed['text']);
        $this->assertSame('lantern', $this->query->parsedText('status: lantern'));
    }

    public function test_a_repeated_label_keeps_the_last_value(): void
    {
        $this->assertSame(
            ['status' => 'completed'],
            $this->query->parsedFilters('status:ongoing status:completed x'),
        );
    }

    public function test_every_projection_reads_the_same_query(): void
    {
        $raw = 'status:ongoing genre:action lantern';

        $this->assertSame(['status' => 'ongoing', 'genre' => 'action'], $this->query->parsedFilters($raw));
        $this->assertSame([], $this->query->ignoredLabels($raw));
        $this->assertSame('lantern', $this->query->parsedText($raw));
    }
}
