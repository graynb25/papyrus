<?php

namespace App\Suwayomi;

/**
 * Asks this machine's resolver for the addresses behind a store host.
 *
 * A failed lookup is reported as no answers rather than as an error. The policy then
 * leaves the name to Suwayomi, which fetches the index from its own network position;
 * guessing that an address is unreachable from a local lookup would refuse a store that
 * works. Only answers that exist are judged.
 */
final class SystemStoreHostResolver implements StoreHostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        $answers = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && $address !== '') {
                $answers[] = $address;
            }
        }

        return array_values(array_unique($answers));
    }
}
