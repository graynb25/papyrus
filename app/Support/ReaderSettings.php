<?php

namespace App\Support;

use App\Models\Category;
use App\Models\ReaderProfile;
use App\Models\SeriesState;
use App\Models\User;

/**
 * Reader settings and the order they resolve in.
 *
 * ## Why resolution is layered rather than one value
 *
 * Mihon resolves global, then category, then series, then a session override. That
 * ordering exists because the useful cases are all "except this one": night mode
 * everywhere except the one series with white pages, double spread except for
 * long webtoons, and a temporary zoom while inspecting a detail.
 *
 * So the layers are kept, and later ones win:
 *
 *   1. defaults, below
 *   2. the reader's default profile
 *   3. the series' home shelf, from `categories.settings`
 *   4. this series' `reader_settings` and `reading_mode`
 *   5. the session override, which the reader sets and clears on its own
 *
 * The shelf sits *below* the series deliberately. A shelf says how a group of
 * series reads, and a series that disagrees is the more specific statement, so
 * putting the shelf above would make a per-series override unreachable.
 *
 * A layer that is absent contributes nothing rather than a null, so an unset key
 * falls through instead of overriding a lower layer with nothing.
 *
 * ## Why the defaults are conservative
 *
 * Vertical scrolling with a fit-width column and no gap. AD-7 says win vertical
 * first, and a reader that starts in a mode the reader did not choose is a
 * reader that has to be reconfigured before first use. Every default here is a
 * value that works for a manga page without thinking.
 */
class ReaderSettings
{
    /** Continuous scroll. The default, and the mode AD-7 is built around. */
    public const MODE_VERTICAL = 'vertical';

    /** One page at a time, advanced explicitly. */
    public const MODE_PAGED = 'paged';

    /** Chapter list oldest first, which is how a series is meant to be read. */
    public const ORDER_OLDEST = 'asc';

    /** Chapter list newest first, for a reader chasing a late chapter. */
    public const ORDER_NEWEST = 'desc';

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            // 'vertical' or 'paged'. Anything else falls back to vertical.
            'mode' => self::MODE_VERTICAL,

            /*
             * Chapter list order on a series page.
             *
             * Here rather than on the page component, and that is the whole point of it. It sits
             * beside `mode` because it is chosen in the same pair of buttons and behaves the same
             * way. It was a plain Livewire property, so it reset to oldest every time the reader
             * navigated away and came back, while the mode buttons either side of it remembered.
             * A reader who sorts a long series newest-first, opens another series, and comes back
             * gets oldest-first again, with nothing on screen saying a choice was ever made.
             */
            'chapter_order' => self::ORDER_OLDEST,

            /*
             * Column width as a percentage of the viewport. 100 is "as wide as
             * the window", which is right for most pages and is what a reader on a
             * monitor expects. Anything narrower than about 45 makes text in an
             * image unreadable on a phone, so that is the floor.
             */
            'width' => 100,
            'min_width' => 45,
            'max_width' => 100,

            /*
             * Gap between pages, in pixels. Small but not zero: a webtoon with no
             * gap reads as one continuous image and the seam is invisible.
             */
            'gap' => 0,

            // Background behind the pages. Not white: a white page on a dark
            // screen is the single most common complaint about night readers.
            'background' => '#0a0a0a',

            // True for a tall strip where a single page spans many screens.
            'long_strip' => false,

            // Fit the whole page in the viewport rather than its width.
            'fit_height' => false,

            // 1.0 is natural size. Zooming is applied as a CSS transform so it
            // costs no layout and no re-fetch.
            'zoom' => 1.0,
            'min_zoom' => 0.5,
            'max_zoom' => 3.0,

            // Hide the tap zones, for a reader who prefers a scrollbar or a
            // keyboard. Also the accessibility escape hatch.
            'hide_tap_zones' => false,

            /*
             * Colour filters, for H10.
             *
             * `brightness` goes below 1.0 as well as above, which is the "negative
             * dim range" the plan asks for: 1.0 is untouched, 0.5 is half as bright
             * for a phone in the dark, 2.0 lifts a scanned page that is too grey.
             * The floor is 0.2 rather than 0 because below that a page is
             * unreadable and the reader cannot tell a filter from a failure.
             */
            'brightness' => 1.0,
            'min_brightness' => 0.2,
            'max_brightness' => 2.0,

            // 1.0 is neutral. A manga page is usually black on white, so a
            // contrast control at 0.5 is the one that helps a faint scan.
            'contrast' => 1.0,
            'min_contrast' => 0.3,
            'max_contrast' => 2.0,

            // Invert, for a reader who reads a white page as glare. Applies to
            // the pages only, never to the chrome around them.
            'invert' => false,

            // Hue rotation in degrees, 0 to 360, for the rare case of a page
            // whose colours are wrong. Not an adjustment most readers ever touch.
            'hue' => 0,

            // Grayscale, for a reader who wants colour out of the way entirely.
            'grayscale' => false,

