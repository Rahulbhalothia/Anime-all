<?php
/**
 * AnimeAll — Multi-Source Scraper + Direct Download
 * ===================================================
 * Sources: GogoAnime → AnimePahe → Nyaa RSS
 * Kaam: Anime search karo, direct download links nikalo, serve karo
 *
 * API Endpoints:
 *   scraper.php?action=search&anime=Naruto&quality=720p
 *   scraper.php?action=episodes&anime_url=<encoded_url>
 *   scraper.php?action=getlinks&ep_url=<encoded_url>&quality=720p
 *   scraper.php?action=download&url=<encoded_direct_url>&name=Naruto_ep1
 *   scraper.php?action=nyaa&anime=Naruto&quality=720p
 */

// ─── CONFIG ───────────────────────────────────────────
define('CACHE_DIR',   __DIR__ . '/cache/');
define('LOG_FILE',    __DIR__ . '/logs/scraper.log');
define('CACHE_TTL',   3600);      // 1 hour cache
define('REQ_TIMEOUT', 15);        // seconds
define('USER_AGENT',  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36');

// Headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Dirs banao
foreach ([CACHE_DIR, dirname(LOG_FILE)] as $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
}

// ─── INPUT ────────────────────────────────────────────
$action  = $_GET['action']  ?? 'search';
$anime   = trim($_GET['anime']   ?? '');
$quality = $_GET['quality'] ?? '720p';
$ep_url  = $_GET['ep_url']  ?? '';
$dl_url  = $_GET['url']     ?? '';
$dl_name = $_GET['name']    ?? 'anime_download';
$anime_url = $_GET['anime_url'] ?? '';

// ─── ROUTER ───────────────────────────────────────────
switch ($action) {

    case 'search':
        if (!$anime) die(err('anime parameter required'));
        $result = searchAnime($anime, $quality);
        echo json_encode($result);
        break;

    case 'episodes':
        if (!$anime_url) die(err('anime_url required'));
        $result = getEpisodes(urldecode($anime_url));
        echo json_encode($result);
        break;

    case 'getlinks':
        if (!$ep_url) die(err('ep_url required'));
        $result = getDownloadLinks(urldecode($ep_url), $quality);
        echo json_encode($result);
        break;

    case 'download':
        if (!$dl_url) die(err('url required'));
        proxyDownload(urldecode($dl_url), $dl_name);
        break;

    case 'nyaa':
        if (!$anime) die(err('anime required'));
        $result = searchNyaa($anime, $quality);
        echo json_encode($result);
        break;

    default:
        echo err('Unknown action');
}

// ══════════════════════════════════════════════════════
// FUNCTION: searchAnime
// GogoAnime se anime search karo → episode list + download links
// ══════════════════════════════════════════════════════
function searchAnime($anime, $quality = '720p') {
    $cache_key = 'search_' . md5($anime . $quality);
    $cached = getCache($cache_key);
    if ($cached) return array_merge($cached, ['from_cache' => true]);

    logMsg("Searching: $anime [$quality]");

    // ── SOURCE 1: GogoAnime ──
    $gogo = gogoSearch($anime, $quality);
    if (!empty($gogo['results'])) {
        setCache($cache_key, $gogo);
        return $gogo;
    }

    // ── SOURCE 2: AnimePahe ──
    $pahe = paheSearch($anime, $quality);
    if (!empty($pahe['results'])) {
        setCache($cache_key, $pahe);
        return $pahe;
    }

    // ── SOURCE 3: Nyaa RSS (fallback) ──
    $nyaa = searchNyaa($anime, $quality);
    if (!empty($nyaa['results'])) {
        setCache($cache_key, $nyaa);
        return $nyaa;
    }

    return ['status' => 'not_found', 'message' => 'Koi source nahi mila', 'results' => []];
}

// ══════════════════════════════════════════════════════
// GOGOANIME SCRAPER
// ══════════════════════════════════════════════════════
function gogoSearch($anime, $quality) {
    $slug    = strtolower(preg_replace('/\s+/', '-', $anime));
    $encoded = urlencode($anime);

    // Search page
    $search_url = "https://gogoanime3.co/search.html?keyword={$encoded}";
    $html = fetchUrl($search_url);
    if (!$html) return ['status' => 'error', 'source' => 'gogoanime', 'results' => []];

    // Parse results
    preg_match_all('/<p class="name"><a href="([^"]+)"[^>]*title="([^"]+)"/i', $html, $matches);

    if (empty($matches[1])) {
        // Try alternate selector
        preg_match_all('/href="(\/category\/[^"]+)"[^>]*>([^<]+)</i', $html, $matches);
    }

    $results = [];
    $base    = 'https://gogoanime3.co';

    for ($i = 0; $i < min(5, count($matches[1])); $i++) {
        $url   = $matches[1][$i];
        $title = html_entity_decode(trim($matches[2][$i]));

        // Full URL
        if (!str_starts_with($url, 'http')) $url = $base . $url;

        $results[] = [
            'title'      => $title,
            'anime_url'  => $url,
            'source'     => 'gogoanime',
            'action_url' => '?action=episodes&anime_url=' . urlencode($url),
        ];
    }

    if (empty($results)) return ['status' => 'error', 'source' => 'gogoanime', 'results' => []];

    // Auto-fetch episodes for top result
    $top = $results[0];
    $eps = gogoGetEpisodes($top['anime_url']);

    return [
        'status'    => 'ok',
        'source'    => 'gogoanime',
        'anime'     => $top['title'],
        'anime_url' => $top['anime_url'],
        'results'   => $results,
        'episodes'  => $eps,
        'quality'   => $quality,
    ];
}

function gogoGetEpisodes($anime_url) {
    $html = fetchUrl($anime_url);
    if (!$html) return [];

    // Movie ID extract
    preg_match('/movie_id\s*=\s*(\d+)/i', $html, $id_match);
    if (empty($id_match[1])) {
        preg_match('/id="movie_id"\s+value="(\d+)"/i', $html, $id_match);
    }
    $movie_id = $id_match[1] ?? '';

    // Episode range
    preg_match('/ep_start\s*=\s*["\']?(\d+)/i', $html, $start_m);
    preg_match('/ep_end\s*=\s*["\']?(\d+)/i',   $html, $end_m);
    $ep_start = $start_m[1] ?? 1;
    $ep_end   = $end_m[1]   ?? 1;

    if (!$movie_id) {
        // Direct episode links from page
        preg_match_all('/href="(\/[^"]*-episode-(\d+)[^"]*)"/i', $html, $ep_matches);
        $eps = [];
        foreach ($ep_matches[1] as $k => $link) {
            $ep_num = $ep_matches[2][$k];
            $eps[]  = [
                'ep'     => (int)$ep_num,
                'url'    => 'https://gogoanime3.co' . $link,
                'label'  => "Episode $ep_num",
            ];
        }
        return array_slice($eps, 0, 50);
    }

    // Ajax episode list
    $ajax_url = "https://ajax.gogocdn.net/ajax/load-list-episode?ep_start={$ep_start}&ep_end={$ep_end}&id={$movie_id}";
    $ajax_html = fetchUrl($ajax_url);

    preg_match_all('/href="\s*([^"]+)"\s*[^>]*>\s*<div[^>]*>\s*EP\s*<span>([^<]+)<\/span>/i', $ajax_html, $ep_m);

    $eps = [];
    for ($i = 0; $i < count($ep_m[1]); $i++) {
        $link   = trim($ep_m[1][$i]);
        $ep_num = trim($ep_m[2][$i]);
        if (!str_starts_with($link, 'http')) $link = 'https://gogoanime3.co' . $link;
        $eps[] = [
            'ep'    => (int)$ep_num,
            'url'   => $link,
            'label' => "Episode $ep_num",
        ];
    }

    usort($eps, fn($a,$b) => $a['ep'] - $b['ep']);
    return $eps;
}

// ══════════════════════════════════════════════════════
// GET DOWNLOAD LINKS FROM EPISODE PAGE
// GogoAnime episode → extract all server download links
// ══════════════════════════════════════════════════════
function getDownloadLinks($ep_url, $quality = '720p') {
    $cache_key = 'links_' . md5($ep_url . $quality);
    $cached = getCache($cache_key);
    if ($cached) return array_merge($cached, ['from_cache' => true]);

    logMsg("Getting links: $ep_url");
    $html = fetchUrl($ep_url);
    if (!$html) return ['status' => 'error', 'links' => []];

    $links = [];

    // ── Method 1: Direct download div ──
    if (preg_match('/<div class="cf-download[^>]*>(.*?)<\/div>/is', $html, $dl_block)) {
        preg_match_all('/href="([^"]+)"[^>]*>\s*([^<]+)\s*<\/a>/i', $dl_block[1], $dl_matches);
        for ($i = 0; $i < count($dl_matches[1]); $i++) {
            $url   = $dl_matches[1][$i];
            $label = trim($dl_matches[2][$i]);
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                $links[] = ['url' => $url, 'label' => $label, 'method' => 'direct'];
            }
        }
    }

    // ── Method 2: Vidstreaming / GogoCDN embed ──
    preg_match('/src="(https?:\/\/[^"]*(?:vidstreaming|gogocdn|streamani)[^"]*)"/', $html, $embed_m);
    if (!empty($embed_m[1])) {
        $cdn_links = extractCdnLinks($embed_m[1], $quality);
        $links = array_merge($links, $cdn_links);
    }

    // ── Method 3: Backup server links ──
    preg_match_all('/data-video="([^"]+)"/i', $html, $server_m);
    foreach ($server_m[1] as $server_url) {
        if (!str_starts_with($server_url, 'http')) continue;
        $server_links = tryExtractFromServer($server_url, $quality);
        $links = array_merge($links, $server_links);
    }

    // ── Method 4: Regex catch-all ──
    preg_match_all('/https?:\/\/[^\s"\'<>]+\.(?:mp4|mkv|m3u8)[^\s"\'<>]*/i', $html, $raw_m);
    foreach ($raw_m[0] as $raw_url) {
        $links[] = ['url' => $raw_url, 'label' => 'Direct MP4/MKV', 'method' => 'raw'];
    }

    // Filter by quality
    $filtered = filterByQuality($links, $quality);
    $final    = !empty($filtered) ? $filtered : $links;

    // Add proxy download URLs
    foreach ($final as &$link) {
        $link['proxy_url']  = '?action=download&url=' . urlencode($link['url']) . '&name=' . urlencode(basename(parse_url($link['url'], PHP_URL_PATH)));
        $link['direct_url'] = $link['url'];
    }

    $result = [
        'status'  => empty($final) ? 'no_links' : 'ok',
        'ep_url'  => $ep_url,
        'quality' => $quality,
        'count'   => count($final),
        'links'   => $final,
    ];

    if (!empty($final)) setCache($cache_key, $result);
    return $result;
}

