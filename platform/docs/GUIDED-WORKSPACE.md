# Guided academy workspace

The WordPress and Operations portal entry points present four areas: Teachers, Students, Classes, and Admin. They are navigation and read-only context. Existing Platform controllers still own writes, permissions, review decisions, and audit events. The WordPress submenu registrations remain intact for direct-page authorization; only their sidebar presentation is collapsed.

## Teachers

Invite from one email at **Teachers → Invite and review teachers**. The minimal Teacher record and invitation intent commit together. The teacher claims the email-bound invitation, creates or connects a WordPress account, fills profile and availability, and submits for staff review. A linked but unapproved teacher remains `not_ready`. The invitation table distinguishes queued, transport-accepted, failed, uncertain, and claimed states. Staff may reissue an existing teacher invitation after resolving mail problems.

## Students

Students create a WordPress account directly in the booking form, or sign in to an existing account before booking. The request records that account ID and appears in its Student Portal immediately. An email match alone never grants access to an older or different booking. The account is a login and request owner, not yet a canonical Student principal or guardian. Staff still review identity and authority before accepting or linking an enrolment, including minors and duplicate identities. No student invitation is required.

The account shows delivery metadata only for system notifications tied to its own booking and addressed to the student. The notification store does not retain a readable sent body, and the current workflow set has no booking acknowledgement intent. Therefore this is not yet a full correspondence archive. Manual staff email and WhatsApp messages remain outside this scope. A later message archive needs a dedicated privacy and recipient-authority contract.

## Classes

The class journey joins each request to its coordination case, accepted arrangement, enrolment, assignment, term, and lessons, with a permission-filtered next action. It also displays matching attention. Each transition still uses the owning command screen. A transport-accepted email, completed introductory slot, or checkout return is not treated as proof of teacher readiness, delivered teaching, or settled payment. Automatic payment-to-term creation and Google Meet projection require their own verified triggers and failure/recovery contract before activation.

## Admin

Advanced pricing, communications, finance, staff, and health tools remain available under Admin. Capabilities filter each link; collapsing navigation does not grant authority.