            /*
             * Whether a filter change is worth remembering.
             *
             * A reader who dims to read on a bus does not want the dimming to
             * follow them to a well-lit desk, and a reader who inspects a detail at
             * 3x does not want the zoom next week. Both are session overrides, and
             * this is the switch that says so.
             */
            'sticky_filters' => false,
        ];
    }

    /**
     * Everything in effect for this reader in this series, merged and clamped.
     *
     * Five layers. The session one is passed in rather than read here, because this
     * class has no request, and a queue job or a command resolving settings must not
     * pick up whatever the last browser left lying around.
     *
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    public function resolve(User|int $user, int $mangaId, array $session = []): array
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        $settings = $this->defaults();

        $settings = $this->merge($settings, $this->profileSettings($userId));
        $settings = $this->merge($settings, $this->categorySettings($userId, $mangaId));
        $settings = $this->merge($settings, $this->seriesSettings($userId, $mangaId));
        $settings = $this->merge($settings, $session);

        return $this->clamp($settings);
    }

    /**
     * The home shelf's settings, which is the third layer.
     *
     * A series can sit on several shelves and this layer needs one answer rather than a
     * merge, because merging arbitrary shelves in an arbitrary order is not
     * deterministic, and a reader who cannot predict which shelf wins will not use it.
     *
     * `default_category_id` is the answer, and the column already exists: it is the
     * shelf the series was filed under, which is the one whose opinion about it the
     * reader actually stated. A series with no home shelf contributes nothing.
     *
     * @return array<string, mixed>
     */
    public function categorySettings(int $userId, int $mangaId): array
    {
        $state = SeriesState::forUser($userId)
            ->where('suwayomi_manga_id', $mangaId)
            ->first();

        if ($state === null || $state->default_category_id === null) {
            return [];
        }

        $category = Category::forUser($userId)
            ->whereKey($state->default_category_id)
            ->first();

        if ($category === null) {
            return [];
        }

        $settings = is_array($category->settings) ? $category->settings : [];

        /*
         * The series' own `reading_mode` beats the shelf's.
         *
         * `seriesSettings()` folds that column in and runs after this layer, so a shelf
         * saying paged and a series saying vertical already resolves to vertical. This
         * only drops the key so the layer does not report a preference it does not get.
         */
        if (isset($settings['mode']) && is_string($state->reading_mode) && $state->reading_mode !== '') {
            unset($settings['mode']);
        }

        return $settings;
    }

    /**
     * Record a shelf's settings.
     *
     * @param  array<string, mixed>  $settings
     */
    public function setCategorySettings(User|int $user, int $categoryId, array $settings): Category
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        $category = Category::forUser($userId)->whereKey($categoryId)->first();

        if ($category === null) {
            throw new \InvalidArgumentException("Shelf {$categoryId} is not this reader's.");
        }

        $existing = is_array($category->settings) ? $category->settings : [];

        // Clamped on write as well as on read, so a shelf never stores a value the
        // reader could not have set deliberately.
        $category->settings = $this->clamp($this->merge($existing, $settings));
        $category->save();

        return $category;
    }

    /**
     * Forget a shelf's settings, so its series fall through to the profile.
     */
    public function clearCategorySettings(User|int $user, int $categoryId): void
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        Category::forUser($userId)
            ->whereKey($categoryId)
            ->update(['settings' => null]);
    }

    /**
     * The user's default profile, if they have one.
     *
     * @return array<string, mixed>
     */
    public function profileSettings(int $userId): array
    {
        $profile = ReaderProfile::forUser($userId)
            ->where('is_default', true)
            ->first();

        if ($profile === null) {
            return [];
        }

        // No guard needed. The cast guarantees an array and the column is
        // non-nullable, so anything reaching here is a map of setting names.
        return $profile->settings;
    }

    /**
     * Per-series overrides, from two columns that exist for this purpose.
     *
     * @return array<string, mixed>
     */
    public function seriesSettings(int $userId, int $mangaId): array
    {
        $state = SeriesState::forUser($userId)
            ->where('suwayomi_manga_id', $mangaId)
            ->first();

        if ($state === null) {
            return [];
        }

        $settings = is_array($state->reader_settings) ? $state->reader_settings : [];

        // reading_mode is a separate column because the series page needs to show
        // and change the mode without knowing how settings are stored.
        if (is_string($state->reading_mode) && $state->reading_mode !== '') {
            $settings['mode'] = $state->reading_mode;
        }

        return $settings;
    }

    /**
     * Record an override for one series.
     *
     * @param  array<string, mixed>  $settings
     */
    public function setSeriesOverride(User|int $user, int $mangaId, array $settings): SeriesState
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        $state = SeriesState::forUser($userId)->where('suwayomi_manga_id', $mangaId)->first();

        if ($state === null) {
            // Reuse the same construction path as ReadingState so the tenancy
            // rules are not duplicated, then write the override.
            $state = app(ReadingState::class)->series($userId, $mangaId);
        }

        $merged = $this->merge(is_array($state->reader_settings) ? $state->reader_settings : [], $settings);
        $state->reader_settings = $merged;

        if (array_key_exists('mode', $settings)) {
            $state->reading_mode = (string) $settings['mode'];
        }

        $state->save();

        return $state;
    }

    /**
     * Forget a series override, so it falls back to the profile.
     *
     * A null column rather than an empty JSON object, because "no override" and
     * "an override that sets nothing" are the same state here and one of them
     * should be representable.
     */
    public function clearSeriesOverride(User|int $user, int $mangaId): void
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        SeriesState::forUser($userId)
            ->where('suwayomi_manga_id', $mangaId)
            ->update(['reader_settings' => null, 'reading_mode' => null]);
    }

    /**
     * Merge a layer over the one below.
     *
     * A null value is skipped rather than applied. Otherwise a column that
     * happens to hold JSON null would silently erase a lower layer, which is the
     * opposite of what "no override" means.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $layer
     * @return array<string, mixed>
     */
    public function merge(array $base, array $layer): array
    {
        foreach ($layer as $key => $value) {
            if ($value !== null) {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Force every value into a range the reader can actually use.
     *
     * Applied at the end rather than at each write, so a setting that was clamped
     * by an older version, or arrived from a profile edited elsewhere, still
     * resolves to something sane.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function clamp(array $settings): array
    {
        $settings += $this->defaults();

        // An unknown mode is vertical, not an error and not paged.
        if (! in_array($settings['mode'], [self::MODE_VERTICAL, self::MODE_PAGED], true)) {
            $settings['mode'] = self::MODE_VERTICAL;
        }

        /*
         * And an unknown order is oldest first.
         *
         * Clamped here rather than at each write for the same reason the rest are: a value can
         * arrive from a shelf edited elsewhere, or from a series row written before this setting
         * existed, and `array_reverse` on something that is neither 'asc' nor 'desc' would silently
         * give the reader the other order with nothing saying so.
         */
        if (! in_array($settings['chapter_order'], [self::ORDER_OLDEST, self::ORDER_NEWEST], true)) {
            $settings['chapter_order'] = self::ORDER_OLDEST;
        }

        $settings['width'] = (int) $this->between(
            $settings['width'],
            (int) $settings['min_width'],
            (int) $settings['max_width'],
        );

        // A gap is a visual choice, but a huge one turns a chapter into a gap
        // survey. 0 to 64 covers every use.
        $settings['gap'] = (int) $this->between($settings['gap'], 0, 64);

        $settings['zoom'] = (float) $this->between(
            $settings['zoom'],
            (float) $settings['min_zoom'],
            (float) $settings['max_zoom'],
        );

        /*
         * Colour filters, clamped here rather than at each write, for the same reason
         * the rest are: a value that arrived from a shelf edited elsewhere, or from a
         * version with different ranges, still has to resolve to something sane before
         * it reaches a style attribute.
         */
        $settings['brightness'] = (float) $this->between(
            $settings['brightness'],
            (float) $settings['min_brightness'],
            (float) $settings['max_brightness'],
        );

        $settings['contrast'] = (float) $this->between(
            $settings['contrast'],
            (float) $settings['min_contrast'],
            (float) $settings['max_contrast'],
        );

        $settings['hue'] = (int) $this->between($settings['hue'], 0, 360);
        $settings['invert'] = (bool) $settings['invert'];
        $settings['grayscale'] = (bool) $settings['grayscale'];
        $settings['sticky_filters'] = (bool) $settings['sticky_filters'];

        $settings['background'] = $this->colour($settings['background']);
        $settings['long_strip'] = (bool) $settings['long_strip'];
        $settings['fit_height'] = (bool) $settings['fit_height'];
        $settings['hide_tap_zones'] = (bool) $settings['hide_tap_zones'];

        return $settings;
    }

    /**
     * Clamp a value into a range.
     *
     * Typed mixed on the way in because the value comes from JSON, which can hold
     * anything: a string, a null, an array. Non-numeric input returns the minimum,
     * which is the safe end of every range here.
     */
    private function between(mixed $value, int|float $min, int|float $max): int|float
    {
        if (! is_numeric($value)) {
            return $min;
        }

        return max($min, min($max, $value + 0));
    }

    /**
     * A CSS colour, or the default if it is not one.
     *
     * The value ends up in a style attribute, so it is checked against a shape
     * rather than trusted. Anything unrecognised falls back rather than being
     * interpolated into CSS.
     */
    private function colour(mixed $value): string
    {
        $defaults = $this->defaults();

        if (! is_string($value) || $value === '') {
            return (string) $defaults['background'];
        }

        // #rgb, #rrggbb, and the two functional forms, plus the few keywords that
        // are genuinely useful for a reader background.
        $patterns = [
            '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i',
            '/^rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)$/i',
            '/^rgba\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*(0|1|0?\.\d+)\s*\)$/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return $value;
            }
        }

        return in_array(strtolower($value), ['black', 'white', 'grey', 'gray'], true)
            ? strtolower($value)
            : (string) $defaults['background'];
    }
}
