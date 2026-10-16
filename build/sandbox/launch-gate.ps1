# Launch the Windows Sandbox gate and wait for its report.
#
# ## Why a script rather than an inline command
#
# Kaspersky on this machine blocks long inline commands carrying several environment variables. Write
# the file and run it with -File, which is the workaround the AGENTS.md tooling table records.
#
# ## What "waiting" means here
#
# WindowsSandbox.exe returns as soon as the window appears. The guest then takes a minute or more to
# boot, and the runner reads from the same read only mount at logon, so the report is written well
# after the launch command has finished. So the launch and the wait cannot be the same process, and
# this one does both.
#
# ## The three ways this gate dies without saying so
#
#   1. A second guest. Windows Sandbox refuses to start one while another is open, exits zero and
#      prints nothing, and that is indistinguishable from a successful launch.
#   2. A missing results folder on the host. It will not create it, the mount fails, the logon command
#      never runs, and the guest closes looking exactly like a slow boot.
#   3. The previous guest's virtual machine not having released its resources. Three runs died this
#      way in a row, each with no progress file and no error.
#
# So this checks for a running guest before launching, creates the results folder first, and treats
# "no report appeared" as a failure that names those three rather than as a machine being slow.

<#
.SYNOPSIS
Runs the packaged install inside the Windows Sandbox and waits for its report.

.DESCRIPTION
Leave the guest open after the report, so it can be inspected. Off by default, because the gate stops
everything it started, and a guest left standing is a stopped install that still looks like a running
one, with Suwayomi's own browser window sitting in front of it. That is what happened on 2026-10-14:
the gate passed, stopped every process, and left a window open showing a manga server's admin panel,
which reads exactly like the app being broken.

.PARAMETER KeepOpen
Leave the guest running after the report, so it can be opened by hand.
#>
param(
    [switch]$KeepOpen
)

$ErrorActionPreference = 'Continue'

$repo    = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$results = Join-Path $repo 'build\sandbox-results'
$report  = Join-Path $results 'sandbox-report.txt'
$progress = Join-Path $results 'progress.txt'
$wsb     = Join-Path $repo 'build\sandbox\papyrus.wsb'

function Say([string]$line) {
    Write-Host $line
}

# --- 1. a guest must not already be running ------------------------------------------------
foreach ($name in @('vmmemWindowsSandbox', 'WindowsSandboxServer')) {
    $running = @(Get-Process -Name $name -ErrorAction SilentlyContinue)

    if ($running.Count -gt 0) {
        # Counted as well as named, and the pid read off `Id`.
        #
        # This refused a run once with an empty list between its parentheses. Reading the member
        # list settled why: `ProcessId` is not a member of the process objects PowerShell 5.1
        # returns, so it reads as nothing, with no error, for a live process and for this shell's
        # own process alike. The refusal could therefore print `(pids )` while a guest was really
        # running. `Id` is the member that exists. A process object that cannot answer at all
        # prints `unreadable` rather than nothing, so "one guest, these pids" and "something
        # matched and no pid could be read" are different messages.
        $pids = @($running | ForEach-Object {
            $id = $null
            try { $id = $_.Id } catch { $id = $null }
            if ($null -eq $id) { 'unreadable' } else { [string]$id }
        })

        Say "REFUSING TO LAUNCH: $name is already running ($($running.Count) matched, pids: $($pids -join ', '))."
        Say 'Only one guest at a time, and Windows Sandbox reports a refusal by exiting zero in silence.'
        exit 1
    }
}

Say 'ok   no guest is running'

# --- 2. the results folder must exist on the host before the guest is asked for anything ------
if (-not (Test-Path $results)) {
    New-Item -ItemType Directory -Path $results -Force | Out-Null
    Say "ok   created $results"
}

# Clear anything already in it, so a stale report can never be read as this run's.
Get-ChildItem $results -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue

# --- 3. the configuration and the runner must be there ----------------------------------------
foreach ($needed in @($wsb, (Join-Path $repo 'build\sandbox\run-in-sandbox.ps1'), (Join-Path $repo 'build\dist\Papyrus-1.2.0-setup.exe'))) {
    if (-not (Test-Path $needed)) {
        Say "REFUSING TO LAUNCH: $needed is missing."
        exit 1
    }
}

Say "ok   the installer under test is $((Get-Item (Join-Path $repo 'build\dist\Papyrus-1.2.0-setup.exe')).LastWriteTime)"

# --- launch -----------------------------------------------------------------------------------
Say ''
Say 'launching Windows Sandbox...'
Start-Process -FilePath "$env:SystemRoot\System32\WindowsSandbox.exe" -ArgumentList "`"$wsb`""

# --- wait -------------------------------------------------------------------------------------
# Twenty minutes. The previous runs finished inside five, and a gate that gives up early reports a
# failure that is really patience.
$deadline = (Get-Date).AddMinutes(20)
$lastProgress = ''
$lastChange  = Get-Date

Say 'waiting for C:\results\sandbox-report.txt'

while ((Get-Date) -lt $deadline) {
    Start-Sleep -Seconds 15

    if (Test-Path $progress) {
        $now = (Get-Content $progress -Raw -ErrorAction SilentlyContinue)

        if ($now -and $now -ne $lastProgress) {
            Say ("  progress: " + $now.Trim())
            $lastProgress = $now
            $lastChange = Get-Date
        }
    }

    if (Test-Path $report) {
        # Give the runner a moment to finish writing rather than reading a half written file.
        Start-Sleep -Seconds 4
        Say ''
        Say '=== the report ==='
        Get-Content $report | ForEach-Object { Write-Host $_ }

        if ($KeepOpen) {
            Say ''
            Say 'Leaving the guest open, as asked. It has been stopped, so nothing in it is running.'
        } else {
            Say ''
            Say 'closing the guest, which the gate has already stopped everything in'

            # The client first, because that is what owns the window. Then wait for the virtual
            # machine to let go, because the next launch refuses while one is still up and does it by
            # exiting zero in silence, which is the first of the three ways this dies without saying.
            Get-Process -Name 'WindowsSandbox' -ErrorAction SilentlyContinue |
                Stop-Process -Force -ErrorAction SilentlyContinue

            $gone = (Get-Date).AddSeconds(90)

            while ((Get-Date) -lt $gone) {
                if (-not (Get-Process -Name 'vmmemWindowsSandbox' -ErrorAction SilentlyContinue)) {
                    Say '  the guest has gone'
                    break
                }

                Start-Sleep -Seconds 5
            }

            if (Get-Process -Name 'vmmemWindowsSandbox' -ErrorAction SilentlyContinue) {
                Say '  WARNING: the virtual machine is still up after 90s. A second run will be refused'
                Say '  silently, so check this before launching again.'
            }
        }

        exit 0
    }

    if (((Get-Date) - $lastChange).TotalMinutes -gt 6) {
        Say ''
        Say 'NO REPORT, and nothing has touched the progress file for six minutes.'
        Say 'That is the failure this gate has, and it has three named causes:'
        Say '  1. a second guest was refused, silently'
        Say '  2. the results folder did not exist on the host, so nothing was ever mounted'
        Say '  3. the previous guest had not released its virtual machine'
        Say 'Nothing here means the install did not pass. It means this run proved nothing.'
        exit 2
    }
}

Say 'TIMED OUT after twenty minutes with no report. The install was not shown to fail and was not shown to pass.'
exit 2