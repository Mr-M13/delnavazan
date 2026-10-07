# Pre-launch staging retirement and production acceptance audit

Date: 2026-10-07

## Source changes on this branch

- Removed `.github/workflows/deploy-staging.yml`, which was manually triggered and targeted the staging hostname.
- Removed `deployment/staging-deploy.sh`, its source contract, and its staging rollback instructions.
- Removed the staging deployment job checks from the pull-request gate.
- Kept the read-only HTTP QA runner as `deployment/qa/site-smoke.sh`; it accepts a site URL and performs no writes or deployment.
- Updated current deployment/readiness documentation to describe the retirement and preserve the production deployment path.
- Kept application-level staging environment behavior and reusable synthetic-data QA contracts. They do not authorize production use.

## Live evidence collected

- `https://staging.delnavazan.com` resolved to Cloudflare proxy addresses and returned HTTP 200 from LiteSpeed with `X-Robots-Tag: noindex, nofollow, noarchive` on 2026-10-07.
- The connected staging WordPress account was verified read-only. It reports WordPress 7.1.3, PHP 8.1.33, the Delnavazan platform plugin, and the Delnavazan theme.
- GitHub repository `Mr-M13/delnavazan` has distinct `production` and `staging` Actions environments. Two historical staging workflow runs were found; both completed with failures. No active run was found in the returned recent-run list.
- The connected production WordPress authentication could not be verified. Public HTTP 200 does not establish database contents.

## Not removed

- The live staging WordPress installation, database, uploads, backups, DNS record, hosting account and GitHub `staging` environment remain. The available evidence does not identify the DirectAdmin account, database name, filesystem paths, backup locations, or whether any storage is shared with production. Deletion must wait for hosting-panel/database evidence that proves separation.
- Production data and configuration were not changed. Genuine academy data absence is unknown because authenticated production inspection failed.
- The staging Actions environment and any secrets were not deleted. First merge this source change, confirm the workflow is absent and idle, then remove only the staging environment and its staging-specific secrets after confirming their scope.
- Synthetic-data seeding remains disabled in production. The current fixture records some workflow IDs but its teardown does not cover every generated record; do not seed it on production.

## Next steps

1. Obtain read-only hosting evidence identifying staging document root, database, uploads and backups, and compare each to production. If any resource is shared or ambiguous, stop and retain it.
2. Back up the positively identified staging-only installation and database, then remove only those exact staging resources through the hosting provider. Verify the staging hostname no longer serves WordPress and production remains healthy.
3. Restore authenticated production WordPress/database inspection. Inventory academy entities and determine whether any could be genuine; if so, stop all destructive production-data actions.
4. Extend the fixture with exact IDs/tags and teardown for every record created by each lifecycle service. Add a fail-closed production preflight requiring both independent data-empty evidence and explicit operator opt-in. Run the production lifecycle only after this review and preflight pass.

## Source verification

Run `bash -n deployment/qa/site-smoke.sh`, `bash deployment/qa/production-deployment-contract.sh`, and the synthetic dataset source and policy contracts. This validates repository behavior only; it does not prove live hosting isolation, production data absence, or successful staging deletion.
