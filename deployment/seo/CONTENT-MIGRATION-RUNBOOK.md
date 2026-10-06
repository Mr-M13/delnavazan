# Delnavazan staging content migration — package and runbook

**Status:** prepared, **not executed**. The import is a WordPress-admin action that this agent cannot
perform, so the work stops at PR ready. Lifecycle owner after that: CD → Reviewed → Merged → Deployed →
Verified → Done.
**Date:** 2026-10-01 (Australia/Brisbane).
**Branch:** `deepseek/content-seo-migration`.

## 1. Verified state before import

Read from staging on 2026-10-01 (public `GET` only):

| Surface | Value |
| --- | --- |
| Posts | 0 |
| Pages | 0 |
| Media | 21 (all `uploads/2026/10/`, the rebuilt design's assets: `C-*.png`, `Logo.png`, `Logo2.png`, `Del.png`, `cropped-*Logo.png`, `online-training-sitar.jpg`) |
| Categories | 1 (`uncategorized`, count 0) |
| Tags | 0 |
| Crawl guard | active — `X-Robots-Tag: noindex, nofollow, noarchive`, `wp-sitemap.xml` 404, `robots.txt` advertises no sitemap |
| `/enrol/`, `/booking/` | 200, served by the application route (booking experience) |
| `/articles/`, `/ostad/` | 404 (content not imported yet) |

Because staging holds no posts or pages, the import cannot collide with existing WordPress content. It
can only collide with application routes and existing media objects (§4).

## 2. Source and package

| Artefact | Detail |
| --- | --- |
| Production export | `/Users/hamed/Downloads/WordPress.2026-10-01-3.xml`, SHA-256 `f896d44449ea4c763da4d9dbeba79047ed4910ed2b1d51093861e092dcca0f71`, 163 items |
| Package builder | `deployment/seo/migration/build-import-package.py` (deterministic; `--dry-run` prints the plan) |
| Generated package | `/Users/hamed/Downloads/delnavazan-staging-import-20261001.xml`, SHA-256 `8ef7e29f54250ed2e0def8e6c7f70b23a7e3f9f0b0329314f801919404dd922c`, 682,802 bytes, **97 items** |
| Package contents | 19 posts (all `publish`), 21 pages (12 `publish`, 8 `pending`, 1 `draft`), 57 attachments, 2 categories, 255 tags |

The package is generated locally and is deliberately **not** committed: it carries production copy and
an author contact address. The builder is committed so the package is reproducible from the export.

## 3. What is excluded, and why

| Excluded object type | Count | Reason |
| --- | --- | --- |
| `wp_global_styles` | 3 | Global styles from the retired site; importing them could override the rebuilt Theme's `theme.json` design |
| `wp_font_face` / `wp_font_family` | 36 / 12 | Font registrations belonging to the retired site's editor styles |
| `wp_navigation` | 1 | A navigation block post from the old design |
| `custom_css` | 1 | Additional CSS written for the retired theme |
| `nav_menu_item` | 13 | The old `پانویس` (footer) menu; the rebuilt site owns its own menus, and importing menu items can reassign theme menu locations |

Everything else is preserved verbatim, including per-object Rank Math metadata, featured images,
categories, tags and the exact slugs.

## 4. Collision and risk report

### R-1 — One application-route collision: the `enrol` page (mitigated)

Production page id 91, «ثبت نام در آکادمی دلنوازان», slug `enrol`, status `publish`, collides with the
application route `/enrol/`.

Today `theme/inc/routes.php` intercepts `/enrol/` before WordPress renders a page, so the booking
experience wins. Importing the page as `publish` would therefore be inert **until** the route is ever
changed — at which point the retired enrolment page would silently surface on the canonical booking URL.

Mitigation applied in the package: the page is imported as **`draft`**. The content, slug and Rank Math
metadata are preserved for the future R4 work (rendering booking through a real page object so Rank Math
owns its title, description and canonical), with no possibility of it becoming public by accident.

This is the one deliberate status change in the package and it needs CD's confirmation.

### R-2 — Media slug collisions (harmless, but duplicates)

Three production attachment slugs already exist on staging: `del`, `logo`, `logo2`. WordPress will store
the imported copies as `del-2`, `logo-2`, `logo2-2`.

### R-3 — Media filename overlaps (no overwrite)

Six filenames exist on both sides: `Logo.png`, `Logo2.png`, `Del.png`, `cropped-Logo.png`,
`cropped-cropped-Logo.png`, `online-training-sitar.jpg`. Production's copies live in `uploads/2025/10`
and staging's in `uploads/2026/10`, so no file is overwritten; the import simply adds parallel attachment
objects. Two of these — `cropped-Logo.png` and `cropped-cropped-Logo.png` — are the retired site's header
logo derivatives and are unlikely to be wanted.

### R-4 — The `home` page

The export's homepage page (id 8, `pending`, with its own Rank Math title and description) is imported as
`pending`. It must **not** be assigned as the static front page: `theme/front-page.php` renders the
recovered Theme composition, and R5 decided that design stays.

### R-5 — No other route collisions

Every post and page slug in the export was compared against `/booking/`, `/login/`, `/dashboard/`,
`/student-portal/`, `/teacher-portal/` and `/admin-operations/`. `enrol` is the only match; the portal and
authentication routes stay application routes and are unaffected by the import.

### R-6 — Tags

255 tags (121 attached to content, 94 used once) are preserved as source data. No indexing decision is
taken here; Rank Math's global taxonomy policy is a separate, later decision (audit R7).

## 5. Import procedure (WordPress admin — CD/Hamed)

1. Log in to staging `wp-admin` as an administrator.
2. Tools → Import → **WordPress** → Install/run the importer if prompted.
3. Upload `/Users/hamed/Downloads/delnavazan-staging-import-20261001.xml`.
4. On the mapping screen, map the author `delnavazan` to an existing staging administrator.
5. **Tick** "Download and import file attachments" so the nine posts with featured images, and the other
   referenced media, resolve. This fetches files from production over HTTP; it does not write to
   production.
6. Do not tick "Import term meta" or any option that assigns the imported pages to a front page.
7. After import, leave the eight `pending` pages pending and the `enrol` page as `draft`.

Alternative once SSH is enabled on the host (currently disabled):
`wp import delnavazan-staging-import-20261001.xml --authors=create`.

## 6. Verification checklist after import

1. Posts count is 19 and every slug matches `deployment/seo/production-seo-baseline.csv` byte-for-byte
   (including the truncated Persian slugs).
2. Pages: 12 `publish`, 8 `pending`, 1 `draft` (`enrol`).
3. Each imported URL that is published returns 200 and its `<title>`/meta description match the baseline.
4. `_thumbnail_id` is set on the nine posts that carried one.
5. Rank Math per-object metadata survived (spot-check three posts and the `articles` page).
6. `/` still renders the recovered Theme homepage; `/enrol/` and `/booking/` still render booking.
7. Staging remains non-indexable (`X-Robots-Tag` header, no core sitemap).
8. No portal or authentication route changed behaviour.

Useful checks:

```bash
curl -sS -o /dev/null -w '%{http_code} %{url_effective}\n' https://staging.delnavazan.com/tar-in-guitar-persian-roots/
curl -sSI https://staging.delnavazan.com/ | grep -i x-robots-tag
curl -sS -o /dev/null -w '%{http_code}\n' https://staging.delnavazan.com/wp-sitemap.xml   # expect 404
```

## 7. Decisions confirmed by CD (2026-10-01)

1. The `enrol` page **stays `draft`** in the imported site (R-1 confirmed).
2. The six overlapping media files **stay in the package**; the duplicate attachment objects are accepted
   for now (R-3 confirmed).
3. All retired presentation objects **remain excluded** — global styles, font faces/families, the
   navigation block, the old theme's Additional CSS and the old `پانویس` menu (R-5 confirmed).

The next controlled step is therefore: **staging import → immediate runbook verification** (§5, §6).

### Merge timing for this branch

This branch is tooling and documentation only. `deployment/sync-mirrors.sh` splits the deployable trees
with `git subtree split --prefix=platform` and `--prefix=theme`, so `deployment/` is not part of either
mirror and merging this branch cannot change what staging receives. The import remains a manual
WordPress-admin action independent of the merge.

## 8. Explicit non-actions

- Production was read only (public `GET`); nothing was written or configured there.
- Staging was read only; no content, setting, plugin or theme was changed.
- Nothing was merged, deployed or published; the package exists only as a local file.
- No Theme, Platform, booking, portal or SEO-emission code was changed by this branch.
- No second SEO plugin was installed or activated, and no Rank Math global setting was guessed or created.

## 9. Live preflight refresh — 2026-10-04

Read-only/reversible staging checks refreshed before import:

- WordPress content remains empty: 0 posts and 0 pages, so the documented content-collision assumptions still hold.
- WordPress `blog_public` is now `0` on staging, in addition to the Theme crawl guard. Keep both protections until production cutover.
- Delnavazan Platform schema is `35`; migrations 001–035 are recorded complete.
- Teacher invitation delivery and Google calendar projection cron hooks are scheduled every five minutes.
- Rank Math is **not installed/active on staging**. The WXR preserves per-object Rank Math metadata, but those values will not emit titles/descriptions/canonicals/sitemaps until the chosen SEO authority is installed/configured.
- Google OAuth is **not configured on staging** (`DZN_GOOGLE_OAUTH_CLIENT_ID` is absent). This is unrelated to the content import, but remains a launch configuration dependency for teacher calendar integration.
- The previously generated local WXR package was not found at its documented Downloads path during this refresh. Recreate it from the production export with the committed deterministic builder and verify its counts/digest before import; do not substitute an unverified XML file.

The content import remains intentionally unexecuted until the source export/package is recovered or freshly exported and verified.

## 10. Source package preflight and promotion inventory

Before an operator imports a regenerated package, run the read-only contract check:

```bash
python3 deployment/seo/migration/validate-import-package.py \
  --package /path/to/delnavazan-staging-import.xml \
  --json /path/to/delnavazan-staging-import.preflight.json
```

The validator compares every post/page type and exact slug with
`production-seo-baseline.csv`, checks the per-object Rank Math title, description and focus keyword,
enforces source status (with the approved `enrol` draft exception), rejects retired presentation
objects and untracked content, and verifies featured-image references point to attachments in the WXR.
It emits a per-URL promotion inventory and exits nonzero on any mismatch. Keep its JSON beside the
generated package for review; neither file belongs in Git because source content includes author data.

The inventory does not publish anything. Under the recorded decisions, the package contains 19
published articles; 13 production-published pages, of which `enrol` is deliberately demoted to draft;
eight production-pending pages; and 57 attachments. The homepage (`home`) stays pending and must not
replace the recovered Theme homepage. The four transactional slugs (`booking-cancelled`,
`booking-received`, `cancellation-help`, `payment-success`) require explicit noindex verification before
public promotion. The other seven pending pages still need editorial approval. No current canonical
page may be overwritten: inspect the destination's current page/post inventory first and stop for
reconciliation on any slug collision. This package validator cannot inspect or mutate that destination.

Passing the package check is necessary, not sufficient. Before public promotion, separately prove the
target inventory is understood, Rank Math is installed and its production global settings are restored,
the target remains noindex during staging, imports preserve media and links, and rendered titles,
descriptions, self canonicals, sitemap membership and redirects match the approved URL map. The checked
in WXR/package is not idempotent to import by itself; do not repeat an import as a retry strategy.
The WordPress importer action remains an operator-controlled step until a target-aware, collision-safe
import mechanism is implemented and reviewed.
