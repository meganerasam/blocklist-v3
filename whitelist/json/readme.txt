========================================================================
WONDER BLOCKER WHITELIST STRUCTURE & SEPARATION LOGIC
========================================================================

This folder contains the cleaned, deduplicated, and categorized whitelisted 
domains extracted from the 5 raw sources (whitelist1.js, whitelist2.js, 
whitelist3.js, whitelist4.txt, whitelist5.txt).

All files are outputted in direct, raw JSON format as flat arrays of domains.
They are sorted alphabetically and contain absolutely no JavaScript wrappers 
or inline comments, making them highly efficient and compatible with modern 
blocker configurations.

Total unique domains collected and processed: 21,820


------------------------------------------------------------------------
1. SEPARATION LOGIC & TIERS
------------------------------------------------------------------------

The compiler uses three progressive tiers to classify each unique domain:

TIER 1: GLOBAL CORE SERVICE BRANDS (Strict Keyword/Brand Matching)
- Any domain containing essential global services or platform keywords is 
  automatically routed to "global_core.json".
- Example keywords: google, apple, microsoft, stripe, paypal, chatgpt, 
  amazon, netflix, etc.

TIER 2: COUNTRY & REGION-SPECIFIC LOCALE (TLD & Local Brand Matching)
- If the domain does not match a Tier 1 keyword, its Top-Level Domain (TLD) 
  and highly localized brand keywords are evaluated to categorize it into a 
  specific country/region file.
- Example: ".fr" goes to "fr.json", ".de" goes to "de.json", ".nl" goes to 
  "nl.json".

TIER 3: GENERAL GLOBAL FALLBACK (Fallback Category)
- If a domain is neither a Tier 1 global core service nor belongs to any 
  specific country/region rules, it falls back to "general_global.json".


------------------------------------------------------------------------
2. FILE DESCRIPTIONS & CONTENT
------------------------------------------------------------------------

- global_core.json
  Contains essential infrastructure, cloud, authentication, search, and 
  operating system domains (e.g., Apple, Google, Microsoft, Stripe, PayPal, 
  OpenAI, Zoom).
  
- general_global.json
  A general fallback file containing non-infrastructure global domains, 
  highly popular multi-national websites, generic TLDs (.com, .net, .org, 
  .info, etc.) that do not belong to localized country tiers.

- fr.json (France & French Territories)
  Domains ending in .fr, .re, .yt, or matching major French brands (like 
  Leboncoin, Cdiscount, Fnac, Darty, Boulanger, ManoMano).

- de.json (Germany)
  Domains ending in .de, or matching major German brands (like Otto, ADAC).

- it.json (Italy)
  Domains ending in .it.

- es.json (Spain)
  Domains ending in .es.

- nl.json (Netherlands & Belgium)
  Domains ending in .nl, .be, or matching major local brands (like Bol.com, 
  123inkt, 123accu, Albert Heijn).

- uk.json (United Kingdom)
  Domains ending in .co.uk, .org.uk, .me.uk.

- se.json (Scandinavia & Nordics)
  Domains ending in .se (Sweden), .dk (Denmark), .no (Norway), .fi (Finland), 
  .is (Iceland), or matching local Nordic brands (like Elgiganten, Apotea, 
  Apoteket).

- au.json (Australia & New Zealand)
  Domains ending in .com.au, .net.au, .co.nz, or matching local brands 
  (like Bunnings, Amaysim).

- br.json (Brazil)
  Domains ending in .com.br, .net.br.

- latam.json (Latin America - excluding Brazil)
  Domains ending in .com.mx (Mexico), .cl (Chile), .co (Colombia), .ar (Argentina), 
  .pe (Peru), or matching localized LATAM brands (like MercadoLibre, Falabella).

- apac.json (Asia-Pacific & India)
  Domains ending in .cn, .hk, .tw, .jp, .kr, .in, .sg, .ph, .id, .vn, or 
  matching major APAC platforms (like Taobao, Tmall, JD, Temu, Shopee, 
  Lazada, Flipkart, Myntra, Coupang).

========================================================================
