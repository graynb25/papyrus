@echo off
rem Papyrus, from a shortcut.
rem
rem ## What this is
rem
rem The only thing a reader ever runs. Every other entry point in this install is either a
rem command line tool or a file a build produced, and neither is something to put on a Start Menu.
rem
rem ## Why a window stays open
rem
rem `start` is the supervisor. It brings the four processes up, waits until they are genuinely
rem usable, then holds the terminal open and watches them. That is deliberate on the launcher's
rem side: the children are not daemons, so closing this window stops Papyrus rather than leaving it
rem running with nothing supervising it. See the comment above the hold loop in papyrus.php.
rem
rem So this shortcut opens a console window and keeps it, and closing it stops the app. That is not
rem the finished behaviour a desktop app should have. It is what is true today, and saying so in a
rem comment is better than a silent wrapper that looks finished and is not.
rem
rem The tray icon (roadmap item 0.8) is what replaces this: it holds the supervisor without a
rem window. Until then, this is the honest shape of it, apart from the browser, which this now does
rem open.
rem
rem ## Why it opens the browser
rem
rem `--open` tells the launcher to open the address it just bound, once it has confirmed the app is
rem genuinely usable. It is here rather than in the launcher unconditionally for two reasons: the
rem clean-room gate runs the same command on a machine with no browser, and anybody scripting a start
rem does not want a window appearing.
rem
rem It was worth adding rather than documenting the port. The port is not a constant: it is 8000 by
rem default, 8402 under the gate, and whatever the reader sets. A document can only state the common
rem case, and a reader who guessed wrong got ERR_CONNECTION_REFUSED from a perfectly healthy install
rem with no way to tell a broken app from a wrong address.
rem
rem ## Why the failure path pauses
rem
rem Because the window is the only place a reason can be seen. Papyrus is an application that
rem runs a JVM, a web server and two workers, and when it does not start the reason is in the
rem output here. A wrapper that closed the window regardless would turn "Suwayomi did not come up on
rem port 4567" into a shortcut that appears to do nothing, which is the single most expensive kind
rem of bug report to receive.
rem
rem ## Why the guard messages are separate files
rem
rem The guard blocks are reader-facing and translated, and cmd.exe cannot read accented UTF-8 in a
rem batch file reliably: measured here, a chcp 65001 script saved as UTF-8 without a BOM loses the
rem first character of every accented line, and a BOM makes the first line an unknown command. So
rem this file stays ASCII and reads a UTF-8 fragment through `for /f` after chcp 65001. The message
rem still expands PAPYRUS_ROOT and PAPYRUS_PHP because `call echo` runs on each fragment line.

setlocal

rem The launcher's own PHP output is UTF-8. Setting the console page once here makes both that and
rem the guard fragments render their accents instead of mojibake. Failure is ignored: an old console
rem without 65001 should still start the app.
chcp 65001 >nul 2>&1

set "PAPYRUS_ROOT=%~dp0"
set "PAPYRUS_PHP=%PAPYRUS_ROOT%runtime\bin\php.exe"
set "PAPYRUS_LAUNCHER=%PAPYRUS_ROOT%packaging\launcher\papyrus.php"
set "PAPYRUS_REG=%SystemRoot%\System32\reg.exe"
set "PAPYRUS_FINDSTR=%SystemRoot%\System32\findstr.exe"

rem The setup wizard's language is the next choice after an explicit environment override. Read
rem it before the Windows UI language, so the pick made during installation is the default the
rem reader sees on first start instead of having to choose again in Settings.
if not defined PAPYRUS_LOCALE if exist "%PAPYRUS_ROOT%installer-locale.txt" set /p PAPYRUS_LOCALE=<"%PAPYRUS_ROOT%installer-locale.txt"

call :detect_language

if not exist "%PAPYRUS_PHP%" (
    call :message missing-php
    pause
    exit /b 1
)

if not exist "%PAPYRUS_LAUNCHER%" (
    call :message missing-launcher
    pause
    exit /b 1
)

rem ## Why there is a check that "exists" does not cover
rem
rem A fresh install of this is 623MB of freshly written binaries, and antivirus software scans them
rem before it lets any of them execute. During that window cmd.exe refuses the runtime and writes one
rem line to stderr: "Access is denied." No file name, no path, and no mention of the app that was
rem being started.
rem
rem Without the check below, that produced this, which is the whole bug:
rem
rem     Access is denied.
rem
rem     Papyrus did not start. The lines above say why.
rem
rem The line above is true and useless. The reason is not the app failing, it is Windows declining to
rem run the interpreter the app is written in, and "the lines above" is cmd.exe's opinion about a
rem layer Papyrus does not own. A reader who files that as a bug report sends us antivirus software
rem we had no way to know about, which is the most expensive kind of report to receive.
rem
rem So the two cases are told apart. `-n -v` is the cheapest PHP that also exits without reading a
rem script: no ini, no extensions, print the version and stop. The `-v` is not decoration. PHP with
rem no script reads one from stdin, and a double-clicked console leaves stdin open, so `-n` alone
rem waited forever on this check. That shipped once: the window sat blank before the launcher could
rem print anything, and no data directory was created. The gate and the tests redirected stdin from
rem an empty file, so only a reader's double-click showed it.
"%PAPYRUS_PHP%" -n -v > nul 2>&1
if errorlevel 1 (
    call :message refused-runtime
    pause
    exit /b 1
)

