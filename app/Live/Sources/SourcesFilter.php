<?php

namespace App\Live\Sources;

use App\Models\Source;

/**
 * The sources page's filter pipeline, kept out of the Livewire component.
 *
 * The page offers four catalogue slices, a name search, the languages a reader
 * has left out and a content rating. The class takes the adult gate's decision
 * and the page's already narrowed ratings as arguments, so the pipeline is a
 * pure function of values the component read and can be exercised without
 * mounting a page. The language list is passed through unchanged because the
 * server's match is case sensitive.
 */
final class SourcesFilter
{
    /**
     * The catalogue filters for one slice of the page.
     *
     * The Installed slice carries no rating filter at all while the gate is on:
     * an extension is either on the server or it is not, and a rating belongs to
     * the reader rather than to a list of what happens to be present. While the
     * gate is off it carries the gate's own allow list, because an adult-rated
     * extension that is installed is one the reader has not agreed to see, and
     * the count of what is hidden is named beside the list.
     *
     * The other three slices carry the ratings the page already narrowed with
     * the gate. The gate is applied in the query and not only here, so the
     * rating cannot come back through the address bar.
     *
     * @param  array<int, string>  $lang  The languages to leave out, in the catalogue's own casing.
     * @param  array<int, string>  $ratings  The page's ratings, already narrowed by the gate.
     * @return array<string, mixed>
     */
    public function filters(
        string $view,
        string $name,
        array $lang,
        array $ratings,
        bool $adultContentOn,
    ): array {
        $filters = match ($view) {
            'installed' => ['installed' => true],
            'available' => ['installed' => false, 'obsolete' => false],
            'updates' => ['has_update' => true, 'installed' => true],
            default => [],
        };

        if (trim($name) !== '') {
            $filters['name'] = trim($name);
        }

        $filters['content'] = $view === 'installed'
            ? ($adultContentOn ? [] : [Source::SAFE, Source::MIXED])
            : $ratings;

        if ($lang !== []) {
            $filters['lang'] = $lang;
        }

        return $filters;
    }

    /**
     * The same filters, without the reader's language choice.
     *
     * For the one thing that has to answer without it: which languages there
     * are. A list of pills that was asked for the languages still in the
     * result would lose a language the moment the reader switched it off, and
     * a language that cannot be seen cannot be switched back on.
     *
     * @param  array<int, string>  $lang
     * @param  array<int, string>  $ratings
     * @return array<string, mixed>
     */
    public function filtersWithoutLanguage(
        string $view,
        string $name,
        array $lang,
        array $ratings,
        bool $adultContentOn,
    ): array {
        $filters = $this->filters($view, $name, $lang, $ratings, $adultContentOn);

        unset($filters['lang']);

        return $filters;
    }
}
