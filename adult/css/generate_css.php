<?php
/**
 * generate_css.php — UNIFIED CSS COSMETIC FILTER GENERATOR (EasyList Adult)
 * Location: all-in-one/adult/css/
 *
 * Fetches EasyList Adult cosmetic/element-hiding sources and converts them
 * into injectable CSS files for a browser extension.
 *
 * SOURCES:
 *   (Currently no cosmetic filter lists provided for EasyList Adult,
 *    but architecture is maintained for future additions).
 *
 * OUTPUT FILES (all in the same directory):
 *   generic.css
 *     → All selectors combined into one CSS rule with a single
 *       { display: none !important; } declaration.
 *
 *   specific.json
 *     → Domain-to-selectors mapping for per-site element hiding.
 *       Format: { "domain.com": ["#ad1", ".banner"], ... }
 *
 *   extended.json
 *     → Extended CSS rules that require JavaScript processing (#?#).
 *       Format: { "domain.com": [{ "selector": "...", "type": "..." }], ... }
 *
 * Usage:
 *   php generate_css.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ─── Configuration ────────────────────────────────────────────────────

$sourceUrls = [
    // Add EasyList Adult CSS filter list URLs here if they become available
];

// Output files (all in the same directory as this script)
$outputFiles = [
    'generic'  => __DIR__ . '/generic.css',
    'specific' => __DIR__ . '/specific.json',
    'extended' => __DIR__ . '/extended.json',
];

// ─── Fetch all sources ────────────────────────────────────────────────
$allLines  = [];
$totalDown = 0;

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║       UNIFIED CSS GENERATOR — EasyList Adult                ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

if (empty($sourceUrls)) {
    echo "No source URLs configured. Generating empty output files...\n\n";
} else {
    echo "Fetching filter lists...\n";
    foreach ($sourceUrls as $i => $url) {
        $num = $i + 1;
        $filename = basename($url);
        echo "  [$num/" . count($sourceUrls) . "] $filename\n";

        $raw = @file_get_contents($url);
        if ($raw === false) {
            fwrite(STDERR, "    ⚠ WARNING: Failed to download — skipping.\n");
            continue;
        }

        $lines = explode("\n", $raw);
        echo "    ✓ " . count($lines) . " lines\n";
        $allLines  = array_merge($allLines, $lines);
        $totalDown += count($lines);
    }
    echo "  Total: $totalDown lines from " . count($sourceUrls) . " source(s).\n\n";
}

// ─── Parse cosmetic filters ──────────────────────────────────────────

$generalSelectors  = [];   // Selectors for generic.css
$specificMap       = [];   // domain => [selectors] for specific.json
$extendedMap       = [];   // domain => [{selector, type}] for extended.json
$skipped           = 0;
$comments          = 0;

if (!empty($allLines)) {
    echo "Parsing and classifying cosmetic filters...\n";

    foreach ($allLines as $line) {
        $line = trim($line);

        if ($line === '' || (isset($line[0]) && $line[0] === '!')) {
            $comments++;
            continue;
        }

        if (strpos($line, '#@#') !== false || strpos($line, '@@') === 0 || strpos($line, '##+js') !== false) {
            $skipped++;
            continue;
        }

        if (strpos($line, '#?#') !== false) {
            $parts = explode('#?#', $line, 2);
            $domainPart = trim($parts[0]);
            $selector   = trim($parts[1]);

            if ($domainPart === '') {
                $extendedMap['*'][] = ['selector' => $selector, 'type' => 'extended'];
            } else {
                foreach (explode(',', $domainPart) as $d) {
                    if (trim($d) !== '') $extendedMap[trim($d)][] = ['selector' => $selector, 'type' => 'extended'];
                }
            }
            continue;
        }

        $hashPos = strpos($line, '##');
        if ($hashPos === false) {
            $skipped++;
            continue;
        }

        $domainPart = trim(substr($line, 0, $hashPos));
        $selector   = trim(substr($line, $hashPos + 2));

        if ($selector === '') {
            $skipped++;
            continue;
        }

        if (preg_match('/\{[^}]+\}/', $selector)) {
            if ($domainPart === '') {
                $extendedMap['*'][] = ['selector' => $selector, 'type' => 'css-injection'];
            } else {
                foreach (explode(',', $domainPart) as $d) {
                    if (trim($d) !== '') $extendedMap[trim($d)][] = ['selector' => $selector, 'type' => 'css-injection'];
                }
            }
            continue;
        }

        if ($domainPart === '') {
            $generalSelectors[$selector] = true;
            continue;
        }

        foreach (explode(',', $domainPart) as $d) {
            if (trim($d) !== '') {
                if (!isset($specificMap[trim($d)])) $specificMap[trim($d)] = [];
                $specificMap[trim($d)][$selector] = true;
            }
        }
    }
}

$generalCount  = count($generalSelectors);
$specificCount = 0;
foreach ($specificMap as $sels) $specificCount += count($sels);
$extendedCount = 0;
foreach ($extendedMap as $rules) $extendedCount += count($rules);

echo "  ✓ General selectors:  $generalCount (apply on all websites)\n";
echo "  ✓ Specific selectors: $specificCount across " . count($specificMap) . " domains\n";
echo "  ✓ Extended/ABP rules: $extendedCount across " . count($extendedMap) . " domains\n";
echo "  ○ Skipped:            $skipped\n";
echo "  ○ Comments:           $comments\n\n";

// Ensure directory exists
if (!is_dir(__DIR__)) {
    mkdir(__DIR__, 0755, true);
}

// ─── Generate generic.css ─────────────────────────────────────────────
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  Building: generic.css\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$css  = "/*\n";
$css .= " * generic.css — EasyList Adult Element Hiding\n";
$css .= " * Auto-generated by generate_css.php\n";
$css .= " * Generated: " . date('Y-m-d H:i:s T') . "\n";
$css .= " *\n";
$css .= " * Total selectors: $generalCount\n";
$css .= " */\n\n";

