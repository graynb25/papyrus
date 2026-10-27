# Take one named screenshot inside the manual sandbox.
#
# ## Why this exists beside the ten second loop
#
# The loop is the record. This is the index. A person who has just installed Papyrus, registered an
# account, added a source, or opened a chapter can name that moment, and the file name then says what
# the frame is rather than leaving it to be guessed from a timestamp.
#
# ## How it is used
#
# The desktop shortcut opens a small form, asks for a name, and captures after the form closes. The
# form is hidden before the capture so it is not in the picture. The shortcut runs PowerShell with
# -WindowStyle Hidden, so only the form is visible.
#
# It can also be called with -Label from a script, which skips the form.

param(
    [string]$Results = 'C:\results',
    [string]$Label = ''
)

$ErrorActionPreference = 'Continue'

. (Join-Path $PSScriptRoot 'screenshot-lib.ps1')
Initialize-SandboxScreenshotTypes

New-Item -ItemType Directory -Force -Path $Results | Out-Null

$chosen = $Label

if ([string]::IsNullOrWhiteSpace($chosen)) {
    Add-Type -AssemblyName System.Windows.Forms
    Add-Type -AssemblyName System.Drawing

    $form = New-Object System.Windows.Forms.Form
    $form.Text = 'Capture a step'
    $form.Size = New-Object -TypeName System.Drawing.Size -ArgumentList 390, 180
    $form.StartPosition = 'CenterScreen'
    $form.TopMost = $true
    $form.FormBorderStyle = 'FixedDialog'
    $form.MaximizeBox = $false
    $form.MinimizeBox = $false

    $prompt = New-Object System.Windows.Forms.Label
    $prompt.Text = 'Name this step, then click Capture:'
    $prompt.AutoSize = $true
    $prompt.Location = New-Object -TypeName System.Drawing.Point -ArgumentList 14, 14
    $form.Controls.Add($prompt)

    $text = New-Object System.Windows.Forms.TextBox
    $text.Text = 'step'
    $text.Location = New-Object -TypeName System.Drawing.Point -ArgumentList 14, 40
    $text.Width = 350
    $form.Controls.Add($text)

    $capture = New-Object System.Windows.Forms.Button
    $capture.Text = 'Capture'
    $capture.Location = New-Object -TypeName System.Drawing.Point -ArgumentList 196, 82
    $capture.Width = 80
    $capture.Add_Click({
        $script:chosen = $text.Text
        $form.Close()
    })
    $form.Controls.Add($capture)

    $cancel = New-Object System.Windows.Forms.Button
    $cancel.Text = 'Cancel'
    $cancel.Location = New-Object -TypeName System.Drawing.Point -ArgumentList 284, 82
    $cancel.Width = 80
    $cancel.Add_Click({
        $script:chosen = ''
        $form.Close()
    })
    $form.Controls.Add($cancel)

    $form.AcceptButton = $capture
    $form.CancelButton = $cancel
    [void]$form.ShowDialog()
    $form.Dispose()
}

if ([string]::IsNullOrWhiteSpace($chosen)) {
    exit 0
}

$safe = ($chosen.Trim() -replace '[^A-Za-z0-9._-]+', '-').Trim('-')

if ([string]::IsNullOrWhiteSpace($safe)) {
    $safe = 'step'
}

if ($safe.Length -gt 60) {
    $safe = $safe.Substring(0, 60)
}

# The form has closed, but Windows may still be activating the window behind it. A short wait keeps
# the form's own frame out of the picture.
Start-Sleep -Milliseconds 1200

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$path = Join-Path $Results ("step-{0}-{1}.png" -f $safe, $stamp)
$shot = Save-SandboxScreenshot -Path $path

$record = [pscustomobject]@{
    timestamp     = (Get-Date).ToString('o')
    label         = $chosen
    file          = (Split-Path -Leaf $path)
    width         = $shot.Width
    height        = $shot.Height
    bytes         = $shot.Bytes
    active_window = (Get-SandboxActiveWindowTitle)
    error         = $shot.Error
}

$record | Export-Csv -Path (Join-Path $Results 'steps.log') -NoTypeInformation -Append -Encoding UTF8

if ($shot.Error) {
    Add-Type -AssemblyName System.Windows.Forms
    [System.Windows.Forms.MessageBox]::Show(
        "Could not capture: $($shot.Error)",
        'Papyrus manual run',
        [System.Windows.Forms.MessageBoxButtons]::OK,
        [System.Windows.Forms.MessageBoxIcon]::Warning
    ) | Out-Null
}
