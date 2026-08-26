<?php
/**
 * fetch_extra_product_photos.php  (CLI only)
 *
 * Adds EXTRA gallery photos to products so the "one product -> many photos"
 * feature has data to show. For each product it downloads N more stock photos
 * from the Pexels API (distinct from the primary one), centre-crops them to
 * 800x1000, saves them as uploads/products/product-{id}-2.jpg, -3.jpg, ... and
 * inserts a non-primary product_images row for each.
 *
 *   php tools/fetch_extra_product_photos.php YOUR_PEXELS_KEY
 *   php tools/fetch_extra_product_photos.php YOUR_PEXELS_KEY --only=1,9,13 --count=2
 *   PEXELS_API_KEY=... php tools/fetch_extra_product_photos.php --count=3
 *
 * Idempotent: re-running replaces the generated extra files + their rows, it
 * never touches the primary photo (product-{id}.jpg) or admin-uploaded photos.
 *
 * Side effects:
 *   - uploads/products/product-{id}-{n}.jpg            (image files, commit these)
 *   - uploads/products/GALLERY_PHOTO_CREDITS.md        (Pexels attribution)
 *   - add_extra_product_photos.sql                     (re-seed script for teammates)
 *
 * Pexels licence permits this use without attribution; credited anyway.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('CLI only');
}

require __DIR__ . '/../includes/db.php'; // $pdo
$queries = require __DIR__ . '/product_photo_queries.php';

$key = (isset($argv[1]) && !str_starts_with($argv[1], '--')) ? $argv[1] : (getenv('PEXELS_API_KEY') ?: '');
if ($key === '') {
    fwrite(STDERR, "Usage: php tools/fetch_extra_product_photos.php <PEXELS_API_KEY> [--only=1,2,3] [--count=2]\n");
    fwrite(STDERR, "   or: PEXELS_API_KEY=... php tools/fetch_extra_product_photos.php [--only=...] [--count=2]\n");
    exit(1);
}

$only  = null;
$count = 2;
foreach ($argv as $a) {
    if (str_starts_with($a, '--only=')) {
        $only = array_filter(array_map('intval', explode(',', substr($a, 7))));
    }
    if (str_starts_with($a, '--count=')) {
        $count = max(1, min(6, (int) substr($a, 8)));
    }
}

$outDir = __DIR__ . '/../uploads/products';
// 4:5 portrait to match the storefront card grid + detail gallery.
const OUT_W = 800;
const OUT_H = 1000;

/** Centre-crop + resize a GD image to OUT_W x OUT_H. */
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

$perPage = max(15, $count + 8);
$done = 0;
$partial = [];
$failed = [];
$credits = [];   // "id|n" => markdown row

foreach ($rows as $r) {
    $id = (int) $r['id'];
    if ($only && !in_array($id, $only, true)) {
        continue;
    }

    $q = $queries[$id] ?? trim(preg_replace('/\s*\(.*?\)\s*/', ' ', strtolower($r['name'])));
    echo "#$id {$r['name']}  <-  \"$q\"\n";

    $data = pexels_get('https://api.pexels.com/v1/search?orientation=portrait&per_page=' . $perPage . '&query=' . rawurlencode($q), $key);
    $photos = $data['photos'] ?? [];
    if (count($photos) < 2) {
        $loose = explode(' ', $q)[0] . ' coffee';
        $data = pexels_get('https://api.pexels.com/v1/search?orientation=portrait&per_page=' . $perPage . '&query=' . rawurlencode($loose), $key);
        $photos = array_merge($photos, $data['photos'] ?? []);
    }
    if (!$photos) {
        $failed[] = $id;
        echo "  no results - skipped\n";
        continue;
    }

    // The primary fetch (fetch_product_photos.php) picks index ($id % count).
    // Start after that so the gallery shots differ from the hero shot.
    $n = count($photos);
    $primaryIdx = $id % $n;
    $saved = 0;
    for ($slot = 0; $slot < $count; $slot++) {
        $pick = $photos[($primaryIdx + 1 + $slot) % $n];
        $imgUrl = $pick['src']['portrait'] ?? $pick['src']['large2x'] ?? $pick['src']['original'];
        $bytes = download($imgUrl);
        if (!$bytes) {
            echo "  slot " . ($slot + 2) . ": download failed\n";
            continue;
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            echo "  slot " . ($slot + 2) . ": bad image data\n";
            continue;
        }
        $box = crop_to_box($src);
        $fileNo = $slot + 2; // primary is "1" (unsuffixed); extras start at 2
        imagejpeg($box, "$outDir/product-$id-$fileNo.jpg", 85);
        imagedestroy($src);
        imagedestroy($box);

        $credits["$id|$fileNo"] = sprintf('| %d | %s | %d | [%s](%s) | %s |',
            $id, $r['name'], $fileNo, $pick['photographer'], $pick['photographer_url'], $pick['url']);
        $saved++;
        usleep(250000); // be polite to the API
    }

    if ($saved === 0) {
        $failed[] = $id;
    } elseif ($saved < $count) {
        $partial[] = $id;
        $done += $saved;
    } else {
        $done += $saved;
    }
}

