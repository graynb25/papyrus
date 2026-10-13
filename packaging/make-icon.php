<?php

declare(strict_types=1);

/**
 * Draw the Papyrus mark and render every size the app and the installer need.
 *
 * ## Parking note, read this before running it
 *
 * Not part of the build. Nothing calls it, `build-install.php` does not invoke it, and the icons it
 * writes are not the ones the application serves: those are still the stock Laravel favicon committed
 * to the repository, because the icon is an open decision and shipping a generated one would be
 * answering that decision without being asked.
 *
 * What it is for is that the icon will have to be generated rather than hand drawn, for a reason that
 * is not obvious until it has gone wrong once. The same source has to produce a sixteen pixel favicon
 * and a two hundred and fifty six pixel installer icon, and every consumer rasterises SVG differently
 * and at whatever quality it happens to manage. It also computes its colours from the vermilion ramp in
 * `resources/css/app.css`, so a second palette cannot appear.
 *
 * To use it: change the mark, run it, look at `public/icon-512.png`, then decide.
 *
 * ## Why it refuses to write an icon carrying less than five percent ink
 *
 * Because the first version produced entirely transparent sixteen and thirty two pixel files of 96 and
 * 104 bytes, with no error and no warning, while the five hundred and twelve pixel one was perfect.
 *
 * The cause was one line. The downscale loop tracked the *target* size rather than the canvas size, so
 * it never halved, and the final resample was told the source was 64 by 64 when it really was 2048 by
 * 2048. It read the empty top left corner. Nothing above this comment says that, so it is repeated
 * where the code is: see `render()`.
 *
 * The large icon is the one anybody looks at, which is exactly why this would have shipped, and it is
 * why the guard is a hard refusal rather than a warning.
 *
 * ## Why the colours are computed rather than picked
 *
 * Because the design already has an answer. `resources/css/app.css` defines exactly one saturated
 * accent, a vermilion ramp, and a comment in it says the ramp is built so `vermilion-600` holds up. An
 * icon written in "a red-orange I thought matched" is a second palette that goes stale the first time
 * the ramp is adjusted.
 *
 * OKLCH is the only reason that conversion exists: GD cannot consume it, and the design uses it.
 */

/** The vermilion ramp, from resources/css/app.css, as OKLCH. Only the stops this mark spends. */
const VERMILION = [
    400 => [0.778, 0.132, 34],
    600 => [0.643, 0.188, 32],
    700 => [0.562, 0.178, 31],
    900 => [0.412, 0.122, 30],
];

/**
 * OKLCH to sRGB hex, through OKLab and the linear-sRGB transfer.
 *
 * The transfer is not optional: skipping it gives a colour of recognisably the right hue and visibly
 * the wrong value, which is worse than being obviously wrong.
 */
function oklchToHex(float $lightness, float $chroma, float $hue): string
{
    $radians = deg2rad($hue);

    $a = $chroma * cos($radians);
    $b = $chroma * sin($radians);

    $l = ($lightness + (0.3963377774 * $a) + (0.2158037573 * $b)) ** 3;
    $m = ($lightness - (0.1055613458 * $a) - (0.0638541728 * $b)) ** 3;
    $s = ($lightness - (0.0894841775 * $a) - (1.2914855480 * $b)) ** 3;

    $red = (4.0767416621 * $l) - (3.3077115913 * $m) + (0.2309699292 * $s);
    $green = (-1.2684380046 * $l) + (2.6097574011 * $m) - (0.3413193965 * $s);
    $blue = (-0.0041960863 * $l) - (0.7034186147 * $m) + (1.7076147010 * $s);

    $encode = static function (float $channel): int {
        $channel = max(0.0, min(1.0, $channel));

        $value = $channel <= 0.0031308
            ? $channel * 12.92
            : (1.055 * ($channel ** (1 / 2.4))) - 0.055;

        return (int) round($value * 255);
    };

    return sprintf('#%02X%02X%02X', $encode($red), $encode($green), $encode($blue));
}

/**
 * Hex to the RGB triple GD's drawing functions take, bounded to a byte.
 *
 * Clamped rather than asserted, because `imagecolorallocate` wants `int<0, 255>` and an unbounded
 * `int` is a genuine way to be wrong here: over 255 is not a brighter colour, it is undefined
 * behaviour in a function with no return value to check.
 *
 * @return array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}
 */
function rgb(string $hex): array
{
    $channel = static fn (string $part): int => max(0, min(255, (int) hexdec($part)));

    return [$channel(substr($hex, 1, 2)), $channel(substr($hex, 3, 2)), $channel(substr($hex, 5, 2))];
}

/**
 * One stop of the ramp as a hex, refusing a stop that is not in it.
 *
 * Named rather than indexed inline, because reading `$palette[600]` straight off a constant leaves
 * the analyser unable to tell that the key exists. A guard says so honestly; a suppression would not.
 */
