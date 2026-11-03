<?php

namespace App\Live\Search;

/**
 * The search action's validation rules, kept out of the component.
 *
 * The action still calls `$this->validate()`; this class only holds the rules, so the
 * required query and the 1 to 150 result limit can be pinned directly.
 */
final class SearchRules
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'max:512'],
            'limit' => ['integer', 'min:1', 'max:150'],
        ];
    }
}
