<?php
/**
 * PHP Compiler Script for Whitelists
 *
 * Source: a single published Google Sheet (CSV) of real usage analytics with the
 * columns `market, hostname, nb_click`. Each hostname appears once per market it
 * was used in, with a click count.
 *
 * Instead of guessing a domain's scope from its TLD, this compiler DERIVES scope
 * from the usage spread across markets:
 *   - A hostname used meaningfully in several markets (no single market dominating)
 *     is GLOBAL (works for everyone) -> global.json
 *   - A hostname whose usage is concentrated in one market belongs to that market
 *     -> <market>.json (e.g. fr.json)
 *
 * On top of the per-country files, a few regional rollup files are written as the
 * UNION of their member markets (latam, apac, nordics). A domain may legitimately
 * appear in both its country file and its region file.
 *
 * Output: flat-array JSON files in /all-in-one/whitelist-json/.
 */

ini_set('memory_limit', '512M');
set_time_limit(300);

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

// Published Google Sheet, "File > Share > Publish to web > CSV".
$CSV_URL = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vSDUJ4FRIJrxAAgLBI7e1JPWwVaxB4LxS8fKrJjgRLoy-2Rf70jLNjItwPh0DCPVMzzkOje3RpaiVPN/pub?gid=1704568972&single=true&output=csv';

// Classification thresholds (tune these to shift the global/local boundary).
$NOISE_FLOOR_PCT  = 0.05; // a market counts as "real" only above 5% of the host's total clicks...
$NOISE_FLOOR_MIN  = 3;    // ...or at least this many clicks, whichever is larger.
$DOMINANCE_MAX    = 0.80; // if one market holds >= 80% of clicks, the host belongs to that market.
$GLOBAL_MIN_MKTS  = 2;    // a host is global if it has real usage in at least this many markets...
$SPREAD_GLOBAL_MAX = 0.50; // ...or if no single market even reaches 50% of total clicks.

// Regional rollups: each region file is the union of these (uppercase) market codes.
// NOTE: the Nordic region is named "nordics" so it does not collide with se.json (Sweden).
$regions = [
    'latam'   => ['BR', 'MX', 'AR', 'CO', 'CL', 'PE', 'VE', 'EC', 'GT', 'CU', 'BO', 'DO', 'HN', 'PY', 'SV', 'NI', 'CR', 'PA', 'UY', 'PR'], // Latin America (incl. Brazil)
    'apac'    => ['SG', 'MY', 'ID', 'TH', 'VN', 'PH', 'IN', 'JP', 'KR', 'CN', 'TW', 'HK', 'NZ'], // Asia-Pacific
    'nordics' => ['SE', 'DK', 'FI', 'NO'], // Scandinavia / Nordics
];

$prodDir   = __DIR__;
$outputDir = dirname($prodDir) . '/all-in-one/whitelist-json';

// ---------------------------------------------------------------------------
// Domain cleaning (kept from the original compiler)
// ---------------------------------------------------------------------------

// Robust domain cleaning function to fix typos and malformed domains
function cleanDomain($domain) {
    if (!$domain) return null;
    $domain = trim(strtolower($domain));

    // 1. Basic validation
    if (!$domain || strpos($domain, '.') === false || strpos($domain, ' ') !== false || strpos($domain, '[') !== false || strpos($domain, ']') !== false) {
        return null;
    }

    // 2. Remove leading/trailing quotes, dots, commas, slashes
    $domain = preg_replace('/^["\'\.\/]+|["\',\.\/]+$/', '', $domain);

    // 3. Detect and fix double-domain typos
    // E.g., amazon.deamazon.de -> amazon.de
    $len = strlen($domain);
    if ($len % 2 === 0) {
        $half = $len / 2;
        if (substr($domain, 0, $half) === substr($domain, $half)) {
            $domain = substr($domain, 0, $half);
        }
    }

    // Fix common double-domain typos with TLD repeating
    $duplicateBrands = ['amazon.de', 'amazon.fr', 'amazon.it', 'amazon.es', 'amazon.co.uk', 'amazon.com.br', 'amazon.nl', 'amazon.com', '5-mejores.es'];
    foreach ($duplicateBrands as $brand) {
        $brandLen = strlen($brand);
        if (substr($domain, -$brandLen * 2) === $brand . $brand) {
            $domain = substr($domain, 0, -$brandLen);
        } else {
            $parts = explode('.', $brand);
            $shortBrand = $parts[0];
            if (substr($domain, -$brandLen) === $brand && strpos($domain, $brand . $shortBrand) !== false) {
                // e.g., www.amazon.deamazon.de -> www.amazon.de
                $domain = str_replace($brand . $shortBrand, $brand, $domain);
            }
        }
    }

    return $domain;
}

