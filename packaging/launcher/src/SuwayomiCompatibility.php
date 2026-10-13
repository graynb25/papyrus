<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

/**
 * Whether the Suwayomi that is running is one Papyrus can work with.
 *
 * ## Why this file exists at all
 *
 * Because there was nothing to check against. The build takes `--jar=<path>` and trusts the name, and
 * `version.txt` recorded the app version, the commit and a timestamp. So the shipped artifact did not
 * say which Suwayomi it was built to run against, and `health` could print the version it found
 * without ever comparing it to anything. A reader on an unsupported server would have seen a series
 * of GraphQL errors about fields that do not exist there, which is the least legible possible way to
 * learn that their server is too old.
 *
 * `packaging/suwayomi.json` is now the single answer, staged into the install root so the launcher
 * and `php artisan suwayomi:check` read the same two numbers and cannot drift apart.
 *
 * ## Why `tested` and `minimum` are two numbers and not one
 *
 * Because they answer different questions and a reader acts on them differently.
 *
 * `tested` is the exact server the suite was run against. Anything else is unproven *here*, which is
 * worth saying out loud without treating it as a fault: a reader on a newer Suwayomi may be entirely
 * fine, and reporting that as a problem would train them to ignore this line.
 *
 * `minimum` is the floor below which Papyrus genuinely cannot work, because the GraphQL fields it
 * depends on are not there. That is a fault, and it is the one case worth failing over, because
 * nothing the reader does will make it work and the only fix is a server upgrade.
 *
 * Collapsing them into one number forces a choice between refusing to start on an untested but
 * working server, and staying silent on one that cannot work. Two numbers avoid both.
 *
 * ## Why nothing here blocks startup
 *
 * Because a reader who cannot read their library is worse off with an app that refuses to open than
 * with one that opens and explains itself. The verdict is reported, never enforced. Startup is not
 * gated on it, and if you are reading this while the app is running, it is already running.
 *
 * ## The version comparison
 *
 * Suwayomi reports `v2.4.2366`. `version_compare('v2.4.2366', '2.4.0', '<')` is **true**, because a
 * leading `v` makes the first component non-numeric and PHP sorts the whole string as less than
 * anything numeric. Left unstripped, every single version this method sees looks too old, and the
 * check would fail a perfectly good server. That is not hypothetical: the same trap is documented in
 * `SuwayomiCheck`, where it was already got right once, and a fix that lives in one command and not
 * in the shared place is a fix that gets forgotten.
 */
final class SuwayomiCompatibility
{
    private function __construct(
        private readonly string $tested,
        private readonly string $minimum,
        private readonly bool $readable,
    ) {}

    /**
     * Whether a string is a version this class can order.
     *
     * ## Why this lives here and not in both callers
     *
     * Because the build and this class both need to answer it, and they disagreed the first time
     * they were written separately. The build rejected `latest` and a bare `v2.4.2366`; `read()`
     * accepted both. So a file the build refused to ship could still be read by a hand-placed one and
     * compared with, and the two answers to "is this usable" were not the same question.
     *
     * One rule, one place. The build requires this one file to ask, rather than keeping its own copy
     * of the pattern, which is how the two drifted apart in the first place.
     *
     * ## Why a leading `v` is rejected here but stripped by `normalise()`
     *
     * Because they are different jobs and conflating them hides a mistake. Suwayomi *reports* `v2.4.2366`
     * and that must be understood. This file *records* numbers, and a recorded `v` prefix is a person
     * having copied a reported version into a field that holds an orderable one. The build should
     * refuse it, because the whole of the comparison depends on this field being orderable and a `v`
     * in it is the exact defect `normalise()` exists to work around at runtime.
     */
    public static function isUsableVersion(string $value): bool
    {
        return preg_match('/^\d+(\.\d+)*$/', trim($value)) === 1;
    }

    /**
     * Read `suwayomi.json`, or report that it could not be read.
     *
     * An unreadable or absent file is not an error here. It means this build has no opinion about
     * compatibility, and inventing a default would produce a confident answer about a file nobody
     * wrote. The caller reports "could not be checked" rather than a verdict.
     */
    public static function read(string $path): self
    {
        if (! is_file($path)) {
            return new self('', '', false);
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return new self('', '', false);
        }

        $tested = trim((string) ($decoded['tested'] ?? ''));
        $minimum = trim((string) ($decoded['minimum'] ?? ''));

        if (! self::isUsableVersion($tested) || ! self::isUsableVersion($minimum)) {
            return new self('', '', false);
        }

        return new self($tested, $minimum, true);
    }

    /**
     * The server the suite was run against, as `v2.4.2366`.
     */
    public function tested(): string
    {
        return $this->tested === '' ? '' : 'v'.$this->tested;
    }

    /**
     * The oldest server that can work, as `v2.4.0`.
     */
    public function minimum(): string
    {
        return $this->minimum === '' ? '' : 'v'.$this->minimum;
    }

    /**
     * Whether this build knows which Suwayomi it wants.
     *
     * False means the caller must say it could not check, not that the server is fine.
     */
    public function isKnown(): bool
    {
        return $this->readable;
    }

    /**
     * Normalise what Suwayomi reported into something `version_compare` can order.
     *
     * Returns null for anything unparseable, including the empty string the probe gives when the
     * server answers but the field is missing. An unknown version is not an old version, and
     * treating it as one would fail a server that is working perfectly.
     */
    public static function normalise(string $reported): ?string
    {
        $stripped = ltrim(trim($reported), 'vV');

        return preg_match('/^\d+(\.\d+)*$/', $stripped) === 1 ? $stripped : null;
    }

    /**
     * Whether the reported server is older than Papyrus can work with.
     *
     * Null in, null out: an unreadable version is not evidence of anything.
     */
    public function isTooOld(string $reported): ?bool
    {
        $version = self::normalise($reported);

        if ($version === null || ! $this->readable) {
            return null;
        }

        return version_compare($version, $this->minimum, '<');
    }

    /**
     * Whether the reported server is the exact one this build was tested against.
     *
     * Also null for an unreadable version, for the same reason.
     */
    public function isTested(string $reported): ?bool
    {
        $version = self::normalise($reported);

        if ($version === null || ! $this->readable) {
            return null;
        }

        return $version === $this->tested;
    }
}
