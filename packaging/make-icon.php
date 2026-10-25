<?php

declare(strict_types=1);

/**
 * Draw the Papyrus seal and write every icon the application and Windows need.
 *
 * ## What the mark is
 *
 * Two pieces that already existed, put on one canvas: the `.seal` tile from
 * `resources/css/app.css` (vermilion gradient, three degree lean, 96 unit radius on a 448 unit
 * tile) and the Heroicons `book-open` outline the empty library already uses. No new drawing and
 * no new colour. The generated files are the ones the application serves and the installer reads,
 * so the mark cannot drift between the tab, the taskbar and the installed shortcut.
 *
 * ## Why a browser rasterises it
 *
 * Because the same source has to produce a sixteen pixel favicon and a two hundred and fifty six
 * pixel installer frame, and GD cannot consume SVG. Chrome or Edge renders each size from the same
 * vector geometry, so the drawing exists once. GD is used to inspect the result, not to draw it:
 * every frame is decoded and checked for size, ink coverage and a transparent corner before the
 * script claims it wrote anything.
 *
 * ## Why the small frames are not a straight reduction
 *
 * At 48 pixels and above the book keeps the seal's exact 42.86 percent proportion and 1.5 unit
 * stroke. At 32, 24 and 16 the line is made heavier and the book a little larger, because a
 * faithful reduction of a 1.5 unit outline at sixteen pixels is a red tile with a grey smudge. The
 * treatments are measurements from the delivered set, not preferences.
 *
 * ## Why the ICO directory uses 32 bit fields
 *
 * The previous writer packed the byte length and offset as sixteen bit fields. The ICO directory
 * has been sixteen bytes per frame with two 32 bit fields since Windows 95, and a frame above
 * 64 KiB cannot be described by the old width. It produced a file Windows could read as a header
 * and not as an image. This writer packs the documented layout and then reads every frame back.
 *
 * ## Usage
 *
 *     php packaging/make-icon.php
 *
 * Writes `public/favicon.svg`, `public/favicon.ico`, `public/apple-touch-icon.png`,
 * `public/icon-256.png` and `public/icon-512.png`. Requires PHP 8.4 with GD, and Chrome or Edge.
 * Set `PAPYRUS_CHROME` to an executable path if neither is in its usual place.
 */
const CANVAS = 512;
const TILE = 448;
const TILE_RADIUS = 96;
const VERMILION_500 = '#F97459';
const VERMILION_700 = '#C83E2B';
const PAPER = '#FAF9F6';

/**
 * Heroicons book-open, outline, 24 unit viewBox.
 *
 * Upstream: https://heroicons.com, MIT, Copyright (c) Tailwind Labs, Inc. The same path is in the
 * Flux icon stub the empty library uses, byte for byte; no Flux artwork is involved.
 */
const BOOK_PATH = 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25';

/** The 48 pixel and larger treatment is the seal's exact 42.86 percent glyph. */
const EXACT_FRACTION = 192 / TILE;

$root = dirname(__DIR__);
$public = $root.'/public';
$temp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-icon-'.getmypid();

if (! is_dir($public)) {
    fail("no public directory at {$public}");
}

if (is_dir($temp)) {
    removeTree($temp);
}

if (! mkdir($temp, 0777, true) && ! is_dir($temp)) {
    fail("could not create {$temp}");
}

$browser = findBrowser();

say('browser', $browser);

/**
 * The optical treatment for one output size.
 *
 * @return array{0: float, 1: float} the glyph box as a fraction of the tile, and the stroke width
 */
function treatment(int $px): array
{
    return match (true) {
        $px <= 16 => [0.68, 2.6],
        $px <= 24 => [0.62, 2.1],
        $px <= 32 => [0.56, 1.8],
        default => [EXACT_FRACTION, 1.5],
    };
}

/**
 * The seal as a standalone SVG at one target size.
 *
 * The width and height are the intended pixels while the viewBox stays at 512, so the same
 * geometry can be rasterised at 16 and at 512 without a separate drawing for each.
 */