// ---------------------------------------------------------------------------
// 1. Fetch the CSV
// ---------------------------------------------------------------------------

echo "Fetching CSV from published sheet...\n";

$ctx = stream_context_create([
    'http' => [
        'method'         => 'GET',
        'follow_location' => 1,
        'max_redirects'  => 10,
        'timeout'        => 60,
        'user_agent'     => 'Mozilla/5.0 (whitelist-compiler)',
    ],
    'https' => [
        'method'         => 'GET',
        'follow_location' => 1,
        'max_redirects'  => 10,
        'timeout'        => 60,
        'user_agent'     => 'Mozilla/5.0 (whitelist-compiler)',
    ],
]);

$csvContent = @file_get_contents($CSV_URL, false, $ctx);
if ($csvContent === false || trim($csvContent) === '') {
    fwrite(STDERR, "ERROR: could not fetch the CSV from {$CSV_URL}\n");
    exit(1);
}

// Strip a UTF-8 BOM if present (double-quoted so the bytes are interpreted).
$csvContent = preg_replace("/^\xEF\xBB\xBF/", '', $csvContent);

// ---------------------------------------------------------------------------
// 2. Parse rows and aggregate clicks per (hostname, market)
// ---------------------------------------------------------------------------

echo "Parsing rows and aggregating per hostname...\n";

$lines = preg_split('/\r\n|\r|\n/', $csvContent);

// Locate columns from the header so we are resilient to column reordering.
$header = str_getcsv(array_shift($lines));
$idx = ['market' => null, 'hostname' => null, 'nb_click' => null];
foreach ($header as $i => $col) {
    $key = strtolower(trim($col));
    if (array_key_exists($key, $idx)) {
        $idx[$key] = $i;
    }
}
if ($idx['market'] === null || $idx['hostname'] === null || $idx['nb_click'] === null) {
    fwrite(STDERR, "ERROR: expected columns market, hostname, nb_click. Got: " . implode(',', $header) . "\n");
    exit(1);
}

$totalByHost   = [];  // host => total clicks (including blank-market rows)
$clicksByHost  = [];  // host => [ MARKET => clicks ] (non-blank markets only)
$rowCount      = 0;
$skippedBlank  = 0;

foreach ($lines as $line) {
    if ($line === '') continue;
    $cols = str_getcsv($line);

    $rawHost = isset($cols[$idx['hostname']]) ? trim($cols[$idx['hostname']]) : '';
    if ($rawHost === '') { $skippedBlank++; continue; }

    $host = cleanDomain($rawHost);
    if (!$host) { $skippedBlank++; continue; }

    $market = isset($cols[$idx['market']]) ? strtoupper(trim($cols[$idx['market']])) : '';
    $clicks = isset($cols[$idx['nb_click']]) ? (int) $cols[$idx['nb_click']] : 0;

    $rowCount++;

    if (!isset($totalByHost[$host]))  $totalByHost[$host]  = 0;
    if (!isset($clicksByHost[$host])) $clicksByHost[$host] = [];

    $totalByHost[$host] += $clicks;
    if ($market !== '') {
        if (!isset($clicksByHost[$host][$market])) $clicksByHost[$host][$market] = 0;
        $clicksByHost[$host][$market] += $clicks;
    }
}