// ══════════════════════════════════════════════════════
// CDN LINK EXTRACTOR (GogoCDN / Vidstreaming)
// ══════════════════════════════════════════════════════
function extractCdnLinks($embed_url, $quality) {
    $html = fetchUrl($embed_url, [
        'Referer: https://gogoanime3.co/',
    ]);
    if (!$html) return [];

    $links = [];

    // Crypto-encoded sources (gogoanime uses crypto-js)
    if (preg_match('/CryptoJS\.AES\.decrypt\(["\']([^"\']+)["\']/', $html, $enc_m)) {
        // Try to get the key
        preg_match('/var\s+(?:secr|key|pass)\s*=\s*["\']([^"\']+)["\']/', $html, $key_m);
        // Note: actual decryption needs the key — log for manual setup
        logMsg("Encrypted CDN source found — key: " . ($key_m[1] ?? 'not found'));
    }

    // Direct m3u8 / mp4 in page
    preg_match_all('/["\'](https?:\/\/[^"\']+\.(?:m3u8|mp4)[^"\']*)["\']/', $html, $raw_m);
    foreach ($raw_m[1] as $url) {
        $label = str_contains($url, 'm3u8') ? 'HLS Stream' : 'MP4 Direct';
        $links[] = ['url' => $url, 'label' => $label, 'method' => 'cdn'];
    }

    // Sources array
    preg_match_all('/file\s*:\s*["\']([^"\']+)["\']/', $html, $file_m);
    foreach ($file_m[1] as $url) {
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $links[] = ['url' => $url, 'label' => 'CDN Source', 'method' => 'cdn_file'];
        }
    }

    return $links;
}

