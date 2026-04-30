<?php

$baseDir = dirname(__DIR__);
$outDir = __DIR__;

$filesToMerge = [
    $baseDir . '/allow/network.json',
    $baseDir . '/allow/popup.json',
    $baseDir . '/block/domains.json',
    $baseDir . '/block/popup.json',
    $baseDir . '/block/urlfilter.json'
];

$mergedDnr = [];
$idCounter = 1;

foreach ($filesToMerge as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        if (is_array($data)) {
            foreach ($data as $rule) {
                $rule['id'] = $idCounter++;
                $mergedDnr[] = $rule;
            }
        }
    } else {
        echo "Warning: File not found: $file\n";
    }
}

$outFile = $outDir . '/dnr.json';
file_put_contents($outFile, json_encode($mergedDnr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Merged all DNR rules into dnr.json\n";
echo "Total rules processed and successfully saved: " . count($mergedDnr) . "\n";
