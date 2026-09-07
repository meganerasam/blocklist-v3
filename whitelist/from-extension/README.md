# whitelist/from-extension

Real-world whitelist data coming from the ad-block extensions themselves: for
each extension, a CSV of the domains its users have manually whitelisted, with
the number of **distinct users** per domain.

```
extension-25.csv     # 25 - Ninja Block
extension-<id>.csv   # one file per extension, <id> = the project number
```

CSV format (produced by each backend, validated again here before storing):

```
domain,count
youtube.com,3980
google.com,3398
```

## How it works

- **Producer**: each extension backend deploys the stateless endpoint
  `user-whitelisted-domains.php` (see `ENDPOINT.md` in the extension's backend
  folder). It recomputes from the DB on every call, counts users (never edits),
  only includes recently active installs (`WL_WINDOW_MONTHS`, currently 6
  months), and drops any domain below the privacy floor (`WL_MIN_USERS`,
  currently 20 distinct users) — so these public CSVs never contain personal
  browsing data.
- **Consumer**: `fetch_extension_whitelists.php` (this folder) loops over the
  `$EXTENSIONS` config array, calls each endpoint with its token, and stores
  the response as `extension-<id>.csv`. It is run daily (23:00 UTC, before the
  00:00 blocklist compile) by `.github/workflows/fetch-extension-whitelists.yml`,
  which commits whatever changed — **git history is the archive**; the servers
  store nothing.

### Fail-closed rules

Only an HTTP 200 whose body validates against the CSV contract may replace a
file. On 401/500/timeout/invalid body the existing CSV is kept untouched, the
run logs a warning annotation, and the other extensions still proceed. The
workflow only fails outright when *every* endpoint failed.

## The shared token (one-time setup)

Every extension backend uses the **same** token value: each server's
`config.php` sets the identical `$whitelist_export_token`, and the repository
secret `USER_WHITELIST_DOMAINS` (underscores — hyphens are not allowed in
secret/env names) carries it to the fetcher:

```
gh secret set USER_WHITELIST_DOMAINS --repo meganerasam/blocklist-v3
```

The token itself is **never** committed — this is a public repository; the
config only names the env var. If the token ever needs rotating, update every
server's `config.php` and the one secret together.

## Adding a new extension

1. Deploy `user-whitelisted-domains.php` on the extension's backend, with the
   shared token value as `$whitelist_export_token` in that server's `config.php`.
2. Append an entry (`id`, `name`, `url`) to `$EXTENSIONS` in
   `fetch_extension_whitelists.php`.

That's it — no new secret, no workflow change.

## Running locally

```
export USER_WHITELIST_DOMAINS='<the shared token>'
php whitelist/from-extension/fetch_extension_whitelists.php
```
