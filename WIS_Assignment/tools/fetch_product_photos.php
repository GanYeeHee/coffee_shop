<?php
/**
 * fetch_product_photos.php  (CLI only)
 *
 * Downloads a real stock photo for each product from the Pexels API, centre-crops
 * it to 800x600, and overwrites uploads/products/product-{id}.jpg. Also writes
 * uploads/products/PHOTO_CREDITS.md listing every source photo + photographer.
 *
 *   php tools/fetch_product_photos.php YOUR_PEXELS_KEY
 *   php tools/fetch_product_photos.php YOUR_PEXELS_KEY --only=13,14,20
 *   PEXELS_API_KEY=... php tools/fetch_product_photos.php
 *
 * Pexels licence allows this use (no attribution required, no watermark); the
 * credits file is generated anyway as good practice. Product 2 keeps its existing
 * real photo unless --include-2 is passed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('CLI only');
}

require __DIR__ . '/../includes/db.php'; // $pdo

$key = (isset($argv[1]) && !str_starts_with($argv[1], '--')) ? $argv[1] : (getenv('PEXELS_API_KEY') ?: '');
if ($key === '') {
    fwrite(STDERR, "Usage: php tools/fetch_product_photos.php <PEXELS_API_KEY> [--only=1,2,3] [--offset=N] [--include-2]\n");
    fwrite(STDERR, "   or: PEXELS_API_KEY=... php tools/fetch_product_photos.php [--only=...]\n");
    exit(1);
}
$only      = null;
$offset    = 0;
$includeP2 = in_array('--include-2', $argv, true);
foreach ($argv as $a) {
    if (str_starts_with($a, '--only=')) {
        $only = array_filter(array_map('intval', explode(',', substr($a, 7))));
    }
    if (str_starts_with($a, '--offset=')) {
        $offset = (int) substr($a, 9);
    }
}

$outDir = __DIR__ . '/../uploads/products';
// 4:5 portrait to match the storefront card grid (.product-img-wrapper padding-top:125%).
const OUT_W = 800;
const OUT_H = 1000;

// Per-product search queries (kept tight so results are on-topic).
$queries = require __DIR__ . '/product_photo_queries.php';

/** Centre-crop + resize a GD image resource to OUT_W x OUT_H. */
function crop_to_box($src): \GdImage
{
    $sw = imagesx($src);
    $sh = imagesy($src);
    $targetRatio = OUT_W / OUT_H;
    $srcRatio = $sw / $sh;
    if ($srcRatio > $targetRatio) {          // source wider -> crop sides
        $cw = (int) round($sh * $targetRatio);
        $ch = $sh;
        $cx = (int) (($sw - $cw) / 2);
        $cy = 0;
    } else {                                  // source taller -> crop top/bottom
        $cw = $sw;
        $ch = (int) round($sw / $targetRatio);
        $cx = 0;
        $cy = (int) (($sh - $ch) / 2);
    }
    $dst = imagecreatetruecolor(OUT_W, OUT_H);
    imagecopyresampled($dst, $src, 0, 0, $cx, $cy, OUT_W, OUT_H, $cw, $ch);
    return $dst;
}

function pexels_get(string $url, string $key): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: $key"],
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) {
        fwrite(STDERR, "  API HTTP $code\n");
        return null;
    }
    return json_decode($body, true);
}

function download(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $data) ? $data : null;
}

$rows = $pdo->query("SELECT id, name FROM products ORDER BY id")->fetchAll();

$done = 0; $failed = []; $credits = [];
foreach ($rows as $r) {
    $id = (int) $r['id'];
    if ($only && !in_array($id, $only, true)) {
        continue;
    }
    if ($id === 2 && !$includeP2) {
        echo "#$id {$r['name']}: skipped (keeping existing real photo)\n";
        continue;
    }

    $q = $queries[$id] ?? preg_replace('/\s*\(.*?\)\s*/', ' ', strtolower($r['name']));
    echo "#$id {$r['name']}  <-  \"$q\"\n";

    $data = pexels_get('https://api.pexels.com/v1/search?orientation=portrait&per_page=8&query=' . rawurlencode($q), $key);
    $photos = $data['photos'] ?? [];
    if (!$photos) {
        // retry with a looser query (first word + "food"/"coffee")
        $loose = explode(' ', $q)[0] . ' coffee';
        $data = pexels_get('https://api.pexels.com/v1/search?orientation=portrait&per_page=8&query=' . rawurlencode($loose), $key);
        $photos = $data['photos'] ?? [];
    }
    if (!$photos) {
        $failed[] = $id;
        echo "  no results - placeholder kept\n";
        continue;
    }

    $pick = $photos[($id + $offset) % count($photos)];
    // 'portrait' is Pexels' 800x1200 crop - matches our 4:5 output with room to spare.
    $imgUrl = $pick['src']['portrait'] ?? $pick['src']['large2x'] ?? $pick['src']['original'];
    $bytes = download($imgUrl);
    if (!$bytes) {
        $failed[] = $id;
        echo "  download failed - placeholder kept\n";
        continue;
    }

    $src = @imagecreatefromstring($bytes);
    if (!$src) {
        $failed[] = $id;
        echo "  bad image data - placeholder kept\n";
        continue;
    }
    $box = crop_to_box($src);
    imagejpeg($box, "$outDir/product-$id.jpg", 85);
    imagedestroy($src);
    imagedestroy($box);

    $credits[$id] = sprintf('| %d | %s | [%s](%s) | %s |',
        $id, $r['name'], $pick['photographer'], $pick['photographer_url'], $pick['url']);
    $done++;
    usleep(300000); // be polite to the API
}

// Merge into the existing credits file so a partial (--only) run doesn't wipe it.
$creditsFile = "$outDir/PHOTO_CREDITS.md";
if (is_file($creditsFile)) {
    foreach (explode("\n", file_get_contents($creditsFile)) as $ln) {
        if (preg_match('/^\|\s*(\d+)\s*\|/', $ln, $m) && !isset($credits[(int) $m[1]])) {
            $credits[(int) $m[1]] = rtrim($ln);
        }
    }
}
ksort($credits);
$md = "# Product photo credits\n\n"
    . "Photos sourced from [Pexels](https://www.pexels.com) via the Pexels API. The Pexels\n"
    . "licence permits this use without attribution; credited here anyway.\n\n"
    . "| Product ID | Name | Photographer | Source |\n|---|---|---|---|\n"
    . implode("\n", $credits) . "\n";
file_put_contents($creditsFile, $md);

echo "\ndownloaded $done photo(s).";
echo $failed ? " No result for product ids: " . implode(', ', $failed) . " (placeholders kept).\n" : "\n";
echo "credits written to uploads/products/PHOTO_CREDITS.md\n";
