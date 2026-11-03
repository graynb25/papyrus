<?php

namespace App\Live\Search;

use App\Search\FilterVocabulary;
use App\Search\QueryParser;

/**
 * The search page's query parsing and vocabulary, kept out of the component.
 *
 * The parser and the vocabulary are injected so the page no longer resolves them
 * inline, and every projection is a function of the raw query the reader typed. The
 * parsed shape is the parser's own and is passed through unchanged.
 */
final class SearchQuery
{
    public function __construct(
        private readonly FilterVocabulary $vocabulary,
        private readonly QueryParser $parser,
    ) {}

    /**
     * The terms a reader can type, for the hint under the box.
     */
    public function filterHint(): string
    {
        return $this->vocabulary->termsHint();
    }

    /**
     * The human name for one of our terms.
     */
    public function filterLabel(string $label): string
    {
        return $this->vocabulary->label($label);
    }

    /**
     * The current query split into free text, understood terms, and labels left as text.
     *
     * @return array{text: string, filters: array<string, string>, ignored: array<int, string>, empty: array<int, string>}
     */
    public function parsed(string $raw): array
    {
        return $this->parser->parse($raw, $this->vocabulary);
    }

    /** @return array<string, string> */
    public function parsedFilters(string $raw): array
    {
        return $this->parsed($raw)['filters'];
    }

    /** @return array<int, string> */
    public function ignoredLabels(string $raw): array
    {
        return $this->parsed($raw)['ignored'];
    }

    public function parsedText(string $raw): string
    {
        return $this->parsed($raw)['text'];
    }
}
