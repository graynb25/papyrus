# Screen capture shared by the manual sandbox watcher and the named step capture.
#
# ## Why this is a library rather than two copies
#
# The watcher takes a screenshot every ten seconds. The named step capture takes one when the person
# asks for it. Both need the same screen bounds, the same active window title, and the same error
# handling for a locked desktop or a UAC secure desktop, which is where CopyFromScreen throws rather
# than returning a black image. A second copy would drift, and the drift would only show up in the
# evidence.
#
# ## What it does not do
#
# It does not write a log. The caller owns the log format, because the watcher and the step capture
# record different fields.

function Initialize-SandboxScreenshotTypes {
    Add-Type -AssemblyName System.Windows.Forms
    Add-Type -AssemblyName System.Drawing

    if (-not ('PapyrusSandboxWindow' -as [type])) {
        Add-Type @'
using System;
using System.Runtime.InteropServices;
using System.Text;

public static class PapyrusSandboxWindow
{
    [DllImport("user32.dll")]
    private static extern IntPtr GetForegroundWindow();

    [DllImport("user32.dll", CharSet = CharSet.Unicode)]
    private static extern int GetWindowText(IntPtr hWnd, StringBuilder text, int count);

    public static string Title()
    {
        StringBuilder text = new StringBuilder(512);
        GetWindowText(GetForegroundWindow(), text, text.Capacity);
        return text.ToString();
    }
}
'@
    }
}

function Get-SandboxScreenBounds {
    Initialize-SandboxScreenshotTypes
    return [System.Windows.Forms.Screen]::PrimaryScreen.Bounds
}

function Get-SandboxActiveWindowTitle {
    Initialize-SandboxScreenshotTypes

    try {
        return [PapyrusSandboxWindow]::Title()
    } catch {
        return ''
    }
}

# Save one PNG and return what was actually written.
#
# A screenshot that failed is a record with an error, not an exception. The watcher must keep going
# after a locked screen or a secure desktop, because the next ten seconds may be the step that
# matters. The caller decides whether an error is fatal.
function Save-SandboxScreenshot {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path
    )

    Initialize-SandboxScreenshotTypes

    $bounds = [System.Windows.Forms.Screen]::PrimaryScreen.Bounds
    $bitmap = $null
    $graphics = $null

    try {
        $directory = Split-Path -Parent $Path

        if ($directory -and -not (Test-Path -LiteralPath $directory)) {
            New-Item -ItemType Directory -Force -Path $directory | Out-Null
        }

        $bitmap = New-Object -TypeName System.Drawing.Bitmap -ArgumentList $bounds.Width, $bounds.Height
        $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
        $graphics.CopyFromScreen($bounds.Location, [System.Drawing.Point]::Empty, $bounds.Size)
        $bitmap.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)

        return [pscustomobject]@{
            Path   = $Path
            Bytes  = (Get-Item -LiteralPath $Path).Length
            Width  = $bounds.Width
            Height = $bounds.Height
            Error  = $null
        }
    } catch {
        return [pscustomobject]@{
            Path   = $Path
            Bytes  = 0
            Width  = $bounds.Width
            Height = $bounds.Height
            Error  = $_.Exception.Message
        }
    } finally {
        if ($null -ne $graphics) { $graphics.Dispose() }
        if ($null -ne $bitmap) { $bitmap.Dispose() }
    }
}
