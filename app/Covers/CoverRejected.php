<?php

namespace App\Covers;

use RuntimeException;

/**
 * An upload that cannot be a cover, with a sentence a reader can act on.
 *
 * A dedicated exception rather than a validation rule because two of the three refusals
 * need to say something the reader can do something about, and a validation message is
 * the wrong shape for "this server does not have the memory to open that image".
 *
 * Carries no user-facing copy of its own beyond the message: the caller decides whether
 * that becomes a toast, a validation error, or a log line.
 */
class CoverRejected extends RuntimeException {}