// --- DB: replace the generated extra rows for the products we just processed ---
$touchedIds = [];
foreach (array_keys($credits) as $k) {
    $touchedIds[(int) explode('|', $k)[0]] = true;
}
if ($touchedIds) {
    $pdo->beginTransaction();
    $delExtra = $pdo->prepare(
        "DELETE FROM product_images
         WHERE product_id = ? AND is_primary = 0
           AND image_path REGEXP '^product-[0-9]+-[0-9]+\\\\.jpg$'"
    );
    $ins = $pdo->prepare("INSERT INTO product_images (product_id, image_path, is_primary) VALUES (?, ?, 0)");
    foreach (array_keys($touchedIds) as $pid) {
        $delExtra->execute([$pid]);
    }
    ksort($credits);
    foreach (array_keys($credits) as $k) {
        [$pid, $fileNo] = array_map('intval', explode('|', $k));
        $ins->execute([$pid, "product-$pid-$fileNo.jpg"]);
    }
    $pdo->commit();
}

// --- Gallery credits file (separate from PHOTO_CREDITS.md so the primary script
//     never clobbers it). Merge with any rows from a previous partial run. ---
$creditsFile = "$outDir/GALLERY_PHOTO_CREDITS.md";
if (is_file($creditsFile)) {
    foreach (explode("\n", file_get_contents($creditsFile)) as $ln) {
        if (preg_match('/^\|\s*(\d+)\s*\|\s*.*?\|\s*(\d+)\s*\|/', $ln, $m)) {
            $k = $m[1] . '|' . $m[2];
            if (!isset($credits[$k]) && (!$touchedIds || !isset($touchedIds[(int) $m[1]]))) {
                $credits[$k] = rtrim($ln);
            }
        }
    }
}
uksort($credits, function ($a, $b) {
    [$ai, $an] = array_map('intval', explode('|', $a));
    [$bi, $bn] = array_map('intval', explode('|', $b));
    return [$ai, $an] <=> [$bi, $bn];
});
$md = "# Extra gallery photo credits\n\n"
    . "Additional per-product photos (beyond the primary one in PHOTO_CREDITS.md),\n"
    . "sourced from [Pexels](https://www.pexels.com) via the Pexels API. The Pexels\n"
    . "licence permits this use without attribution; credited here anyway.\n\n"
    . "| Product ID | Name | Photo # | Photographer | Source |\n|---|---|---|---|---|\n"
    . implode("\n", $credits) . "\n";
file_put_contents($creditsFile, $md);

// --- Re-seed SQL, generated from what is actually on disk so a teammate can
//     `git pull` then run this file with no API key. ---
$onDisk = [];
foreach (glob("$outDir/product-*-*.jpg") as $f) {
    if (preg_match('#/product-(\d+)-(\d+)\.jpg$#', str_replace('\\', '/', $f), $m)) {
        $onDisk[(int) $m[1]][] = (int) $m[2];
    }
}
ksort($onDisk);
$values = [];
foreach ($onDisk as $pid => $nos) {
    sort($nos);
    foreach ($nos as $no) {
        $values[] = "  ($pid, 'product-$pid-$no.jpg', 0)";
    }
}
$sql = "-- =============================================================================\n"
    . "--  ADD EXTRA PRODUCT PHOTOS - gives selected products additional gallery images\n"
    . "--  so the \"one product -> multiple photos\" feature has data to demonstrate.\n"
    . "--  Generated by tools/fetch_extra_product_photos.php from the committed image\n"
    . "--  files (uploads/products/product-{id}-{n}.jpg), so after a git pull a\n"
    . "--  teammate only needs to run this file - no API key, no uploading.\n"
    . "--  Safe to run more than once. Does not touch primary photos.\n"
    . "--\n"
    . "--    \"C:/xampp/mysql/bin/mysql.exe\" -u root < add_extra_product_photos.sql\n"
    . "-- =============================================================================\n"
    . "USE `coffee_shop`;\n\n"
    . "DELETE FROM `product_images`\n"
    . "WHERE `is_primary` = 0 AND `image_path` REGEXP '^product-[0-9]+-[0-9]+\\\\.jpg\$';\n\n"
    . ($values
        ? "INSERT INTO `product_images` (`product_id`, `image_path`, `is_primary`) VALUES\n"
          . implode(",\n", $values) . ";\n"
        : "-- (no extra photo files found on disk)\n");
file_put_contents(__DIR__ . '/../add_extra_product_photos.sql', $sql);

echo "\ndownloaded $done extra photo(s) across " . count($touchedIds) . " product(s).\n";
if ($partial) {
    echo "partial (fewer than $count): " . implode(', ', $partial) . "\n";
}
if ($failed) {
    echo "no usable results for product ids: " . implode(', ', $failed) . "\n";
}
echo "credits -> uploads/products/GALLERY_PHOTO_CREDITS.md\n";
echo "re-seed -> add_extra_product_photos.sql\n";
