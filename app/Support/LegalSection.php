<?php

namespace App\Support;

/**
 * One section of a legal page: a heading and the paragraphs under it.
 *
 * A value and nothing more. The prose lives in {@see Legal}, and this exists
 * so the markup has a shape to walk rather than an array to guess at.
 */
final class LegalSection
{
    /**
     * @param  list<string>  $paragraphs
     * @param  bool  $exceptions  Whether this section carries the outbound list.
     */
    public function __construct(
        public readonly string $heading,
        public readonly array $paragraphs,
        public readonly bool $exceptions = false,
    ) {}
}
