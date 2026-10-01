<?php

namespace App\Search;

/**
 * Splits a typed search into free text and named filters.
 *
 * ## The shape, and why it is this shape
 *
 * `status:ongoing berserker` becomes free text `berserker` plus `status = ongoing`. That
 * is the whole language, and it is Mihon's rather than anything invented here.
 *
 * ## The rule that matters most: an unrecognised label stays text
 *
 * A reader searching for a literal string that happens to contain a colon must get their
 * string searched. So `foo:bar`, where `foo` is not a term we know, is not a filter and
 * not an error: it is two words of a title, passed through untouched. A parser that
 * silently swallowed unknown `label:value` pairs would quietly return nothing for a real
 * search.
 *
 * ## Quoting
 *
 * `author:"Someone With Spaces"` because author names contain spaces, and an unquoted
 * value would stop at the first one and leave the rest as search text, which is how a
 * filter turns into a nonsense query without any error being possible.
 */
class QueryParser
{
    /**
     * Split a raw query.
     *
     * @return array{
     *     text: string,
     *     filters: array<string, string>,
     *     ignored: array<int, string>,
     *     empty: array<int, string>
     * }
     */
    public function parse(string $raw, FilterVocabulary $vocabulary): array
    {
        $text = [];
        $filters = [];
        $ignored = [];
        $empty = [];

        /*
         * Matches `label:"quoted value"`, `label:bare-value` or a bare word.
         *
         * The label alternative comes **first**, and that ordering is load-bearing. PCRE
         * tries alternatives left to right at each position, so with the bare-word branch
         * first, `status:ongoing` matched `word=status` and stopped at the colon: every
         * filter came back empty and every test failed as though the parser were broken.
         * With the label first, a word that is not followed by a colon simply fails the
         * label branch and falls through to `word`.
         *
         * The quoted branch is before the bare one for the same reason: a bare-value
         * branch would stop at the opening quote and leave `"Isang` behind as text.
         */
        $pattern = '/(?P<label>[A-Za-z][A-Za-z0-9_-]*):(?:"(?P<quoted>[^"]*)"|(?P<bare>\S*))|(?P<word>[^\s:]+)/';

        preg_match_all($pattern, $raw, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (($match['label'] ?? '') === '') {
                $text[] = $match['word'] ?? '';

                continue;
            }

            $label = strtolower($match['label']);

            if (! $vocabulary->has($label)) {
                /*
                 * Not a term we know, so it is not a filter. It goes into BOTH the text
                 * and the ignored list: the text is what gets searched, and the list is
                 * what lets the page say so.
                 *
                 * Recording it only in `ignored` loses it entirely, which is how
                 * `http://example.com/x` came back with an empty search: one label, no
                 * words, and nothing for a reader to see.
                 */
                $ignored[] = $match[0];
                $text[] = $match[0];

                continue;
            }

            $value = trim(($match['quoted'] ?? '') !== ''
                ? $match['quoted']
                : ($match['bare'] ?? ''));

            if ($value === '') {
                $empty[] = $label;

                continue;
            }

            /*
             * First one wins for a repeated label, because `status:ongoing status:completed`
             * is a reader changing their mind and the last value is the one they meant.
             * Overwriting is also the only reading that needs no extra syntax.
             */
            $filters[$label] = $value;
        }

        return [
            'text' => trim(implode(' ', array_filter($text, static fn (string $w): bool => $w !== ''))),
            'filters' => $filters,
            'ignored' => $ignored,
            'empty' => $empty,
        ];
    }
}
