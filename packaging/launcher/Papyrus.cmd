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
rem window and opens the browser at the right moment. Until then, this is the honest shape of it.
rem
rem ## Why the failure path pauses
rem
rem Because the window is the only place a reason can be seen. Papyrus is an application that
rem runs a JVM, a web server and two workers, and when it does not start the reason is in the
rem output here. A wrapper that closed the window regardless would turn "Suwayomi did not come up on
rem port 4567" into a shortcut that appears to do nothing, which is the single most expensive kind
rem of bug report to receive.

setlocal

set "PAPYRUS_ROOT=%~dp0"
set "PAPYRUS_PHP=%PAPYRUS_ROOT%runtime\bin\php.exe"
set "PAPYRUS_LAUNCHER=%PAPYRUS_ROOT%packaging\launcher\papyrus.php"

if not exist "%PAPYRUS_PHP%" (
    echo Papyrus cannot start: the PHP runtime is missing from
    echo   "%PAPYRUS_ROOT%runtime\bin"
    echo.
    echo This install is incomplete. Reinstalling Papyrus will fix it.
    echo.
    pause
    exit /b 1
)

if not exist "%PAPYRUS_LAUNCHER%" (
    echo Papyrus cannot start: the launcher is missing from
    echo   "%PAPYRUS_ROOT%packaging\launcher"
    echo.
    echo This install is incomplete. Reinstalling Papyrus will fix it.
    echo.
    pause
    exit /b 1
)

"%PAPYRUS_PHP%" "%PAPYRUS_LAUNCHER%" start
set "PAPYRUS_EXIT=%ERRORLEVEL%"

if not "%PAPYRUS_EXIT%"=="0" (
    echo.
    echo Papyrus did not start. The lines above say why.
    echo.
    echo Keeping this window open so they can be read, or copied into a bug report.
    echo Press any key to close it.
    pause > nul
)

exit /b %PAPYRUS_EXIT%