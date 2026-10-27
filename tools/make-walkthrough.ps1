# Builds the walkthrough images in docs/walkthrough from one manual sandbox
# session's screenshots.
#
# The walkthrough (docs/WALKTHROUGH.md) shows the reader flow from the
# 2026-10-26 manual installer run; docs/ENGINEERING-NOTES.md section 16.5
# records that session. The session folder lives under build/, which git
# ignores, so the committed images in docs/walkthrough are the durable copy
# and this script is their provenance.
#
# Each frame is cropped to the one window it documents, so nothing from the
# desktop, the screenshots folder or the taskbar survives. In the browser
# frames the two third-party extension-store tabs and the new-tab button are
# blurred out of the tab strip: the guide must not show or name them.
#
# Geometry was measured on the source frames, not eyeballed. Frames are
# 2032x1111. The Edge window's own borders sit at x=17 (left), x=1003 (right),
# y=10 (top) and y=1041 (bottom); a window parked off-screen to the left shows
# a sliver at x 0..16 behind Edge's shadow, so the crop starts at the border.
# In every browser frame the active tab's white area ends at x=294, and the
# tab strip runs from y=11 (below the top border line) to y=49 (where the
# address bar's white row starts), so the blur region below covers the two
# third-party tabs and the new-tab button and nothing else. The setup window
# spans x 717..1314 and y 296..758 including its borders.

param(
    [string] $SessionDir = (Join-Path (Split-Path $PSScriptRoot) 'build\manual-sandbox-results\session-20261026-210533'),
    [string] $OutDir = (Join-Path (Split-Path $PSScriptRoot) 'docs\walkthrough')
)

Add-Type -AssemblyName System.Drawing

$edgeRect = New-Object System.Drawing.Rectangle(18, 11, 986, 1031)
$setupRect = New-Object System.Drawing.Rectangle(717, 296, 598, 463)
# Blur rect in edge-crop coordinates: x 297..812 and y 11..49 of the frame.
$blurRect = New-Object System.Drawing.Rectangle(279, 0, 516, 39)

# The frames behind each walkthrough image. 01 is the setup wizard; the rest
# are the Edge window in reading order.
$frames = @(
    @{ Out = '01-install.png';  Src = 'shot-000008-20261026-210700.png';  Rect = $setupRect; Blur = $false },
    @{ Out = '02-register.png'; Src = 'shot-000135-20261026-212823.png'; Rect = $edgeRect;  Blur = $false },
    @{ Out = '03-library.png';  Src = 'shot-000139-20261026-212904.png'; Rect = $edgeRect;  Blur = $false },
    @{ Out = '04-sources.png';  Src = 'shot-000148-20261026-213035.png'; Rect = $edgeRect;  Blur = $true },
    @{ Out = '05-search.png';   Src = 'shot-000151-20261026-213105.png'; Rect = $edgeRect;  Blur = $true },
    @{ Out = '06-series.png';   Src = 'shot-000164-20261026-213316.png'; Rect = $edgeRect;  Blur = $true },
    @{ Out = '07-reader.png';   Src = 'shot-000155-20261026-213146.png'; Rect = $edgeRect;  Blur = $true }
)

# Blurs by shrinking the region and drawing it back stretched. A 12x
# reduction destroys 14px tab titles while keeping the region's tone.
function Invoke-TabBlur($bmp, [System.Drawing.Rectangle] $rect) {
    $region = $bmp.Clone($rect, $bmp.PixelFormat)
    $smallW = [Math]::Max(1, [int][Math]::Round($rect.Width / 12.0))
    $smallH = [Math]::Max(1, [int][Math]::Round($rect.Height / 12.0))
    $small = New-Object System.Drawing.Bitmap($smallW, $smallH)
    $shrink = [System.Drawing.Graphics]::FromImage($small)
    $shrink.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $shrink.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $shrink.DrawImage($region, (New-Object System.Drawing.Rectangle(0, 0, $smallW, $smallH)),
        (New-Object System.Drawing.Rectangle(0, 0, $rect.Width, $rect.Height)),
        [System.Drawing.GraphicsUnit]::Pixel)
    $shrink.Dispose()
    $region.Dispose()
    $stretch = [System.Drawing.Graphics]::FromImage($bmp)
    $stretch.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $stretch.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $stretch.DrawImage($small, $rect,
        (New-Object System.Drawing.Rectangle(0, 0, $smallW, $smallH)),
        [System.Drawing.GraphicsUnit]::Pixel)
    $stretch.Dispose()
    $small.Dispose()
}

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
    if ($frame.Blur) {
        Invoke-TabBlur $crop $blurRect
    }
    $dstPath = Join-Path $OutDir $frame.Out
    $crop.Save($dstPath, [System.Drawing.Imaging.ImageFormat]::Png)
    $bytes = (Get-Item $dstPath).Length
    $crop.Dispose()
    "saved $($frame.Out) ($([math]::Round($bytes / 1KB)) KB)"
}
