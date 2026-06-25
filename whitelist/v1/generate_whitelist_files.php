<?php
/**
 * PHP Compiler Script for Whitelists
 * Extracts, cleans, deduplicates, and splits domains from whitelist1-5 into dedicated files.
 * Output: dedicated JSON files for global_core, general_global, and each country in /all-in-one/whitelist-json/ folder.
 */

ini_set('memory_limit', '512M');
set_time_limit(300);

$prodDir = __DIR__;
// Put inside the all-in-one/whitelist-json folder relative to this directory
$outputDir = dirname($prodDir) . '/all-in-one/whitelist-json';

// Create output folder if it doesn't exist
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

// Clean up any old .json files in the output directory
if (is_dir($outputDir)) {
    foreach (glob($outputDir . '/*.json') as $jsonFile) {
        unlink($jsonFile);
    }
}

$whitelist1Path = $prodDir . '/whitelist1.js';
$whitelist2Path = $prodDir . '/whitelist2.js';
$whitelist3Path = $prodDir . '/whitelist3.js';
$whitelist4Path = $prodDir . '/whitelist4.txt';
$whitelist5Path = $prodDir . '/whitelist5.txt';

echo "Reading files...\n";

$whitelist1Content = file_get_contents($whitelist1Path);
$whitelist2Content = file_get_contents($whitelist2Path);
$whitelist3Content = file_get_contents($whitelist3Path);
$whitelist4Content = file_get_contents($whitelist4Path);
$whitelist5Content = file_get_contents($whitelist5Path);

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

// Parse comments/metadata from whitelist1.js
$domainComments = [];
$lines = preg_split('/\r?\n/', $whitelist1Content);
foreach ($lines as $line) {
    $line = trim($line);
    if (!$line) continue;
    if (preg_match('/^([\'"])(.*?)\1/', $line, $domainMatch)) {
        $domain = trim(strtolower($domainMatch[2]));
        $comment = '';
        $commentIndex = strpos($line, '//');
        if ($commentIndex !== false) {
            $comment = trim(substr($line, $commentIndex));
        }
        if ($comment) {
            $domainComments[$domain] = $comment;
        }
    }
}

// Collect unique domains in a hash map for fast O(1) deduplication
$allDomains = [];

function extractFromJs($content, &$allDomains) {
    preg_match_all('/(["\'])(.*?)\1/', $content, $matches);
    if (!empty($matches[2])) {
        foreach ($matches[2] as $match) {
            $cleaned = cleanDomain($match);
            if ($cleaned) {
                $allDomains[$cleaned] = true;
            }
        }
    }
}

function extractFromTxt($content, &$allDomains) {
    $lines = preg_split('/\r?\n/', $content);
    foreach ($lines as $line) {
        $line = trim($line);
        if (!$line || strpos($line, '//') === 0 || strpos($line, '#') === 0) continue;
        $cleaned = cleanDomain($line);
        if ($cleaned) {
            $allDomains[$cleaned] = true;
        }
    }
}

echo "Extracting domains...\n";
extractFromJs($whitelist1Content, $allDomains);
extractFromJs($whitelist2Content, $allDomains);
extractFromJs($whitelist3Content, $allDomains);
extractFromTxt($whitelist4Content, $allDomains);
extractFromTxt($whitelist5Content, $allDomains);

$uniqueCount = count($allDomains);
echo "Total unique domains collected across all 5 files: {$uniqueCount}\n";

// Define Global Core Brands/Keywords (including their localized variations)
$globalCoreKeywords = [
    'google', 'gmail', 'gstatic', 'googleapis', 'googleusercontent', 'goog',
    'youtube', 'youtu.be', 'ytimg', 'ggpht', 'googlevideo', 'youtubei',
    'microsoft', 'office', 'outlook', 'onedrive', 'live.com', 'windows', '1drv', 'msauth', 'msftauth', 'teams.microsoft', 'microsoft365',
    'apple', 'icloud', 'itunes',
    'slack', 'discord', 'github', 'gitlab', 'trello', 'asana', 'notion', 'figma', 'miro', 'dropbox', 'box.com', 'salesforce', 'zoom.us', 'zoom.com',
    'stripe', 'paypal', 'adyen', 'checkout.com', 'visa.com', 'mastercard.com', 'cardinalcommerce',
    'chatgpt', 'claude.ai', 'openai', 'midjourney', 'copilot', 'deepseek', 'groq', 'anthropic', 'huggingface',
    'netflix', 'spotify', 'hulu', 'disneyplus',
    'instagram', 'facebook', 'fbcdn', 'tiktok', 'twitter', 'x.com',
    'amazon', 'ebay', 'wikipedia', 'stackoverflow', 'adobe', 'canva'
];

// Initialize category containers
$globalCore = [];
$byCountry = [
    'fr' => [],
    'de' => [],
    'it' => [],
    'es' => [],
    'nl' => [], // Netherlands & Belgium
    'uk' => [],
    'se' => [], // Scandinavia
    'au' => [], // Australia & New Zealand
    'br' => [],
    'latam' => [], // Latin America (excluding Brazil)
    'apac' => [] // Asia-Pacific & India
];
$generalGlobal = [];

