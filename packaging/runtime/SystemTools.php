<?php

namespace Papyrus\Runtime;

use RuntimeException;

/**
 * The Windows tools the build scripts start, named by absolute path.
 *
 * ## Why this is separate from the launcher's copy
 *
 * The launcher ships inside an install and cannot reach `packaging/runtime`, while these scripts
 * run from the repository and never travel. The rule is the same and the reason is the same:
 * Windows resolves a bare image name through the current directory and `PATH`, and a build
 * machine's working directory is not a trusted place to find `tar.exe`.
 *
 * The two files are kept deliberately small so the duplication is a path calculation rather than
 * a second implementation of behaviour.
 */
final class SystemTools
{
    public static function systemRoot(): string
    {
        $root = getenv('SystemRoot');

        if (is_string($root) && trim($root) !== '') {
            return rtrim($root, '\\/');
        }

        return 'C:\\Windows';
    }

    public static function powershell(): string
    {
        return self::required(self::systemRoot().'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe');
    }

    public static function taskkill(): string
    {
        return self::required(self::systemRoot().'\\System32\\taskkill.exe');
    }

    public static function tar(): string
    {
        return self::required(self::systemRoot().'\\System32\\tar.exe');
    }

    private static function required(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException('required Windows tool is missing: '.$path);
        }

        return $path;
    }
}
