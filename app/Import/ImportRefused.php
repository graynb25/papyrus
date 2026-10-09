<?php

namespace App\Import;

use App\Covers\CoverRejected;
use RuntimeException;

/**
 * A backup this server will not read, with a sentence a reader can act on.
 *
 * A dedicated exception rather than a validation rule, for the reason
 * {@see CoverRejected} gives: several of these refusals need to say
 * something the reader can do something about, and a validation message is the
 * wrong shape for "this server does not have the memory to open that archive" or
 * "this backup is from a newer version of the app than this server understands".
 *
 * The distinction from a crash matters more here than a cover does. Every one of
 * these is a claim about the *input*, and they are all recoverable: a reader who
 * exports again, picks a different file, or frees disk space gets a working
 * import. A thrown PDOException or ZipArchive error instead surfaces as a 500 and
 * teaches the reader nothing, which is the outcome this class exists to prevent.
 *
 * Carries no user-facing copy of its own beyond the message: the caller decides
 * whether that becomes a toast, a validation error, or a log line.
 */
class ImportRefused extends RuntimeException {}
