<?php

namespace App\Suwayomi;

/**
 * Resolves a store host to the addresses this machine would connect to.
 *
 * The store check needs the answers because a name is only as public as the address
 * behind it. The resolver is an interface so a test can state those answers instead of
 * depending on whatever DNS says today, and so an install that cannot resolve a name
 * can still pass it to Suwayomi, which is the party that fetches the index.
 */
interface StoreHostResolver
{
    /**
     * @return list<string> the A and AAAA answers, or an empty list when the name does not resolve
     */
    public function resolve(string $host): array;
}
