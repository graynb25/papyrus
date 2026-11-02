<?php

namespace Papyrus\Launcher;

use RuntimeException;

/**
 * The operating system process list could not be read.
 *
 * A distinct type from an empty list, because "there is nothing running" and "the probe failed"
 * are opposite answers. Returning an empty array for a failed probe made `stop` report that
 * nothing belonged to the install while its processes were still alive and its ports were held.
 */
final class ProcessProbeFailed extends RuntimeException {}
