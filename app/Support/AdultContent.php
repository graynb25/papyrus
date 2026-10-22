<?php

namespace App\Support;

use App\Models\User;

/**
 * Whether this reader has said they want adult content.
 *
 * ## What it is
 *
 * A per-reader, per-machine yes or no, off until the reader turns it on
 * through a confirmation that states they are 18 or older. The owner's words,
 * 2026-10-19: "we need a way to hide the NSFW extensions until it is
 * activated, and confirmed. An explicit ask that the user is 18+ as well.
 * This needs to be built in. Because we want this app to also be safe."
 *
 * ## Why it is a permission and not a filter
 *
 * The catalogue's Content filter is a scope: Safe, Mixed and NSFW are three
 * ratings a reader may look at. This is a different thing, and it says whether
 * the reader may look at the third one at all. So the two are separate axes
 * and the gate removes NSFW from whatever was asked for and never adds it: a
 * reader who has turned it on may still be looking at Safe only.
 *
 * ## Why it is stored and not carried in the address
 *
 * Because a link is not a confirmation. The measurement of 2026-10-21 found
 * that `?content[]=NSFW` in the address put **41 adult-rated extensions on
 * the first page of the catalogue**, with nothing asked and the page still
 * claiming they were hidden. A gate a URL can open is not a gate, so the state
 * is a row in the local database beside the reader's own AniList switch, and
 * the address can only ever narrow what the gate already allows.
 *
 * ## Why it is per reader
 *
 * For the same reason the AniList switch is: in a shared deployment one
 * reader's choice must not decide what another sees. A reader who has turned
 * it on has not turned it on for whoever sits down next.
 */
final class AdultContent
{
    /**
     * The key this preference is filed under.
     *
     * A key in `user_settings` rather than a column, for the reason that
     * table's own first reader gives: the next preference belongs beside this
     * one, and a migration for every reader toggle is a worse answer than a
     * key.
     */
    public const KEY = 'adult_content';

    public function __construct(
        private readonly UserPreference $preferences,
    ) {}

    /**
     * Whether this reader has turned adult content on.
     *
     * Off is the answer when the row is absent, so a reader who has never
     * been asked is not asked by the reading.
     */
    public function enabled(User|int $user): bool
    {
        return $this->preferences->read($user, self::KEY) ?? false;
    }

    /**
     * Turn it on or off for this reader.
     */
    public function set(User|int $user, bool $enabled): void
    {
        $this->preferences->write($user, self::KEY, $enabled);
    }

    /**
     * What the confirmation says, which is where the 18+ ask lives.
     *
     * The rating is the catalogue's own, and the copy says so rather than
     * describing the material: the app cannot know what is in any particular
     * source, and the rating is the one fact it actually holds.
     */
    public function statement(): string
    {
        return __('Papyrus will then list and search the sources and extensions the catalogue marks NSFW. Turning this on is your confirmation that you are 18 or older. Nothing is sent anywhere: this is one setting in the database on this machine, and turning it off again takes it away at once.');
    }
}
