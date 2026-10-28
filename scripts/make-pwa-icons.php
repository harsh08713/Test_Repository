<?php

// Draws the app icons: three white bars (the sidebar's chart mark) on the brand steel blue.
// Usage: php scripts/make-pwa-icons.php public/icons   (writes the five PNGs named in docs/pwa.md)
// Each icon is drawn at 4x and scaled down, so the edges are smooth.

$out = rtrim($argv[1] ?? '.', '/\\');
@mkdir($out, 0777, true);

function icon(int $size, float $inset, bool $rounded): GdImage
{
    $s = $size * 4;
    $img = imagecreatetruecolor($s, $s);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    $clear = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $clear);
    imagealphablending($img, true);

    $blue = imagecolorallocate($img, 0x1F, 0x5A, 0x8F);   // brand-600
    $white = imagecolorallocate($img, 255, 255, 255);
    $amber = imagecolorallocate($img, 0xD9, 0x8A, 0x00);  // safety-500

    if ($rounded) {
        $r = (int) ($s * 0.22);
        imagefilledrectangle($img, $r, 0, $s - $r - 1, $s - 1, $blue);
        imagefilledrectangle($img, 0, $r, $s - 1, $s - $r - 1, $blue);
        foreach ([[$r, $r], [$s - $r - 1, $r], [$r, $s - $r - 1], [$s - $r - 1, $s - $r - 1]] as [$x, $y]) {
            imagefilledellipse($img, $x, $y, $r * 2, $r * 2, $blue);
        }
    } else {
        imagefilledrectangle($img, 0, 0, $s - 1, $s - 1, $blue);   // full bleed: the platform masks it
    }

    // the mark sits inside the safe zone; $inset is the margin as a share of the side
    $m = (int) ($s * $inset);
    $box = $s - 2 * $m;
    $barW = (int) ($box * 0.22);
    $gap = (int) (($box - 3 * $barW) / 2);
    $heights = [0.46, 0.74, 1.0];
    $base = $m + $box;

    foreach ($heights as $i => $h) {
        $x = $m + $i * ($barW + $gap);
        $top = (int) ($base - $box * $h);
        $color = $i === 2 ? $amber : $white;
        $rad = (int) ($barW * 0.18);
        imagefilledrectangle($img, $x + $rad, $top, $x + $barW - $rad, $base, $color);
        imagefilledrectangle($img, $x, $top + $rad, $x + $barW, $base, $color);
        imagefilledellipse($img, $x + $rad, $top + $rad, $rad * 2, $rad * 2, $color);
        imagefilledellipse($img, $x + $barW - $rad, $top + $rad, $rad * 2, $rad * 2, $color);
    }

    $small = imagecreatetruecolor($size, $size);
    imagealphablending($small, false);
    imagesavealpha($small, true);
    imagecopyresampled($small, $img, 0, 0, 0, 0, $size, $size, $s, $s);

    return $small;
}

$files = [
    'icon-192.png' => [192, 0.24, true],
    'icon-512.png' => [512, 0.24, true],
    'icon-maskable-512.png' => [512, 0.30, false],   // the platform crops to a circle/squircle: keep the mark well inside
    'apple-touch-icon.png' => [180, 0.24, false],     // iOS rounds the corners itself and fills transparency with black
    'favicon-32.png' => [32, 0.18, true],
];

foreach ($files as $name => [$size, $inset, $rounded]) {
    imagepng(icon($size, $inset, $rounded), "$out/$name", 9);
    echo "$name ".filesize("$out/$name")." bytes\n";
}
