# Catalogue naming and reconciliation

## Automatic creation

Creating an Instrument through **Courses → Instruments** now atomically creates or reconciles:

- one introductory Course named `کلاس معارفه [نام ساز]`;
- one standard Course named `کلاس [نام ساز]`;
- one active standard commercial product (with no regional price);
- the active `instrument_intro_course_defaults` row used by public booking.

The source is the Instrument’s Persian name where present. If only an English name exists, the English name is used as a deterministic fallback. The same slug converges on the existing Instrument and only creates missing course/default rows. Multiple active Courses of the same type fail closed as `catalogue_course_ambiguous`.

No commercial product, regional price, Term, Enrolment, Lesson or provider record is created by this path. Introductory lessons remain free-only and regional prices remain under Finance/catalogue authority.

## Read-only audit

Run the report with an authorised administrator or catalogue operator:

```sh
wp --skip-themes --user=<admin-id> eval-file deployment/qa/catalogue-reconciliation.php
```

The report is SELECT-only and never changes a record. It reports:

- duplicate active introductory/standard Courses per Instrument;
- missing or invalid intro-course defaults;
- duplicate active Persian names;
- booked Instruments without exactly one standard Course;
- booking requests whose selected intro Course is not the current default;
- standard Courses without exactly one active product or active regional price.

## Reconciliation policy

Any repair is a separate, explicitly approved operation. The report does not implement destructive repair.

1. Take a database backup and record the current schema/build.
2. Run the dry-run report and retain its JSON output.
3. Classify each finding as safe-to-apply, operator-review, or leave-untouched.
4. Never delete catalogue rows. Prefer `status='inactive'` followed by archive only after dependency review.
5. Do not rename historical Courses automatically. Historical names remain part of booking, notification and finance evidence.
6. Do not merge or repoint a Course that is referenced by bookings, Lessons, Enrolments, Terms, Teacher eligibility, products, prices, post-intro preparations or Finance.
7. Apply changes only after explicit operator approval, using the owning service and an idempotency key, then re-run the report and compare the before/after plan digest.
8. Roll back only by restoring the previous status/default/name values; there is no automatic production deletion.

## Constraints

- No production database deletion or bulk migration.
- No automatic product or price creation.
- No Terms or Lessons until the paid enrolment lifecycle requires them.
- Public booking and Teacher instrument selection continue to read the same canonical `instruments`/`courses` tables; public booking additionally requires the active default intro Course.
