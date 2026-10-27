# Stop the manual sandbox screenshot loop, from the desktop shortcut.
#
# The watcher checks for STOP.txt before each capture. Writing it is the whole mechanism; the message
# box only tells the person that the request was recorded, because a hidden shortcut with no feedback
# looks like it did nothing.

param(
    [string]$Results = 'C:\results'
)

$ErrorActionPreference = 'Continue'

New-Item -ItemType Directory -Force -Path $Results | Out-Null

$path = Join-Path $Results 'STOP.txt'
Set-Content -Path $path -Value ("stop requested at " + (Get-Date).ToString('o')) -Encoding UTF8

Add-Type -AssemblyName System.Windows.Forms
[System.Windows.Forms.MessageBox]::Show(
    "Screenshots stopped. Wait ten seconds for the loop to write its last frame, then close the sandbox window.",
    'Papyrus manual run',
    [System.Windows.Forms.MessageBoxButtons]::OK,
    [System.Windows.Forms.MessageBoxIcon]::Information
) | Out-Null