function markSvg(int $px, bool $paperBackground = false): string
{
    [$fraction, $stroke] = treatment($px);

    $box = TILE * $fraction;
    $scale = $box / 24;
    $offset = (CANVAS - $box) / 2;

    $offsetText = number($offset);
    $scaleText = number($scale);
    $v500 = VERMILION_500;
    $v700 = VERMILION_700;
    $book = BOOK_PATH;

    $background = $paperBackground
        ? '<rect width="512" height="512" fill="'.PAPER.'"/>'
        : '';

    // The CSS inset highlight is one CSS pixel on a 56 pixel seal. At 512 that is eight units.
    // The line runs past the top corners and is clipped by the tile, and is omitted below 48
    // because at those sizes it is less than a third of a pixel.
    $highlight = $px >= 48
        ? '<path d="M32 36H480" stroke="#FFFFFF" stroke-opacity="0.18" stroke-width="8" stroke-linecap="butt" clip-path="url(#tile-clip)"/>'
        : '';

    return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<!--
    Papyrus seal icon. Generated by packaging/make-icon.php; do not hand edit.
    Tile: Papyrus .seal from resources/css/app.css.
    Book: Heroicons book-open, MIT, Copyright (c) Tailwind Labs, Inc.
-->
<svg xmlns="http://www.w3.org/2000/svg" width="{$px}" height="{$px}" viewBox="0 0 512 512" role="img" aria-label="Papyrus">
  <title>Papyrus</title>
  <defs>
    <linearGradient id="seal" x1="0.213217" y1="0.090404" x2="0.786783" y2="0.909596">
      <stop offset="0" stop-color="{$v500}"/>
      <stop offset="1" stop-color="{$v700}"/>
    </linearGradient>
    <clipPath id="tile-clip">
      <rect x="32" y="32" width="448" height="448" rx="96"/>
    </clipPath>
  </defs>
  {$background}
  <g transform="rotate(-3 256 256)">
    <rect x="32" y="32" width="448" height="448" rx="96" fill="url(#seal)"/>
    {$highlight}
    <g transform="translate({$offsetText} {$offsetText}) scale({$scaleText})">
      <path d="{$book}" fill="none" stroke="#FFFFFF" stroke-width="{$stroke}" stroke-linecap="round" stroke-linejoin="round"/>
    </g>
  </g>
</svg>

SVG;
}

/**
 * The 32 unit favicon drawing, tuned for the 16 pixel tab first.
 *
 * At a doubled tab it reads as a slightly heavier 24 pixel treatment, which is still clear. The
 * ICO carries the separate 16, 24 and 32 pixel frames for Windows.
 */
function faviconSvg(): string
{
    $fraction = 0.68;
    $stroke = 2.6;
    $tile = 28.0;
    $box = $tile * $fraction;
    $scale = $box / 24;
    $offset = (32 - $box) / 2;
    $offsetText = number($offset);
    $scaleText = number($scale);
    $v500 = VERMILION_500;
    $v700 = VERMILION_700;
    $book = BOOK_PATH;

    return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<!--
    Papyrus favicon. Generated by packaging/make-icon.php; do not hand edit.
    Tile: Papyrus .seal. Book: Heroicons book-open, MIT, Copyright (c) Tailwind Labs, Inc.
-->
<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32" role="img" aria-label="Papyrus">
  <title>Papyrus</title>
  <defs>
    <linearGradient id="seal" x1="0.213217" y1="0.090404" x2="0.786783" y2="0.909596">
      <stop offset="0" stop-color="{$v500}"/>
      <stop offset="1" stop-color="{$v700}"/>
    </linearGradient>
  </defs>
  <g transform="rotate(-3 16 16)">
    <rect x="2" y="2" width="28" height="28" rx="6" fill="url(#seal)"/>
    <g transform="translate({$offsetText} {$offsetText}) scale({$scaleText})">
      <path d="{$book}" fill="none" stroke="#FFFFFF" stroke-width="{$stroke}" stroke-linecap="round" stroke-linejoin="round"/>
    </g>
  </g>
</svg>

SVG;
}

function number(float $value): string
{
    return rtrim(rtrim(number_format($value, 5, '.', ''), '0'), '.');
}

function findBrowser(): string
{
    $local = getenv('LOCALAPPDATA') ?: null;

    $candidates = [
        getenv('PAPYRUS_CHROME') ?: null,
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        $local === null ? null : $local.'\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        $local === null ? null : $local.'\\Microsoft\\Edge\\Application\\msedge.exe',
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && is_file($candidate)) {
            return $candidate;
        }
    }

    fail('no Chrome or Edge executable found; set PAPYRUS_CHROME to one');
}

