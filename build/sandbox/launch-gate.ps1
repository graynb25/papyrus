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
    $running = Get-Process -Name $name -ErrorAction SilentlyContinue

    if ($running) {
        Say "REFUSING TO LAUNCH: $name is already running (pids $(@($running).ProcessId -join ', '))."
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