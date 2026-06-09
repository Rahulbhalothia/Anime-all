<?php
/**
 * AnimeAll — Download Handler
 * File: download.php
 * 
 * Usage: download.php?anime=Naruto&quality=1080p&malId=20
 * 
 * Yeh file do kaam karti hai:
 * 1. Agar server pe actual file hai → direct download serve karta hai
 * 2. Agar file nahi hai → best available mirror/source pe redirect karta hai
 */

// ═══════════════════════════════
// HEADERS — CORS & Security
// ═══════════════════════════════
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("X-Content-Type-Options: nosniff");

// ═══════════════════════════════
// INPUT VALIDATION
// ═══════════════════════════════
$anime   = isset($_GET['anime'])   ? trim(strip_tags($_GET['anime']))   : '';
$quality = isset($_GET['quality']) ? trim(strip_tags($_GET['quality'])) : '720p';
$malId   = isset($_GET['malId'])   ? intval($_GET['malId'])             : 0;
$ep      = isset($_GET['ep'])      ? intval($_GET['ep'])                : 1;

// Sanitize — sirf safe characters allow
$anime   = preg_replace('/[^a-zA-Z0-9\s\-_\'\.]/', '', $anime);
$quality = preg_replace('/[^a-zA-Z0-9]/', '', $quality);

// Quality whitelist
$allowed_qualities = ['4K', '1080p', '720p', '480p', '360p'];
if (!in_array($quality, $allowed_qualities)) {
    $quality = '720p';
}

if (empty($anime)) {
    http_response_code(400);
    die(json_encode(['error' => 'Anime name required', 'status' => 400]));
}

// ═══════════════════════════════
// LOCAL FILE CHECK
// Download folder structure:
// /downloads/{AnimeName}/{quality}/ep01.mkv
// ═══════════════════════════════
$downloads_dir = __DIR__ . '/downloads/';
$anime_folder  = $downloads_dir . preg_replace('/\s+/', '_', $anime) . '/';
$quality_dir   = $anime_folder . $quality . '/';

// File patterns to search
$ep_str     = sprintf('ep%02d', $ep);
$extensions = ['mkv', 'mp4', 'avi'];

$local_file = null;
$local_name = null;

foreach ($extensions as $ext) {
    $path = $quality_dir . $ep_str . '.' . $ext;
    if (file_exists($path) && is_readable($path)) {
        $local_file = $path;
        $local_name = "{$anime}_{$ep_str}_{$quality}.{$ext}";
        break;
    }
}

// ═══════════════════════════════
// SERVE LOCAL FILE (agar mila)
// ═══════════════════════════════
if ($local_file) {
    $filesize = filesize($local_file);
    $ext      = pathinfo($local_file, PATHINFO_EXTENSION);

    // MIME type
    $mime_map = [
        'mkv' => 'video/x-matroska',
        'mp4' => 'video/mp4',
        'avi' => 'video/x-msvideo',
    ];
    $mime = $mime_map[$ext] ?? 'application/octet-stream';

    // Resume support (Range header)
    $start = 0;
    $end   = $filesize - 1;

    if (isset($_SERVER['HTTP_RANGE'])) {
        preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches);
        $start = intval($matches[1]);
        $end   = !empty($matches[2]) ? intval($matches[2]) : $end;

        header("HTTP/1.1 206 Partial Content");
        header("Content-Range: bytes {$start}-{$end}/{$filesize}");
    } else {
        header("HTTP/1.1 200 OK");
    }

    $length = $end - $start + 1;

    header("Content-Type: {$mime}");
    header("Content-Disposition: attachment; filename=\"{$local_name}\"");
    header("Content-Length: {$length}");
    header("Accept-Ranges: bytes");
    header("Cache-Control: no-cache, must-revalidate");
    header("Pragma: no-cache");

    // Stream file
    $fp = fopen($local_file, 'rb');
    fseek($fp, $start);
    $buffer = 8192; // 8KB chunks
    $sent   = 0;

    while (!feof($fp) && $sent < $length) {
        $read = min($buffer, $length - $sent);
        echo fread($fp, $read);
        $sent += $read;
        flush();
    }
    fclose($fp);
    exit;
}

// ═══════════════════════════════
// MIRROR / EXTERNAL SOURCE
// Local file nahi mila → best source pe redirect
// ═══════════════════════════════

// Quality → resolution mapping
$quality_map = [
    '4K'    => '2160',
    '1080p' => '1080',
    '720p'  => '720',
    '480p'  => '480',
    '360p'  => '360',
];
$res = $quality_map[$quality] ?? '720';

// Anime name URL encode
$anime_encoded = urlencode($anime);
$anime_slug    = strtolower(preg_replace('/\s+/', '-', $anime));

// ═══════════════════════════════
// SOURCE SELECTION
// Alag-alag sources try karo
// (Aap apne actual sources yahan add karo)
// ═══════════════════════════════

// Option 1: Nyaa.si RSS search (public torrent)
// Option 2: Animixplay / gogoanime style API
// Option 3: Custom CDN

// Default: Nyaa search redirect (most reliable public source)
$nyaa_query  = urlencode("{$anime} {$quality} [SubsPlease]");
$nyaa_url    = "https://nyaa.si/?f=0&c=1_2&q={$nyaa_query}";

// GogoAnime search fallback
$gogo_url    = "https://gogoanime3.co/search.html?keyword={$anime_encoded}";

// AnimePahe (high quality)
$pahe_url    = "https://animepahe.ru/search?q={$anime_encoded}";

// Choose source based on quality
if ($quality === '4K') {
    // 4K → Nyaa has best 4K content
    $redirect_url = $nyaa_url;
} elseif (in_array($quality, ['1080p', '720p'])) {
    // HD → AnimePahe (best quality/size ratio)
    $redirect_url = $pahe_url;
} else {
    // SD → GogoAnime
    $redirect_url = $gogo_url;
}

// ═══════════════════════════════
// LOG DOWNLOAD REQUEST (optional)
// ═══════════════════════════════
$log_file = __DIR__ . '/logs/downloads.log';
if (is_writable(dirname($log_file))) {
    $log_entry = date('Y-m-d H:i:s') . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') 
               . " | Anime: {$anime} | Quality: {$quality} | MAL_ID: {$malId}\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// ═══════════════════════════════
// RESPONSE
// ═══════════════════════════════

// Agar AJAX request hai → JSON return karo
$is_ajax = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) || isset($_GET['json']);

if ($is_ajax) {
    header("Content-Type: application/json");
    echo json_encode([
        'status'       => 'redirect',
        'anime'        => $anime,
        'quality'      => $quality,
        'redirect_url' => $redirect_url,
        'source'       => ($quality === '4K') ? 'Nyaa.si' : (in_array($quality, ['1080p','720p']) ? 'AnimePahe' : 'GogoAnime'),
        'message'      => "Server pe file nahi mili. Best source pe redirect kar rahe hain.",
    ]);
    exit;
}

// Normal request → direct redirect
header("Location: {$redirect_url}", true, 302);
exit;
?>