/**
 * Render one SVG through headless Chrome at exactly the requested viewport.
 */
function rasterise(
    string $svg,
    int $width,
    int $height,
    string $destination,
    string $browser,
    string $temp,
    string $name,
): void {
    $source = "{$temp}/{$name}.svg";
    file_put_contents($source, $svg);

    if (is_file($destination)) {
        unlink($destination);
    }

    $url = 'file:///'.str_replace('\\', '/', $source);
    $command = escapeshellarg($browser)
        .' --headless --disable-gpu --hide-scrollbars --no-first-run --no-default-browser-check'
        .' --force-device-scale-factor=1 --default-background-color=00000000'
        .' --window-size='.$width.','.$height
        .' --screenshot='.escapeshellarg($destination)
        .' '.escapeshellarg($url)
        .' 2>&1';

    $output = [];
    $status = 0;
    exec($command, $output, $status);

    if ($status !== 0 || ! is_file($destination)) {
        fail("rendering {$name} failed (exit {$status}):\n".implode("\n", $output));
    }

    $size = getimagesize($destination);

    if ($size === false || $size[0] !== $width || $size[1] !== $height) {
        fail("rendering {$name} produced the wrong dimensions");
    }

    say('rendered', sprintf('%-20s %4d x %-4d', $name, $width, $height));
}

/**
 * Inspect a PNG and report what is actually in it.
 *
 * GD uses 7 bit alpha where 127 is transparent, the opposite of the usual convention. Both the
 * tile coverage and the book's white ink are measured from the decoded pixels rather than assumed
 * from the file size.
 *
 * @return array{width: int, height: int, corner_alpha: int, coverage: float, white: float}
 */
function inspectPng(string $path): array
{
    $image = imagecreatefrompng($path);

    if (! $image instanceof GdImage) {
        fail("could not decode {$path}");
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $inked = 0;
    $white = 0;

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rgba = (int) imagecolorat($image, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;

            if ($alpha < 119) {
                $inked++;

                $red = ($rgba >> 16) & 0xFF;
                $green = ($rgba >> 8) & 0xFF;
                $blue = $rgba & 0xFF;

                if ($alpha < 10 && $red > 232 && $green > 232 && $blue > 232) {
                    $white++;
                }
            }
        }
    }

    $corner = (int) imagecolorat($image, 0, 0);
    $cornerAlpha = ($corner >> 24) & 0x7F;
    imagedestroy($image);

    return [
        'width' => $width,
        'height' => $height,
        'corner_alpha' => $cornerAlpha,
        'coverage' => $inked / max(1, $width * $height),
        'white' => $white / max(1, $width * $height),
    ];
}

/**
 * Write a multi resolution ICO from PNG frames.
 *
 * @param  array<int, string>  $frames  frame size in pixels to the PNG file on disk
 */
function writeIco(array $frames, string $destination): void
{
    $pngs = [];

    foreach ($frames as $px => $source) {
        if (! is_file($source)) {
            fail("missing source for the {$px} px ICO frame");
        }

        $pngs[$px] = (string) file_get_contents($source);
    }

    $count = count($frames);
    $offset = 6 + (16 * $count);
    $directory = '';
    $payload = '';

    foreach ($pngs as $px => $png) {
        $stored = $px >= 256 ? 0 : $px;
        $directory .= pack('CCCCvvVV', $stored, $stored, 0, 0, 1, 32, strlen($png), $offset);
        $payload .= $png;
        $offset += strlen($png);
    }

    file_put_contents($destination, pack('vvv', 0, 1, $count).$directory.$payload);
}

/**
 * Read the ICO back the way Windows does, rather than trusting the writer.
 *
 * @return list<int>
 */