$hostCount = count($totalByHost);
echo "Parsed {$rowCount} data rows ({$skippedBlank} blank/invalid hostnames skipped); {$hostCount} distinct hostnames.\n";

// ---------------------------------------------------------------------------
// 3. Classify each hostname: global vs dominant market
// ---------------------------------------------------------------------------

echo "Classifying domains by usage spread...\n";

$globalSet = [];           // domain => true
$byMarket  = [];           // MARKET => [ domain => true ]

foreach ($totalByHost as $host => $total) {
    $markets = $clicksByHost[$host];

    // No country signal at all -> global.
    if (empty($markets)) {
        $globalSet[$host] = true;
        continue;
    }

    $floor = max($NOISE_FLOOR_MIN, $NOISE_FLOOR_PCT * $total);

    $nsig    = 0;
    $topMkt  = null;
    $topClk  = -1;
    foreach ($markets as $mkt => $clk) {
        if ($clk >= $floor) $nsig++;
        if ($clk > $topClk) { $topClk = $clk; $topMkt = $mkt; }
    }

    $topShare = $total > 0 ? $topClk / $total : 1.0;

    $isGlobal = ($topShare < $DOMINANCE_MAX) &&
                ($nsig >= $GLOBAL_MIN_MKTS || $topShare < $SPREAD_GLOBAL_MAX);

    if ($isGlobal) {
        $globalSet[$host] = true;
    } else {
        if (!isset($byMarket[$topMkt])) $byMarket[$topMkt] = [];
        $byMarket[$topMkt][$host] = true;
    }
}

// ---------------------------------------------------------------------------
// 4. Regional rollups (union of member markets)
// ---------------------------------------------------------------------------

$byRegion = []; // region => [ domain => true ]
foreach ($regions as $region => $members) {
    $byRegion[$region] = [];
    foreach ($members as $mkt) {
        $mkt = strtoupper($mkt);
        if (!isset($byMarket[$mkt])) continue;
        foreach ($byMarket[$mkt] as $domain => $_) {
            $byRegion[$region][$domain] = true;
        }
    }
}

// ---------------------------------------------------------------------------
// 5. Sort everything
// ---------------------------------------------------------------------------

$global = array_keys($globalSet);
sort($global);

$marketLists = [];
foreach ($byMarket as $mkt => $set) {
    $list = array_keys($set);
    sort($list);
    $marketLists[$mkt] = $list;
}
ksort($marketLists);

$regionLists = [];
foreach ($byRegion as $region => $set) {
    $list = array_keys($set);
    sort($list);
    $regionLists[$region] = $list;
}

// ---------------------------------------------------------------------------
// 6. Stats
// ---------------------------------------------------------------------------

echo "\nStats:\n";
echo "- global: " . count($global) . "\n";
foreach ($marketLists as $mkt => $list) {
    echo "- " . strtolower($mkt) . ": " . count($list) . "\n";
}
foreach ($regionLists as $region => $list) {
    echo "- {$region} (region): " . count($list) . "\n";
}

// ---------------------------------------------------------------------------
// 7. Write output JSON files
// ---------------------------------------------------------------------------

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}
// Clean up any old .json files in the output directory.
foreach (glob($outputDir . '/*.json') as $jsonFile) {
    unlink($jsonFile);
}

function writeJsonFile($filePath, $list) {
    $content = json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($filePath, $content, LOCK_EX);
}

echo "\nWriting dedicated JSON files to folder: {$outputDir}\n";

writeJsonFile($outputDir . '/global.json', $global);
echo "Written: global.json\n";

foreach ($marketLists as $mkt => $list) {
    $name = strtolower($mkt) . '.json';
    writeJsonFile($outputDir . '/' . $name, $list);
    echo "Written: {$name}\n";
}

foreach ($regionLists as $region => $list) {
    writeJsonFile($outputDir . "/{$region}.json", $list);
    echo "Written: {$region}.json\n";
}

echo "\nSuccessfully compiled whitelist files from usage analytics!\n";