// ══════════════════════════════════════════════════════
// SERVER LINK EXTRACTOR (Doodstream, Streamtape, etc.)
// ══════════════════════════════════════════════════════
function tryExtractFromServer($server_url, $quality) {
    $html = fetchUrl($server_url);
    if (!$html) return [];

    $links = [];
    $host  = parse_url($server_url, PHP_URL_HOST) ?? '';

    // Streamtape
    if (str_contains($host, 'streamtape')) {
        preg_match("/document\.getElementById\('norobotlink'\)\.innerHTML\s*=\s*['\"]([^'\"]+)/", $html, $m);
        if (!empty($m[1])) {
            $links[] = ['url' => 'https:' . $m[1], 'label' => 'Streamtape Direct', 'method' => 'streamtape'];
        }
    }

    // Doodstream
    if (str_contains($host, 'dood')) {
        preg_match("/pass_md5\/([^'\"]+)/", $html, $m);
        if (!empty($m[0])) {
            $token_url = 'https://dood.to/' . $m[0];
            $token_html = fetchUrl($token_url, ['Referer: ' . $server_url]);
            if ($token_html) {
                preg_match('/\?token=([^&\s"\']+)/', $token_html, $tm);
                if (!empty($tm[0])) {
                    $links[] = ['url' => 'https://dood.to' . $m[0] . $tm[0], 'label' => 'Doodstream', 'method' => 'dood'];
                }
            }
        }
    }

    // Generic mp4/m3u8 catch
    preg_match_all('/["\'](https?:\/\/[^"\']+\.(?:mp4|mkv|m3u8)[^"\']*)["\']/', $html, $raw_m);
    foreach ($raw_m[1] as $url) {
        $links[] = ['url' => $url, 'label' => 'Direct Stream', 'method' => 'generic'];
    }

    return $links;
}

