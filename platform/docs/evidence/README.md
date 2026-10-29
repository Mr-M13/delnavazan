# Evidence: unified notification management

These files are read-only visual evidence captured from a **disposable local WordPress** instance
(`http://127.0.0.1:18081`). The WordPress administration interface is English; notification copy and
portal content remain Persian with RTL presentation. provisioned by `acceptance/`, with synthetic `@example.invalid` identities
only. No production site, provider credential or real recipient was used.

- `admin-templates.html` — **Templates**: 90 event/channel/locale rows, version state and warnings.
- `admin-editor.html` — editor: allowed/required placeholders, active version, synthetic Persian
  preview (RTL), draft/publish/restore controls and immutable version history.
- `admin-rules.html` — **Delivery Rules**: category channel policy and event → channel mapping.
- `admin-monitoring.html` — **Delivery Monitoring**: aggregate states, hand-off vs verified delivery wording,
  diagnostics and portal inbox counts.
- `admin-channels.html` — **Channels**: secret-free readiness, WhatsApp template mapping and SMS
  segmentation rule.
- `admin-website.html` — **Portal Notifications**: student/teacher/admin counts, recent rows, retention and the
  admin-only test action.
- `student-portal-notifications.html` — Student shell with bell, unread badge and account inbox.
- `teacher-portal-notifications.html` — Teacher shell with bell, unread badge and inbox section.
- `email-preview.html` — branded RTL email with ivory/turquoise/pomegranate palette, logo, one primary
  action and plain-text body.
- `portal-notifications-preview.html` — both inbox partials rendered together for layout review.

Nonce values in the captured HTML are redacted (`[redacted]`). The files are static evidence only; their
forms and links are inert. Provider/transport readiness shown in them is intentionally
`not_configured` — deployment of this change does not activate any messaging channel.

PNG screenshots could not be captured because the automation desktop window was not visible; the HTML
renders the same DOM/CSS and can be opened in a browser for pixel review.