"%PAPYRUS_PHP%" "%PAPYRUS_LAUNCHER%" start --open
set "PAPYRUS_EXIT=%ERRORLEVEL%"

if not "%PAPYRUS_EXIT%"=="0" (
    echo.
    call :message start-failed
    pause > nul
)

exit /b %PAPYRUS_EXIT%

rem -------------------------------------------------------------------------------------------
rem Language detection. PAPYRUS_LOCALE wins, then the Windows UI language list, then LocaleName,
rem then English. The same order as the PHP translator, so the guard text and the launcher's own
rem output agree.
rem -------------------------------------------------------------------------------------------

:detect_language
set "PAPYRUS_LANG="
if defined PAPYRUS_LOCALE call :locale_from_tag "%PAPYRUS_LOCALE%"
if not defined PAPYRUS_LANG (
    for /f "tokens=2,*" %%A in ('%PAPYRUS_REG% query "HKCU\Control Panel\International\User Profile" /v Languages 2^>nul ^| %PAPYRUS_FINDSTR% /i "Languages"') do call :locale_from_multi "%%B"
)
if not defined PAPYRUS_LANG (
    for /f "tokens=2,*" %%A in ('%PAPYRUS_REG% query "HKCU\Control Panel\International" /v LocaleName 2^>nul ^| %PAPYRUS_FINDSTR% /i "LocaleName"') do call :locale_from_tag "%%B"
)
if not defined PAPYRUS_LANG set "PAPYRUS_LANG=en"
goto :eof

rem The UI language list is a REG_MULTI_SZ printed as `en-US\0es-CO`; the first tag wins.
:locale_from_multi
set "PAPYRUS_TAG=%~1"
for /f "tokens=1 delims=\" %%T in ("%PAPYRUS_TAG%") do set "PAPYRUS_TAG=%%T"
call :locale_from_tag "%PAPYRUS_TAG%"
goto :eof

:locale_from_tag
set "PAPYRUS_TAG=%~1"
if /i "%PAPYRUS_TAG%"=="en" set "PAPYRUS_LANG=en"
if /i "%PAPYRUS_TAG:~0,3%"=="en-" set "PAPYRUS_LANG=en"
if /i "%PAPYRUS_TAG:~0,3%"=="en_" set "PAPYRUS_LANG=en"
if /i "%PAPYRUS_TAG%"=="es" set "PAPYRUS_LANG=es"
if /i "%PAPYRUS_TAG:~0,3%"=="es-" set "PAPYRUS_LANG=es"
if /i "%PAPYRUS_TAG:~0,3%"=="es_" set "PAPYRUS_LANG=es"
if /i "%PAPYRUS_TAG%"=="fr" set "PAPYRUS_LANG=fr"
if /i "%PAPYRUS_TAG:~0,3%"=="fr-" set "PAPYRUS_LANG=fr"
if /i "%PAPYRUS_TAG:~0,3%"=="fr_" set "PAPYRUS_LANG=fr"
if /i "%PAPYRUS_TAG%"=="pt" set "PAPYRUS_LANG=pt_BR"
if /i "%PAPYRUS_TAG%"=="pt-br" set "PAPYRUS_LANG=pt_BR"
if /i "%PAPYRUS_TAG%"=="pt_br" set "PAPYRUS_LANG=pt_BR"
goto :eof

rem -------------------------------------------------------------------------------------------
rem One guard block, expanded. `call echo` is what expands PAPYRUS_ROOT and PAPYRUS_PHP inside the
rem fragment; plain `type` would print the variable names. A line reading `<blank>` is an empty line,
rem because `for /f` skips genuinely empty lines.
rem -------------------------------------------------------------------------------------------

:message
set "PAPYRUS_MESSAGE=%PAPYRUS_ROOT%packaging\launcher\lang\cmd\%PAPYRUS_LANG%\%~1.txt"
if not exist "%PAPYRUS_MESSAGE%" set "PAPYRUS_MESSAGE=%PAPYRUS_ROOT%packaging\launcher\lang\cmd\en\%~1.txt"
if not exist "%PAPYRUS_MESSAGE%" (
    echo This install is incomplete. Reinstalling Papyrus will fix it.
    goto :eof
)
for /f "usebackq delims=" %%L in ("%PAPYRUS_MESSAGE%") do (
    if "%%L"=="<blank>" (
        echo.
    ) else (
        call echo %%L
    )
)
goto :eof
