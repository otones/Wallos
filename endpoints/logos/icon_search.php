<?php
/*
  Icon search backed by the selfh.st and Dashboard Icons (homarr-labs) collections.
  Both are GitHub-backed icon sets served over jsDelivr with a machine-readable index,
  so this endpoint just filters cached indexes — no scraping involved.
  Returns the same JSON shape as search.php: {"results": [{thumbnail, image, ...}]}.

  [CN-PATCH 17] jsDelivr 多镜像回退。
  
  这两个图标集都托管在 GitHub，通过 jsDelivr 的 /gh/ 路径访问。jsDelivr 的
  各个域名在国内的可达性差异很大，而且会随时间变化：
  
    cdn.jsdelivr.net          本次实测 200 / 0.44s（可用，但历史上多次被墙）
    fastly.jsdelivr.net       200 / 0.43s
    gcore.jsdelivr.net        200 / 1.25s
    testingcf.jsdelivr.net    200 / 1.22s
    cdn.statically.io         超时
    ghproxy.net（GitHub 代理） 200 / 6.8s（慢，仅作最后手段）
  
  上游只写死 cdn.jsdelivr.net，一旦它被墙，两个图标集区块就一起失效。
  这里改成按顺序尝试多个镜像，第一个成功的就缓存下来（记住用哪个镜像
  可用，后续请求直接用它，不再逐个试超时）。
  
  镜像列表按实测速度排。如果全部失败，退回 stale cache —— 至少让用户
  还能看到一天前的索引。
*/

const ICON_SEARCH_CACHE_TTL = 86400; // one day
const ICON_SEARCH_MAX_RESULTS = 24;

// jsDelivr mirrors, fastest first (measured from a mainland-China host).
const ICON_CDN_MIRRORS = [
    'https://cdn.jsdelivr.net/gh/',
    'https://fastly.jsdelivr.net/gh/',
    'https://gcore.jsdelivr.net/gh/',
    'https://testingcf.jsdelivr.net/gh/',
];

// Last resort: a GitHub raw proxy. Slow (~7s) but reachable when every
// jsDelivr domain is blocked.
const ICON_CDN_FALLBACK = 'https://ghproxy.net/https://raw.githubusercontent.com/';

/**
 * Remembers which mirror worked, so subsequent searches do not re-pay the
 * timeouts of the ones that are blocked.
 */
function iconMirrorIndexGet()
{
    $f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wallos-icon-mirror.txt';
    if (file_exists($f)) {
        $idx = (int) trim((string) file_get_contents($f));
        if ($idx >= 0 && $idx < count(ICON_CDN_MIRRORS)) {
            return $idx;
        }
    }
    return 0;
}

function iconMirrorIndexSet($idx)
{
    file_put_contents(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wallos-icon-mirror.txt', (string) $idx);
}

function iconIndexFetch($url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Wallos');

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);

    return ($response !== false && $status === 200) ? $response : null;
}

/**
 * Fetches one of the two icon-set indexes.
 *
 * $repoPath is the jsDelivr /gh/ path, e.g. "selfhst/icons/index.json".
 * Tries the remembered mirror first, then the rest, then the raw proxy.
 */
function iconIndexFetchFromMirrors($repoPath)
{
    $count = count(ICON_CDN_MIRRORS);
    $start = iconMirrorIndexGet();

    for ($i = 0; $i < $count; $i++) {
        $idx = ($start + $i) % $count;
        $response = iconIndexFetch(ICON_CDN_MIRRORS[$idx] . $repoPath);
        if ($response !== null) {
            if ($idx !== $start) {
                iconMirrorIndexSet($idx);
            }
            return $response;
        }
    }

    // Every jsDelivr domain failed: try the raw proxy once.
    // repoPath "owner/repo/file" maps to "owner/repo/main/file" on GitHub.
    if (preg_match('~^([^/]+)/([^/]+)/(.+)$~', $repoPath, $parts)) {
        $rawUrl = ICON_CDN_FALLBACK . $parts[1] . '/' . $parts[2] . '/main/' . $parts[3];
        $response = iconIndexFetch($rawUrl);
        if ($response !== null) {
            return $response;
        }
    }

    return null;
}

