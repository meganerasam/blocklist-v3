<?php

require __DIR__ . '/scrub_whitelist.php';

$baseDir = dirname(__DIR__);
$subdirs = ['adult', 'easylist', 'easyprivacy', 'fanboy'];
$outDir = __DIR__;

// Domains that must NEVER end up in a block rule (not an allow list: they are
// stripped out of the block rules themselves before merging).
$WHITELIST_URL = 'https://raw.githubusercontent.com/meganerasam/whitelist-domains/refs/heads/master/whitelistes3.txt';

// 1. Run generation scripts in subdirectories
foreach ($subdirs as $dir) {
    $path = $baseDir . '/' . $dir;
    
    // allow
    if (is_dir($path . '/allow')) {
        echo "Running generate_allow.php in $dir...\n";
        exec("cd " . escapeshellarg($path . '/allow') . " && php generate_allow.php");
    }
    
    // block
    if (is_dir($path . '/block')) {
        echo "Running generate_dnr.php in $dir...\n";
        exec("cd " . escapeshellarg($path . '/block') . " && php generate_dnr.php");
    }
    
    // css
    if (is_dir($path . '/css')) {
        echo "Running generate_css.php in $dir...\n";
        exec("cd " . escapeshellarg($path . '/css') . " && php generate_css.php");
    }
}

// 2. Ensure output directories exist
$outAllow = $outDir . '/allow';
$outBlock = $outDir . '/block';
$outCss = $outDir . '/css';

if (!is_dir($outDir)) mkdir($outDir, 0777, true);
if (!is_dir($outAllow)) mkdir($outAllow, 0777, true);
if (!is_dir($outBlock)) mkdir($outBlock, 0777, true);
if (!is_dir($outCss)) mkdir($outCss, 0777, true);

// 3. Fetch the do-not-block whitelist (abort on failure so whitelisted
// domains can never leak into a freshly published block list).
echo "Fetching do-not-block whitelist...\n";
$whitelist = fetchWhitelistDomains($WHITELIST_URL);
if ($whitelist === false) {
    fwrite(STDERR, "ERROR: could not fetch the whitelist from $WHITELIST_URL — aborting merge.\n");
    exit(1);
}
echo "Loaded " . count($whitelist) . " whitelisted domains; they will be stripped from all block rules.\n";

// 4. Helper to merge DNR JSON arrays ($whitelist scrubs block rules)
function mergeDnrFiles($subdirs, $baseDir, $folder, $filename, $outFile, ?array $whitelist = null) {
    $merged = [];
    $idCounter = 1;
    $stats = ['domainsRemoved' => 0, 'rulesDropped' => 0, 'exclusionsAdded' => 0];

    foreach ($subdirs as $dir) {
        $path = $baseDir . '/' . $dir . '/' . $folder . '/' . $filename;
        if (file_exists($path)) {
            $content = file_get_contents($path);
            $data = json_decode($content, true);
            if (is_array($data)) {
                if ($whitelist !== null) {
                    $data = scrubBlockRules($data, $whitelist, $stats);
                }
                foreach ($data as $rule) {
                    $rule['id'] = $idCounter++;
                    $merged[] = $rule;
                }
            }
        }
    }

    file_put_contents($outFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "Merged $folder/$filename (Total rules: " . count($merged) . ")";
    if ($whitelist !== null && ($stats['domainsRemoved'] || $stats['rulesDropped'] || $stats['exclusionsAdded'])) {
        echo " [whitelist scrub: {$stats['domainsRemoved']} domains removed, {$stats['rulesDropped']} rules dropped, {$stats['exclusionsAdded']} exclusions added]";
    }
    echo "\n";
}

// Merge allow files
mergeDnrFiles($subdirs, $baseDir, 'allow', 'network.json', $outAllow . '/network.json');
mergeDnrFiles($subdirs, $baseDir, 'allow', 'popup.json', $outAllow . '/popup.json');

// Merge block files (scrubbed against the do-not-block whitelist)
mergeDnrFiles($subdirs, $baseDir, 'block', 'domains.json', $outBlock . '/domains.json', $whitelist);
mergeDnrFiles($subdirs, $baseDir, 'block', 'popup.json', $outBlock . '/popup.json', $whitelist);
mergeDnrFiles($subdirs, $baseDir, 'block', 'urlfilter.json', $outBlock . '/urlfilter.json', $whitelist);

// 5. Merge CSS files
// generic.css
$allSelectors = [];
foreach ($subdirs as $dir) {
    $path = $baseDir . '/' . $dir . '/css/generic.css';
    if (file_exists($path)) {
        $content = file_get_contents($path);
        $content = preg_replace('!/\*.*?\*/!s', '', $content); // remove comments
        if (strpos($content, '{') !== false) {
            $selectorsPart = substr($content, 0, strpos($content, '{'));
            $selectors = explode(',', $selectorsPart);
            foreach ($selectors as $sel) {
                $sel = trim($sel);
                if ($sel !== '') {
                    $allSelectors[$sel] = true;
                }
            }
        }
    }
}

if (!empty($allSelectors)) {
    $selectorsList = array_keys($allSelectors);
    sort($selectorsList);
    $mergedCss = "/*\n * generic.css \n * Merged from adult, easylist, easyprivacy, fanboy\n * Generated: " . date('Y-m-d H:i:s T') . "\n */\n\n";
    $mergedCss .= implode(",\n", $selectorsList);
    $mergedCss .= " {\n  display: none !important;\n}\n";
    file_put_contents($outCss . '/generic.css', $mergedCss);
    echo "Merged css/generic.css (Total selectors: " . count($selectorsList) . ")\n";
}

// specific.json, extended.json & unhide.json
function mergeCssJson($subdirs, $baseDir, $folder, $filename, $outFile) {
    $merged = [];
    foreach ($subdirs as $dir) {
        $path = $baseDir . '/' . $dir . '/' . $folder . '/' . $filename;
        if (file_exists($path)) {
            $content = file_get_contents($path);
            $data = json_decode($content, true);
            if (is_array($data)) {
                foreach ($data as $domain => $rules) {
                    if (!isset($merged[$domain])) {
                        $merged[$domain] = [];
                    }
                    $merged[$domain] = array_merge($merged[$domain], $rules);
                }
            }
        }
    }
    
    // Deduplicate array values
    foreach ($merged as $domain => &$rules) {
        $unique = [];
        $seen = [];
        foreach ($rules as $rule) {
            $hash = is_string($rule) ? $rule : json_encode($rule);
            if (!isset($seen[$hash])) {
                $seen[$hash] = true;
                $unique[] = $rule;
            }
        }
        $rules = $unique;
    }
    ksort($merged);
    file_put_contents($outFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "Merged css/$filename (Total domains: " . count($merged) . ")\n";
}

mergeCssJson($subdirs, $baseDir, 'css', 'specific.json', $outCss . '/specific.json');
mergeCssJson($subdirs, $baseDir, 'css', 'extended.json', $outCss . '/extended.json');

// Merge unhide.json using the exact same logic as CSS JSON files (associative array by domain)
mergeCssJson($subdirs, $baseDir, 'allow', 'unhide.json', $outAllow . '/unhide.json');

echo "All tasks completed successfully!\n";
