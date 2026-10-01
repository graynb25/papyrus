<?php

namespace App\Support;

/**
 * The reader's session-scoped settings, the topmost resolution layer.
 *
 * ## Why a session layer exists at all
 *
 * The layers below it are durable. A reader who dims the page to read on a bus does
 * not want that dimming on a well-lit desk tomorrow, and a reader who zooms to 3x to
 * inspect a detail does not want the zoom next week. Mihon solves this with a
 * separate "temporary" mode, and the difference is worth keeping rather than folding
 * into "the last value the reader set".
 *
 * ## Why it is in the session, not the database
 *
 * It is not user state, so it does not belong in a per-reader table that a migration
 * or an export would sweep. It is not a cookie either, because the reader already has
 * a session and a second store would need its own expiry rules.
 *
 * ## Why nothing here is a view concern
 *
 * The reader is a plain document with plain JavaScript, so a filter change has to
 * reach the server to be a *layer* rather than a local style tweak. The script posts
 * it, the session holds it, and the next render resolves it. That costs a round trip
 * per adjustment, which is why the script also applies the change immediately rather
 * than waiting: the page looks right at once and the stored value catches up.
 */
class SessionOverride
{
    /**
     * The session key.
     *
     * Namespaced and suffixed, because a bare `settings` would collide with anything
     * else that decides to use one.
     */
    public const KEY = 'papyrus.reader.override';

    /**
     * The settings a reader may override for one visit.
     *
     * A list rather than a free map, because this is attacker-adjacent data: a session
     * is readable by whatever writes it, and anything outside this list would end up
     * in a style attribute or a resolved settings array without having been ranged.
     *
     * Deliberately excludes the ranges themselves (`min_width`, `max_width` and their
     * siblings). Those describe the control, not the choice, and a reader overriding
     * them would be changing the limits rather than a value within them.
     *
     * @var array<int, string>
     */
    private const ALLOWED = [
        'mode',
        'width',
        'gap',
        'background',
        'long_strip',
        'fit_height',
        'zoom',
        'hide_tap_zones',
        'brightness',
        'contrast',
        'invert',
        'hue',
        'grayscale',
    ];

    /**
     * What the session currently overrides.
     *
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $stored = session(self::KEY, []);

        if (! is_array($stored)) {
            return [];
        }

        // Filtered on read as well as on write, so a session written by an older
        // version, or by hand, cannot introduce a key that was never allowed.
        return array_intersect_key($stored, array_flip(self::ALLOWED));
    }

    /**
     * Replace the session's overrides.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed> what was actually stored
     */
    public function write(array $settings): array
    {
        $filtered = array_intersect_key($settings, array_flip(self::ALLOWED));

        // Nulls are dropped rather than stored, because `merge()` skips a null anyway
        // and a stored null would look like a value the reader had set.
        $filtered = array_filter($filtered, static fn (mixed $value): bool => $value !== null);

        if ($filtered === []) {
            session()->forget(self::KEY);

            return [];
        }

        session([self::KEY => $filtered]);

        return $filtered;
    }

    /**
     * Forget every override, so the reader is back to their durable settings.
     */
    public function clear(): void
    {
        session()->forget(self::KEY);
    }

    /**
     * Whether anything is overridden right now.
     */
    public function active(): bool
    {
        return $this->read() !== [];
    }

    /**
     * Move the current overrides into a durable layer and forget them.
     *
     * This is what a reader means by "remember this". A caller reads the session,
     * hands it to the layer that should own it, and clears it, so the two never
     * disagree about which value is in effect.
     *
     * @param  array<string, mixed>  $durable
     * @return array<string, mixed> what was made durable
     */
    public function commit(array $durable): array
    {
        $current = $this->read();

        $this->clear();

        return $current === [] ? $durable : $current;
    }

    /**
     * The keys a reader may override, for the validation rules on the endpoint.
     *
     * @return array<int, string>
     */
    public static function allowedKeys(): array
    {
        return self::ALLOWED;
    }
}
