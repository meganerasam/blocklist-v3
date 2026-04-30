<?php

$baseDir = dirname(__DIR__);
$subdirs = ['adult', 'easylist', 'easyprivacy', 'fanboy'];
$outDir = __DIR__;

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

// 3. Helper to merge DNR JSON arrays
function mergeDnrFiles($subdirs, $baseDir, $folder, $filename, $outFile) {
    $merged = [];
    $idCounter = 1;
    
    foreach ($subdirs as $dir) {
        $path = $baseDir . '/' . $dir . '/' . $folder . '/' . $filename;
        if (file_exists($path)) {
            $content = file_get_contents($path);
            $data = json_decode($content, true);
            if (is_array($data)) {
                foreach ($data as $rule) {
                    $rule['id'] = $idCounter++;
                    $merged[] = $rule;
                }
            }
        }
    }
    
    file_put_contents($outFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "Merged $folder/$filename (Total rules: " . count($merged) . ")\n";
}

// Merge allow files
mergeDnrFiles($subdirs, $baseDir, 'allow', 'network.json', $outAllow . '/network.json');
mergeDnrFiles($subdirs, $baseDir, 'allow', 'popup.json', $outAllow . '/popup.json');

// Merge block files
mergeDnrFiles($subdirs, $baseDir, 'block', 'domains.json', $outBlock . '/domains.json');
mergeDnrFiles($subdirs, $baseDir, 'block', 'popup.json', $outBlock . '/popup.json');
mergeDnrFiles($subdirs, $baseDir, 'block', 'urlfilter.json', $outBlock . '/urlfilter.json');

// 4. Merge CSS files
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
