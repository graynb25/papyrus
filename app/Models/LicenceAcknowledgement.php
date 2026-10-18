<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Support\Carbon;

/**
 * One reader's acknowledgement of one licence, carrying the identity that read covered.
 *
 * ## Why the identity is stored and compared rather than assumed
 *
 * A pill that said `Acknowledged` for whatever version is on the page today would lie exactly when
 * the reader's attention is needed most: a version bump or a licence change has just happened and
 * the page claims the reader covered it. {@see stateFor()} therefore answers for this package under
 * this identity, and a row whose version or licence has moved re-arms the reader rather than
 * standing for the package it no longer describes.
 *
 * @property int $id
 * @property int $user_id
 * @property string $package
 * @property string $version
 * @property string $licence
 * @property Carbon|null $acknowledged_at
 */
#[Fillable(['package', 'version', 'licence', 'acknowledged_at'])]
class LicenceAcknowledgement extends OwnedModel
{
    /** The row's identity matches what the page shows: the read stands. */
    public const ACKNOWLEDGED = 'acknowledged';

    /** The package's row exists for this reader, but its identity has moved since the read. */
    public const REARMED = 'rearmed';

    /** No row for this reader and this package: nothing was ever read. */
    public const NONE = 'none';

    /**
     * Stamp, or re-stamp, the identity this reader has read, keeping one row per package.
     *
     * Re-acknowledging overwrites the identity rather than appending a history, because the only
     * question anything downstream asks is "has this reader read what is on the page now", which
     * one row answers and a history only complicates.
     */
    public static function markFor(User|int $user, string $package, string $version, string $licence): void
    {
        $row = static::forUser($user)->where('package', $package)->first();

        if ($row === null) {
            static::createFor($user, [
                'package' => $package,
                'version' => $version,
                'licence' => $licence,
                'acknowledged_at' => now(),
            ]);

            return;
        }

        $row->fill([
            'version' => $version,
            'licence' => $licence,
            'acknowledged_at' => now(),
        ]);

        $row->save();
    }

    /**
     * What the reader's standing is for this package under the identity the page shows.
     *
     * @return self::ACKNOWLEDGED|self::REARMED|self::NONE
     */
    public static function stateFor(User|int $user, string $package, string $version, string $licence): string
    {
        return self::statesFor($user, [$package => [$version, $licence]])[$package];
    }

    /**
     * The standing for many packages under one query, keyed by package.
     *
     * The licences page asks this for every row it renders: one question per row against
     * one query, rather than hundreds of the same question against hundreds of queries.
     * The comparison is the same one {@see stateFor()} makes; batching it changes nothing
     * about what an answer means.
     *
     * With no reader the answers are all NONE by shape, because nothing without a reader
     * was read by anyone: that is the honest answer, not a default.
     *
     * @param  array<string, array{0: string, 1: string}>  $identities  package => [version, licence]
     * @return array<string, self::ACKNOWLEDGED|self::REARMED|self::NONE> package => state
     */
    public static function statesFor(User|int|null $user, array $identities): array
    {
        if ($user === null) {
            return array_fill_keys(array_keys($identities), self::NONE);
        }

        if ($identities === []) {
            return [];
        }

        /** @var array<string, self> $rows */
        $rows = static::forUser($user)
            ->whereIn('package', array_keys($identities))
            ->get()
            ->keyBy('package');

        $states = [];

        foreach ($identities as $package => [$version, $licence]) {
            $row = $rows[$package] ?? null;

            $states[$package] = match (true) {
                $row === null => self::NONE,
                $row->version === $version && $row->licence === $licence => self::ACKNOWLEDGED,
                default => self::REARMED,
            };
        }

        return $states;
    }
}
