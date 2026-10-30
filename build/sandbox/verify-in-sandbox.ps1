# Run the two automated install harnesses inside a fresh Windows Sandbox guest.
#
# ## Why this runs in the guest
#
# `verify-installer.ps1` and `verify-install-immutable.ps1` install into %LOCALAPPDATA% and create a
# real library there. On the host that would collide with the reader's live 1.4.0 install and 1.5 GB
# of reader data, which is why both harnesses abort at pre-flight. A guest has neither, so the same
# checks can run against the 1.5.1 setup without touching the host.
#
# ## What it writes
#
#   verify-installer.txt      the full output of the install, data location and uninstall run
#   verify-installer.err.txt  stderr from that run
#   verify-immutable.txt      the full output of the zero-write install run
#   verify-immutable.err.txt  stderr from that run
#   verify-summary.txt        both exit codes and timings
#   DONE.txt                  written last, so the host knows the run ended
#
# The second harness only runs when the first one passed, because a failed first run can leave the
# install, the data directory, or a process behind and the second would measure that instead.

$ErrorActionPreference = 'Continue'

$results = 'C:\results'
$repo = 'C:\repo'
$tools = 'C:\tools'
$powershell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'

New-Item -ItemType Directory -Force -Path $results | Out-Null

$summary = [System.Collections.Generic.List[string]]::new()

function Add-Summary([string]$line) {
    Write-Host $line
    $script:summary.Add($line) | Out-Null
    Set-Content -Path (Join-Path $results 'verify-summary.txt') -Value ($summary -join "`n") -Encoding UTF8
}

function Run-Harness([string]$name, [string]$script) {
    $out = Join-Path $results "$name.txt"
    $err = Join-Path $results "$name.err.txt"

    Remove-Item -LiteralPath $out -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $err -Force -ErrorAction SilentlyContinue

    Add-Summary "$name=started $((Get-Date).ToString('o'))"

    $watch = [System.Diagnostics.Stopwatch]::StartNew()
    $code = 99

    try {
        $process = Start-Process -FilePath $powershell `
            -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $script, '-Repo', $repo) `
            -Wait -PassThru -NoNewWindow `
            -RedirectStandardOutput $out -RedirectStandardError $err

        $code = $process.ExitCode
    } catch {
        $code = 99
        Set-Content -Path $err -Value ("wrapper could not start the harness: " + $_.Exception.Message) -Encoding UTF8
    }

    $watch.Stop()

    Add-Summary ("{0}_exit={1}" -f $name, $code)
    Add-Summary ("{0}_seconds={1}" -f $name, [math]::Round($watch.Elapsed.TotalSeconds))

    return $code
}

Add-Summary "started=$((Get-Date).ToString('o'))"
Add-Summary "user=$env:USERNAME"
Add-Summary "computer=$env:COMPUTERNAME"
Add-Summary "repo=$repo"

$installerCode = Run-Harness 'verify-installer' (Join-Path $tools 'verify-installer.ps1')

if ($installerCode -eq 0) {
    $immutableCode = Run-Harness 'verify-immutable' (Join-Path $tools 'verify-install-immutable.ps1')
} else {
    $immutableCode = 'skipped'
    Add-Summary 'verify-immutable=skipped because verify-installer did not pass'
}

Add-Summary "finished=$((Get-Date).ToString('o'))"

@(
    "finished=$((Get-Date).ToString('o'))"
    "installer_exit=$installerCode"
    "immutable_exit=$immutableCode"
) | Set-Content -Path (Join-Path $results 'DONE.txt') -Encoding UTF8

$message = if ($installerCode -eq 0 -and $immutableCode -eq 0) {
    "Both install harnesses passed.`n`nClose the sandbox when you are ready."
} else {
    "One or more install harnesses did not pass.`n`nRead verify-summary.txt and the harness output in the results folder."
}

Add-Type -AssemblyName System.Windows.Forms
[System.Windows.Forms.MessageBox]::Show(
    $message,
    'Papyrus automated install run',
    [System.Windows.Forms.MessageBoxButtons]::OK,
    $(if ($installerCode -eq 0 -and $immutableCode -eq 0) { [System.Windows.Forms.MessageBoxIcon]::Information } else { [System.Windows.Forms.MessageBoxIcon]::Warning })
) | Out-Null

exit 0
