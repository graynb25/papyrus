<?php

namespace App\Support;

/**
 * A legal page: a title, a promise line and its sections.
 *
 * The promise comes first because it is the sentence a reader is looking for
 * when they open a privacy page, and a document that buries it under three
 * headings has already lost the reader.
 */
final class LegalDocument
{
    /**
     * @param  list<LegalSection>  $sections
     */
    public function __construct(
        public readonly string $title,
        public readonly string $promise,
        public readonly array $sections,
    ) {}
}
