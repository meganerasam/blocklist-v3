========================================================================
WONDER BLOCKER WHITELIST STRUCTURE & SEPARATION LOGIC
========================================================================

These files are compiled by ../../whitelist/generate_whitelist_files.php
from a single published Google Sheet (CSV) of real usage analytics with
the columns:

    market, hostname, nb_click

Each hostname appears once per market it was used in, together with a
click count. All output files are flat JSON arrays of domains, sorted
alphabetically, deduplicated, with no comments or wrappers.


------------------------------------------------------------------------
1. SEPARATION LOGIC (DERIVED FROM USAGE, NOT GUESSED FROM TLD)
------------------------------------------------------------------------

Scope is derived from how each hostname's usage is SPREAD across markets,
not from its market label or TLD. For every hostname all of its rows are
aggregated (total clicks + clicks per market), then:

  - A "noise floor" of max(3 clicks, 5% of the hostname's total) filters
    out stray cross-market clicks.

  - GLOBAL: the hostname is used in 2+ markets above the noise floor AND
    no single market holds 80%+ of its clicks (or no market even reaches
    50%). These are services used everywhere -> global.json
    (e.g. chatgpt.com, google.com, booking.com).

  - COUNTRY: otherwise the hostname's usage is concentrated in one market,
    and it is written to that market's file (e.g. amazon.fr -> fr.json,
    sncf-connect.com -> fr.json, amazon.com -> us.json).

  - Hostnames seen only with a blank market (no country attribution) are
    treated as GLOBAL.

Thresholds live at the top of generate_whitelist_files.php and can be
tuned to shift the global/country boundary.


------------------------------------------------------------------------
2. FILE DESCRIPTIONS
------------------------------------------------------------------------

- global.json
  Domains used broadly across markets - relevant to everyone regardless
  of country.

- Country files (one per market code present in the data):
  at, br, ca, ch, de, dk, es, fi, fr, hk, in, it, mx, nl, no, se, sg,
  tw, uk, us.
  Each contains the domains whose usage is concentrated in that market.

- Regional rollup files (UNION of their member country files):
    latam.json   = br + mx
    apac.json    = sg + in + tw + hk
    nordics.json = se + dk + fi + no
  A domain may legitimately appear in BOTH its country file and its region
  file (e.g. mercadolivre.com.br is in both br.json and latam.json). The
  Nordic region is named "nordics" so it does not collide with se.json
  (Sweden).


------------------------------------------------------------------------
3. REGENERATING
------------------------------------------------------------------------

Run manually (not part of the daily GitHub Action):

    cd ../../whitelist
    php generate_whitelist_files.php

The script fetches the published CSV, recompiles, and overwrites every
*.json file in this folder.

========================================================================