function hexFor(int $stop): string
{
    $value = VERMILION[$stop] ?? null;

    if ($value === null) {
        fwrite(STDERR, "  the vermilion ramp has no stop {$stop}\n");
        exit(1);
    }

    return oklchToHex($value[0], $value[1], $value[2]);
}

/**
 * A fully transparent colour, guarded the same way as the opaque ones.
 */
function transparent(GdImage $canvas): int
{
    $colour = imagecolorallocatealpha($canvas, 0, 0, 0, 127);

    if ($colour === false) {
        fwrite(STDERR, "  could not allocate a transparent colour\n");
        exit(1);
    }

    return $colour;
}

/**
 * A colour GD will accept, or nothing.
 *
 * `imagecolorallocate` is typed `int|false` and every drawing call wants a plain `int`, which is not
 * a complaint about this code: it is true that a failure is possible. Resolving it here means one
 * guard rather than a cast at eight call sites, and a cast would have hidden a real allocation
 * failure instead of refusing to draw with it.
 */
function allocate(GdImage $canvas, string $hex): int
{
    $colour = imagecolorallocate($canvas, ...rgb($hex));

    if ($colour === false) {
        fwrite(STDERR, "  could not allocate {$hex}\n");
        exit(1);
    }

    return $colour;
}

/**
 * A truecolour image of at least one pixel, because GD rejects zero in both directions.
 */
function surface(int $width, int $height): GdImage
{
    $image = imagecreatetruecolor(max(1, $width), max(1, $height));
    imagesavealpha($image, true);
    imagealphablending($image, false);

    return $image;
}

/** A filled rounded rectangle, in unscaled 512 space. */
function roundedRect(GdImage $canvas, int $x, int $y, int $w, int $h, int $r, int $colour, int $scale): void
{
    imagefilledrectangle($canvas, ($x + $r) * $scale, $y * $scale, ($x + $w - $r) * $scale, ($y + $h) * $scale, $colour);
    imagefilledrectangle($canvas, $x * $scale, ($y + $r) * $scale, ($x + $w) * $scale, ($y + $h - $r) * $scale, $colour);

    $d = $r * 2;
    imagefilledellipse($canvas, ($x + $r) * $scale, ($y + $r) * $scale, $d * $scale, $d * $scale, $colour);
    imagefilledellipse($canvas, ($x + $w - $r) * $scale, ($y + $r) * $scale, $d * $scale, $d * $scale, $colour);
    imagefilledellipse($canvas, ($x + $r) * $scale, ($y + $h - $r) * $scale, $d * $scale, $d * $scale, $colour);
    imagefilledellipse($canvas, ($x + $w - $r) * $scale, ($y + $h - $r) * $scale, $d * $scale, $d * $scale, $colour);
}

/**
 * Downsample to a size, which is where the antialiasing happens.
 *
 * ## The line in here that is the whole bug, commented because it will otherwise be tidied
 *
 * `$currentSize` is the size of `$current`, which begins as the full canvas. It is emphatically not
 * the target size. When it held `$size * $scale` the halving loop never ran at all, because the
 * condition was already false, and the final resample was then told its source was 64 by 64 while
 * the source really was 2048 by 2048. It read the empty top left corner and wrote a fully transparent
 * sixteen pixel file. No error. No warning. And a perfect five hundred and twelve pixel file beside it,
 * because at that size the corner it happened to read contained the mark.
 */
function render(GdImage $canvas, int $size, int $scale): GdImage
{
    $current = $canvas;
    $currentSize = imagesx($canvas);

    while ($currentSize > ($size * 4)) {
        $half = max($size, intdiv($currentSize, 2));
        $next = surface($half, $half);
        imagecopyresampled($next, $current, 0, 0, 0, 0, $half, $half, $currentSize, $currentSize);
        imagealphablending($next, true);

        if ($current !== $canvas) {
            imagedestroy($current);
        }

        $current = $next;
        $currentSize = $half;
    }

    $out = surface($size, $size);
    imagecopyresampled($out, $current, 0, 0, 0, 0, $size, $size, $currentSize, $currentSize);
    imagealphablending($out, true);

    if ($current !== $canvas) {
        imagedestroy($current);
    }

    return $out;
}

/**
 * How much of a frame is actually ink, which is the thing that has to be checked rather than assumed.
 */
function inkCoverage(GdImage $img): float
{
    $w = imagesx($img);
    $h = imagesy($img);
    $inked = 0;

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            // GD alpha is 0 for opaque and 127 for fully transparent, the reverse of every other
            // library, which is why "the icon has no alpha" and "the icon is invisible" are one file.
            if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) < 119) {
                $inked++;
            }
        }
    }

    return $inked / max(1, $w * $h);
}

$palette = [];
foreach (VERMILION as $stop => [$lightness, $chroma, $hue]) {
    $palette[$stop] = oklchToHex($lightness, $chroma, $hue);
}

echo "  the vermilion ramp, converted from the design tokens:\n";
foreach ($palette as $stop => $hex) {
    echo "    vermilion-{$stop}: {$hex}\n";
}

