# Search Console "Blocked due to other 4xx issue" — investigation and remediation

**Status:** investigation complete, legacy redirect map implemented in this branch. No production write,
no Search Console change, no deployment.
**Date:** 2026-11-16 (Australia/Brisbane).
**Session:** deepseek-flash-1017, branch `fix/seo-legacy-redirects-20261116`.
**Trigger:** Google Search Console notice [WNC-20237597] — *"New reason preventing your pages from being
indexed: Blocked due to other 4xx issue"*, plus the owner's report that "the sitemap and Google indexing
look outdated".

## 1. Verdict

The sitemap is **not** stale and is **not** the cause. The cause is a content replacement: on
2026-11-16 the site began serving a new article library at `/learning-guide/<id>/`, and the
previously published article and page URLs were dropped without redirects. They answer `404` today.
Search Console reports a *new* 4xx reason because those addresses started failing on the day of the
relaunch, not because the sitemap is out of date.

Confirming the affected URL list from Search Console itself was not possible from this session (no
Search Console access), so the retirement set below is derived from the committed production baseline
and verified against the live site.

## 2. Method

Read-only public requests only. No production write, no settings change, no GSC mutation.

```bash
curl -sI https://delnavazan.com/robots.txt
curl -s  https://delnavazan.com/sitemap_index.xml
curl -s  https://delnavazan.com/post-sitemap.xml
curl -s  https://delnavazan.com/page-sitemap.xml
curl -s  https://delnavazan.com/wp-json/wp/v2/posts?per_page=100&_fields=id,slug,link,date
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' https://delnavazan.com/<path>/
```

The comparison set is `production-seo-baseline.csv` (40 previously published posts and pages) and
`url-migration-map.csv`, both committed on 2026-10-01.

## 3. Evidence

### 3.1 The content library was replaced

| Signal | Value |
| --- | --- |
| Published posts (`wp-json/wp/v2/posts`) | **58**, every one dated 2026-11-16 |
| Article URL pattern | `/learning-guide/<post_id>/` |
| Baseline articles still reachable at their old slug | **0 of 19** |
| Baseline articles present in the current post inventory | **0 of 19** |
| Category archive | `/category/learning-guide/` (the previous `/category/موسیقی-ایرانی/` is 404) |

### 3.2 Previously published URLs now returning 4xx

| Group | URLs | Live response today |
| --- | --- | --- |
| Baseline articles (19) | e.g. `/tar-in-guitar-persian-roots/`, `/tarikhche-tahavol-sakhtari-setar/`, `/کنسرت-بیداد…/` | `404`, no redirect |
| `/ostad/`, `/honarjo/`, `/how/`, `/info/`, `/pay/` | 5 | `404`, no redirect |
| `/payment-success/`, `/booking-received/`, `/booking-cancelled/`, `/cancellation-help/` | 4 | `404`, no redirect |
| `/در-مورد-ما/`, `/نیما-زارعی-مدرس-تار-و-سه‌تار/` | 2 | `404`, no redirect |
| Previous category archive | 1 | `404`, no redirect |
| **Total** | **31** | `404` |

Already handled before this branch, and unchanged:

| URL | Response |
| --- | --- |
| `/terms/` | `301` → `/terms-and-conditions/` (`theme/inc/legal.php`) |
| `/refund_returns/` | `301` → `/cancellation-and-refunds/` (`theme/inc/legal.php`) |
| `/booking/` | `302` → `/enrol/` (`theme/inc/routes.php`) |
| `/`, `/articles/`, `/enrol/`, `/privacy-policy/` | `200` |

### 3.3 What is healthy — and why the sitemap only *looks* outdated

| Control | Observed |
| --- | --- |
| `robots.txt` | advertises `Sitemap: https://delnavazan.com/sitemap_index.xml` |
| `sitemap_index.xml` | post, page and category sitemaps, `lastmod` = 2026-11-16T03:05Z (the same minute as the newest post) |
| `post-sitemap.xml` | 59 `<loc>` entries = 58 posts + homepage, all `200` |
| `page-sitemap.xml` | 7 entries (homepage, `/articles/`, 5 legal pages), all `200` |
| `category-sitemap.xml` | 1 entry, `200` |
| Homepage head | title, meta description, self canonical, `index` directive, Organization + WebSite JSON-LD |
| Article head | `BlogPosting` JSON-LD, meta description, self canonical, `index` directive |
| `404` response | real `404` status, `noindex` |

So every URL the sitemap advertises works. Google's "outdated" index is the **old** URL set, which is
exactly what the 4xx notice is about. Nothing is required in the sitemap itself; the fix is to resolve
the retired addresses and re-crawl.

## 4. Remediation in this branch

`theme/inc/legacy-redirects.php`, loaded from `theme/functions.php`:

- one explicit retired-path → live-target map covering all 31 retired URLs above;
- `template_redirect` priority `1`, which runs after the legal redirect at priority `0`; the map
  deliberately excludes `/terms/` and `/refund_returns/` so the two surfaces never compete;
- `301` through `wp_safe_redirect()`, `GET` and `HEAD` only, never for an admin request;
- **only while WordPress answers `404`** — if an editor recreates a page at a retired path, the real
  response wins and the map becomes inert for that path;
- the map is filterable (`dzn_theme_legacy_redirect_map`) so a future retirement is one line, not a new
  mechanism;
