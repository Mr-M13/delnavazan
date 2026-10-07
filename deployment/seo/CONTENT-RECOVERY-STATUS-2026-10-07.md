# Content recovery status — 2026-10-07

## Outcome

No WordPress content was imported, published, deployed, or duplicated. The prepared
import source cannot be recovered from the repository or the accessible local
locations below. Creating a replacement package from partial public HTML would lose
WordPress object data and is deliberately not an acceptable substitute.

The existing migration tooling is intact and already provides the safe path once the
source export is supplied:

1. `build-import-package.py` filters a production WXR while retaining articles,
   pages, attachments, taxonomies and per-object Rank Math metadata.
2. `validate-import-package.py` rejects slug/status/metadata/media mismatches before
   an operator can import it.
3. `CONTENT-MIGRATION-RUNBOOK.md` requires a target inventory check and a single
   controlled WordPress-admin import; it explicitly rejects retries and collisions.

## Production read-only verification

Public production was checked before any proposed import on 2026-10-07
(Australia/Brisbane):

- `https://delnavazan.com/articles/` rendered an archive containing 19 articles.
- `https://delnavazan.com/terms/` rendered the Terms page.
- `https://delnavazan.com/privacy-policy/` rendered the Privacy Policy.
- Public retrieval of `https://delnavazan.com/refund_returns/` did not yield a usable
  page through the available read-only client. The earlier authoritative inventory
  records `refund_returns` as a prepared pending page, so it must remain in the WXR
  recovery scope rather than being recreated.

This confirms that the public source has content which must not be duplicated, but
it does **not** prove the WordPress records needed for a safe migration. In
particular, public rendering does not reliably expose the original statuses,
taxonomies, attachment IDs, featured-media relationships, Rank Math object metadata
or footer-menu membership.

## Locations and history searched

| Location or history | Result |
| --- | --- |
| Documented source export: `/Users/hamed/Downloads/WordPress.2026-10-01-3.xml` | Missing. The runbook records its expected SHA-256 as `f896d44449ea4c763da4d9dbeba79047ed4910ed2b1d51093861e092dcca0f71`. |
| Documented generated package: `/Users/hamed/Downloads/delnavazan-staging-import-20261001.xml` | Missing. The runbook records `8ef7e29f54250ed2e0def8e6c7f70b23a7e3f9f0b0329314f801919404dd922c` and 97 items. |
| Alternate documented package/preflight names in Downloads | Missing: `delnavazan-staging-import.xml` and `delnavazan-staging-import.preflight.json`. |
| All reachable Git refs and paths | No WXR/XML or checked-in article/legal source. The content branches contain only the migration documentation and tooling. |
| SEO migration history | Reviewed commits `6992e38`, `93cd8ff`, `a58cea4`, `d77a1fb`, `474bcbd`, `eaff06f`, and `f65a109`; none contains the export or generated package. |
| Unreachable Git blobs | Scanned with `git fsck --no-reflogs --unreachable`; no recoverable WXR-sized blob was found. |
| Accessible related worktrees and temporary/Desktop candidate files | Scanned `/private/tmp` and `/Users/hamed/Desktop` for XML/WXR and content/legal candidates. Only the same committed docs/tooling, a prior tooling patch, and 404 HTML captures were found; no usable export/package. |
| `deepseek/seo-migration`, `deepseek/content-seo-migration`, and `origin/codex/content-seo-live-readiness` | Inspected directly. They preserve the audit, builder, validator and runbook, not the source content. |

The production baseline in `production-seo-baseline.csv` remains useful evidence of
the intended inventory: 40 objects (19 posts and 21 pages), their slugs, Rank Math
metadata and featured-image flags. It intentionally does not contain the article or
policy bodies and cannot regenerate an import.

## Smallest required action from Hamed

Provide a fresh **WordPress Tools → Export → All content** WXR from
`https://delnavazan.com` to a private local path, plus the production Rank Math
global-settings export. Do not edit the WXR and do not use a browser-scraped
replacement. The WXR is the smallest artifact that preserves the bodies, statuses,
categories/tags, attachment references, featured media and per-object SEO metadata
together.

With that artifact available, the next operator actions are strictly mechanical:

```bash
python3 deployment/seo/migration/build-import-package.py \
  --source /private/path/production-export.xml \
  --output /private/path/delnavazan-staging-import.xml
python3 deployment/seo/migration/validate-import-package.py \
  --package /private/path/delnavazan-staging-import.xml \
  --json /private/path/delnavazan-staging-import.preflight.json
```

Only if the validator passes should an authorized WordPress administrator inventory
the target for collisions and perform the single staging import described in
`CONTENT-MIGRATION-RUNBOOK.md`. Production deployment and public promotion remain
separate, operator-authorized steps after live verification.
