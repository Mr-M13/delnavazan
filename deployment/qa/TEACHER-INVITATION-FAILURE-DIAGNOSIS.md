# Teacher invitation failure diagnosis

This is an operator runbook for the two distinct teacher-invitation boundaries. It does not authorize a production test send, a credential change, or a resend to a real recipient.

## 1. Invitation creation failed before dispatch

The message `Invitation was not created...` means `ScreenController::issueTeacherInvitation()` did not reach `TeacherInvitationDeliveryWorker::dispatchNow()`. It is not evidence of an SMTP, Yahoo, Gmail, or mailbox-delivery failure. `PrincipalInvitationService::issueForEmail()` runs the following in one database transaction:

1. validates the email and locks any active, non-archived Teacher profiles with that email;
2. reuses exactly one unlinked active Teacher, or creates one blank active Teacher;
3. writes the invitation, a queued generation, its audit record, and its delivery outbox row; and
4. commits. Any exception rolls the whole transaction back.

The server log records only one fixed category, prefixed `teacher_invitation_issuance_failed`; it never records the recipient, command key, invitation secret, SQL text, or SMTP transcript. Use that category as follows:

| Category | Meaning and bounded response |
| --- | --- |
| `invalid_recipient` | Correct the entered address. |
| `duplicate_active_teacher_profiles` | Multiple active, non-archived Teacher records share the email. Resolve the duplicate profiles under the normal data-governance process before retrying. |
| `teacher_already_linked` | The matching Teacher already has active principal authority; do not create another invitation. |
| `teacher_not_onboarding_eligible` | Restore/correct the Teacher lifecycle only through its normal workflow, then retry if appropriate. |
| `persistence_failed` or `transaction_failed` | Check the Core Status/schema and database health; do not repeatedly submit the form. |
| `unexpected_failure` | Preserve the timestamp and category for engineering review; do not copy raw server errors into tickets. |

The generic historical message therefore cannot establish that Yahoo SMTP was attempted. A retry should use the screen's new command key only after the category is understood; the failed transaction creates no invitation, generation, or outbox state to resend.

## 2. Dispatch failed after a durable invitation was created

`Invitation dispatch failed; the recorded status below is authoritative.` is different. It occurs only after issuance committed and an attempt to hand off to WordPress mail returned failure. Inspect the invitation table's delivery outcome (`failed`, `pending`, `prepared`, or `delivered`) and its safe reason code. `delivered` means accepted by the WordPress mail transport, not delivered to a recipient mailbox. A replacement invitation, not reuse of a prepared secret, is the safe retry mechanism.

## Gmail mailbox troubleshooting (separate from SMTP)

A controlled Yahoo-recipient SMTP test that was accepted/delivered is evidence that the configured path could deliver to that Yahoo mailbox at that time. It does **not** prove that Gmail accepted or placed a teacher invitation. When Gmail is missing while Yahoo receives mail, keep the investigation at the recipient-delivery boundary:

1. Confirm the invitation row says `delivered`/accepted by WordPress mail transport; if it says `failed`, use the durable reason code instead of Gmail troubleshooting.
2. Have the intended Gmail recipient inspect Spam, All Mail, Promotions, and any workspace quarantine/admin logs. Do not ask them to forward a one-time invitation body or code.
3. Compare a non-secret message header, if the recipient can provide it, for envelope sender, `From`, `Return-Path`, DKIM result, SPF result, DMARC alignment, and receiving-server rejection/defer evidence. Redact addresses and message identifiers before sharing it internally.
4. Ask the sending-domain administrator to verify SPF, DKIM signing, DMARC alignment, sending IP/reputation, reverse DNS, and any provider suppression/bounce records. Gmail can apply recipient-specific filtering even where Yahoo accepts the same transport.
5. Use a controlled staging recipient and the existing explicitly marked Email integration test only with owner approval. Never use a live teacher invitation as a mail diagnostic, and never put SMTP credentials, invitation secrets, or raw SMTP transcripts in this runbook or a ticket.

No application change can truthfully turn `wp_mail()` acceptance into a Gmail delivery guarantee.
