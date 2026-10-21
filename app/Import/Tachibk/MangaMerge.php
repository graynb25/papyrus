<?php

namespace App\Import\Tachibk;

/**
 * What the export plans to add to one series record, decided before any byte is touched.
 *
 * Everything here names things another app's file could never know: the shelf orders are
 * this server's own numbering, the read marks are the ledger's, and the source id and url
 * are only the key the export finds the record by. The merger applies plans append only,
 * so nothing here can ever turn a mark off or file a series out of a shelf it was in.
 */
final class MangaMerge
{
    /**
     * @param  list<int>  $categoryOrders  The shelf orders to append under field 17, unique
     *                                     across the whole file by the caller's plan.
     * @param  array<int, bool>  $readByOrder  Near Suwayomi's `sourceOrder`, true where the
     *                                         ledger says the chapter was read.
     * @param  array<int, bool>  $readByNumber  Truncated chapter numbers, the same second key
     *                                          the importer pairs by when an order misses.
     */
    public function __construct(
        public readonly int $sourceId,
        public readonly string $url,
        public readonly array $categoryOrders = [],
        public readonly array $readByOrder = [],
        public readonly array $readByNumber = [],
    ) {}
}
