<?php

namespace App\Support;

/**
 * Site behaviour facts the Sources page states before an install.
 *
 * The four ban entries are what the owner named on 2026-10-16: these sites
 * block readers who search aggressively, and the bans run long enough that the
 * block page reads like the site is gone. Comix is the other class, corroborated
 * upstream in the Keiyoushi extensions-source tracker (issues 12130 and 16684):
 * searches through its extension return results unrelated to the query, page
 * images arrive scrambled, and the library can error out. No bans, just a push
 * back onto its own site, where the ads are.
 *
 * Only the sites named here get a notice. A partnership between sites stays out
 * of this file until something public and attributable documents one. When a
 * site's behaviour changes, this is the one file to correct, and the card copy
 * that represents it lives beside the page that renders it.
 */
final class SourceNotices
{
    public const BAN = 'ban';

    public const SCRAMBLE = 'scramble';

    /**
     * The notice kind for an extension's display name, or null when its site
     * carries none of these behaviours.
     *
     * Matched on the name, not merely inside it. Measured live on 2026-10-17,
     * when the matcher trusted substring containment it showed the scramble
     * notice on every extension whose name happens to contain "comix", eight
     * cards across seven unrelated publishers ("GlobalComix" and friends),
     * while the two upstream issues that corroborate this class, Keiyoushi
     * extensions-source 12130 and 16684, name exactly one extension, the
     * English "Comix". The one tolerance kept is a parenthetical qualifier
     * appended to the name, because a store writes the language family the
     * reader recognises there: "Comix (all)" is still Comix.
     */
    public static function kind(string $extensionName): ?string
    {
        $sites = config('source-notices.sites');

        if (! is_array($sites) || $sites === []) {
            return null;
        }

        $name = trim(mb_strtolower($extensionName));

        foreach ($sites as $site => $kind) {
            if (! is_string($site) || ! is_string($kind) || ! in_array($kind, [self::BAN, self::SCRAMBLE], true)) {
                continue;
            }

            $needle = trim(mb_strtolower($site));

            if ($name === $needle || str_starts_with($name, $needle.' (')) {
                return $kind;
            }
        }

        return null;
    }
}