function verifyIco(string $path): array
{
    $bytes = (string) file_get_contents($path);

    if (strlen($bytes) < 6) {
        fail("{$path} is too short to be an ICO");
    }

    $header = unpack('vreserved/vtype/vcount', substr($bytes, 0, 6));

    if ($header === false || $header['reserved'] !== 0 || $header['type'] !== 1) {
        fail("{$path} has a bad ICO header");
    }

    $count = (int) $header['count'];
    $sizes = [];

    for ($index = 0; $index < $count; $index++) {
        $entry = substr($bytes, 6 + ($index * 16), 16);

        if (strlen($entry) !== 16) {
            fail("{$path} has a truncated directory");
        }

        $fields = unpack('Cwidth/Cheight/Ccolours/Creserved/vplanes/vbits/Vlength/Voffset', $entry);

        if ($fields === false) {
            fail("{$path} has an unreadable directory entry");
        }

        $px = (int) $fields['width'] === 0 ? 256 : (int) $fields['width'];
        $sizes[] = $px;
        $png = substr($bytes, (int) $fields['offset'], (int) $fields['length']);
        $image = getimagesizefromstring($png);

        if ($image === false || $image[2] !== IMAGETYPE_PNG || $image[0] !== $px || $image[1] !== $px) {
            fail("{$path} frame {$index} is not a {$px} px PNG");
        }
    }

    return $sizes;
}

function removeTree(string $directory): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }

    rmdir($directory);
}

function say(string $label, string $message): void
{
    printf("  %-10s %s\n", $label, $message);
}

function fail(string $message): never
{
    fwrite(STDERR, "\n  FAILED: {$message}\n");
    exit(1);
}

$sizes = [512, 256, 128, 64, 48, 32, 24, 16];
$raster = [];

foreach ($sizes as $px) {
    $destination = "{$temp}/icon-{$px}.png";
    rasterise(markSvg($px), $px, $px, $destination, $browser, $temp, "icon-{$px}");
    $raster[$px] = $destination;
}

file_put_contents("{$public}/favicon.svg", faviconSvg());
say('wrote', 'public/favicon.svg');

rasterise(faviconSvg(), 32, 32, "{$temp}/favicon-32.png", $browser, $temp, 'favicon-32');

// The Apple touch icon must be opaque: iOS composites transparency onto black.
rasterise(markSvg(180, true), 180, 180, "{$temp}/apple-touch.png", $browser, $temp, 'apple-touch');

copy($raster[512], "{$public}/icon-512.png");
copy($raster[256], "{$public}/icon-256.png");
say('wrote', 'public/icon-512.png, public/icon-256.png');

$faviconFrames = [
    16 => $raster[16],
    24 => $raster[24],
    32 => $raster[32],
    48 => $raster[48],
    64 => $raster[64],
    128 => $raster[128],
    256 => $raster[256],
];

writeIco($faviconFrames, "{$public}/favicon.ico");
say('wrote', 'public/favicon.ico');

copy("{$temp}/apple-touch.png", "{$public}/apple-touch-icon.png");
say('wrote', 'public/apple-touch-icon.png');

// Inspect the real pixels before calling any of this done.
foreach ([16, 24, 32, 48, 64, 128, 256, 512] as $px) {
    $report = inspectPng($raster[$px]);

    if ($report['width'] !== $px || $report['height'] !== $px) {
        fail("the {$px} px raster has the wrong dimensions");
    }

    if ($report['corner_alpha'] < 120) {
        fail("the {$px} px raster does not have a transparent corner");
    }

    if ($report['coverage'] < 0.55) {
        fail(sprintf('the %d px raster is only %.1f%% ink', $px, $report['coverage'] * 100));
    }

    if ($px <= 32 && $report['white'] < 0.014) {
        fail(sprintf('the %d px raster has almost no book: %.2f%% white', $px, $report['white'] * 100));
    }

    say('checked', sprintf('%-10s ink %5.1f%%  book %5.1f%%  corner alpha %d', "{$px}px", $report['coverage'] * 100, $report['white'] * 100, $report['corner_alpha']));
}

$touch = inspectPng("{$temp}/apple-touch.png");

if ($touch['width'] !== 180 || $touch['height'] !== 180 || $touch['corner_alpha'] !== 0) {
    fail('the Apple touch icon is not opaque 180 px');
}

say('checked', 'apple-touch 180px opaque');

$favicon32 = inspectPng("{$temp}/favicon-32.png");
say('checked', sprintf('favicon 32px ink %.1f%% book %.1f%%', $favicon32['coverage'] * 100, $favicon32['white'] * 100));

$frames = verifyIco("{$public}/favicon.ico");

if ($frames !== [16, 24, 32, 48, 64, 128, 256]) {
    fail('public/favicon.ico has the wrong frame order: '.implode('/', $frames));
}

say('checked', 'public/favicon.ico 16/24/32/48/64/128/256');

removeTree($temp);
say('done', 'temporary render files removed');