// Helper to check if a domain contains any keyword
function containsAny($domain, $keywords) {
    foreach ($keywords as $kw) {
        if (strpos($domain, $kw) !== false) {
            return true;
        }
    }
    return false;
}

// Helper to check if domain ends with
function endsWith($domain, $suffix) {
    return substr($domain, -strlen($suffix)) === $suffix;
}

echo "Classifying domains...\n";
foreach (array_keys($allDomains) as $domain) {
    // 1. Check if it matches any global core keywords (Option A - goes strictly to Global Core)
    if (containsAny($domain, $globalCoreKeywords)) {
        $globalCore[] = $domain;
        continue;
    }

    // 2. Country/Region Check
    // France
    if (endsWith($domain, '.fr') || endsWith($domain, '.re') || endsWith($domain, '.yt') || 
        containsAny($domain, ['leboncoin', 'cdiscount', 'fnac', 'darty', 'boulanger', 'manomano'])) {
        $byCountry['fr'][] = $domain;
        continue;
    }

    // Germany
    if (endsWith($domain, '.de') || containsAny($domain, ['otto', 'adac'])) {
        $byCountry['de'][] = $domain;
        continue;
    }

    // Italy
    if (endsWith($domain, '.it')) {
        $byCountry['it'][] = $domain;
        continue;
    }

    // Spain
    if (endsWith($domain, '.es')) {
        $byCountry['es'][] = $domain;
        continue;
    }

    // Netherlands & Belgium
    if (endsWith($domain, '.nl') || endsWith($domain, '.be') || containsAny($domain, ['bol.com', '123inkt', '123accu', 'ah.nl'])) {
        $byCountry['nl'][] = $domain;
        continue;
    }

    // United Kingdom
    if (endsWith($domain, '.co.uk') || endsWith($domain, '.org.uk') || endsWith($domain, '.me.uk')) {
        $byCountry['uk'][] = $domain;
        continue;
    }

    // Scandinavia
    if (endsWith($domain, '.se') || endsWith($domain, '.dk') || endsWith($domain, '.no') || endsWith($domain, '.fi') || endsWith($domain, '.is') || 
        containsAny($domain, ['elgiganten', 'apotea', 'apoteket'])) {
        $byCountry['se'][] = $domain;
        continue;
    }

    // Australia & New Zealand
    if (endsWith($domain, '.com.au') || endsWith($domain, '.net.au') || endsWith($domain, '.co.nz') || 
        containsAny($domain, ['bunnings', 'amaysim'])) {
        $byCountry['au'][] = $domain;
        continue;
    }

    // Brazil
    if (endsWith($domain, '.com.br') || endsWith($domain, '.net.br')) {
        $byCountry['br'][] = $domain;
        continue;
    }

    // Latin America
    if (endsWith($domain, '.com.mx') || endsWith($domain, '.cl') || endsWith($domain, '.co') || endsWith($domain, '.ar') || endsWith($domain, '.pe') || 
        containsAny($domain, ['mercadolibre', 'falabella'])) {
        $byCountry['latam'][] = $domain;
        continue;
    }

    // Asia-Pacific & India
    if (endsWith($domain, '.cn') || endsWith($domain, '.hk') || endsWith($domain, '.tw') || endsWith($domain, '.jp') || endsWith($domain, '.kr') || endsWith($domain, '.in') || endsWith($domain, '.sg') || endsWith($domain, '.ph') || endsWith($domain, '.id') || endsWith($domain, '.vn') || 
        containsAny($domain, ['taobao', 'tmall', 'jd.com', 'temu', 'shopee', 'lazada', 'flipkart', 'myntra', 'coupang'])) {
        $byCountry['apac'][] = $domain;
        continue;
    }

    // 3. Fallback to General Global
    $generalGlobal[] = $domain;
}

// Sort all lists alphabetically
sort($globalCore);
sort($generalGlobal);
foreach ($byCountry as $lang => &$list) {
    sort($list);
}
unset($list); // clean reference

echo "\nStats:\n";
echo "- globalCore: " . count($globalCore) . "\n";
foreach ($byCountry as $lang => $list) {
    echo "- byCountry.{$lang}: " . count($list) . "\n";
}
echo "- generalGlobal: " . count($generalGlobal) . "\n";

// Helper function to write a JSON file
function writeJsonFile($filePath, $list) {
    $content = json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($filePath, $content, LOCK_EX);
}

echo "\nWriting dedicated JSON files to folder: {$outputDir}\n";

// Write global_core.json
writeJsonFile($outputDir . '/global_core.json', $globalCore);
echo "Written: global_core.json\n";

// Write general_global.json
writeJsonFile($outputDir . '/general_global.json', $generalGlobal);
echo "Written: general_global.json\n";

// Write each country file
foreach ($byCountry as $lang => $list) {
    writeJsonFile($outputDir . "/{$lang}.json", $list);
    echo "Written: {$lang}.json\n";
}

echo "\nSuccessfully completed whitelist merge, categorization, and dedicated JSON file splitting!\n";