$fibre = hexFor(400);
$ink = hexFor(600);
$deep = hexFor(700);
$shade = hexFor(900);

$scale = 4;

$canvas = surface(512 * $scale, 512 * $scale);
imagefill($canvas, 0, 0, transparent($canvas));

$cInk = allocate($canvas, $ink);
$cDeep = allocate($canvas, $deep);
$cFibre = allocate($canvas, $fibre);
$cShade = allocate($canvas, $shade);

// The sheet: wide, upright, and clear of the edges so it survives being reduced to sixteen pixels.
roundedRect($canvas, 140, 88, 232, 300, 18, $cInk, $scale);

// The fibre. Three lines rather than a texture, because a texture is noise at favicon size, and
// vermilion-400 rather than something lighter, because vermilion-200 on this ground measured as
// almost white and read as a rendering fault rather than as a material.
foreach ([136, 180, 224] as $y) {
    imagefilledrectangle($canvas, 172 * $scale, $y * $scale, 340 * $scale, ($y + 14) * $scale, $cFibre);
}

// The rolled edge, sitting proud of the sheet so the silhouette reads as a scroll.
roundedRect($canvas, 104, 336, 304, 92, 44, $cDeep, $scale);
roundedRect($canvas, 132, 356, 248, 34, 17, $cFibre, $scale);

// A dark lip under the roll, so the roll has a direction and is not a lozenge.
roundedRect($canvas, 128, 398, 256, 22, 11, $cShade, $scale);

imagealphablending($canvas, true);

$public = dirname(__DIR__).'/public';

if (! is_dir($public)) {
    fwrite(STDERR, "  no public directory at {$public}\n");
    exit(1);
}

foreach ([16 => 'favicon-16.png', 32 => 'favicon-32.png', 180 => 'apple-touch-icon.png', 512 => 'icon-512.png'] as $px => $name) {
    $img = render($canvas, $px, $scale);
    $coverage = inkCoverage($img);

    if ($coverage < 0.05) {
        fwrite(STDERR, "  {$name} rendered empty ({$coverage} ink). Refusing to write it.\n");
        exit(1);
    }

    imagepng($img, "{$public}/{$name}");
    imagedestroy($img);

    printf("  wrote public/%-22s %4dpx  ink %5.1f%%\n", $name, $px, $coverage * 100);
}

/**
 * A multi resolution ICO, hand rolled.
 *
 * GD has no ICO writer and the alternative is shipping a tool for one file format. The layout is a six
 * byte header, one sixteen byte directory entry per frame, then the images, which modern Windows reads
 * happily out of PNG frames.
 *
 * A dimension of 256 does not fit in a byte and is stored as zero, which is the documented way to say
 * 256. Getting that wrong renders as a blank square in Windows.
 */
$frames = [16, 24, 32, 48, 64, 128, 256];

$pngs = [];
foreach ($frames as $px) {
    $img = render($canvas, $px, $scale);

    if (inkCoverage($img) < 0.05) {
        fwrite(STDERR, "  ICO frame at {$px}px rendered empty. Refusing to write it.\n");
        exit(1);
    }

    ob_start();
    imagepng($img);
    $pngs[$px] = (string) ob_get_clean();
    imagedestroy($img);
}

$offset = 6 + (16 * count($frames));
$directory = '';

foreach ($frames as $px) {
    $stored = $px >= 256 ? 0 : $px;
    $directory .= pack('CCCCvvvv', $stored, $stored, 0, 0, 1, 32, strlen($pngs[$px]), $offset);
    $offset += strlen($pngs[$px]);
}

file_put_contents("{$public}/favicon.ico", pack('vvv', 0, 1, count($frames)).$directory.implode('', $pngs));
printf("  wrote public/%-22s %s\n", 'favicon.ico', implode('/', $frames).'px');

$svg = <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<!--
    Generated by packaging/make-icon.php. Do not hand edit. The raster and this file come from the same
    coordinates, and editing one without the other is how the two start to disagree.

    Colours are the vermilion ramp from resources/css/app.css, converted from OKLCH to sRGB. The design
    spends exactly one saturated accent and this mark spends it on one value.
-->
<svg width="512" height="512" viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Papyrus">
    <rect x="140" y="88" width="232" height="300" rx="18" fill="{$ink}"/>
    <rect x="172" y="136" width="168" height="14" fill="{$fibre}"/>
    <rect x="172" y="180" width="168" height="14" fill="{$fibre}"/>
    <rect x="172" y="224" width="168" height="14" fill="{$fibre}"/>
    <rect x="104" y="336" width="304" height="92" rx="44" fill="{$deep}"/>
    <rect x="132" y="356" width="248" height="34" rx="17" fill="{$fibre}"/>
    <rect x="128" y="398" width="256" height="22" rx="11" fill="{$shade}"/>
</svg>

SVG;

file_put_contents("{$public}/favicon.svg", $svg);
echo "  wrote public/favicon.svg\n";

imagedestroy($canvas);
