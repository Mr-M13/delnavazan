# Delnavazan SEO migration audit — production → staging

**Status:** audit and mapping only. Nothing in this branch changes the Theme, the Platform, production
or staging. No deployment, no merge, no production access.
**Date:** 2026-10-01 (Australia/Brisbane).
**Owner of next steps:** CD (import, staging settings, Rank Math configuration), Hamed (booking URL decision).

## Decisions recorded (CD, 2026-10-01)

| # | Decision | Status |
| --- | --- | --- |
| R1 | Staging must be explicitly non-indexable, and this is the only urgent item | **Implemented in this branch** as a fail-safe Theme crawl guard, plus the WordPress "Discourage search engines" setting as the primary control — see §3 |
| R2 | Do not import the WXR yet; import deliberately when the rebuilt site is ready to reconcile old content | Deferred by CD |
| R3 | Export Rank Math global settings from production **before cutover** (the WXR does not carry them) | Scheduled for pre-cutover |
| R4 | `/enrol/` is the canonical booking URL: it exists in production, is referenced by the recovered homepage and preserves SEO continuity. `/booking/` may remain as an internal/compatibility alias but must canonicalise to `/enrol/` | Decided; implementation belongs to the booking surface (Hamed) and is **not** part of this branch |
| R5 | Keep the recovered visual homepage. Do not revert to the old production page. Restore the useful SEO metadata/title/canonical behaviour separately; the H1 wording remains a product/content decision | Decided; mechanism is Rank Math homepage settings, not Theme code |
| R6 | Restore the `Sitemap:` directive in `robots.txt` at cutover | Scheduled for cutover |
| R7 | The four pending landing pages and the 94 single-use tags need a conscious content decision, but they do not block booking, student or teacher work | Deferred by CD |

## 1. Sources and method

| Source | Detail |
| --- | --- |
| Production export | `/Users/hamed/Downloads/WordPress.2026-10-01-3.xml` (938 KB), SHA-256 `f896d44449ea4c763da4d9dbeba79047ed4910ed2b1d51093861e092dcca0f71` |
| Export contents | 19 posts, 21 pages (13 published, 8 pending), 57 attachments, 2 categories, 255 tags, 13 footer-menu items, Rank Math meta on 18/19 posts |
| Production surfaces probed | `sitemap_index.xml`, `post-sitemap.xml`, `page-sitemap.xml`, `category-sitemap.xml`, `robots.txt`, homepage head |
| Staging surfaces probed | `/`, `/enrol/`, `/booking/`, `/login/`, `/dashboard/`, `/student-portal/`, `/teacher-portal/`, `/admin-operations/`, `/articles/`, `/ostad/`, `/honarjo/`, `/pay/`, `/terms/`, `/privacy-policy/`, `/refund_returns/`, two article URLs, `robots.txt`, `sitemap_index.xml`, `wp-sitemap.xml`, `wp-sitemap-posts-page-1.xml` |
| Derived artefacts | `production-seo-baseline.csv` (40 rows: every post/page with its production URL, Rank Math title, description, focus keyword, template, featured-image flag), `url-migration-map.csv` (48 rows: source URL → target → action → owner) |

Both CSV files are generated mechanically from the export plus the probes above; the export itself is
**not** committed to the repository (it contains author contact data).

## 2. Audit findings

### S1 — Staging is actively crawlable today (HIGH)

- `https://staging.delnavazan.com/robots.txt` returns `200` and advertises
  `Sitemap: https://staging.delnavazan.com/wp-sitemap.xml`.
- `https://staging.delnavazan.com/wp-sitemap.xml` returns `200` and references
  `wp-sitemap-posts-page-1.xml`, which returns `200` and lists the staging homepage.
- No probed staging URL emits `<meta name="robots">`, a canonical link or a meta description, and
  `sitemap_index.xml` returns `404` (Rank Math is not producing sitemaps on staging).

Robots.txt is a crawl *permission* file, not an indexing block. As configured, staging invites crawlers
to index a site that currently 404s most of production's indexed URLs.

### S2 — Production URLs are missing on staging (HIGH)

Sampled and confirmed `404` on staging: both article URLs tested (one Latin slug, one Persian slug),
`/articles/`, `/ostad/`, `/honarjo/`, `/pay/`, `/terms/`, `/privacy-policy/`, `/refund_returns/`.
Production serves all of these (legal pages are `pending`, so they are intentionally not yet public).
Combined with S1, an indexing event now would replace ranked URLs with not-found responses.

### S3 — Two URLs serve the same booking experience (HIGH)

- `/enrol/` → `200`, `<title>درخواست جلسهٔ معارفه – Delnavazan</title>`
- `/booking/` → `200`, identical title
- Neither emits a canonical link.

Production's `/enrol/` is an indexed landing page with its own Rank Math title
(`ثبت نام کلاس موسیقی ایرانی | دلنوازان`), description and four focus keywords. On staging the virtual
route in `theme/inc/routes.php` intercepts `enrol` and renders the booking experience with
`status_header( 200 )` and `exit`, so no WordPress page object exists for the request and Rank Math
cannot apply that page's metadata. The ranked URL would silently lose its title, description and
canonical while a second competing URL appears.

