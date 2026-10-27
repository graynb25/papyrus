<?php

namespace App\Suwayomi;

/**
 * Decides whether Suwayomi may be asked to fetch a store index from an address.
 *
 * ## Why this exists
 *
 * Suwayomi fetches the store index, not the browser. A reader supplied address is
 * therefore a request made from this server, with this server's network position, so
 * `127.0.0.1`, a LAN address or a cloud metadata address would let a store address reach
 * services the reader cannot reach. Refusing it on the page alone is not enough because
 * the console command and the page both call the catalogue.
 *
 * ## Why the address is parsed rather than only matched
 *
 * A URL parser and the operating system accept several spellings of the same interface:
 * `2130706433`, `0177.0.0.1`, `0x7f.0.0.1` and `127.1` all mean loopback. A check for four
 * dotted decimals would pass every one of them to Suwayomi. The same applies to IPv6,
 * including addresses that embed an IPv4 address, so the address is reduced to bytes and
 * classified by its range instead of by its spelling.
 *
 * ## Why a name is resolved
 *
 * A public looking name can answer with a private address. Every answer is classified,
 * and one blocked answer refuses the store even when another answer is public, because
 * the client may try either. A name with no answer is left to Suwayomi: the server is the
 * party that fetches the index, and its resolver may know a name this machine does not.
 */
final class StoreUrlPolicy
{
    public function __construct(
        private readonly StoreHostResolver $resolver,
    ) {}

    /**
     * @throws SuwayomiException when the address is not one Suwayomi should fetch
     */
    public function assertAllowed(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new SuwayomiException(
                'A store address has to start with http:// or https://.',
                FailureKind::Query,
            );
        }

        if (! is_string($host)) {
            $this->refusePrivate();
        }

