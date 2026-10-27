# Take a screenshot every ten seconds inside the manual sandbox.
#
# ## Why a loop rather than named screenshots only
#
# The person driving the sandbox knows when a step starts, but the interesting failure often appears
# between the named screenshots: a dialog that opens late, a browser that shows an error after a
# redirect, a launcher that is still starting. A ten second loop covers those. The named captures are
# still the index, because a folder of hundreds of near identical frames is not a report.
#
# ## What it writes
#
#   shot-000001-20261026-201500.png   one per interval
#   screenshots.log                   timestamp, sequence, size, active window, error
#   capture-session.txt               when it started, the screen size, the limits
#   capture-stopped.txt               why it stopped and how many it took
#   capture.pid                       the process id, while it is running
#
# ## How it stops
#
# A file named STOP.txt in the results folder. The Stop screenshots desktop shortcut writes it. The
# loop checks before each capture, so it stops within one interval. It also stops at the shot limit,
# the time limit, or when the guest has less than 1 GB free, and writes the reason either way.
#
# ## Why errors do not end the run
#
# CopyFromScreen throws on a locked screen and on a UAC secure desktop. Either can happen once and
# then clear. The record carries the error and the loop continues, so one failed frame does not hide
# the rest of the session.

param(
    [string]$Results = 'C:\results',
    [int]$IntervalSeconds = 10,
    [int]$MaxShots = 1440,
    [double]$MaxHours = 4
)

$ErrorActionPreference = 'Continue'

. (Join-Path $PSScriptRoot 'screenshot-lib.ps1')
Initialize-SandboxScreenshotTypes

New-Item -ItemType Directory -Force -Path $Results | Out-Null

$stopFile = Join-Path $Results 'STOP.txt'
$log = Join-Path $Results 'screenshots.log'
$pidFile = Join-Path $Results 'capture.pid'
$sessionFile = Join-Path $Results 'capture-session.txt'
$stoppedFile = Join-Path $Results 'capture-stopped.txt'

Set-Content -Path $pidFile -Value $PID -Encoding UTF8

$started = Get-Date
$bounds = Get-SandboxScreenBounds

@(
    "started $($started.ToString('o'))"
    "pid $PID"
    "screen $($bounds.Width)x$($bounds.Height)"
    "interval_seconds $IntervalSeconds"
    "max_shots $MaxShots"
    "max_hours $MaxHours"
) | Set-Content -Path $sessionFile -Encoding UTF8

$sequence = 0
$reason = ''

try {
    while ($true) {
        if (Test-Path -LiteralPath $stopFile) {
            $reason = 'stop marker'
            break
        }

        if ($sequence -ge $MaxShots) {
            $reason = 'shot limit'
            break
        }

        if (((Get-Date) - $started).TotalHours -ge $MaxHours) {
            $reason = 'time limit'
            break
        }

        $free = $null

        try {
            $free = (New-Object -TypeName System.IO.DriveInfo -ArgumentList 'C').AvailableFreeSpace
        } catch {
            $free = $null
        }

        if ($null -ne $free -and $free -lt 1GB) {
            $reason = 'less than 1 GB free'
            break
        }

        $sequence++
        $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
        $path = Join-Path $Results ("shot-{0:D6}-{1}.png" -f $sequence, $stamp)
        $shot = Save-SandboxScreenshot -Path $path

        $record = [pscustomobject]@{
            timestamp     = (Get-Date).ToString('o')
            sequence      = $sequence
            width         = $shot.Width
            height        = $shot.Height
            bytes         = $shot.Bytes
            active_window = (Get-SandboxActiveWindowTitle)
            error         = $shot.Error
        }

        $record | Export-Csv -Path $log -NoTypeInformation -Append -Encoding UTF8

        if ($shot.Error) {
            Write-Host "capture $sequence failed: $($shot.Error)"
        }

        Start-Sleep -Seconds $IntervalSeconds
    }
} finally {
    $ended = Get-Date

    @(
        "started $($started.ToString('o'))"
        "ended $($ended.ToString('o'))"
        "reason $reason"
        "shots $sequence"
    ) | Set-Content -Path $stoppedFile -Encoding UTF8

    Remove-Item -LiteralPath $pidFile -Force -ErrorAction SilentlyContinue
}
