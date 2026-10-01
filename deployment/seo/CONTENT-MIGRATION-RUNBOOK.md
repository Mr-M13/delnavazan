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

## 7. Decisions required from CD

1. Confirm the `enrol` page should stay a `draft` in the imported site (R-1).
2. Confirm whether the six overlapping media files (R-3) should be excluded from the package, which would
   avoid duplicate logo derivatives at the cost of dropping two retired-site images.
3. Confirm that excluding the retired presentation objects (global styles, fonts, navigation, custom CSS,
   menus) is correct — this protects the rebuilt design and is the conservative reading of "preserve the
   new homepage and navigation".

## 8. Explicit non-actions

- Production was read only (public `GET`); nothing was written or configured there.
- Staging was read only; no content, setting, plugin or theme was changed.
- Nothing was merged, deployed or published; the package exists only as a local file.
- No Theme, Platform, booking, portal or SEO-emission code was changed by this branch.
- No second SEO plugin was installed or activated, and no Rank Math global setting was guessed or created.