- a numeric rule: a `/learning-guide/<id>/` request that no longer resolves redirects to the post's
  current canonical address. It is inert while the permalink structure still produces the id URL, and it
  is the safety net for the URL improvement recommended in §6.1.

It writes no titles, descriptions, canonicals, structured data or sitemap entries. Rank Math keeps every
piece of SEO metadata; `theme/inc/crawl-guard.php` keeps its separate public/non-public authority.

Redirect targets were chosen as the closest surviving surface, not by topic invention:

| Retired group | Target | Reason |
| --- | --- | --- |
| 19 articles | `/articles/` | the article library moved to the hub; no retired article has a one-to-one successor |
| `/how/` | `/articles/` | the "first class" guidance is now article content |
| `/pay/`, `/payment-success/`, `/booking-received/`, `/booking-cancelled/` | `/enrol/` | the booking experience is the surviving entry point |
| `/cancellation-help/` | `/cancellation-and-refunds/` | the published cancellation policy |
| `/ostad/`, `/honarjo/`, `/info/`, `/در-مورد-ما/`, teacher profile | `/` | no equivalent page exists on the relaunched site |
| previous category archive | `/category/learning-guide/` | the single surviving category |

## 5. Verification checklist after deploy

Each retired URL must answer `301` to the target in §4, and each target must still answer `200`:

```bash
curl -s -o /dev/null -w '%{http_code} -> %{redirect_url}\n' https://delnavazan.com/pay/
curl -s -o /dev/null -w '%{http_code} -> %{redirect_url}\n' https://delnavazan.com/tarikhche-tahavol-sakhtari-setar/
curl -s -o /dev/null -w '%{http_code} -> %{redirect_url}\n' https://delnavazan.com/cancellation-help/
curl -s -o /dev/null -w '%{http_code}\n' https://delnavazan.com/articles/
curl -s -o /dev/null -w '%{http_code}\n' https://delnavazan.com/enrol/
curl -s -o /dev/null -w '%{http_code}\n' https://delnavazan.com/terms/          # 301, unchanged owner
curl -s -o /dev/null -w '%{http_code}\n' https://delnavazan.com/learning-guide/80/   # 200, numeric rule inert
```

Then, in Search Console (owner action, not an agent action):

1. Open the "Blocked due to other 4xx issue" report and confirm the listed URLs are the retired set.
2. Re-submit `sitemap_index.xml` (robots.txt already advertises it).
3. Ask for validation once the redirects are live; retired URLs with no successor age out of the index.

## 6. Recommendations not implemented here

### 6.1 Descriptive article URLs (highest visibility impact)

Every article already carries a good Persian slug (`دف-و-نسل-بعد-…`) that the URL discards in favour of
the post id. Moving the permalink structure to `/learning-guide/%postname%/` puts the topic in the URL and
makes the address stable across re-imports. This is a WordPress setting, so it is an operator action, and
it must be done knowing that:

- the numeric rule in this branch 301s every previously published id URL to the new slug, so no index
  entry is lost by the move;
- `post_name` values are already percent-encoded by WordPress for non-Latin titles, so the resulting
  addresses are the same byte pattern the 2026-10-01 baseline recorded — no new encoding problem;
- the change needs one production deploy of this branch **before** the setting is switched.

Not executed here: production settings are read-only for agents.

### 6.2 The `/enrol/` landing page has no canonical

`/enrol/` is the one historically ranked commercial page. It answers `200` and is correctly indexable,
but because the virtual route renders before a WordPress page object exists, Rank Math cannot apply the
page's title, description and canonical: the response carries the theme title, no meta description and
**no canonical link**. Rank Math's page metadata for that URL is retained on production but unused.

`/login/`, `/admin-operations/` and the other protected routes are already covered: they send
`X-Robots-Tag: noindex, nofollow` with `no-store`, which outranks their `index` meta tag. Restoring the
`/enrol/` canonical is the crawl-hygiene change tracked as its own PR; the full Rank Math ownership of
that page is audit decision R4 in `SEO-MIGRATION-AUDIT.md`.

### 6.3 Magazine hub pagination

The hub renders a Query Loop, so pages 2–7 are `/articles/?query-8-page=N` and **every** page
canonicalises to `/articles/`. Pages 2–7 are therefore treated as duplicates of page 1. Discovery still
works — all 58 articles are in the sitemap and the category archive paginates with self canonicals and
`rel="next"` — but the hub's internal linking is weaker than it should be.

### 6.4 Smaller items

| Item | Observed | Suggested |
| --- | --- | --- |
| `og:locale` | `en_US` on a `lang="fa-IR"` site | set the WordPress site language to Persian, or map the locale |
| `404` document title | `Page Not Found - دلنوازان` (English) | localise the 404 template title |
| Tag archives | never existed on the new site (`404`) | no action; keep them out of the sitemap |

## 7. Explicit non-actions

- Production was read only (public `GET`); no settings, content, plugin, redirect or DNS change.
- Search Console was not accessed; the affected URL list is derived and must be confirmed there.
- No deployment, no merge, and no change to `theme/inc/crawl-guard.php`, Booking, portal or legal code.
- No SEO metadata was added: Rank Math remains the single authority for titles, descriptions,
  canonicals, schema and sitemaps.
