# Consent notice drafts and guarded publication

**Status:** draft only. Nothing in this change has been published. The live portal therefore still
shows no recording/research choice until an owner- and legal-approved notice exists.

## Why the portal currently shows no choice

The consent UI is fail-closed. It renders a purpose-specific form only when the newest published
notice for that exact audience and language exists. Production currently has no published recording,
research, recorded-work or privacy notice, so:

- teacher onboarding/account shows the purpose sections with an “approved publication unavailable”
  state;
- student account shows the neutral unavailable state;
- no checkbox or decision form is rendered.

This is intentional: the system must not record consent to an unpublished or unresolved policy.

## Draft set

`platform/content/consent-notices/2026-11-04-draft.json` contains twelve drafts:

| Audience | Persian | English |
|---|---|---|
| `teacher_terms` | yes | yes |
| `teacher_lesson_recording` | yes | yes |
| `teacher_research_model_training` | yes | yes |
| `student_lesson_recording` | yes | yes |
| `student_research_model_training` | yes | yes |
| `privacy_terms` | yes | yes |

Every draft intentionally contains unresolved placeholders such as `[[RETENTION_PERIOD]]`,
`[[THIRD_PARTIES]]` and `[[PRIVACY_VERSION]]`. The guarded publisher refuses the set while any
placeholder, draft marker or unsupported audience remains.

## Required owner/legal decisions before publication

1. Legal operator name, ABN/registration and governing jurisdiction.
2. Final retention period for raw audio/video, mixed recordings, transcripts, features, annotations,
   datasets, backups and derived assets.
3. Approved third-party processors and cross-border transfer safeguards.
4. Privacy policy version identifier and final privacy wording.
5. Child/guardian rules for students aged under 18, including age range and authority evidence.
6. Final withdrawal limitations, including the statement that an already-trained model may not forget.
7. Any jurisdiction-specific lawful basis or additional disclosure.

## Publication after approval

Preferred operator path:

**WP Admin → Delnavazan → Settings → Policies → Publish a reviewed version**

Publish each purpose and language separately. Publication creates an immutable version and makes
the corresponding portal form appear.

Guarded CLI path after the code is deployed and the JSON has been finalised:

```sh
DZN_CONSENT_NOTICE_FILE=platform/content/consent-notices/2026-11-04-draft.json \
DZN_CONSENT_NOTICE_CONFIRM=PUBLISH_CONSENT_NOTICES \
wp eval-file platform/runtime/bin/publish-consent-notices.php --user=1
```

The publisher:

- requires an authenticated administrator and the literal confirmation;
- validates every notice before writing anything;
- refuses placeholders, `DRAFT`/`TBD` markers, unsafe markup, short bodies and unsupported audiences;
- publishes only through the existing append-only authority;
- never edits or deletes an earlier version.

## Verification after publication

1. Open `/recording-and-research/` and confirm each published notice appears.
2. Log in as a teacher with an active principal link and confirm the onboarding/account purpose forms.
3. Log in as an adult student with capacity classification and an active principal link and confirm
   the separate recording/research forms.
4. Log in as a minor with an active guardian grant and confirm the guardian state.
5. Confirm the recording and research forms remain independent and no choice is preselected.
6. Do not enable capture or research export; `CAPTURE_ENABLED` and `RESEARCH_EXPORT_ENABLED` remain
   false.
