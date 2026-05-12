<?php

$baseDir = dirname(__DIR__);
$outDir = __DIR__;

// All source files for the full DNR merge
$allFiles = [
    $baseDir . '/allow/network.json',
    $baseDir . '/allow/popup.json',
    $baseDir . '/block/domains.json',
    $baseDir . '/block/popup.json',
    $baseDir . '/block/urlfilter.json'
];

// Popup files to exclude for the no-popup variant (keep allow/popup.json for overrides)
$popupFiles = [
    $baseDir . '/block/popup.json'
];

/**
 * Merge an array of DNR JSON files into a single rules array with sequential IDs.
 */
function mergeDnrFromFiles(array $files): array {
    $merged = [];
    $idCounter = 1;

    foreach ($files as $file) {
        if (file_exists($file)) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);
            if (is_array($data)) {
                foreach ($data as $rule) {
                    $rule['id'] = $idCounter++;
                    $merged[] = $rule;
                }
            }
        } else {
            echo "Warning: File not found: $file\n";
        }
    }

    return $merged;
}

// 1. Full DNR (all rules)
$fullDnr = mergeDnrFromFiles($allFiles);
file_put_contents($outDir . '/dnr.json', json_encode($fullDnr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Merged all DNR rules into dnr.json\n";
echo "Total rules: " . count($fullDnr) . "\n\n";

// 2. DNR without popup rules
$noPopupFiles = array_values(array_diff($allFiles, $popupFiles));
$noPopupDnr = mergeDnrFromFiles($noPopupFiles);
file_put_contents($outDir . '/dnr-no-popup.json', json_encode($noPopupDnr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Merged DNR rules (no popup) into dnr-no-popup.json\n";
echo "Total rules: " . count($noPopupDnr) . "\n";
