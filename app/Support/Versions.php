<?php

namespace App\Support;

use App\Suwayomi\Gateway;
use App\Suwayomi\SuwayomiException;
use Papyrus\Launcher\SuwayomiCompatibility;

/**
 * What this install is, and what it is running.
 *
 * ## Why this exists
 *
 * Because a reader who has a problem needs two numbers and had nowhere to read either of them. The
 * Papyrus version was recorded in a file at the root of the install and shown by nothing. The
 * Suwayomi version was shown by `papyrus health`, in a console window most readers never open, and
 * was never compared against anything even there. So the first question in every bug report, "which
 * version are you on", could not be answered from inside the app.
 *
 * ## Why the comparison is not written here
 *
 * Because the launcher already asks it, in `SuwayomiCompatibility`, and a second implementation is a
 * second opinion. `Papyrus\Launcher\` is PSR-4 mapped to `packaging/launcher/src/`, so this class
 * uses that one directly rather than reimplementing the floor, the comparison, or the leading-`v`
 * handling. Two copies of that rule disagreed the moment they existed: the launcher rejected a file
 * the reader accepted, which meant a build could refuse to ship something the app would then happily
 * compare against. One owner, asked twice.
 *
 * ## Why a missing file is never a guess
 *
 * Every method here returns null rather than a plausible default when it cannot read something, and
 * the view renders "unknown" or says it could not check. A checkout run from source has no
 * `version.txt` and a packaged install has no `VERSION` file, so both layouts are normal and both are
 * read. What neither is allowed to do is invent a version, because an invented version is worse than
 * an absent one: it is believed.
 */
final class Versions
{
    /**
     * What we know about the Suwayomi in front of us, given whatever we can read.
     *
     * Four states, and the middle two are the ones that used to be conflated.
     *
     * - `too_old`: below the floor. Nothing the reader does will help, and the fix is a server
     *   upgrade. This is the only state that is a fault.
     * - `untested`: at or above the floor, but not the server the suite ran against. Probably fine,
     *   unproven here, and reported as such rather than in the failure colour.
     * - `tested`: exactly the server this build expects.
     * - `unknown`: the server did not answer, or answered without a version, or this build records
     *   no expectation. No verdict, because none was reached.
     */
    public const TOO_OLD = 'too_old';

    public const UNTESTED = 'untested';

    public const TESTED = 'tested';

    public const UNKNOWN = 'unknown';

    /**
     * Read a small file from the install, trying both layouts a real install can have.
     *
     * Order matters and is the reverse of the intuition. A packaged install has `version.txt` and no
     * `VERSION`; a checkout has `VERSION` and no `version.txt`. Preferring `version.txt` means a
     * packaged install also gets the commit and the build time for free, because the build writes all
     * three into that one file.
     *
     * @return array<string, string> empty when neither file is readable
     */
    private function versionFile(): array
    {
        foreach (['version.txt', 'VERSION'] as $name) {
            $path = base_path($name);

            if (! is_file($path)) {
                continue;
            }

            $parsed = [];

            // The checkout's VERSION is one bare line, and the packaged file is three labelled lines.
            // Reading both the same way means `1.2.0` comes back as the version in either layout
            // rather than only in one of them.
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                if (preg_match('/^([a-z]+)\s+(\S+)$/i', $line, $matches) === 1) {
                    $parsed[strtolower($matches[1])] = $matches[2];

                    continue;
                }

                // A line with no label is the version itself, which is the only shape VERSION has.
                $parsed['version'] ??= $line;
            }

            if (($parsed['version'] ?? '') !== '') {
                return $parsed;
            }
        }

        return [];
    }

    /**
     * The Papyrus version, such as `1.2.0`, or null when this install does not record one.
     */
    public function papyrus(): ?string
    {
        return $this->versionFile()['version'] ?? null;
    }

    /**
     * The commit this install was built from, when the build recorded one.
     *
     * Null on a checkout, which is not a checkout of nothing: it is a working tree, and the commit
     * it is sitting on is whatever `git` says rather than what was shipped.
     */
    public function commit(): ?string
    {
        return $this->versionFile()['commit'] ?? null;
    }

    /**
     * When this install was built, when the build recorded it.
     */
    public function builtAt(): ?string
    {
        return $this->versionFile()['built'] ?? null;
    }

    /**
     * The Suwayomi release this build expects, read from the same file the launcher reads.
     *
     * Both layouts again: staged at the root of a packaged install, and at `packaging/` in the
     * repository. The build copies rather than regenerates precisely so there is one file, and this
     * looks in both places so the answer does not depend on which layout is running.
     */
    public function compatibility(): SuwayomiCompatibility
    {
        foreach (['suwayomi.json', 'packaging/suwayomi.json'] as $name) {
            $path = base_path($name);

            if (is_file($path)) {
                return SuwayomiCompatibility::read($path);
            }
        }

        return SuwayomiCompatibility::read('');
    }

    /**
     * Ask the running Suwayomi what it is.
     *
     * Returns null rather than throwing when it cannot be reached, because this is a page a reader
     * opened to find out what went wrong, and a page that 500s while explaining a broken server is
     * the worst possible answer. Every failure mode collapses to "could not read it" here and the
     * view words each one differently, so the caller decides what to say.
     */
    public function runningSuwayomi(Gateway $gateway): ?string
    {
        try {
            $data = $gateway->execute(<<<'GQL'
                query Version {
                    aboutServer { version }
                }
                GQL);
        } catch (SuwayomiException) {
            return null;
        }

        $version = $data['aboutServer']['version'] ?? null;

        return is_string($version) && trim($version) !== '' ? trim($version) : null;
    }

    /**
     * Which of the four states this install is in.
     *
     * A null running version is `unknown` rather than `untested`, because "we could not ask" and "we
     * asked and it is a version we have not seen" are different facts and a reader being told
     * "untested" when the server was simply unreachable would be told something untrue.
     */
    public function verdict(?string $running): string
    {
        $compatibility = $this->compatibility();

        if (! $compatibility->isKnown() || $running === null) {
            return self::UNKNOWN;
        }

        if ($compatibility->isTooOld($running) === true) {
            return self::TOO_OLD;
        }

        return $compatibility->isTested($running) === true ? self::TESTED : self::UNTESTED;
    }
}
