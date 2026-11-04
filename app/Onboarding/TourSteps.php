<?php

namespace App\Onboarding;

use App\Support\OnboardingCopy;

/**
 * The first-run tour, as data.
 *
 * Copy, route, selector and the event that advances each step live here rather
 * than in the Blade view, so the sequence can be tested without a browser and
 * the copy can be checked for a URL or a store name in one place.
 *
 * @phpstan-type Step array{
 *     key: string,
 *     route: string,
 *     selector: string,
 *     title: string,
 *     body: string,
 *     mode: 'modal'|'interactive',
 *     advance: string|null,
 *     next: bool
 * }
 */
final class TourSteps
{
    public const MODE_MODAL = 'modal';

    public const MODE_INTERACTIVE = 'interactive';

    public const STORE_ADDED = 'tour-store-added';

    public const STORE_TRUSTED = 'tour-store-trusted';

    public const EXTENSION_INSTALLED = 'tour-extension-installed';

    public const SEARCH_COMPLETED = 'tour-search-completed';

    public const LIBRARY_ADDED = 'tour-library-added';

    /**
     * @return array<int, Step>
     */
    public static function forOwner(): array
    {
        return [
            [
                'key' => 'source-requirement',
                'route' => 'library',
                'selector' => '[data-tour="empty-library"]',
                'title' => __('Papyrus needs a source'),
                'body' => __(OnboardingCopy::SOURCE_REQUIREMENT),
                'mode' => self::MODE_MODAL,
                'advance' => null,
                'next' => true,
            ],
            [
                'key' => 'open-sources',
                'route' => 'sources',
                'selector' => '[data-tour="sources-page"]',
                'title' => __('This is Sources'),
                'body' => __('Stores and the extensions they publish live here. Nothing is bundled, and nothing is added for you.'),
                'mode' => self::MODE_MODAL,
                'advance' => null,
                'next' => true,
            ],
            [
                'key' => 'add-store',
                'route' => 'sources',
                'selector' => '[data-tour="add-store"]',
                'title' => __('Add a store you trust'),
                'body' => __('Paste the index URL of an extension repository you trust. The URL is the index file, usually index.pb or index.min.json. Papyrus does not ship or recommend one.'),
                'mode' => self::MODE_INTERACTIVE,
                'advance' => self::STORE_ADDED,
                'next' => false,
            ],
            [
                'key' => 'trust-store',
                'route' => 'sources',
                'selector' => '[data-tour="store-card"]',
                'title' => __('Compare the signing key'),
                'body' => __('Read the key this store publishes and compare it with the key its publisher has published. If they match, trust it. Trusting is what makes its extensions installable.'),
                'mode' => self::MODE_INTERACTIVE,
                'advance' => self::STORE_TRUSTED,
                'next' => false,
            ],
            [
                'key' => 'install-extension',
                'route' => 'sources',
                'selector' => '[data-tour="available"]',
                'title' => __('Install an extension'),
                'body' => __('Open Available. Each extension covers one site, or one language of it. Install the one that matches what you read.'),
                'mode' => self::MODE_INTERACTIVE,
                'advance' => self::EXTENSION_INSTALLED,
                'next' => false,
            ],
            [
                'key' => 'search',
                'route' => 'search',
                'selector' => '[data-tour="search-field"]',
                'title' => __('Search every source'),
                'body' => __('Ask once and every runnable source answers. Papyrus groups the duplicates and shows which sources answered.'),
                'mode' => self::MODE_INTERACTIVE,
                'advance' => self::SEARCH_COMPLETED,
                'next' => false,
            ],
            [
                'key' => 'open-result',
                'route' => 'search',
                'selector' => '[data-tour="first-result"]',
                'title' => __('Open a result'),
                'body' => __('Open the series you want. The next screen shows its chapters and the source it came from.'),
                'mode' => self::MODE_INTERACTIVE,
                'advance' => null,
                'next' => false,
            ],
            [
                'key' => 'add-to-library',
                'route' => 'series',
                'selector' => '[data-tour="add-to-library"]',
                'title' => __('Add it to your library'),
                'body' => __('Adding the series keeps it in your library. Papyrus remembers the source and your place.'),
                'mode' => self::MODE_INTERACTIVE,
                'advance' => self::LIBRARY_ADDED,
                'next' => false,
            ],
            [
                'key' => 'read',
                'route' => 'series',
                'selector' => '[data-tour="read-chapter"]',
                'title' => __('Read a chapter'),
                'body' => __('Open the first chapter. The reader saves your place as you go. Done when you are ready.'),
                'mode' => self::MODE_INTERACTIVE,
                'advance' => null,
                'next' => false,
            ],
        ];
    }

    /**
     * @return Step|null
     */
    public static function at(int $step): ?array
    {
        return self::forOwner()[$step - 1] ?? null;
    }

    public static function count(): int
    {
        return count(self::forOwner());
    }
}
