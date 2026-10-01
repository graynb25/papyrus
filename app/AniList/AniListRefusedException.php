<?php

namespace App\AniList;

/**
 * AniList would not answer, or could not be reached.
 *
 * Distinct from "AniList has no such title", which is an answer and returns null.
 * The distinction is not pedantry: a probe that conflated the two reported 71 of 91
 * titles as unmatched when the truth was that the tail of its sweep was being rate
 * limited, and a cached "no match" written on the strength of a 429 would be
 * permanent.
 */
class AniListRefusedException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $retryAfter = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
