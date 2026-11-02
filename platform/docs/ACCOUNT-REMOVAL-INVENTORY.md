# Permanent account removal: schema and ownership inventory

Source snapshot: current `main` schema 57, `platform/src/Core/Infrastructure/Migration/Migrator.php` and the feature-local LiveKit schema. Parsed 241 distinct `CREATE TABLE` declarations. This is a source inventory, not a verified production database inventory.

## Relationship map

| Table | Relevant ownership or dependency columns |
| --- | --- |
| `accepted_service_arrangements` | `accepting_wordpress_user_id`, `booking_request_id`, `guardian_grant_id`, `principal_link_id`, `student_id`, `teacher_id` |
| `account_claim_attempts` | `wordpress_user_id` |
| `booking_request_confirmations` | `booking_request_id`, `connection_id`, `teacher_id` |
| `booking_request_contact_snapshots` | `booking_request_id` |
| `booking_request_duplicate_flags` | `booking_request_id`, `candidate_booking_request_id` |
| `booking_request_identity_resolution_events` | `booking_request_id`, `student_id` |
| `booking_request_intro_outcomes` | `booking_request_id`, `student_id`, `teacher_id` |
| `booking_request_privacy_tombstones` | `booking_request_id` |
| `booking_request_requested_times` | `booking_request_id` |
| `booking_request_student_notifications` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `booking_request_submission_keys` | `booking_request_id` |
| `booking_request_teacher_commands` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `booking_request_teacher_deliveries` | `booking_request_id`, `teacher_id` |
| `booking_requests` | `student_id` |
| `canonical_academy_obligations` | `enrolment_id`, `source_lesson_id`, `student_id`, `teacher_assignment_id`, `teacher_id` |
| `canonical_attendance_case_anomalies` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `canonical_attendance_cases` | `enrolment_id`, `lesson_id`, `student_id`, `teacher_assignment_id`, `teacher_id` |
| `canonical_attendance_commands` | `lesson_id` |
| `canonical_attendance_conflicts` | `lesson_id` |
| `canonical_attendance_cutover_policies` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `canonical_attendance_decisions` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `canonical_attendance_evidence` | `lesson_id`, `resolved_student_id`, `resolved_teacher_id` |
| `canonical_attendance_participant_mappings` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `canonical_continuation_cases` | `enrolment_id`, `intro_lesson_id`, `student_id`, `teacher_assignment_id`, `teacher_id` |
| `canonical_continuation_commands` | `intro_lesson_id`, `student_id`, `teacher_id` |
| `canonical_continuation_decisions` | `guardian_grant_id`, `principal_link_id` |
| `canonical_continuation_interventions` | `intro_lesson_id`, `student_id`, `teacher_id` |
| `canonical_continuation_reservations` | `source_intro_lesson_id`, `student_id`, `teacher_id` |
| `canonical_continuation_slot_authorities` | `intro_lesson_id`, `student_id`, `teacher_id` |
| `canonical_lesson_commands` | `enrolment_id`, `expected_lesson_id`, `expected_teacher_assignment_id`, `replacement_origin_lesson_id`, `result_lesson_id` |
| `canonical_lesson_delivery_commands` | `enrolment_id`, `lesson_id`, `teacher_id` |
| `canonical_lesson_delivery_outcomes` | `enrolment_id`, `lesson_id`, `teacher_assignment_id`, `teacher_id` |
| `canonical_lesson_lifecycle_events` | `lesson_id` |
| `canonical_lesson_schedule_commands` | `enrolment_id`, `lesson_id`, `teacher_id` |
| `canonical_lesson_schedule_events` | `lesson_id` |
| `canonical_lesson_schedule_versions` | `enrolment_id`, `lesson_id`, `teacher_assignment_id`, `teacher_id` |
| `checkout_sessions` | `offer_id`, `student_id` |
| `collection_intent_commands` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `collection_intent_events` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `collection_intents` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `commercial_account_adjustment_events` | `offer_id` |
| `commercial_account_adjustments` | `beneficiary_student_id`, `consumed_offer_id` |
| `commercial_account_roots` | `student_id` |
| `commercial_capacity_claim_intervals` | `satisfied_lesson_id`, `teacher_id` |
| `commercial_capacity_claims` | `purchase_id`, `student_id`, `teacher_id` |
| `commercial_commands` | `offer_id`, `purchase_id`, `student_id`, `teacher_id` |
| `commercial_entitlements` | `beneficiary_student_id`, `enrolment_id`, `purchase_id` |
| `commercial_exceptions` | `offer_id`, `purchase_id`, `student_id`, `teacher_id` |
| `commercial_obligation_settlements` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `commercial_offer_adjustments` | `offer_id` |
| `commercial_offer_obligations` | `offer_id` |
| `commercial_offer_policies` | `offer_id` |
| `commercial_offers` | `beneficiary_student_id`, `guardian_grant_id`, `intro_lesson_id`, `principal_link_id`, `student_id`, `teacher_id` |
| `commercial_payment_evidence` | `offer_id`, `purchase_id` |
| `commercial_payment_facts` | `purchase_id` |
| `commercial_policies` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `commercial_prices` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `commercial_products` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `commercial_promotion_redemptions` | `beneficiary_student_id`, `offer_id` |
| `commercial_promotions` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `commercial_purchases` | `beneficiary_student_id`, `offer_id` |
| `commercial_recurring_patterns` | `intro_lesson_id`, `student_id`, `teacher_id` |
| `commercial_term_funding_plans` | `enrolment_id`, `offer_id`, `purchase_id` |
| `coordination_case_candidates` | `teacher_id` |
| `coordination_cases` | `booking_request_id` |
| `core_dataset_corrections` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `core_dataset_operator_commands` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `core_dataset_provenance` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `core_dataset_reconciliation_findings` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `core_dataset_reconciliation_runs` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `courses` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `enrolment_conversion_commands` | `enrolment_id` |
| `enrolment_identity_roots` | `student_id` |
| `enrolment_lifecycle_commands` | `enrolment_id` |
| `enrolment_lifecycle_events` | `enrolment_id` |
| `enrolments` | `student_id`, `teacher_id` |
| `finance_cash_accounts` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `finance_cash_commands` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `finance_cash_reconciliations` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `finance_cash_transactions` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `finance_cash_transfers` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `finance_exceptions` | `lesson_id`, `teacher_id` |
| `finance_golden_hour_details` | `lesson_id` |
| `finance_lesson_snapshots` | `enrolment_id`, `lesson_id`, `teacher_assignment_id`, `teacher_id` |
| `finance_manual_refund_events` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `finance_manual_refund_reservations` | `offer_id`, `purchase_id`, `student_id` |
| `finance_notification_intents` | `student_id` |
| `finance_payability_commands` | `lesson_id`, `teacher_id` |
| `finance_payability_evaluations` | `lesson_id` |
| `finance_payability_overrides` | `lesson_id` |
| `finance_policies` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `finance_policy_commands` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `finance_policy_roots` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `finance_reconciliation_commands` | `teacher_id` |
| `finance_reconciliation_findings` | `lesson_id`, `teacher_id` |
| `finance_reconciliation_runs` | `teacher_id` |
| `finance_regional_prices` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `finance_settings` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `finance_snapshot_commands` | `lesson_id`, `teacher_id` |
| `finance_snapshot_corrections` | `lesson_id` |
| `finance_statement_batch_runs` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `finance_statement_commands` | `teacher_id` |
| `finance_statement_email_attempts` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `finance_statement_email_intents` | `teacher_id` |
| `finance_statement_events` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `finance_statement_lines` | `lesson_id`, `teacher_id` |
| `finance_statements` | `teacher_id` |
| `finance_teacher_rate_commands` | `teacher_id` |
| `finance_teacher_rate_events` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `finance_teacher_rates` | `teacher_id` |
| `finance_teacher_roots` | `teacher_id` |
| `instrument_intro_course_defaults` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `instruments` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `integration_connections` | `teacher_id` |
| `integration_credentials` | `connection_id` |
| `integration_oauth_authorizations` | `connection_id`, `principal_user_id`, `teacher_id` |
| `intro_checkout_sessions` | `student_id` |
| `intro_payment_commands` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `intro_payment_events` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `intro_payment_obligations` | `booking_request_id`, `student_id`, `teacher_id` |
| `lesson_reschedule_calendar_jobs` | `lesson_id` |
| `lesson_reschedule_events` | `student_id` |
| `lesson_reschedule_notifications` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `lesson_reschedule_proposals` | `enrolment_id`, `lesson_id`, `student_id`, `teacher_assignment_id`, `teacher_id` |
| `lesson_schedule_versions` | `lesson_id` |
| `lessons` | `enrolment_id`, `replacement_for_lesson_id`, `student_id`, `teacher_id` |
| `livekit_attendance_connections` | `lesson_id` |
| `livekit_attendance_events` | `connection_id` |
| `livekit_attendance_rooms` | `enrolment_id`, `lesson_id`, `student_id`, `teacher_id` |
| `notification_attempt_events` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `notification_attempts` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `notification_commands` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `notification_deliveries` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `notification_events` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `notification_privacy_tombstones` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `notification_rendered_snapshots` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `notification_suppression_commands` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `notification_suppression_events` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `notification_suppressions` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `notification_template_commands` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `notification_template_versions` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `notification_templates` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `notification_workflow_commands` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `notification_workflow_rules` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `notification_workflow_versions` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `notification_workflows` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `notifications` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `operational_exceptions` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `payment_execution_attempts` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `payment_execution_commands` | `purchase_id`, `student_id` |
| `payment_execution_dispatches` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `payment_execution_results` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `payment_provider_account_commands` | `payment_provider_account_id` |
| `payment_provider_account_events` | `payment_provider_account_id` |
| `payment_provider_accounts` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `payment_provider_event_decision_claims` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `payment_provider_event_decisions` | `offer_id`, `purchase_id` |
| `payment_provider_event_receipts` | `payment_provider_account_id` |
| `payment_provider_events` | `payment_provider_account_id` |
| `payment_provider_object_commands` | `payment_provider_object_id` |
| `payment_provider_object_events` | `payment_provider_object_id` |
| `payment_provider_objects` | `payment_provider_account_id` |
| `payment_provider_secret_events` | `payment_provider_account_id` |
| `payment_provider_secrets` | `payment_provider_account_id` |
| `platform_audit_events` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `platform_outbox` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `portal_access_denials` | `lesson_id`, `principal_id` |
| `portal_lesson_capability_roots` | `lesson_id` |
| `portal_notifications` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `portal_public_action_events` | `lesson_id`, `resolved_student_id` |
| `portal_public_capabilities` | `lesson_id`, `subject_student_id` |
| `portal_public_capability_commands` | `lesson_id` |
| `portal_public_capability_events` | `lesson_id` |
| `post_intro_activation_jobs` | `offer_id`, `purchase_id` |
| `post_intro_preparations` | `booking_request_id`, `enrolment_id`, `intro_lesson_id`, `offer_id`, `student_id`, `teacher_id` |
| `proposal_acceptance_events` | `booking_request_id` |
| `proposal_families` | `booking_request_id` |
| `proposal_option_outcome_events` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `proposal_options` | `teacher_id` |
| `proposal_versions` | `booking_request_id`, `teacher_id` |
| `provider_calendar_event_mappings` | `connection_id`, `lesson_id` |
| `provider_event_conflicts` | `lesson_id` |
| `provider_identity_mappings` | `connection_id`, `teacher_id` |
| `provider_ingest_events` | `connection_id`, `lesson_id` |
| `provider_ingest_outcomes` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `provider_integration_commands` | `connection_id`, `lesson_id`, `teacher_id` |
| `provider_meeting_mappings` | `connection_id`, `lesson_id` |
| `provider_projection_secrets` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `recording_decision_commands` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `recording_decisions` | `guardian_actor_user_id` |
| `recording_notice_versions` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `recovery_case_commands` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `recovery_case_events` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `recovery_cases` | `recurring_enrolment_id` |
| `recurring_enrolment_commands` | `recurring_enrolment_id` |
| `recurring_enrolment_events` | `recurring_enrolment_id` |
| `recurring_enrolments` | `enrolment_id`, `student_id` |
| `recurring_protection_commands` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `recurring_protection_events` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `recurring_protections` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `refund_review_cases` | `purchase_id` |
| `refund_review_commands` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `refund_review_events` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `renewal_cycle_commands` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `renewal_cycle_events` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `renewal_cycles` | `recurring_enrolment_id` |
| `student_acceptance_authority_grants` | `acting_wordpress_user_id`, `student_id` |
| `student_acceptance_capacity_classifications` | `student_id` |
| `student_account_claim_events` | `principal_user_id` |
| `student_account_claim_generations` | `claimed_principal_id` |
| `student_account_claim_invitations` | `claimed_principal_id`, `student_id` |
| `student_account_invitation_generations` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `student_account_invitations` | `student_id` |
| `student_principal_links` | `student_id`, `wordpress_user_id` |
| `student_schedule_roots` | `student_id` |
| `students` | `wordpress_user_id` |
| `teacher_accepting_states` | `teacher_id` |
| `teacher_assignment_commands` | `enrolment_id`, `teacher_id` |
| `teacher_assignment_lifecycle_events` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `teacher_assignments` | `enrolment_id`, `teacher_id` |
| `teacher_availability_assent_snapshots` | `booking_request_id`, `teacher_id` |
| `teacher_availability_assents` | `booking_request_id`, `teacher_actor_user_id`, `teacher_id` |
| `teacher_availability_exceptions` | `teacher_id` |
| `teacher_availability_profiles` | `teacher_id` |
| `teacher_availability_rules` | `teacher_id` |
| `teacher_course_eligibilities` | `teacher_id` |
| `teacher_induction_progress` | `teacher_id` |
| `teacher_invitation_generations` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `teacher_invitations` | `teacher_id` |
| `teacher_onboarding_events` | `teacher_id` |
| `teacher_onboarding_states` | `teacher_id` |
| `teacher_principal_links` | `teacher_id`, `wordpress_user_id` |
| `teacher_profile_links` | `teacher_id` |
| `teacher_schedule_roots` | `teacher_id` |
| `teachers` | `wordpress_user_id` |
| `telnyx_events` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `telnyx_messages` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `telnyx_templates` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `term_commands` | `enrolment_id` |
| `term_lifecycle_events` | No direct column in this shortlist; inspect via parent IDs, digests, command IDs, or user ownership. |
| `terms` | `enrolment_id` |

