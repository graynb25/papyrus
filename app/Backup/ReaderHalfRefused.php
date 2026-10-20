<?php

namespace App\Backup;

use RuntimeException;

/**
 * A refusal to take a reader half, carrying the reason.
 *
 * The guard raises this rather than letting SQLite name the failure, because a VACUUM inside
 * a transaction surfaces as a generic SQL error that says nothing about transactions, which is
 * the worst answer to hand the caller of a backup operation.
 */
class ReaderHalfRefused extends RuntimeException {}
