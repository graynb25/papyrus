<?php

namespace Papyrus\Launcher;

use RuntimeException;

/**
 * The install cannot be laid out as asked, said before anything is started.
 *
 * Separate from a plain RuntimeException so the caller can tell a layout problem, which a
 * reader can fix by choosing a different folder, from a process problem, which they cannot.
 */
final class LayoutRefused extends RuntimeException {}
