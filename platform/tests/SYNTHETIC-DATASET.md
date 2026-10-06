# Synthetic academy dataset (isolated sites only)

`synthetic-dataset.php` is a WP-CLI-only test fixture. It is not loaded by the plugin and is kept under `tests/`, which is excluded from deployment packages. It creates the catalog, teacher, and student core records through `CatalogueService`, `TeacherService`, and `StudentService`.

The operator must explicitly opt in from a `local` or `development` WordPress environment and use a user with all three management capabilities:

```sh
DZN_SYNTHETIC_DATASET=1 wp eval-file platform/tests/synthetic-dataset.php preview
DZN_SYNTHETIC_DATASET=1 wp eval-file platform/tests/synthetic-dataset.php seed
DZN_SYNTHETIC_DATASET=1 wp eval-file platform/tests/synthetic-dataset.php verify
DZN_SYNTHETIC_DATASET=1 wp eval-file platform/tests/synthetic-dataset.php teardown
```

The fixed `DZN TEST` labels and `@synthetic.example.invalid` contacts identify fixture records. A private WordPress option stores the exact created IDs. `seed` is idempotent after that manifest exists; if the reserved namespace is occupied without a manifest, it refuses to adopt the rows. `verify` checks each manifest ID still exists. `teardown` deletes only those manifest IDs, leaves first, then removes the manifest. The fixture does not create WordPress accounts, contact anyone, create payment intents, or call notification or calendar providers.

## Coverage and limits

This first fixture version covers two instruments, three courses, active and inactive teacher records, and two students. It does not create onboarding workflow history, availability/coverage cases, booking requests, enrolments, terms, lessons, attendance/progress, finance/payment scenarios, notification queue states, Google projections, or exception diagnostics. Those records have separate authorities and lifecycle contracts; do not treat this core fixture as the requested comprehensive end-to-end operations dataset. Extend it only by adding each aggregate through its canonical service and by including its exact owned IDs in verification and teardown before adding that aggregate to the advertised coverage.

Because core entities have no dataset foreign key and many operational tables are append-only, teardown currently covers only these four core tables. Do not extend the fixture with a workflow whose records cannot be enumerated and removed safely.

Run the static contract with:

```sh
php platform/tests/synthetic-dataset-contract.php
```
