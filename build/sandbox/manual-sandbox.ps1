# Prepare the manual acceptance sandbox: the real installer, a desktop shortcut for it, the screenshot
# watcher, and a short set of instructions.
#
# ## Why this is not the gate
#
# `run-in-sandbox.ps1` copies `build/install` and tests it. That proves the install tree, and it never
# runs the setup executable. This script exists for the part the gate cannot prove: a person running
# the actual installer, accepting its choices, launching the installed app from its own shortcut,
# registering, adding a source, searching, and reading a chapter. Its evidence is manual acceptance,
# not a gate pass.
#
# ## What the person sees
#
# The installer is copied to the desktop so it is not run from a mapped folder. The desktop also gets
# shortcuts for the installer, a named step capture, stopping the screenshot loop, and opening the
# results folder. Notepad opens with the same instructions that are written to the results folder.
#
# ## What runs in the background
#
# capture-screen.ps1 takes a PNG every ten seconds until STOP.txt appears. It is hidden. The watcher
# writes into C:\results, which the host mapped to a session folder before the guest started.

$ErrorActionPreference = 'Continue'

$results = 'C:\results'
$desktop = [Environment]::GetFolderPath('Desktop')
$powershell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'

New-Item -ItemType Directory -Force -Path $results | Out-Null

$log = [System.Collections.Generic.List[string]]::new()

function Say([string]$line) {
    Write-Host $line
    $script:log.Add($line) | Out-Null
}

function Save-SetupLog {
    Set-Content -Path (Join-Path $results 'setup.log') -Value ($log -join "`n") -Encoding UTF8
}

function New-Shortcut {
    param(
        [string]$Path,
        [string]$Target,
        [string]$Arguments = '',
        [string]$WorkingDirectory = '',
        [string]$Description = ''
    )

    $shell = New-Object -ComObject WScript.Shell
    $shortcut = $shell.CreateShortcut($Path)
    $shortcut.TargetPath = $Target

    if ($Arguments -ne '') { $shortcut.Arguments = $Arguments }
    if ($WorkingDirectory -ne '') { $shortcut.WorkingDirectory = $WorkingDirectory }
    if ($Description -ne '') { $shortcut.Description = $Description }

    $shortcut.Save()
}

Say "manual sandbox started $((Get-Date).ToString('o'))"
Say "user $env:USERNAME, computer $env:COMPUTERNAME"
Say "results $results"

# The host writes this before launching. It is what makes the copied installer verifiable rather than
# merely present.
$expected = @{}
$expectedPath = Join-Path $results 'expected-installer.txt'

if (Test-Path -LiteralPath $expectedPath) {
    foreach ($line in Get-Content -LiteralPath $expectedPath) {
        if ($line -match '^([^=]+)=(.*)$') {
            $expected[$matches[1]] = $matches[2]
        }
    }
}

# The host writes the file name and version from VERSION, so the guest never guesses which
# installer to test. A build folder can hold several versions, and picking the newest once
# selected a payload the host had not verified.
$expectedFile = if ($expected.ContainsKey('file')) { [string]$expected['file'] } else { '' }
$runVersion = if ($expected.ContainsKey('version')) { [string]$expected['version'] } else { 'unknown' }

if ($expectedFile -eq '') {
    Say 'NO EXPECTED INSTALLER: expected-installer.txt has no file entry'
    Save-SetupLog

    Add-Type -AssemblyName System.Windows.Forms
    [System.Windows.Forms.MessageBox]::Show(
        "expected-installer.txt has no file entry.`n`nThe host did not record which installer this session should test, so nothing is launched.",
        'Papyrus manual run',
        [System.Windows.Forms.MessageBoxButtons]::OK,
        [System.Windows.Forms.MessageBoxIcon]::Error
    ) | Out-Null

    exit 1
}

$installerName = $expectedFile
$installerSource = Join-Path 'C:\installer' $installerName
$installer = Join-Path $desktop $installerName

if (-not (Test-Path -LiteralPath $installerSource)) {
    Say "NO INSTALLER: $installerSource is not in C:\installer"
    Save-SetupLog

    Add-Type -AssemblyName System.Windows.Forms
    [System.Windows.Forms.MessageBox]::Show(
        "The expected installer $installerName was not found in C:\installer.`n`nThe host mapping is wrong, so this session cannot test anything.",
        'Papyrus manual run',
        [System.Windows.Forms.MessageBoxButtons]::OK,
        [System.Windows.Forms.MessageBoxIcon]::Error
    ) | Out-Null

    exit 1
}

Say "copying $installerSource to $installer"
Copy-Item -LiteralPath $installerSource -Destination $installer -Force -ErrorAction Stop

$size = (Get-Item -LiteralPath $installer).Length
$hash = (Get-FileHash -LiteralPath $installer -Algorithm SHA256).Hash.ToLowerInvariant()
$expectedHash = if ($expected.ContainsKey('sha256')) { [string]$expected['sha256'] } else { '' }
$match = if ($expectedHash -ne '') { $hash -eq $expectedHash.ToLowerInvariant() } else { $null }

Say "installer size $size"
Say "installer sha256 $hash"

@(
    "file=$installerName"
    "size=$size"
    "sha256=$hash"
    "expected_sha256=$expectedHash"
    "match=$match"
) | Set-Content -Path (Join-Path $results 'installer-verified.txt') -Encoding UTF8

