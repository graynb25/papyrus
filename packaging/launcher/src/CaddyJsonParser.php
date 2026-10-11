<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Caddy's JSON log, converted to the same five fields as everything else.
 *
 * ## Why JSON is converted rather than left alone
 *
 * The doc's reason, and it holds: one `grep` across every collected file should find every line
 * whatever produced it. A reader looking for a refused connection should not have to know that
 * Caddy speaks JSON and Suwayomi does not.
 *
 * ## Why the extra fields are kept
 *
 * Caddy attaches context to a message by adding keys beside `msg`:
 *
 * ```
 * {"level":"info","ts":1791681286.5189898,"msg":"GOMEMLIMIT is updated","GOMEMLIMIT":15217857331,...}
 * ```
 *
 * Dropping them would discard exactly the part that makes a Caddy line diagnosable, and dropping
 * them silently is worse, because the line still looks complete. They are appended after `msg`,
 * which keeps `msg` byte for byte at the start where a grep for it will find it.
 */
final class CaddyJsonParser implements LineParser
{
    /** The keys that become fields of their own rather than part of the message. */
    private const RESERVED = ['level', 'ts', 'msg'];

    public function parse(string $line): ParsedLine
    {
        $decoded = json_decode($line, true);

        if (! is_array($decoded)) {
            /*
             * Not JSON, which is either a Caddy start-up banner on the same file or a truncated
             * final line of a file being appended to while it is read. Either way the line is
             * kept, because the alternative is a bundle that silently omits the moment Caddy
             * decided not to speak JSON.
             */
            return ParsedLine::record(null, 'INFO', 'caddy', $line);
        }

        $message = (string) ($decoded['msg'] ?? '');
        $extra = $this->extraFields($decoded);

        return ParsedLine::record(
            $this->timestamp($decoded['ts'] ?? null),
            $this->level((string) ($decoded['level'] ?? 'info')),
            'caddy',
            $extra === '' ? $message : $message.' ('.$extra.')',
        );
    }

    /**
     * Every key that is not one of the three reserved ones, in the order Caddy wrote them.
     *
     * Order is preserved rather than sorted because Caddy's ordering is the order the fields were
     * attached at the call site, which is more meaningful than an alphabetical accident.
     *
     * @param  array<string, mixed>  $decoded
     */
    private function extraFields(array $decoded): string
    {
        $parts = [];

        foreach ($decoded as $key => $value) {
            if (in_array((string) $key, self::RESERVED, true)) {
                continue;
            }

            $parts[] = $key.'='.$this->stringify($value);
        }

        return implode(', ', $parts);
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    private function level(string $level): string
    {
        $upper = strtoupper($level);

        return $upper === 'WARNING' ? 'WARN' : $upper;
    }

    /**
     * Caddy writes a Unix timestamp as a float, so the seconds are exact and there is no date to
     * guess. That is the only format in this layer that is unambiguous, and it is worth noting,
     * because the others all had to be given a date from somewhere.
     *
     * ## Why the result is checked
     *
     * `createFromFormat` returns false when it cannot parse, and this layer's rule is that a line
     * is never lost. A float that is numeric but out of range, `1.0E+25`, is enough to reach that
     * and calling `setTimezone()` on false is a fatal, which would take down the whole collection
     * for a single malformed line in a log we do not control.
     *
     * Null is returned instead, which is the same thing every other format that has no usable
     * timestamp produces, and the collector fills it from the log file rather than inventing one.
     */
    private function timestamp(mixed $ts): ?string
    {
        if (! is_numeric($ts)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(
            'U.u',
            (string) $ts,
            new DateTimeZone('UTC'),
        );

        if ($parsed === false) {
            return null;
        }

        return $parsed->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d\TH:i:s.vP');
    }
}
