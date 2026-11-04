# Builds the walkthrough images in docs/walkthrough from one manual sandbox
# session's screenshots.
#
# The walkthrough (docs/WALKTHROUGH.md) shows the reader flow from the
# 2026-11-04 manual installer run; docs/ENGINEERING-NOTES.md section 16.6
# records that session. The session folder lives under build/, which git
# ignores, so the committed images in docs/walkthrough are the durable copy
# and this script is their provenance.
#
# Each frame is cropped to the one window it documents, so nothing from the
# desktop, the screenshots folder or the taskbar survives. The 2026-11-04
# session had a single browser tab, so there is no tab-blur step. The
# add-store frame had the tester's pasted index URL in the input; its interior
# is filled white, so the guide shows the empty field a fresh install presents
# and publishes no store URL.
#
# Geometry was measured on the source frames, not eyeballed. Frames are
# 2032x1111. The Edge window's own borders sit at x=17 (left), x=1003 (right),
# y=10 (top) and y=1041 (bottom); a window parked off-screen to the left shows
# a sliver at x 0..16 behind Edge's shadow, so the crop starts at the border.
# The setup mode dialog spans x 833..1182 and y 371..623 including its border.
# The search frame is cut at y=811 so it ends above covers that were still
# loading when the frame was taken.

param(
    [string] $SessionDir = (Join-Path (Split-Path $PSScriptRoot) 'build\manual-sandbox-results\session-20261104-094038'),
    [string] $OutDir = (Join-Path (Split-Path $PSScriptRoot) 'docs\walkthrough')
)

Add-Type -AssemblyName System.Drawing

$edgeRect = New-Object System.Drawing.Rectangle(18, 11, 986, 1031)
$setupRect = New-Object System.Drawing.Rectangle(832, 370, 352, 255)
$searchRect = New-Object System.Drawing.Rectangle(18, 11, 986, 900)
# Input interior in edge-crop coordinates: x 80..831 and y 933..965 of the frame.
$storeInputRect = New-Object System.Drawing.Rectangle(62, 922, 751, 32)

# The frames behind each walkthrough image, in reading order. The two 04
# images are the add-store form and the Available grid it leads to.
$frames = @(
    @{ Out = '01-install.png';      Src = 'shot-000003-20261104-094125.png'; Rect = $setupRect;  Redact = $false },
    @{ Out = '02-register.png';     Src = 'shot-000010-20261104-094236.png'; Rect = $edgeRect;   Redact = $false },
    @{ Out = '03-library.png';      Src = 'shot-000013-20261104-094307.png'; Rect = $edgeRect;   Redact = $false },
    @{ Out = '04-add-store.png';    Src = 'shot-000011-20261104-094246.png'; Rect = $edgeRect;   Redact = $true },
    @{ Out = '04-sources.png';      Src = 'shot-000012-20261104-094256.png'; Rect = $edgeRect;   Redact = $false },
    @{ Out = '05-search.png';       Src = 'shot-000014-20261104-094317.png'; Rect = $searchRect; Redact = $false },
    @{ Out = '06-series.png';       Src = 'shot-000015-20261104-094327.png'; Rect = $edgeRect;   Redact = $false },
    @{ Out = '07-reader.png';       Src = 'shot-000016-20261104-094337.png'; Rect = $edgeRect;   Redact = $false },
    @{ Out = '08-series-read.png';  Src = 'shot-000017-20261104-094347.png'; Rect = $edgeRect;   Redact = $false }
)

New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

foreach ($frame in $frames) {
    $srcPath = Join-Path $SessionDir $frame.Src
    $img = [System.Drawing.Image]::FromFile($srcPath)
    $crop = New-Object System.Drawing.Bitmap($frame.Rect.Width, $frame.Rect.Height)
    $g = [System.Drawing.Graphics]::FromImage($crop)
    $g.DrawImage($img, (New-Object System.Drawing.Rectangle(0, 0, $frame.Rect.Width, $frame.Rect.Height)),
        $frame.Rect, [System.Drawing.GraphicsUnit]::Pixel)
    $g.Dispose()
    $img.Dispose()
    if ($frame.Redact) {
        $g = [System.Drawing.Graphics]::FromImage($crop)
        $brush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::White)
        $g.FillRectangle($brush, $storeInputRect)
        $g.Dispose()
        $brush.Dispose()
    }
    $dstPath = Join-Path $OutDir $frame.Out
    $crop.Save($dstPath, [System.Drawing.Imaging.ImageFormat]::Png)
    $bytes = (Get-Item $dstPath).Length
    $crop.Dispose()
    "saved $($frame.Out) ($([math]::Round($bytes / 1KB)) KB)"
}
