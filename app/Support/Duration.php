<?php

namespace App\Support;

use Illuminate\Support\Number;

/**
 * Seconds said the way a reader says them.
 *
 * ## Why this is not four copies of a formatter
 *
 * Three pages were formatting time themselves, and two of them were wrong in
 * different ways. Insights dropped the seconds once a duration passed a minute,
 * so a 90 second sitting read "1 minute". The import modal printed the raw
 * Carbon difference, which is how a reader got `1273352.650004s`. The backup
 * modal counted whole seconds but in a sentence of its own. One formatter means
 * a fix to the wording fixes every place at once.
 *
 * ## Three units, largest first
 *
 * A number with five units in it is not more precise to a reader, it is longer.
 * `1 day 2 hours 3 minutes 4 seconds` becomes `1 day 2 hours 3 minutes`, and
 * a duration shorter than a minute keeps its seconds because "0 minutes" would
 * be a lie about a reading that happened.
 */
final class Duration
{
    /**
     * At most three units, largest first, with zeros left out.
     */
    public static function forHumans(int $seconds): string
    {
        $seconds = max(0, $seconds);

        if ($seconds < 60) {
            return self::part('second', $seconds);
        }

        /** @var list<array{0: string, 1: int}> $units */
        $units = [
            ['day', 86400],
            ['hour', 3600],
            ['minute', 60],
            ['second', 1],
        ];

        $parts = [];
        $remaining = $seconds;

        foreach ($units as [$unit, $size]) {
            if ($remaining < $size) {
                continue;
            }

            $count = intdiv($remaining, $size);
            $remaining %= $size;
            $parts[] = self::part($unit, $count);

            if (count($parts) === 3) {
                break;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * One unit, pluralised by the translator rather than by an `s`.
     *
     * `trans_choice` handles zero with the `[2,*]` branch, so a stopped clock
     * reads "0 seconds" instead of claiming a second that did not pass.
     */
    private static function part(string $unit, int $count): string
    {
        return trim(match ($unit) {
            'day' => trans_choice('{1} :count day|[2,*] :count days', $count, ['count' => Number::format($count)]),
            'hour' => trans_choice('{1} :count hour|[2,*] :count hours', $count, ['count' => Number::format($count)]),
            'minute' => trans_choice('{1} :count minute|[2,*] :count minutes', $count, ['count' => Number::format($count)]),
            default => trans_choice('{1} :count second|[2,*] :count seconds', $count, ['count' => Number::format($count)]),
        });
    }
}