if ($match -ne $true) {
    Say 'REFUSING TO CONTINUE: the copied installer does not match expected-installer.txt'
    Save-SetupLog

    Add-Type -AssemblyName System.Windows.Forms
    [System.Windows.Forms.MessageBox]::Show(
        "The copied installer does not match the hash the host recorded.`n`nNothing was installed. Check installer-verified.txt in the results folder.",
        'Papyrus manual run',
        [System.Windows.Forms.MessageBoxButtons]::OK,
        [System.Windows.Forms.MessageBoxIcon]::Error
    ) | Out-Null

    exit 1
}

$os = Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue

@(
    "started=$((Get-Date).ToString('o'))"
    "user=$env:USERNAME"
    "computer=$env:COMPUTERNAME"
    "os=$($os.Caption) $($os.Version)"
    "version=$runVersion"
    "installer=$installerName"
    "installer_size=$size"
    "installer_sha256=$hash"
    "expected_sha256=$expectedHash"
    "sha256_match=$match"
) | Set-Content -Path (Join-Path $results 'session.txt') -Encoding UTF8

$readme = @"
Papyrus $runVersion manual acceptance run

This guest has the real installer, not a copy of the built install tree. Nothing else is installed.
The point is to do what a reader does, in the order a reader does it, and let the screenshots record
what actually appeared.

BEFORE YOU START

  The desktop has these shortcuts:

    Install Papyrus      runs the copied setup executable
    Capture a step       opens a small form, then takes one named screenshot
    Stop screenshots     writes the stop marker and ends the ten second loop
    Open results         opens the folder the host reads

  The screenshot loop is already running. It writes a PNG every ten seconds to C:\results.

THE RUN

  1. Double-click Install Papyrus. Accept the default install location and the default shortcuts.
     The installer is per user, so it should not ask for administrator rights.

  2. When setup finishes, leave Launch Papyrus checked and let it open the browser. If no browser
     appears, open Edge inside this sandbox and go to:

         http://127.0.0.1:8000

     A browser on the host cannot reach this address. The sandbox has its own loopback.

  3. Register a reader account on the login page. Use any address and password you will remember for
     this disposable guest.

  4. Open Sources. Add the Keiyoushi store:

         https://raw.githubusercontent.com/keiyoushi/extensions/repo/index.pb

     Refresh the store, install the MangaTaro extension, then sync sources if the page asks.

  5. Search for One Piece, open the series, and read a chapter. Let the reader show at least one
     page.

  6. Use Capture a step at the moments that matter: before the installer, after the install, after
     registration, after the store is added, after the extension is installed, when the series is
     open, and when a page is visible. Name each one. The ten second loop is the record; the named
     shots are the index.

  7. When you are done, double-click Stop screenshots and wait ten seconds for the loop to write
     its last frame, then close the sandbox window.

WHAT IS BEING TESTED

  The installer payload, its shortcuts, the first run from the installed app, the account flow, the
  source and extension flow through the UI, the search and reader pages, and what the pages actually
  look like at this window size. This is manual acceptance evidence. It is not the automated gate,
  and it does not become a gate pass.

  The installer is unsigned. If Windows shows a warning, record what it says before choosing to run
  it. Do not work around a warning silently.

  The app binds loopback only. That is deliberate. The only working address is the one inside this
  sandbox.
"@

$readmePath = Join-Path $results 'MANUAL-README.txt'
$readmeDesktop = Join-Path $desktop 'START-HERE.txt'
Set-Content -Path $readmePath -Value $readme -Encoding UTF8
Set-Content -Path $readmeDesktop -Value $readme -Encoding UTF8

New-Shortcut -Path (Join-Path $desktop 'Install Papyrus.lnk') -Target $installer `
    -WorkingDirectory $desktop -Description "Install Papyrus $runVersion"

New-Shortcut -Path (Join-Path $desktop 'Capture a step.lnk') -Target $powershell `
    -Arguments '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\sandbox\capture-step.ps1"' `
    -WorkingDirectory 'C:\sandbox' -Description 'Take one named screenshot'

New-Shortcut -Path (Join-Path $desktop 'Stop screenshots.lnk') -Target $powershell `
    -Arguments '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\sandbox\stop-capture.ps1"' `
    -WorkingDirectory 'C:\sandbox' -Description 'Stop the ten second screenshot loop'

New-Shortcut -Path (Join-Path $desktop 'Open results.lnk') -Target $results `
    -WorkingDirectory $results -Description 'Open the folder the host reads'

# A new session folder is created by the host each time, so a stop marker from an earlier run cannot
# be inherited. Removing it here is only a second line of defence.
Remove-Item -LiteralPath (Join-Path $results 'STOP.txt') -Force -ErrorAction SilentlyContinue

Say 'starting the screenshot watcher'
$watcher = Start-Process -FilePath $powershell `
    -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-WindowStyle', 'Hidden', '-File', 'C:\sandbox\capture-screen.ps1') `
    -WindowStyle Hidden `
    -PassThru

Start-Sleep -Seconds 2

$watcherAlive = $null -ne (Get-Process -Id $watcher.Id -ErrorAction SilentlyContinue)
Say "watcher pid $($watcher.Id), alive $watcherAlive"

@(
    "ready=$((Get-Date).ToString('o'))"
    "watcher_pid=$($watcher.Id)"
    "watcher_alive=$watcherAlive"
    "installer=$installer"
) | Set-Content -Path (Join-Path $results 'READY.txt') -Encoding UTF8

Save-SetupLog

Start-Process -FilePath (Join-Path $env:SystemRoot 'notepad.exe') -ArgumentList "`"$readmePath`""
