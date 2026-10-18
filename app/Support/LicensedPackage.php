<?php

namespace App\Support;

/**
 * One piece of third party code, as the licences page needs to show it.
 *
 * ## Why this is separate from {@see Licences}
 *
 * Because one of these is a fact about a package and the other is a fact about this install, and a
 * class that is both has to be constructed before it can answer the question it exists to answer.
 * `Licences` is resolved from the container with no arguments and reads the lock files; this is a
 * value, created by that reading.
 *
 * ## Why the classification lives here rather than in the reader
 *
 * Because the reader builds each of these and knows nothing else about them, so a flag computed
 * anywhere else is a flag that can disagree with the string printed beside it. A row reading
 * `not open source` next to a licence that says something else is the exact failure this page exists
 * to prevent, and it is better made impossible than tested for.
 *
 * ## The three states, and why there are three
 *
 * Named as proprietary, licensed, and not licensed. The first version had two, and folded "not
 * licensed" into "not open source" on the reasoning that silence is not permission. That reported
 * twenty three packages as closed when exactly one is: the rest were platform specific prebuilt
 * binaries for darwin, freebsd and arm that record no licence field at all.
 *
 * Overstating your own restrictions is a worse failure than leaving a reader to notice a blank. A
 * page that claims more than is true is not evidence of compliance either.
 */
final class LicensedPackage
{
    /**
     * Licences that are open in substance whatever they are called.
     *
     * Matched rather than enumerated as the full set of SPDX identifiers, because the question is not
     * "is this in the list" but "does this permit redistribution", and a permissive licence this
     * project has not seen before should count as open rather than as suspicious.
     *
     * @var list<string>
     */
    private const OPEN = [
        'mit', 'bsd', 'apache', 'isc', 'mpl', 'mozilla', 'gpl', 'lgpl', 'agpl', 'epl',
        'unlicense', 'public domain', 'artistic', 'zlib', '0bsd', 'cc0', 'wtfpl', 'osl',
    ];

    private function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $licence,
        public readonly string $source,
        public readonly bool $isProprietary,
        public readonly string $textKey = '',
        public readonly string $kind = '',
        public readonly string $upstream = '',
    ) {}

    /**
     * @param  string  $textKey  the identity a data source uses later to read this row's licence
     *                           text and to find this row again, empty when the reader building this row wants none
     * @param  string  $kind  `'composer'`, `'npm'`, or `'bundled'`, which is how the text reader
     *                        knows where a text file for this row lives, empty when there is no text to read
     * @param  string  $upstream  a link to the project behind this package, empty when none was
     *                            recorded anywhere the reader looked
     */
    public static function make(
        string $name,
        string $version,
        string $licence,
        string $source,
        string $textKey = '',
        string $kind = '',
        string $upstream = '',
    ): self {
        return new self($name, $version, $licence, $source, self::classify($licence) === 'closed', $textKey, $kind, $upstream);
    }

    /**
     * Whether this package says, in as many words, that it is not open source.
     *
     * Only that. An unrecorded licence is not an accusation.
     */
    public function isUnrecorded(): bool
    {
        return self::classify($this->licence) === 'unrecorded';
    }

    /**
     * `closed`, `open`, or `unrecorded`. The only copy of this rule.
     *
     * It existed twice in the first version: a static one used when constructing, and an instance one
     * used by `isUnrecorded()`. Two answers to one question that can disagree, with every test green
     * because both were individually correct, which is lesson 227 and the reason it is written down
     * here. A mutation that made the two disagree would have been caught by nothing at all.
     *
     * An unrecognised licence that does not name itself proprietary is left as open, because the
     * alternative is guessing and a wrong guess in either direction is worse than reporting it. The
     * list below is of licences open in substance, not a full set of SPDX identifiers, so a permissive
     * licence nobody has seen before counts as open rather than as suspicious.
     */
    private static function classify(string $licence): string
    {
        $needle = strtolower($licence);

        if (str_contains($needle, 'proprietary')) {
            return 'closed';
        }

        if ($needle === '' || str_contains($needle, 'not recorded')) {
            return 'unrecorded';
        }

        foreach (self::OPEN as $open) {
            if (str_contains($needle, $open)) {
                return 'open';
            }
        }

        return 'open';
    }

    /**
     * The name and version on one line, which is how a reader scans several hundred of these.
     */
    public function label(): string
    {
        return in_array($this->version, ['unknown', 'bundled'], true)
            ? $this->name
            : $this->name.' '.$this->version;
    }
}
