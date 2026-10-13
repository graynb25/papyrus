<?php

namespace App\Support;

/**
 * One question and its answer.
 *
 * ## Why the text lives in PHP rather than in the view
 *
 * Because the claims on this page are the product's most important promises, and a promise nobody
 * checks is a promise that quietly stops being true. Keeping them here means a test can assert that
 * the page still names every exception it is obliged to name, and that a claim about what leaves the
 * machine has not quietly lost an entry.
 *
 * That has already earned its place. The obvious first draft of the privacy answer was "nothing
 * leaves your computer", which is false: connecting an AniList account sends progress, downloading a
 * chapter contacts the source site it came from, and installing a source extension fetches a jar.
 * None of those is surveillance and all of them are things a reader should be told plainly, because a
 * privacy page that overstates is worth less than none.
 */
final class FaqEntry
{
    /**
     * @param  list<string>  $answer  paragraphs, in order
     * @param  list<string>  $exceptions  things that DO contact the internet, for the privacy answer
     */
    public function __construct(
        public readonly string $question,
        public readonly array $answer,
        public readonly array $exceptions = [],
        public readonly bool $isPrivacy = false,
    ) {}

    public function isExceptionless(): bool
    {
        return $this->exceptions === [];
    }
}
