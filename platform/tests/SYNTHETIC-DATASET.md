# Synthetic academy dataset (staging and isolated sites)

`synthetic-dataset.php` is a WP-CLI-only test fixture. It is not loaded by the plugin and is kept under `tests/`, which is excluded from deployment packages. It creates the catalog, teacher, and student core records through `CatalogueService`, `TeacherService`, and `StudentService`.

Every run requires WP-CLI, an operator with the fixture's required platform capabilities, and `DZN_SYNTHETIC_DATASET=1`. Local/development environments use that gate but still refuse the live `delnavazan.com` host, `www.delnavazan.com`, and every non-staging `*.delnavazan.com` subdomain, so a misconfigured environment type cannot authorize production. Staging also requires a second exact acknowledgement and an approved staging hostname; a `production` environment always fails closed, even if the host resembles staging. The runner permits only `staging.delnavazan.com` and its subdomains. Do not change this allowlist to a production hostname.

```sh
# Confirm WP-CLI reports `staging` and `home_url()` is the staging hostname first.
export DZN_SYNTHETIC_DATASET=1
export DZN_SYNTHETIC_DATASET_STAGING=I_UNDERSTAND_STAGING_TEST_DATA
wp --url=https://staging.delnavazan.com eval-file platform/tests/synthetic-dataset.php preview
wp --url=https://staging.delnavazan.com eval-file platform/tests/synthetic-dataset.php seed
wp --url=https://staging.delnavazan.com eval-file platform/tests/synthetic-dataset.php verify
wp --url=https://staging.delnavazan.com eval-file platform/tests/synthetic-dataset.php reconcile
# Only when the staging fixture is to be removed:
wp --url=https://staging.delnavazan.com eval-file platform/tests/synthetic-dataset.php teardown
```

The staging `wp-config.php` must set `WP_ENVIRONMENT_TYPE` to `staging`; the environment is not inferred from the URL. `preview` is informational and does not authorize writes. Keep WP-CLI pointed at the intended staging site with `--url` on multisite.

The fixed `DZN TEST` labels and `@synthetic.example.invalid` contacts identify fixture records. A private WordPress option stores the exact created IDs. `seed` is idempotent after that manifest exists; if the reserved namespace is occupied without a manifest, it refuses to adopt the rows. `verify` checks each manifest ID still exists. `reconcile` verifies an existing manifest and reports `absent` without writing when no manifest exists. `teardown` checks for dependent platform rows and refuses when lifecycle-owned references remain; when safe, it deletes only manifest-owned core IDs. The fixture does create one synthetic WordPress teacher account, but does not contact anyone, create payment intents, or call notification or calendar providers.

## Current coverage and lifecycle boundaries

This recovered fixture covers instruments, courses, teacher invitation/account claim and onboarding preparation, availability, eligibility, accepting state, two students, one student account-claim notification intent, and one matchable booking request. It deliberately does not invoke notification delivery or provider APIs. It is not yet a whole-site E2E dataset: canonical enrolment conversion, teacher assignment, terms, lesson scheduling, attendance, sandbox commercial/payment relationships, Google/Meet projections and additional booking/notification edge states are not seeded. Add each aggregate through its canonical lifecycle service, verify the exact linked IDs, and make reset safe before advertising that coverage. The policy contract proves staging authorization and production rejection; it does not prove that the staging database currently has the required schema or that activation has occurred.

Because core entities have no dataset foreign key and many operational tables are append-only, teardown currently covers only these four core tables. Do not extend the fixture with a workflow whose records cannot be enumerated and removed safely.

Run the source contracts with:

```sh
php platform/tests/synthetic-dataset-contract.php
php platform/tests/synthetic-dataset-policy-contract.php
```
