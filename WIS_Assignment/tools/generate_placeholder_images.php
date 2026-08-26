<?php
/**
 * generate_placeholder_images.php  (CLI only)
 *
 * Writes one labelled placeholder JPEG per product to
 *   uploads/products/product-{id}.jpg
 * so every product has an image file that can be committed to the repo. The
 * files are named by product id and are wired up by the set-based INSERT in
 * schema.sql / add_product_photos.sql.
 *
 *   php tools/generate_placeholder_images.php           # skip files that already exist
 *   php tools/generate_placeholder_images.php --force    # regenerate every file
 *
 * To use a real photo for a product, just drop it in over
 * uploads/products/product-{id}.jpg (keep the name) and commit - no SQL change.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('CLI only');
}

require __DIR__ . '/../includes/db.php'; // provides $pdo

$force        = in_array('--force', $argv, true);
$outDir       = __DIR__ . '/../uploads/products';
$font         = 'C:/Windows/Fonts/arialbd.ttf';
$hasFont      = is_file($font);
$realProduct2 = $outDir . '/product_1787474167_2_872.jpg';

// 4:5 portrait to match the storefront card grid.
const IMG_W = 800;
const IMG_H = 1000;

// category_id => [r, g, b] (coffee-shop earth tones)
$palette = [
    1 => [111, 78, 55],   // Coffee
    3 => [156, 91, 63],   // Pastries & Desserts
    4 => [62, 44, 35],    // Coffee Beans & Tumblers
    5 => [79, 117, 97],   // Non-Coffee Drinks
    6 => [192, 138, 46],  // Breakfast & Savoury
];
$fallbackColor = [90, 90, 90];

/** Word-wrap $text so each line fits within $maxWidth px at $size pt. */
function wrap_lines(string $text, string $font, int $size, int $maxWidth): array
{
    $words = preg_split('/\s+/', trim($text)) ?: [];
    $lines = [];
    $line = '';
    foreach ($words as $w) {
        $try = $line === '' ? $w : "$line $w";
        $bbox = imagettfbbox($size, 0, $font, $try);
        if (abs($bbox[2] - $bbox[0]) > $maxWidth && $line !== '') {
            $lines[] = $line;
            $line = $w;
        } else {
            $line = $try;
        }
    }
    if ($line !== '') {
        $lines[] = $line;
    }
    return $lines;
}

/** Draw one TTF line horizontally centred; returns the text width in px. */
function draw_centered($im, int $size, int $y, string $font, $color, string $text): void
{
    $bbox = imagettfbbox($size, 0, $font, $text);
    $w = abs($bbox[2] - $bbox[0]);
    $x = (int) ((IMG_W - $w) / 2) - $bbox[0];
    imagettftext($im, $size, 0, $x, $y, $color, $font, $text);
}

$rows = $pdo->query(
    "SELECT p.id, p.name, p.category_id, c.name AS cat
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     ORDER BY p.id"
)->fetchAll();

if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

$created = 0;
$skipped = 0;
$copied  = 0;

foreach ($rows as $r) {
    $id   = (int) $r['id'];
    $dest = "$outDir/product-$id.jpg";

    if (!$force && is_file($dest)) {
        $skipped++;
        continue;
    }

    // Preserve the one real photo already committed in the repo.
    if ($id === 2 && is_file($realProduct2)) {
        copy($realProduct2, $dest);
        $copied++;
        continue;
    }

    [$br, $bg, $bb] = $palette[(int) $r['category_id']] ?? $fallbackColor;

    $im      = imagecreatetruecolor(IMG_W, IMG_H);
    $bgCol   = imagecolorallocate($im, $br, $bg, $bb);
    $band    = imagecolorallocate($im, (int) ($br * 0.72), (int) ($bg * 0.72), (int) ($bb * 0.72));
    $cream   = imagecolorallocate($im, 245, 239, 230);
    $creamLo = imagecolorallocate($im, 205, 190, 170);

    imagefilledrectangle($im, 0, 0, IMG_W, IMG_H, $bgCol);
    imagefilledrectangle($im, 0, (int) (IMG_H * 0.31), IMG_W, (int) (IMG_H * 0.69), $band);
    imagesetthickness($im, 2);
    imagerectangle($im, 18, 18, IMG_W - 19, IMG_H - 19, $creamLo);

    if ($hasFont) {
        $nameSize   = 34;
        $lineHeight = 48;
        $lines      = wrap_lines($r['name'], $font, $nameSize, 640);
        $blockH     = count($lines) * $lineHeight;
        $firstBase  = (int) (IMG_H / 2 - $blockH / 2 + $nameSize);

        draw_centered($im, 15, $firstBase - $nameSize - 26, $font, $creamLo,
            strtoupper($r['cat'] ?? 'Product'));

        foreach ($lines as $i => $line) {
            draw_centered($im, $nameSize, $firstBase + $i * $lineHeight, $font, $cream, $line);
        }

        draw_centered($im, 13, IMG_H - 40, $font, $creamLo, 'TAR Coffee');
    } else {
        $txt = $r['name'];
        $x = (int) ((IMG_W - imagefontwidth(5) * strlen($txt)) / 2);
        imagestring($im, 5, $x, (int) (IMG_H / 2) - 8, $txt, $cream);
        imagestring($im, 3, (int) ((IMG_W - imagefontwidth(3) * 10) / 2), IMG_H - 40, 'TAR Coffee', $creamLo);
    }

    imagejpeg($im, $dest, 82);
    imagedestroy($im);
    $created++;
}

echo "placeholders: created $created, copied $copied, skipped $skipped (of " . count($rows) . " products)\n";
if (!$hasFont) {
    echo "note: $font not found - used GD bitmap font fallback\n";
}
