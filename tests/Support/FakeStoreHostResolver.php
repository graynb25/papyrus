<?php

namespace Tests\Support;

use App\Suwayomi\StoreHostResolver;

/**
 * A resolver a test controls, so a store address never depends on the machine's DNS.
 *
 * The public address checks have to prove two different things: a name that answers with a
 * private address is refused, and one that answers with a public address is accepted. A real
 * resolver makes both tests depend on whatever the network says today, and the unresolved
 * case (which must be left to Suwayomi) is indistinguishable from a DNS outage. So the map
 * is explicit and empty by default, which is the unresolved case.
 */
final class FakeStoreHostResolver implements StoreHostResolver
{
    /**
     * Answers by lowercased host name. A host with no entry is unresolved.
     *
     * @var array<string, list<string>>
     */
    public array $answers = [];

    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        return $this->answers[strtolower($host)] ?? [];
    }
}