### S4 — Authentication and account routes are indexable (HIGH)

`/login/`, `/student-portal/`, `/teacher-portal/` and `/admin-operations/` all return `200` with the
generic title `دلنوازان`, no robots directive and no canonical. `/dashboard/` returns `302` today.
None of these should ever be a search landing page.

### S5 — Homepage content has diverged (MEDIUM)

| | Production (indexed) | Staging (today) |
| --- | --- | --- |
| Title tag | `یادگیری موسیقی ایرانی برای ایرانیانِ دور از وطن` | `دلنوازان – آموزش موسیقی برای ایرانیان.دور از وطن` |
| Meta description | present | absent |
| Canonical | `https://delnavazan.com/` | absent |
| H1 | `یادگیری موسیقی ایرانی برای ایرانیانِ دور از وطن` | `موسیقی ایرانی؛ برای ایرانیان دور وطن` |
| Section H2s | نحوه برگزاری کلاس‌ها، دوره‌های آموزشی، پاسخ به پرسش‌های متداول، تازه‌ترین مقالات، تماس با ما | چرا دلنوازان؟، چطور ثبت نام کنم؟، شهریهٔ یک ترم، همنواز، پرسش‌های متداول، مجلهٔ دلنوازان |

`theme/front-page.php` renders the Theme's own composition
(`dzn_theme_render_canonical_homepage()` → `theme/patterns/homepage-editorial.php`) and ignores the
imported homepage page (export id 8), which carries production's Rank Math title, description and five
focus keywords. The homepage is a primary ranking asset, so this divergence is a product decision, not
a formatting detail.

### S6 — Tag inventory would create thin archives (MEDIUM)

The export declares 255 tags; 121 are attached to content and 94 of those are used exactly once.
Production has a single category (`موسیقی ایرانی`). Indexing 94 single-use tag archives adds
low-value URLs.

### S7 — Sitemap discovery differs between the two sites (MEDIUM)

Production's `robots.txt` has **no** `Sitemap:` line although `sitemap_index.xml` (Rank Math) works;
staging's `robots.txt` advertises a WordPress core sitemap instead. Whichever site is public at cutover
determines whether search engines can find the correct sitemap.

### S8 — The Theme emits no SEO authority (INFO, keep as is)

`theme/inc/setup.php` registers `title-tag` and `theme/header.php` prints `wp_head()`. The Theme emits
no canonical, description, Open Graph, schema, robots or sitemap markup. That is correct: Rank Math
owns SEO metadata. This branch deliberately adds none.

### S9 — The legal pages already carry SEO metadata (INFO)

`terms` (id 154), `privacy-policy` (id 152) and `refund_returns` (id 426) are **pending** on production,
so they are absent from the sitemap, but each already has a Persian Rank Math title and description and
real body text (English for terms/privacy, Persian RTL for refunds). Publishing them is a decision, not
an authoring task. Four further pending Persian landing pages carry focus keywords
(آموزش آنلاین تار / سه‌تار / موسیقی ایرانی در استرالیا / کلاس آنلاین تار و سه‌تار).

## 3. Implemented changes in this branch

The crawl guard (§R1) plus documentation. No Platform, booking, portal or SEO-emission code was
changed, and production behaviour is untouched:

- `theme/inc/crawl-guard.php` — off the public site (`delnavazan.com`, `www.delnavazan.com`) it forces
  `noindex, nofollow, noarchive` through `wp_robots` and an `X-Robots-Tag` header, withholds the
  WordPress core sitemap and drops `Sitemap:` lines from `robots.txt`. On the public site every filter
  returns its input unchanged. It writes no settings and emits no SEO metadata of its own. A blanket
  `Disallow: /` is deliberately not used, because crawlers must still be able to read the `noindex`.
- `theme/functions.php` — loads `inc/crawl-guard.php`.
- `deployment/seo/SEO-MIGRATION-AUDIT.md` (this file).
- `deployment/seo/production-seo-baseline.csv` — the preservation baseline for titles, descriptions and
  focus keywords per URL.
- `deployment/seo/url-migration-map.csv` — old URL → staging status today → target → action → owner.

