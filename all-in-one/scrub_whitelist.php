<?php
/**
 * scrub_whitelist.php — "do-not-block" whitelist guard
 *
 * Fetches a plain-text list of domains (one per line) and provides a scrub
 * function that guarantees none of those domains can be matched by a block
 * rule. This is NOT an allow list: no allow rules are created — the domains
 * are simply removed from the block rules themselves.
 *
 * How a whitelisted domain W is protected:
 *   1. requestDomains entries equal to W (or living under W, e.g. cdn.W)
 *      are removed from the rule; a rule left with no domains is dropped.
 *   2. urlFilter rules anchored on W or a subdomain of W (||W^..., ||x.W/...)
 *      are dropped entirely.
 *   3. If a rule blocks a PARENT of W (e.g. blocklist has example.com while
 *      W = app.example.com), the parent stays blocked but W is carved out
 *      via excludedRequestDomains, so W keeps working.
 *
 * Generic path-only urlFilter rules (e.g. "/ads/banner*") are left alone:
 * they are not tied to a specific domain.
 */

/**
 * Fetch and parse the whitelist. Returns a set (domain => true) with
 * lowercased, cleaned hostnames, or false if the download failed.
 */
function fetchWhitelistDomains(string $url) {
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'follow_location' => 1,
            'max_redirects'   => 10,
            'timeout'         => 60,
            'user_agent'      => 'Mozilla/5.0 (blocklist-compiler)',
        ],
        'https' => [
            'method'          => 'GET',
            'follow_location' => 1,
            'max_redirects'   => 10,
            'timeout'         => 60,
            'user_agent'      => 'Mozilla/5.0 (blocklist-compiler)',
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || trim($raw) === '') {
        return false;
    }

    $domains = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = strtolower(trim($line));
        if ($line === '' || $line[0] === '#' || $line[0] === '!') continue;
        // Tolerate pasted URLs: strip scheme, path, port, query.
        $line = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $line);
        $line = preg_replace('#[/:?].*$#', '', $line);
        $line = trim($line, '.');
        if ($line === '' || strpos($line, '.') === false) continue;
        $domains[$line] = true;
    }

    return $domains;
}

/**
 * Strict parent suffixes of a domain, keeping at least 2 labels.
 * "a.b.example.com" -> ["b.example.com", "example.com"]
 */
function domainAncestors(string $domain): array {
    $labels = explode('.', $domain);
    $out = [];
    for ($i = 1; $i <= count($labels) - 2; $i++) {
        $out[] = implode('.', array_slice($labels, $i));
    }
    return $out;
}

/**
 * True if $domain is a whitelisted domain or lives under one.
 */
function isWhitelistCovered(string $domain, array $whitelist): bool {
    if (isset($whitelist[$domain])) return true;
    foreach (domainAncestors($domain) as $parent) {
        if (isset($whitelist[$parent])) return true;
    }
    return false;
}

/**
 * Scrub an array of DNR rules so no whitelisted domain can be blocked.
 * $stats accumulates: domainsRemoved, rulesDropped, exclusionsAdded.
 */
function scrubBlockRules(array $rules, array $whitelist, array &$stats): array {
    $out = [];

    foreach ($rules as $rule) {
        $isBlock = isset($rule['action']['type']) && $rule['action']['type'] === 'block';
        if (!$isBlock || !isset($rule['condition']) || empty($whitelist)) {
            $out[] = $rule;
            continue;
        }

        $cond = $rule['condition'];

        if (isset($cond['requestDomains']) && is_array($cond['requestDomains'])) {
            // 1. Remove whitelisted domains (and their subdomains) from the batch.
            $kept = [];
            foreach ($cond['requestDomains'] as $d) {
                if (isWhitelistCovered(strtolower($d), $whitelist)) {
                    $stats['domainsRemoved']++;
                    continue;
                }
                $kept[] = $d;
            }
            if (empty($kept)) {
                $stats['rulesDropped']++;
                continue;
            }
            $cond['requestDomains'] = $kept;

            // 2. If a kept domain is a PARENT of a whitelisted domain, carve
            //    the whitelisted domain out of the block via exclusion.
            $keptSet = array_flip(array_map('strtolower', $kept));
            $carve = [];
            foreach ($whitelist as $w => $_) {
                foreach (domainAncestors($w) as $parent) {
                    if (isset($keptSet[$parent])) {
                        $carve[$w] = true;
                        break;
                    }
                }
            }
            if (!empty($carve)) {
                $excl = isset($cond['excludedRequestDomains']) ? $cond['excludedRequestDomains'] : [];
                foreach (array_keys($carve) as $w) {
                    if (!in_array($w, $excl, true)) {
                        $excl[] = $w;
                        $stats['exclusionsAdded']++;
                    }
                }
                sort($excl);
                $cond['excludedRequestDomains'] = $excl;
            }
        } elseif (isset($cond['urlFilter']) && strpos($cond['urlFilter'], '||') === 0) {
            // Domain-anchored urlFilter: ||domain^path, ||domain/path, ...
            if (preg_match('/^\|\|([a-z0-9.-]+)/i', $cond['urlFilter'], $m)) {
                $anchor = strtolower(trim($m[1], '.'));

                if (isWhitelistCovered($anchor, $whitelist)) {
                    $stats['rulesDropped']++;
                    continue;
                }

                // Anchor is a PARENT of a whitelisted domain: keep the rule
                // but exclude the whitelisted domain from matching it.
                $carve = [];
                foreach ($whitelist as $w => $_) {
                    if (in_array($anchor, domainAncestors($w), true)) {
                        $carve[$w] = true;
                    }
                }
                if (!empty($carve)) {
                    $excl = isset($cond['excludedRequestDomains']) ? $cond['excludedRequestDomains'] : [];
                    foreach (array_keys($carve) as $w) {
                        if (!in_array($w, $excl, true)) {
                            $excl[] = $w;
                            $stats['exclusionsAdded']++;
                        }
                    }
                    sort($excl);
                    $cond['excludedRequestDomains'] = $excl;
                }
            }
        }

        $rule['condition'] = $cond;
        $out[] = $rule;
    }

    return $out;
}