## Blocking ownership chains

- Student and Teacher roots link to `*_principal_links`, WordPress users, invitations and claim generations/attempts. A WordPress identity can be shared across domains or own posts/comments.
- Booking requests lead through coordination, proposals, acceptance, intro obligations and enrolment conversion. Enrolments lead to terms, lessons, schedules, attendance, portal capabilities, reschedule events, provider mappings and LiveKit attendance.
- Commercial offers, purchases, entitlements, payment evidence, provider objects and finance tables contain retained accounting evidence. A direct `student_id` or `teacher_id` count cannot prove that their descendants are absent.
- Recording decisions and notices, consent/acceptance evidence, notification outbox and rendered snapshots can be referenced by intermediate IDs or digests and require retention classification.
- Integration connections, OAuth authorizations, provider identity mappings, calendar event and meeting mappings, checkout sessions, notifications, and LiveKit rooms may have remote resources. Database transactions cannot undo WordPress hooks or provider calls.
- The current schema has no immutable disposable-test attestation. An administrator-editable flag or account email would not be sufficient proof.

## Current implementation boundary

The administrator screen performs read-only direct-reference discovery for both account types and produces counts, blockers, and a manifest digest. It always blocks deletion because disposable-test provenance and indirect/external ownership are unverified. No execution route, token, or production deletion exists in this branch.

## Required before enabling execution

1. Introduce immutable, creation-time test provenance and verify it against a reserved identity namespace and WordPress ownership.
2. Classify every descendant edge into disposable, retained, shared, or remote; add explicit dependency-order operations and refusal rules.
3. Define external cleanup contracts and reconciliation states for scheduling, calendar, notifications, payment provider and recordings.
4. Add fresh reauthentication, manifest-bound single-use execution token, immediately repeated discovery and atomic claim of the token.
5. Use WordPress account deletion APIs only after content ownership is resolved; verify both platform and portal absence and emit a PII-minimized receipt.
6. Exercise successful and partial cases on a disposable database; never use production data in automated tests.
