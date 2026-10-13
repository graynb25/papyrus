# Write a Windows Sandbox configuration from the template beside it.
#
# ## Why a template and not a tracked .wsb
#
# Because a `.wsb` has to name an ABSOLUTE host folder. Windows Sandbox will not expand an
# environment variable in `<HostFolder>`, will not accept a relative one, and silently fails to mount
# anything else: the logon command never runs, the window closes, and there is no error. So the
# tracked file has to contain a real path.
#
# That path is where the developer's repository happens to live. It is one machine's folder layout,
# it is nobody's project content, and shipping it names the machine the project was worked on. This
# script keeps the real path out of the repository and writes it on demand instead.
#
# ## How to use it
#
#     powershell -File build\sandbox\resolve-wsb.ps1            both configurations
#     powershell -File build\sandbox\resolve-wsb.ps1 papyrus    just the gate
#     powershell -File build\sandbox\resolve-wsb.ps1 papyrus-hold
#
# Then launch the resolved file:
#
#     WindowsSandbox.exe build\sandbox\papyrus.wsb
#
# ## Why the results folder is created here rather than by the guest
#
# Because Windows Sandbox will not create a mapped folder that is missing on the host. The mount
# fails, the logon command never runs, and the sandbox closes looking exactly like a slow boot. That
# has cost this project real time twice, so it happens before the guest is asked for anything.

$ErrorActionPreference = 'Stop'

$sandbox = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoRoot = Split-Path -Parent (Split-Path -Parent $sandbox)

# Derived from this file rather than from the current directory, because the folder name is the thing
# that is wrong when somebody runs it from elsewhere and silently resolving to a different path is
# how a gate appears to work while testing nothing.
Write-Host "  repository root : $repoRoot"

$results = Join-Path $repoRoot 'build\sandbox-results'

if (-not (Test-Path $results)) {
    New-Item -ItemType Directory -Path $results -Force | Out-Null
    Write-Host "  created $results"
}

$wanted = @('papyrus', 'papyrus-hold')

if ($args.Count -gt 0) {
    $wanted = $args
}

foreach ($name in $wanted) {
    $template = Join-Path $sandbox "$name.wsb.template"
    $resolved = Join-Path $sandbox "$name.wsb"

    if (-not (Test-Path $template)) {
        Write-Host "  no template for $name, skipping"
        continue
    }

    $text = Get-Content $template -Raw

    if ($text -notmatch '%PAPYRUS_REPO_ROOT%') {
        Write-Host "  $name.wsb.template has no placeholder, copying it unchanged"
        Set-Content $resolved $text -NoNewline
        continue
    }

    Set-Content $resolved $text.Replace('%PAPYRUS_REPO_ROOT%', $repoRoot) -NoNewline

    Write-Host "  wrote $resolved"
}

Write-Host ""
Write-Host '  launch it with:'
Write-Host '    WindowsSandbox.exe build\sandbox\papyrus.wsb          the gate, which stops everything'
Write-Host '    WindowsSandbox.exe build\sandbox\papyrus-hold.wsb    leaves it running so you can use it'
Write-Host ''
Write-Host '  Only one guest at a time. Windows Sandbox refuses a second one by exiting zero with no'
Write-Host '  output, which is indistinguishable from a launch that worked.'