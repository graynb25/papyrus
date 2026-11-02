<?php

namespace Papyrus\Launcher;

use RuntimeException;

/**
 * The Windows tools the launcher starts, named by absolute path.
 *
 * ## Why a bare image name is not good enough
 *
 * Windows resolves a bare image name through the application directory, then the current
 * directory, then `System32` and `PATH`. The per-user install lives under
 * `%LOCALAPPDATA%\Programs`, which the reader owns, and the shortcut's working directory can be
 * anywhere they chose. A `powershell.exe` or `taskkill.exe` placed beside the install, in
 * `runtime\bin`, or in the shortcut's working directory would run with the reader's privileges in
 * place of the system tool.
 *
 * Every other executable the launcher starts is already named by absolute path. This is the one
 * place that rule was not followed, and it is the only reason this class exists.
 *
 * ## Why it throws rather than falling back
 *
 * A missing `powershell.exe` is not a reason to try whatever the current directory calls
 * `powershell.exe`. The fallback is the attack. A tool that is genuinely absent is reported by
 * name so the reader can see which part of Windows is missing rather than silently running
 * something else.
 */
final class SystemTools
{
    /**
     * The Windows directory, from the environment rather than assumed to be `C:\Windows`.
     *
     * `SystemRoot` is set by Windows itself and points at the real directory on a machine whose
     * system drive is not `C:`. The literal is a fallback for a process that inherited no
     * environment, which is the same case `ManagedProcess` already handles.
     */
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

    public static function tasklist(): string
    {
        return self::required(self::systemRoot().'\\System32\\tasklist.exe');
    }

    /**
     * Refuse a tool that is not there, naming the path.
     *
     * The failure this replaces is worse than an error: a bare `taskkill` that resolves to
     * nothing runs no command and its exit status is the only signal, which is indistinguishable
     * from the tool running and refusing.
     */
    private static function required(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException('required Windows tool is missing: '.$path);
        }

        return $path;
    }
}
