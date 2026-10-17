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
     * Matched inside the name rather than against it, because the store appends
     * the language family to the name a reader recognises: "Comix (all)" is
     * still Comix, and "Kayn Scans (EN)" is still Kayn Scans.
     */
    public static function kind(string $extensionName): ?string
    {
        $sites = config('source-notices.sites');

        if (! is_array($sites) || $sites === []) {
            return null;
        }

        $name = mb_strtolower($extensionName);

        foreach ($sites as $site => $kind) {
            if (is_string($site) && str_contains($name, mb_strtolower($site))) {
                if (is_string($kind) && in_array($kind, [self::BAN, self::SCRAMBLE], true)) {
                    return $kind;
                }
            }
        }

        return null;
    }
}