// ══════════════════════════════════════════════════════
// ANIMEPAHE SCRAPER
// ══════════════════════════════════════════════════════
function paheSearch($anime, $quality) {
    $encoded = urlencode($anime);
    $api_url = "https://animepahe.ru/api?m=search&q={$encoded}";

    $json = fetchUrl($api_url, [
        'Accept: application/json',
        'X-Requested-With: XMLHttpRequest',
        'Referer: https://animepahe.ru/',
    ]);

    if (!$json) return ['status' => 'error', 'source' => 'animepahe', 'results' => []];

    $data = json_decode($json, true);
    if (empty($data['data'])) return ['status' => 'not_found', 'source' => 'animepahe', 'results' => []];

    $results = [];
    foreach (array_slice($data['data'], 0, 5) as $item) {
        $session = $item['session'] ?? '';
        $title   = $item['title']   ?? '';
        if (!$session) continue;

        $anime_url = "https://animepahe.ru/anime/{$session}";
        $results[] = [
            'title'     => $title,
            'anime_url' => $anime_url,
            'session'   => $session,
            'type'      => $item['type'] ?? 'TV',
            'episodes'  => $item['episodes'] ?? 0,
            'source'    => 'animepahe',
            'action_url'=> '?action=episodes&anime_url=' . urlencode($anime_url),
        ];
    }

    // Auto-fetch episodes for top result
    $top = $results[0] ?? null;
    $eps = [];
    if ($top) $eps = paheGetEpisodes($top['session']);

    return [
        'status'   => 'ok',
        'source'   => 'animepahe',
        'anime'    => $top['title'] ?? $anime,
        'results'  => $results,
        'episodes' => $eps,
        'quality'  => $quality,
    ];
}

function paheGetEpisodes($session, $page = 1) {
    $url  = "https://animepahe.ru/api?m=release&id={$session}&sort=episode_asc&page={$page}";
    $json = fetchUrl($url, [
        'Accept: application/json',
        'X-Requested-With: XMLHttpRequest',
        'Referer: https://animepahe.ru/',
    ]);

    if (!$json) return [];
    $data = json_decode($json, true);
    if (empty($data['data'])) return [];

    $eps = [];
    foreach ($data['data'] as $ep) {
        $ep_session = $ep['session'] ?? '';
        $ep_num     = $ep['episode'] ?? 0;
        if (!$ep_session) continue;
        $eps[] = [
            'ep'     => $ep_num,
            'url'    => "https://animepahe.ru/play/{$session}/{$ep_session}",
            'label'  => "Episode $ep_num",
            'source' => 'animepahe',
        ];
    }

    // Multi-page support
    if (!empty($data['next_page_url'])) {
        $more = paheGetEpisodes($session, $page + 1);
        $eps  = array_merge($eps, $more);
    }

    return $eps;
}

