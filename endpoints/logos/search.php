<?php
/*
  [CN-PATCH 17] 图片搜索源本地化。
  
  上游用 DuckDuckGo 和 Brave。这两个在国内直连不通 —— 实测（N150 容器内，
  8 秒超时）：
  
    DuckDuckGo   HTTP 000  超时
    Brave        HTTP 000  超时
  
  这就是「网页搜索」弹窗里两个区块一直转圈、最后报「获取图片结果时出错」的原因。
  
  改成国内可直连的两个源，实测：
  
    必应中国 cn.bing.com       HTTP 200  0.61s   25 条结果
    360 图片 image.so.com      HTTP 200  0.34s   37 条结果
  
  另两个候选被反爬挡住了，没有采用：
  
    搜狗 pic.sogou.com         {"status":1,"info":"forbid"}
    百度 image.baidu.com       {"antiFlag":1,"message":"Forbid spider access"}
  
  两者都要求签名参数（搜狗要 us 和 k 参数由前端 JS 生成，百度要 token），
  逆向这些签名不稳定，一旦对方改算法就失效。必应和 360 不需要。
  
  保留 DDG/Brave 的代码不动，用 source 参数区分 —— 如果将来海外的
  部署者需要，或者国内通了，前端仍然可以列出它们。
*/

require_once '../../includes/ssrf_helper.php';

if (!isset($_GET['search'])) {
    echo json_encode(['error' => 'Invalid request.']);
    exit;
}

$rawSearch = $_GET['search'];
$searchTerm = urlencode($rawSearch);

header('Content-Type: application/json');

/**
 * Shared outbound request helper.
 *
 * Every call is pinned to the resolved IP (CURLOPT_RESOLVE) and checked
 * against the private ranges first, so a DNS rebinding answer cannot turn
 * this endpoint into a probe of the local network.
 */
function cnCurlGet($url, $allowedHosts, $headers = [])
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!in_array($host, $allowedHosts, true)) {
        return null;
    }

    $ip = gethostbyname($host);
    $port = parse_url($url, PHP_URL_PORT) ?: (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80);

    $isPrivate = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        || is_cgnat_ip($ip);

    if ($isPrivate) {
        return null;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:{$ip}"]);

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    // Only the lowercase POSIX-style proxy variables are honored: the
    // uppercase ones can be supplied by a client "Proxy:" header in some
    // nginx+php-fpm setups (the httpoxy class), which would let a caller
    // route this request through their own proxy and defeat the IP pinning.
    $proxy = getenv('https_proxy') ?: getenv('http_proxy') ?: getenv('all_proxy') ?: null;
    if ($proxy) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
    } else {
        curl_setopt($ch, CURLOPT_PROXY, '');
        curl_setopt($ch, CURLOPT_NOPROXY, '*');
    }

    $response = curl_exec($ch);
    unset($ch);

    return $response ?: null;
}

/**
 * 必应中国图片搜索。
 *
 * 结果是服务端渲染的 HTML，每张图在 `m` 属性里带一段 JSON，
 * 原图直链在 murl 字段。HTML 实体是 &quot;，所以要按实体解码后匹配。
 *
 * 没有走必应官方的 Image Search API：那个要在 Azure 门户注册、拿 key、
 * 每月 1000 次免费额度，对一个自托管应用来说门槛过高。
 */
function fetchBingChinaImages($term)
{
    $url = 'https://cn.bing.com/images/search?q=' . $term . '&form=HDRSC2&first=1';
    $html = cnCurlGet($url, ['cn.bing.com'], [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
    ]);

    if (!$html) {
        return null;
    }

    if (!preg_match_all('~murl&quot;:&quot;(.*?)&quot;~', $html, $matches)) {
        return null;
    }

    $urls = array_values(array_unique(array_filter($matches[1])));
    if (empty($urls)) {
        return null;
    }

    $results = [];
    foreach ($urls as $imageUrl) {
        // murl is HTML-entity encoded; undo the remaining entities so the
        // browser gets a usable URL.
        $imageUrl = html_entity_decode($imageUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!preg_match('~^https?://~i', $imageUrl)) {
            continue;
        }
        $results[] = [
            'thumbnail' => $imageUrl,
            'image' => $imageUrl,
            'width' => null,
            'height' => null,
        ];
    }

    return $results ?: null;
}

/**
 * 360 图片搜索。
 *
 * 返回 JSON，结构比 HTML 抓取稳定得多。每条记录里：
 *   img   原图直链
 *   thumb 缩略图
 *   width/height 原始尺寸
 *
 * 优先用 img（原图更清晰），没有才退回 thumb。
 */