$selectors = array_keys($generalSelectors);
sort($selectors);
if (!empty($selectors)) {
    $css .= implode(",\n", $selectors);
    $css .= " {\n  display: none !important;\n}\n";
}

$bytes = file_put_contents($outputFiles['generic'], $css);
echo "  ✓ $generalCount selectors → generic.css ($bytes bytes)\n\n";

// ─── Generate specific.json ──────────────────────────────────────────
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  Building: specific.json\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$specificOutput = [];
ksort($specificMap);
foreach ($specificMap as $domain => $sels) {
    $selList = array_keys($sels);
    sort($selList);
    $specificOutput[$domain] = $selList;
}

$json = json_encode($specificOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$bytes = file_put_contents($outputFiles['specific'], $json . "\n");
echo "  ✓ $specificCount selectors across " . count($specificOutput) . " domains → specific.json ($bytes bytes)\n\n";

// ─── Generate extended.json ──────────────────────────────────────────
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  Building: extended.json\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

ksort($extendedMap);
$json = json_encode($extendedMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$bytes = file_put_contents($outputFiles['extended'], $json . "\n");
echo "  ✓ $extendedCount rules across " . count($extendedMap) . " domains → extended.json ($bytes bytes)\n\n";

// ─── Final summary ────────────────────────────────────────────────────
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  DONE ✓                                                     ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";

foreach ($outputFiles as $cat => $path) {
    $size = file_exists($path) ? filesize($path) : 0;
    $sizeKB = round($size / 1024);
    printf("║  %-10s → %s (%s KB)%s║\n",
        $cat,
        basename($path),
        str_pad($sizeKB, 5, ' ', STR_PAD_LEFT),
        str_repeat(' ', max(0, 20 - strlen(basename($path))))
    );
}

echo "╚══════════════════════════════════════════════════════════════╝\n";
