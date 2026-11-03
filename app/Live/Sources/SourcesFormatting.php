<?php

namespace App\Live\Sources;

use App\Models\Source;
use App\Support\SourceNotices;
use Illuminate\Support\Collection;

/**
 * The sources page's pure formatting rules, kept out of the Livewire component.
 *
 * Everything here is a function of its arguments: the view labels, the site
 * behaviour notice, the host a source groups under and the site name recovered
 * from its language variants. No query and no reader state belongs here, which
 * is what lets the rules be exercised without mounting a page.
 */
final class SourcesFormatting
{
    /**
     * The strip's views: the four slices of the extension catalogue, and after
     * them the Migrate view, which is not a slice but reads at the same width.
     *
     * Built here rather than inline in the template because the template needs a
     * plain PHP string to interpolate into a component tag's attribute. Blade
     * compiles `{{ }}` inside one but does not compile `@js()`, which is why the
     * earlier version of this page threw "Invalid or unexpected token" in Alpine
     * the moment any of these controls was clicked.
     *
     * @return array<string, string>
     */
    public function views(): array
    {
        return [
            'installed' => (string) __('Installed'),
            'available' => (string) __('Available'),
            'updates' => (string) __('Updates'),
            'all' => (string) __('All'),
            /*
             * Last: the four above are one family (slices of the catalogue) and
             * their order is muscle memory. Migrate is another kind of thing the
             * strip reaches, and at the end it does not disturb the four.
             */
            'migrate' => (string) __('Migrate'),
        ];
    }

    /**
     * The site behaviour notice for one extension, or null.
     *
     * The kind comes from App\Support\SourceNotices, so the day a site's
     * behaviour changes there is one file to correct and the facts outlive the
     * wording; what a reader is told lives here with the page's other copy.
     *
     * @param  array<string, mixed>  $extension
     * @return array{icon: string, text: string}|null
     */
    public function noticeFor(array $extension): ?array
    {
        $kind = SourceNotices::kind((string) ($extension['name'] ?? ''));

        return $kind === null ? null : [
            'icon' => $kind === SourceNotices::BAN ? 'exclamation-triangle' : 'arrow-path',
            'text' => $kind === SourceNotices::BAN
                ? __('This site is known to block readers that search aggressively. Space searches out or pin it; expect long bans, not errors you can retry away.')
                : __('Searches from extensions here come back scrambled and pages can arrive broken. It does not ban: it sends you back to its own ad-laden site.'),
        ];
    }

    /**
     * The host to group a source under, or null when it has none worth grouping by.
     *
     * Parsed, not stripped. The first version removed the scheme with a regular expression and
     * took what was left as the host, which is fine until a `home_url` carries a path: measured
     * across the installed sources, `lhtranslation.net` is published as
     * `https://lhtranslation.net/manga/?m_orderby=views`, and stripping the scheme left
     *
     * ```
     * lhtranslation.net/manga/?m_orderby=views
     * ```
     *
     * as the group's host. That string was then printed under the site name, so a reader was
     * shown a query string as though it were a domain, and it was used as the grouping key,
     * which means the group a source lands in depends on a sort order in someone else's URL. An
     * extension that changed its default ordering would silently become a second group for the
     * same site.
     *
     * `parse_url` is the answer and the only reason this needed saying: a host is a component of
     * a URL, and asking for the component is different from deleting the parts you did not want.
     *
     * Null for a source with no `home_url`, and for one whose `home_url` is unparseable, since
     * both mean "no host to group by" and both get the local group rather than a group named
     * after a malformed string.
     */
    public function hostOf(?string $homeUrl): ?string
    {
        if ($homeUrl === null || trim($homeUrl) === '') {
            return null;
        }

        $host = parse_url(trim($homeUrl), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }

    /**
     * The friendliest name for a site, from the source names that share its host.
     *
     * A group is keyed by host, so every source on it is the same site and their names
     * differ only by the language suffix. The suffix is stripped to recover the site name,
     * which is why MangaDex reads as "MangaDex" rather than "MangaDex (EN)".
     *
     * @param  Collection<int, Source>  $rows
     */
    public function siteName(Collection $rows, string $host): string
    {
        $first = $rows->filter(fn (Source $s): bool => $s->home_url !== null && str_contains((string) $s->home_url, $host))->first();

        $name = (string) ($first->display_name ?? $host);
        $lang = (string) ($first->lang ?? '');

        if ($lang !== '' && str_ends_with($name, ' ('.$lang.')')) {
            return substr($name, 0, -strlen(' ('.$lang.')'));
        }

        $stripped = (string) preg_replace('/\s*\(([A-Za-z]{2}(?:-[A-Za-z0-9]+)*)\)\s*$/', '', $name);

        return $stripped !== '' ? $stripped : $host;
    }

    /**
     * The last page of a catalogue of this many rows.
     */
    public function lastPage(int $total, int $perPage): int
    {
        return max(1, (int) ceil($total / $perPage));
    }
}