/**
 * Fetches an index JSON with a temp-file cache so we don't pull it on every search.
 */
function iconIndexGetCached($name, $repoPath)
{
    $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wallos-icon-index-' . $name . '.json';

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < ICON_SEARCH_CACHE_TTL) {
        $cached = file_get_contents($cacheFile);
        if ($cached !== false && $cached !== '') {
            return json_decode($cached, true);
        }
    }

    $fresh = iconIndexFetchFromMirrors($repoPath);
    if ($fresh === null) {
        // Network failed: fall back to a stale cache if one exists
        if (file_exists($cacheFile)) {
            $cached = file_get_contents($cacheFile);
            if ($cached !== false && $cached !== '') {
                return json_decode($cached, true);
            }
        }
        return null;
    }

    file_put_contents($cacheFile, $fresh);
    return json_decode($fresh, true);
}

/** The mirror-prefixed URL for one icon file, using the mirror that works. */
function iconFileUrl($repoPath)
{
    $mirror = ICON_CDN_MIRRORS[iconMirrorIndexGet()];
    return $mirror . $repoPath;
}

function searchSelfhstIcons($searchTerm)
{
    $index = iconIndexGetCached('selfhst', 'selfhst/icons/index.json');
    if (!is_array($index)) {
        return [];
    }

    $results = [];
    foreach ($index as $icon) {
        $name = $icon['Name'] ?? '';
        $slug = $icon['Reference'] ?? '';
        if ($slug === '' || ($icon['PNG'] ?? '') !== 'Yes') {
            continue;
        }
        if (stripos($name, $searchTerm) === false && stripos($slug, $searchTerm) === false) {
            continue;
        }

        $url = iconFileUrl("selfhst/icons/png/{$slug}.png");
        $results[] = [
            'thumbnail' => $url,
            'image' => $url,
            'width' => null,
            'height' => null,
        ];
    }

    return $results;
}

function searchDashboardIcons($searchTerm)
{
    $index = iconIndexGetCached('dashboard-icons', 'homarr-labs/dashboard-icons/tree.json');
    if (!is_array($index) || !isset($index['png'])) {
        return [];
    }

    $results = [];
    foreach ($index['png'] as $file) {
        $slug = preg_replace('/\.png$/', '', $file);
        // Skip theme variants; the base icon is enough for search results
        if (preg_match('/-(light|dark)$/', $slug)) {
            continue;
        }
        if (stripos($slug, $searchTerm) === false) {
            continue;
        }

        $url = iconFileUrl("homarr-labs/dashboard-icons/png/{$file}");
        $results[] = [
            'thumbnail' => $url,
            'image' => $url,
            'width' => null,
            'height' => null,
        ];
    }

    return $results;
}

// --- Main flow ---

header('Content-Type: application/json');

if (!isset($_GET['search']) || trim($_GET['search']) === '') {
    echo json_encode(['error' => 'Invalid request.']);
    exit;
}

$searchTerm = trim($_GET['search']);
$source = $_GET['source'] ?? 'all';

if ($source === 'selfhst') {
    $results = searchSelfhstIcons($searchTerm);
} elseif ($source === 'dashboardicons') {
    $results = searchDashboardIcons($searchTerm);
} else {
    $results = array_merge(searchSelfhstIcons($searchTerm), searchDashboardIcons($searchTerm));
}

// Prefer shorter (closer) matches and de-duplicate by URL
usort($results, fn($a, $b) => strlen($a['image']) <=> strlen($b['image']));
$seen = [];
$results = array_values(array_filter($results, function ($result) use (&$seen) {
    if (isset($seen[$result['image']])) {
        return false;
    }
    $seen[$result['image']] = true;
    return true;
}));

$results = array_slice($results, 0, ICON_SEARCH_MAX_RESULTS);

echo json_encode(['results' => $results]);