        $host = $this->normaliseHost($host);

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            $this->refusePrivate();
        }

        $literal = $this->classify($host);

        if ($literal === true) {
            $this->refusePrivate();
        }

        /*
         * A literal public address has no name to resolve. Only names go to the resolver,
         * so an address the reader typed is judged exactly as written.
         */
        if ($literal === false) {
            return;
        }

        foreach ($this->resolver->resolve($host) as $answer) {
            $normalised = $this->normaliseHost($answer);

            /*
             * Fail closed on an answer that is not an address. A and AAAA records are
             * addresses, so anything else means the resolver or this classifier is wrong,
             * and treating that as harmless would be the one case where an unreadable
             * answer opens the network.
             */
            if ($this->classify($normalised) !== false) {
                $this->refusePrivate();
            }
        }
    }

    private function refusePrivate(): never
    {
        throw new SuwayomiException(
            'That store address points at this server or a private network. Use a public address for the store.',
            FailureKind::Query,
        );
    }

    private function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host));

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        $zone = strpos($host, '%');

        if ($zone !== false) {
            $host = substr($host, 0, $zone);
        }

        return rtrim($host, '.');
    }

    /**
     * Classifies an address or name.
     *
     * @return bool|null true when blocked, false when it is a public address, null when the
     *                   value is a name rather than an address
     */
    private function classify(string $host): ?bool
    {
        $ipv4 = $this->legacyIpv4($host);

        if ($ipv4 !== null) {
            return $this->blockedIpv4($ipv4);
        }

        if (str_contains($host, ':')) {
            $packed = @inet_pton($host);

            if (is_string($packed) && strlen($packed) === 16) {
                return $this->blockedIpv6($packed);
            }
        }

        return null;
    }

    /**
     * Parses every spelling the C library accepts for an IPv4 address.
     *
     * The last part carries the remaining bytes, which is why `127.1` is loopback and not
     * `127.0.0.1` with a trailing one. A part that is out of range is treated as a name
     * rather than guessed at; Suwayomi will reject a nonsense address on its own.
     */
    private function legacyIpv4(string $host): ?int
    {
        if ($host === '' || str_contains($host, ':')) {
            return null;
        }

        $parts = explode('.', $host);
        $count = count($parts);

        if ($count > 4) {
            return null;
        }

        $values = [];

        foreach ($parts as $part) {
            $value = $this->numericPart($part);

            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        if ($count === 1) {
            $address = $values[0];
        } elseif ($count === 2) {
            $address = $values[0] > 255 || $values[1] > 0xFFFFFF
                ? null
                : ($values[0] << 24) | $values[1];
        } elseif ($count === 3) {
            $address = $values[0] > 255 || $values[1] > 255 || $values[2] > 0xFFFF
                ? null
                : ($values[0] << 24) | ($values[1] << 16) | $values[2];
        } else {
            $address = $values[0] > 255 || $values[1] > 255 || $values[2] > 255 || $values[3] > 255
                ? null
                : ($values[0] << 24) | ($values[1] << 16) | ($values[2] << 8) | $values[3];
        }

        if ($address === null || $address > 0xFFFFFFFF) {
            return null;
        }

        return $address;
    }

    private function numericPart(string $part): ?int
    {
        if ($part === '') {
            return null;
        }

        if (preg_match('/^0x([0-9a-f]+)$/i', $part, $matches) === 1) {
            if (strlen($matches[1]) > 8) {
                return null;
            }

            $value = hexdec($matches[1]);

            return is_int($value) ? $value : null;
        }

        if (preg_match('/^0[0-7]+$/', $part) === 1) {
            if (strlen($part) > 11) {
                return null;
            }

            $value = octdec($part);

            return is_int($value) ? $value : null;
        }

        if (preg_match('/^[0-9]+$/', $part) === 1) {
            if (strlen($part) > 10) {
                return null;
            }

            return (int) $part;
        }

        return null;
    }

    private function blockedIpv4(int $address): bool
    {
        $first = ($address >> 24) & 0xFF;

        return $first === 0
            || $first === 10
            || $first === 127
            || ($address & 0xFFC00000) === 0x64400000 // 100.64/10, carrier grade NAT behind mobile and shared uplinks
            || ($address & 0xFFFF0000) === 0xA9FE0000 // 169.254/16, link local, assigned per host
            || ($address & 0xFFF00000) === 0xAC100000 // 172.16/12, the middle private range
            || ($address & 0xFFFF0000) === 0xC0A80000 // 192.168/16, the small private range most home routers use
            || $first >= 224; // multicast, reserved and broadcast
    }

    private function blockedIpv6(string $packed): bool
    {
        $bytes = unpack('C16', $packed);

        if ($bytes === false) {
            return true;
        }

        $bytes = array_values($bytes);

        if ($bytes[0] === 0xFF) {
            return true; // ff00::/8, multicast
        }

        if (($bytes[0] & 0xFE) === 0xFC) {
            return true; // fc00::/7, unique local
        }

        if ($bytes[0] === 0xFE && ($bytes[1] & 0xC0) === 0x80) {
            return true; // fe80::/10, link local
        }

        $ipv4 = ($bytes[12] << 24) | ($bytes[13] << 16) | ($bytes[14] << 8) | $bytes[15];

        /*
         * IPv4 mapped (::ffff:a.b.c.d) and the deprecated IPv4 compatible form
         * (::a.b.c.d) reach the same interface. `::` and `::1` are covered because they
         * reduce to the unspecified and loopback IPv4 addresses, which the IPv4 ranges
         * already refuse.
         */
        $prefix = array_slice($bytes, 0, 12);
        $firstTenZero = array_slice($bytes, 0, 10) === array_fill(0, 10, 0);

        if ($prefix === array_fill(0, 12, 0)) {
            return $this->blockedIpv4($ipv4);
        }

        if ($firstTenZero && $bytes[10] === 0xFF && $bytes[11] === 0xFF) {
            return $this->blockedIpv4($ipv4);
        }

        return false;
    }
}