// AnimePahe episode download links
function paheGetDownloadLinks($ep_url, $quality) {
    $html = fetchUrl($ep_url, ['Referer: https://animepahe.ru/']);
    if (!$html) return [];

    $links = [];

    // Kwik player links
    preg_match_all('/href="(https?:\/\/kwik\.si\/[^"]+)"[^>]*>([^<]*(?:' . $quality . '|HD|SD)[^<]*)<\/a>/i', $html, $kwik_m);
    for ($i = 0; $i < count($kwik_m[1]); $i++) {
        $links[] = [
            'url'    => $kwik_m[1][$i],
            'label'  => trim($kwik_m[2][$i]) ?: "Kwik $quality",
            'method' => 'kwik',
        ];
    }

    // All kwik links if quality-specific not found
    if (empty($links)) {
        preg_match_all('/href="(https?:\/\/kwik\.si\/[^"]+)"/i', $html, $all_kwik);
        foreach ($all_kwik[1] as $url) {
            $links[] = ['url' => $url, 'label' => 'Kwik Player', 'method' => 'kwik'];
        }
    }

    return $links;
}

// ══════════════════════════════════════════════════════
// NYAA.SI RSS SCRAPER (Torrent links)
// ══════════════════════════════════════════════════════
function searchNyaa($anime, $quality) {
    $groups = ['[SubsPlease]', '[Erai-raws]', '[HorribleSubs]'];
    $all_results = [];

    foreach ($groups as $group) {
        $q   = urlencode("$anime $quality $group");
        $url = "https://nyaa.si/?page=rss&q={$q}&c=1_2&f=0";

        $xml_str = fetchUrl($url);
        if (!$xml_str) continue;

        // Parse RSS
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_str);
        if (!$xml) continue;

        foreach ($xml->channel->item as $item) {
            $title    = (string)$item->title;
            $link     = (string)$item->link;
            $magnet   = (string)$item->children('nyaa', true)->magnetUri ?? '';
            $size_raw = (string)$item->children('nyaa', true)->size ?? '';
            $seeders  = (int)$item->children('nyaa', true)->seeders ?? 0;

            // Skip dead torrents
            if ($seeders < 1) continue;

            $all_results[] = [
                'title'      => $title,
                'torrent_url'=> $link,
                'magnet'     => $magnet,
                'size'       => $size_raw,
                'seeders'    => $seeders,
                'group'      => $group,
                'quality'    => $quality,
                'method'     => 'torrent',
                'source'     => 'nyaa',
            ];
        }
    }

    // Sort by seeders
    usort($all_results, fn($a, $b) => $b['seeders'] - $a['seeders']);

    return [
        'status'  => empty($all_results) ? 'not_found' : 'ok',
        'source'  => 'nyaa',
        'quality' => $quality,
        'count'   => count($all_results),
        'results' => array_slice($all_results, 0, 15),
    ];
}