function fetchSo360Images($term)
{
    $url = 'https://image.so.com/j?q=' . $term . '&src=srp&correct=' . $term . '&sn=0&pn=30';
    $body = cnCurlGet($url, ['image.so.com'], [
        'Accept: application/json, text/plain, */*',
        'Referer: https://image.so.com/',
    ]);

    if (!$body) {
        return null;
    }

    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['list']) || !is_array($data['list'])) {
        return null;
    }

    $results = [];
    foreach ($data['list'] as $row) {
        $image = $row['img'] ?? '';
        $thumb = $row['thumb'] ?? $image;
        if (!is_string($image) || !preg_match('~^https?://~i', $image)) {
            continue;
        }
        $results[] = [
            'thumbnail' => is_string($thumb) && preg_match('~^https?://~i', $thumb) ? $thumb : $image,
            'image' => $image,
            'width' => isset($row['width']) ? (int) $row['width'] : null,
            'height' => isset($row['height']) ? (int) $row['height'] : null,
        ];
    }

    return $results ?: null;
}

// ---------------------------------------------------------------------------
// Legacy engines, kept for non-Chinese deployments and for the case where a
// future upstream change re-adds them to the UI. Unreachable from mainland
// China today, which is why they are no longer the default.
// ---------------------------------------------------------------------------

function getVqdToken($query)
{
    $html = cnCurlGet("https://duckduckgo.com/?q={$query}&ia=images", ['duckduckgo.com']);
    if ($html && preg_match('/vqd="?([\d-]+)"?/', $html, $matches)) {
        return $matches[1];
    }
    return null;
}

function searchDDGImages($query)
{
    $vqd = getVqdToken($query);
    if (!$vqd) {
        return null;
    }

    $params = http_build_query([
        'l' => 'us-en',
        'o' => 'json',
        'q' => urldecode($query),
        'vqd' => $vqd,
        'f' => ',,transparent,Wide,',
        'p' => '1',
    ]);

    $response = cnCurlGet("https://duckduckgo.com/i.js?{$params}", ['duckduckgo.com'], [
        'Accept: application/json',
        'Referer: https://duckduckgo.com/',
    ]);
    if (!$response) {
        return null;
    }

    $data = json_decode($response, true);
    if (!isset($data['results']) || empty($data['results'])) {
        return null;
    }

    $png = [];
    $other = [];
    foreach ($data['results'] as $row) {
        $result = [
            'thumbnail' => $row['thumbnail'] ?? $row['image'] ?? null,
            'image' => $row['image'] ?? null,
            'width' => $row['width'] ?? null,
            'height' => $row['height'] ?? null,
        ];
        $path = parse_url($result['image'] ?? '', PHP_URL_PATH) ?? '';
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'png') {
            $png[] = $result;
        } else {
            $other[] = $result;
        }
    }

    return array_merge($png, $other);
}

function fetchBraveImages($query)
{
    $html = cnCurlGet("https://search.brave.com/images?q={$query}", ['search.brave.com'], [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.5',
        'Referer: https://search.brave.com/',
    ]);
    if (!$html) {
        return null;
    }

    if (!preg_match_all('~https://imgs\.search\.brave\.com/[A-Za-z0-9_-]+/rs:fit:[0-9:]+/[^"\'\\\\\s]+~', $html, $matches)) {
        return null;
    }

    $urls = [];
    foreach (array_unique($matches[0]) as $src) {
        if (preg_match('~/rs:fit:(\d+):(\d+)~', $src, $fit)) {
            $largest = max((int) $fit[1], (int) $fit[2]);
            if ($largest > 0 && $largest <= 64) {
                continue;
            }
        }
        $urls[] = $src;
    }

    return $urls ? array_slice($urls, 0, 30) : null;
}

// ---------------------------------------------------------------------------
// Main flow
// ---------------------------------------------------------------------------

$source = $_GET['source'] ?? 'all';

// Cache successful responses: repeat searches are common while filling the
// form, and both engines rate-limit (360 after a burst, Bing after ~20).
$cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'wallos-logo-search-v3-' . md5($source . '|' . strtolower(urldecode($searchTerm))) . '.json';
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
    echo file_get_contents($cacheFile);
    exit;
}

$results = null;
$error = null;

if ($source === 'bing' || $source === 'all') {
    $results = fetchBingChinaImages($searchTerm);
}

if (!$results && ($source === 'so360' || $source === 'all')) {
    $results = fetchSo360Images($searchTerm);
}

// Legacy engines: only answer when explicitly asked for, so a Chinese
// installation never pays their 8-second timeouts.
if (!$results && $source === 'duckduckgo') {
    $results = searchDDGImages($searchTerm);
}

if (!$results && $source === 'brave') {
    $braveUrls = fetchBraveImages($searchTerm);
    if ($braveUrls) {
        $results = array_map(fn($url) => [
            'thumbnail' => $url,
            'image' => $url,
            'width' => null,
            'height' => null,
        ], $braveUrls);
    }
}

if ($results) {
    $payload = json_encode(['results' => $results]);
    file_put_contents($cacheFile, $payload);
    echo $payload;
    exit;
}

$messages = [
    'bing' => '必应没有返回结果，或触发了频率限制。请稍候再试。',
    'so360' => '360 图片没有返回结果，或触发了频率限制。请稍候再试。',
    'duckduckgo' => 'DuckDuckGo returned no results or rate-limited the request.',
    'brave' => 'Brave returned no results or rate-limited the request.',
    'all' => '必应和 360 图片都没有返回结果，请稍候再试。',
];
echo json_encode(['error' => $messages[$source] ?? $messages['all']]);
