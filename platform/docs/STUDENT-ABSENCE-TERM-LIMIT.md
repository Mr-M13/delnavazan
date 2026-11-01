# Student absence and the Term replacement limit

## Policy

A Term's configured `replacement_allowance` (two by default) bounds the Student's eligible
absence-related replacement/make-up arrangements. Attendance reporting remains separate from
entitlement: a Student may still report a third absence or dispute a class, and the evidence is
recorded, but no replacement slot is promised or reserved for an occurrence beyond the allowance.

An eligible Student-origin absence consumes one slot when it enters the replacement workflow. A
confirmed replacement Lesson consumes a slot directly, and an accepted absence-driven revision of
a future occurrence counts as used rather than pending. Remaining slots are allocated once to
distinct eligible absences awaiting discussion, a Teacher proposal, or Student acceptance, in
first-entered occurrence order. Rejection or expiry of a proposed time does not release the allocation; the
Teacher may propose another time. An explicit audited resolution that the absence is ineligible,
withdrawn, delivered, academy-owed or otherwise no longer owed releases it.

Advance absence notices use the same quota. A future occurrence may still be revised rather than
replaced, preserving the canonical distinction between a schedule revision and a linked replacement
Lesson. An ordinary Teacher schedule change with no Student absence does not consume the allowance.

## Portal and workflow

- Student portal: allowance, confirmed/used, pending allocated and truly available counts.
- A Student with two pending eligible absences sees zero available before either replacement Lesson
  is issued.
- An excess absence is recorded, the Student is told immediately in Persian that the Term limit is
  reached, and an administrator review exception is recorded.
- No Teacher replacement task, actionable absence card or replacement-request notification is
  created for an excess absence.
- The Teacher absence card is shown only for an occurrence that owns an allocated slot.

## Tests

- `platform/tests/term-replacement-allocation-unit.php` proves slot allocation, overrides, dedupe
  and the ordinary-reschedule no-op.
- `platform/tests/lesson-replacement-service-unit.php` proves allocated advance absences may revise
  a future occurrence and excess/completed absences are refused before a proposal is created.
- `platform/tests/missed-lesson-replacement-contract.php` guards the portal, controller, Teacher
  attention and allocation source contracts.
- `platform/tests/lesson-replacement-runtime.php` exercise the real canonical replacement path in
  the disposable attendance runtime.