// ══════════════════════════════════════════════════════
// PROXY DOWNLOAD — Direct file streaming through server
// User ko dusri site pe nahi jana padta
// ══════════════════════════════════════════════════════
function proxyDownload($file_url, $filename) {
    // Validate URL
    if (!filter_var($file_url, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Invalid URL']));
    }

    // Block local/private IPs
    $host = parse_url($file_url, PHP_URL_HOST);
    if (in_array($host, ['localhost', '127.0.0.1', '::1']) || str_starts_with($host, '192.168.') || str_starts_with($host, '10.')) {
        http_response_code(403);
        die(json_encode(['error' => 'Blocked']));
    }

    // Sanitize filename
    $filename = preg_replace('/[^a-zA-Z0-9\-_\.]/', '_', $filename);
    if (!preg_match('/\.(mp4|mkv|avi|m3u8)$/i', $filename)) $filename .= '.mp4';

    logMsg("Proxy download: $file_url → $filename");

    // HEAD request pehle — size check
    $head_ch = curl_init($file_url);
    curl_setopt_array($head_ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_HTTPHEADER     => [
            'Referer: https://gogoanime3.co/',
            'Accept: */*',
        ],
    ]);
    curl_exec($head_ch);
    $content_length = curl_getinfo($head_ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    $content_type   = curl_getinfo($head_ch, CURLINFO_CONTENT_TYPE);
    $final_url      = curl_getinfo($head_ch, CURLINFO_EFFECTIVE_URL);
    curl_close($head_ch);

    // Headers for download
    $mime = $content_type ?: 'application/octet-stream';
    if (str_contains($filename, '.mkv')) $mime = 'video/x-matroska';
    if (str_contains($filename, '.mp4')) $mime = 'video/mp4';

    header("Content-Type: $mime");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Accept-Ranges: bytes");
    header("Cache-Control: no-cache");

    if ($content_length > 0) {
        header("Content-Length: $content_length");
    }

    // Range support
    $range_start = 0;
    $range_end   = null;
    if (isset($_SERVER['HTTP_RANGE'])) {
        preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $rm);
        $range_start = (int)$rm[1];
        $range_end   = !empty($rm[2]) ? (int)$rm[2] : null;
        header("HTTP/1.1 206 Partial Content");
        if ($content_length > 0) {
            $end = $range_end ?? ($content_length - 1);
            header("Content-Range: bytes {$range_start}-{$end}/{$content_length}");
        }
    }

    // Stream via cURL
    $ch = curl_init($final_url ?: $file_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_HTTPHEADER     => array_filter([
            'Referer: https://gogoanime3.co/',
            'Accept: */*',
            $range_start > 0 ? "Range: bytes={$range_start}-" . ($range_end ?? '') : null,
        ]),
        CURLOPT_WRITEFUNCTION  => function($ch, $data) {
            echo $data;
            flush();
            return strlen($data);
        },
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_BUFFERSIZE     => 131072, // 128KB buffer
    ]);

    curl_exec($ch);
    curl_close($ch);
    exit;
}

// ══════════════════════════════════════════════════════
// EPISODE LIST (generic)
// ══════════════════════════════════════════════════════
function getEpisodes($url) {
    if (str_contains($url, 'gogoanime')) {
        return ['status' => 'ok', 'episodes' => gogoGetEpisodes($url), 'source' => 'gogoanime'];
    }
    if (str_contains($url, 'animepahe')) {
        preg_match('/\/anime\/([^\/]+)/', $url, $m);
        $session = $m[1] ?? '';
        return ['status' => 'ok', 'episodes' => paheGetEpisodes($session), 'source' => 'animepahe'];
    }
    return ['status' => 'error', 'message' => 'Unsupported source'];
}

// ══════════════════════════════════════════════════════
// QUALITY FILTER
// ══════════════════════════════════════════════════════
function filterByQuality($links, $quality) {
    $q = strtolower($quality);
    return array_filter($links, function($link) use ($q) {
        $url   = strtolower($link['url'] ?? '');
        $label = strtolower($link['label'] ?? '');
        return str_contains($url, $q) || str_contains($label, $q);
    });
}

// ══════════════════════════════════════════════════════
// HTTP FETCH (cURL wrapper)
// ══════════════════════════════════════════════════════
function fetchUrl($url, $extra_headers = []) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return null;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => REQ_TIMEOUT,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING       => 'gzip, deflate',
        CURLOPT_HTTPHEADER     => array_merge([
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Connection: keep-alive',
        ], $extra_headers),
        CURLOPT_COOKIEJAR      => CACHE_DIR . 'cookies.txt',
        CURLOPT_COOKIEFILE     => CACHE_DIR . 'cookies.txt',
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error     = curl_error($ch);
    curl_close($ch);

    if ($error) { logMsg("cURL Error: $error for $url"); return null; }
    if ($http_code >= 400) { logMsg("HTTP $http_code for $url"); return null; }

    return $response ?: null;
}

// ══════════════════════════════════════════════════════
// CACHE HELPERS
// ══════════════════════════════════════════════════════
function getCache($key) {
    $file = CACHE_DIR . md5($key) . '.json';
    if (!file_exists($file)) return null;
    if (time() - filemtime($file) > CACHE_TTL) { @unlink($file); return null; }
    $data = json_decode(file_get_contents($file), true);
    return $data ?: null;
}

function setCache($key, $data) {
    $file = CACHE_DIR . md5($key) . '.json';
    file_put_contents($file, json_encode($data), LOCK_EX);
}

// ══════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════
function err($msg) {
    return json_encode(['status' => 'error', 'message' => $msg]);
}

function logMsg($msg) {
    $line = date('[Y-m-d H:i:s]') . " $msg\n";
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}