**Placement note (supersedes §6 R1's earlier suggestion):** this guard sits in the Theme rather than the
Platform plugin because it must run on every request that renders a page, and the Theme is always active
whereas the Platform plugin may not be. It is not SEO authority: it never writes titles, descriptions,
canonicals, schema or a sitemap, and it is inert on the public host. The host list is filterable
(`dzn_theme_public_site_hosts`) for a future domain change, and an unreadable host fails safe to
non-indexable. If CD prefers Platform ownership, the file moves as a unit.

No redirects were implemented. No URL was changed. Redirects are only required where a URL decision
changes a path (see §4), and every such case is flagged for CD rather than assumed.

## 4. Redirect / migration map

The machine-readable map is `url-migration-map.csv`. Summary of its rules:

| Case | Rows | Action |
| --- | --- | --- |
| 19 published articles | 19 | Preserve the exact slug; no redirect. Persian slugs are truncated mid-word on production and must stay byte-identical. |
| 13 published pages | 13 | Preserve the exact slug; no redirect. |
| 8 pending pages (3 legal, 4 landing, homepage) | 8 | Publish decision required before a URL is claimed; no redirect either way. |
| Production category | 1 | Preserve `/category/موسیقی-ایرانی/`. |
| Homepage | 1 | Stays `/`; content parity is the decision, not the URL. |
| New staging-only routes | 6 | `/booking/` duplicates `/enrol/`; the five account/portal routes need a noindex decision. |

Redirects become necessary only if: `/booking/` is kept as a second public URL (then choose one
canonical and 301 the other), a page slug is altered during import, or a legal page is published under a
different slug than `terms` / `privacy-policy` / `refund_returns`.

## 5. Intended indexing behaviour (documented, not guessed)

No mechanism currently enforces any of this; production's Rank Math configuration is the source of
truth and must be exported before it can be reproduced.

| URL | Intent | Mechanism to confirm after import |
| --- | --- | --- |
| `/`, 19 articles, `/articles/`, `/ostad/`, `/honarjo/`, `/pay/`, `/how/`, `/info/`, `/در-مورد-ما/`, teacher profile | index | Rank Math post/page defaults |
| `/enrol/` | index (conversion landing page) | page-level Rank Math meta; requires the page object to be reachable (see S3) |
| `/booking/` | compatibility alias; canonicalises to `/enrol/` | **decided** (CD, 2026-10-01) — implementation belongs to the booking surface |
| `/terms/`, `/privacy-policy/`, `/refund_returns/` | index once published | publish + Rank Math defaults |
| `/payment-success/`, `/booking-received/`, `/booking-cancelled/`, `/cancellation-help/` | noindex | transactional pages; needs an explicit Rank Math noindex |
| `/login/`, `/dashboard/`, `/student-portal/`, `/teacher-portal/`, `/admin-operations/` | noindex | not currently enforced (S4) |
| category archive | index (single category) | Rank Math taxonomy defaults |
| tag archives | noindex | Rank Math taxonomy defaults (see S6) |
| search results, author archives, feeds | noindex | Rank Math global settings |

## 6. Recommendations — not implemented here

| # | Recommendation | Owner |
| --- | --- | --- |
| R1 | Make staging non-indexable before it is linked anywhere. **Implemented in this branch** (`theme/inc/crawl-guard.php`); the Dashboard → Settings → Reading "discourage search engines" switch and/or HTTP authentication remain the primary controls to set as well. | CD (setting) + this branch (guard) |
| R2 | Import the export with "download and import file attachments" enabled, map author `delnavazan` to an administrator, and verify every slug against `production-seo-baseline.csv`. | CD |
| R3 | Export Rank Math settings from production (Status & Tools → Import/Export) and import them on the target: titles/descriptions defaults, noindex rules for auth/tag/search, schema, sitemap. Per-post meta already travels in the WXR; global settings do not. | CD |
| R4 | **Decided: `/enrol/` is canonical**; `/booking/` stays a compatibility alias that must canonicalise to `/enrol/`, and the booking experience should render through the real page object so Rank Math owns its title, description and canonical. Touches booking behaviour, so it is not part of this branch. | Hamed + CD |
| R5 | **Decided: keep the recovered visual homepage** and restore SEO metadata/title/canonical separately (Rank Math homepage settings); H1 wording stays a product/content decision. | CD + Ina |
| R6 | Restore the `Sitemap:` directive in `robots.txt` on the public site after cutover. | CD |
| R7 | Decide the fate of the four pending Persian landing pages and the 94 single-use tags before they become indexable. | CD |

## 7. Post-import verification checklist

1. Every URL in `production-seo-baseline.csv` returns `200` on the target (or the deliberate `pending` state).
2. Slugs still match byte-for-byte, including the truncated Persian slugs.
3. Title and meta description match the baseline where the baseline has one.
4. Canonical is present and self-referencing; the homepage canonical is the site root.
5. `sitemap_index.xml` lists all 19 posts and the published pages; `robots.txt` advertises it.
6. Staging (or the pre-cutover target) remains `noindex` throughout.
7. No production URL returns a soft 404, and no duplicate content pair (`/enrol/` + `/booking/`) remains.
8. Featured images resolve for the nine posts that have `_thumbnail_id`.
9. Structured data on the pages carrying `rank_math_schema_Course` still validates.
10. Any URL that did change has a single 301 to the preserved target.

## 8. Explicit non-changes

- Production `delnavazan.com` was only read (public `GET` requests); nothing was written or configured.
- Staging was only read; no settings, content, plugin or theme were changed.
- The Theme remains free of SEO emission, so no duplicate SEO authority was created.
- Booking, student portal and teacher portal behaviour were not touched.
- No deployment, no merge, no branch other than `deepseek/seo-migration`.
