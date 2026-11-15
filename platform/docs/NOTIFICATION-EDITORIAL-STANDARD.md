# Delnavazan notification editorial standard

Version: 2026-10-29 · applies to email, WhatsApp, SMS and website notifications.

## 1. Voice

Warm, respectful, concise and professional — the voice of a Persian music academy that teaches students
in Iran and internationally. Write to one person. Prefer a calm sentence over an administrative formula,
and never mix a friendly opening with a bureaucratic body.

- Open with «درود». Do not use «سلام» in new customer-facing copy.
- Address the recipient directly: «شما».
- Use «هنرجو»، «مدرس»، «کلاس» — not «دانشجو»، «استاد کلاس» or «معلم» in student-facing copy.
- Do not use honorific stacks («جنابعالی»، «به استحضار میرساند») in operational messages.
- Never blame the recipient. Describe the fact, the next step and the deadline.
- Do not use marketing urgency for an operational event.

## 2. Canonical identity

Public identity comes from `platform/src/Core/Support/BrandIdentity.php`: دلنوازان · `+61 413 413 004` ·
WhatsApp `+61413413004` · `admin@delnavazan.com` · `https://delnavazan.com`. The authenticated sender
(`no-reply@delnavazan.com` by default) is separate from this public contact identity and must not be
changed by copy edits.

## 3. Mandatory structure

Every message answers, in this order:

1. **What happened?** One sentence.
2. **What must the recipient do?** One sentence, only if action is required.
3. **When is the class/deadline?** The authoritative instant and timezone.
4. **Where can they act?** One primary link to the authoritative screen.

If a message does not need action, say so explicitly instead of inventing a button.

## 4. Terminology

| Use | Avoid |
|---|---|
| هنرجو | دانشجو، شاگرد، کاربر |
| مدرس | استاد (unless it is a name), معلم |
| کلاس | درس، سشن |
| کلاس آشنایی | کلاس معارفه، جلسهٔ آزمایشی، کلاس تست |
| جلسهٔ جبرانی | کلاس جبرانی، معادل |
| تغییر زمان کلاس | جابه‌جایی، ری‌شجویل |
| تأیید زمان پیشنهادی | اوکی کردن، قبول زمان |
| حساب کاربری | اکانت، پروفایل (unless it means profile data)، پرتال هنرجو |
| پرداخت | تسویه (unless finance-internal) |
| تمدید دوره | شارژ مجدد، خرید دوباره |
| پرتال مدرس | پنل، داشبورد (in teacher copy) |

## 5. Time, date and timezone

- Use the recipient's configured timezone; never the server timezone.
- Respect the configured calendar preference (Persian or Gregorian).
- Always include the timezone when the recipient may be outside Iran.
- Avoid abbreviations such as «IRST»، «CET» or «UTC+3:30». Prefer «به وقت تهران».
- Persian digits and Persian month names are the default in Persian copy.
- Never write a bare «فردا» or «این هفته»; state the date.
- A deadline is a date plus a time, or explicitly «پایان روز».

## 6. Channel rules

### Email

- Subject ≤ 60 characters where possible (hard limit 180), no emoji, no ALL CAPS.
- Body: greeting, fact, action, time/date, link, closing.
- One primary action. The branded shell supplies the button, palette, logo and footer.
- The body is plain text: line breaks are preserved; HTML is never authored.
- Never put a password, token, one-time code or full payment detail in a subject or a URL.

### WhatsApp

- Short conversational copy, ≤ 4 short paragraphs.
- The local copy is **not** the Meta-approved template. A Meta template name/language must be mapped and
  approved; changing local copy marks the mapping as requiring approval.
- Do not send a business-initiated template without an approved mapping and consent.

### SMS

- One idea, one action, ≤ 2 segments.
- Persian is UCS-2: 70 characters for one part, 67 per part thereafter. Keep links short.
- No one-time code in SMS unless the security policy explicitly permits that channel.
- SMS is an independent event-owned delivery method; it is sent only when enabled for that notification and the recipient is eligible.

### Website inbox

- Title ≤ 60 characters; one or two sentences.
- Link to the authoritative class, booking, payment or task screen.
- Read/unread state is presentation only: clicking a notification never approves anything.
- No one-time secret is ever stored in the inbox.

## 7. Category rules

The "recipient opt-out" column is about marketing consent only. It never limits the administrator's
delivery control described in §10: any notification and any offered channel can be switched off.

| Category | Tone | Recipient marketing opt-out |
|---|---|---|
| Security | Direct, factual, no marketing | Not offered; the message is not marketing |
| Account | Calm and operational | Not offered; the message is not marketing |
| Booking | Helpful, time-specific | Not offered while a booking is live |
| Lesson | Operational, time-zone explicit | Not offered for changes already made |
| Payment | Precise, neutral, no pressure | Not offered; requires financial/legal review before activation |
| Optional | Warm but restrained | Requires marketing consent |

Operational authority comes from the teaching relationship, not from marketing consent. Marketing consent
is never used to justify a booking, class, payment or security message.

## 8. Examples

**Class confirmed (email, student)**

> درود باران،
>
> کلاس آشنایی «تار» با استاد ندا تأیید شد.
>
> زمان کلاس: شنبه ۱۰ آبان ۱۴۰۵، ساعت ۱۷:۰۰ به وقت تهران
>
> این کلاس در محیط کلاس آنلاین دلنوازان برگزار می‌شود. کمی پیش از زمان کلاس وارد حساب کاربری خود شوید.
>
> برای دیدن برنامهٔ کلاس‌ها وارد حساب کاربری خود شوید: …
>
> با احترام، دلنوازان

**Reschedule proposed (website, student)**

> برای شما یک زمان تازه برای کلاس پیشنهاد شد: …
> لطفاً در حساب کاربری آن را بپذیرید یا رد کنید. تا پاسخ شما، زمان فعلی کلاس تغییر نمی‌کند.

**Absence reported (email, teacher)**

> درود استاد ندا،
>
> باران برای جلسهٔ «تار» در … اطلاع غیبت فرستاده است.
> لطفاً با هنرجو هماهنگ کنید و در صورت توافق، زمان جایگزین همین جلسه را در پرتال مدرس ثبت کنید: …

**Student account invitation (security)**

> کد یک‌بارمصرف زیر را در صفحهٔ دعوت‌نامه وارد کنید: …
> این کد فقط یک‌بار قابل استفاده است و برای دیگران قابل بازگویی نیست.

## 9. Review rules

- Financial, refund and renewal copy must be reviewed by the academy's finance/legal owner before the
  category is activated. The dashboard labels this explicitly.
- Security copy must be reviewed together with the claim/invitation flow; never add extra variables.
- Any change to a WhatsApp template requires a new Meta approval before sending.
- Persian and English variants must carry the same facts, deadline and action; they need not be literal
  translations.
- An editor may not change the recipient, the channel eligibility, the consent rule or the business
  action. Those are owned by the producing service.

## 10. Administrator delivery control

An administrator may switch off any notification and any offered channel, including security, account and
payment notices, and including the portal and email routes that used to be locked.

- Disabling delivery is never a business decision. It does not cancel a class, reverse a payment, revoke an
  account, refuse consent or alter a transaction record. Those authorities stay with the services that own
  them.
- The catalogue shows a warning on sensitive notifications so the consequence of switching delivery off is
  explicit before the change is saved.
- A notification-wide switch off keeps the saved per-channel preferences. Re-enabling the notification
  restores exactly the channels that were on.
- A channel that is genuinely unsupported, missing its copy, missing its provider or awaiting Meta approval
  cannot be switched on. Disabling is always possible; enabling is fail-closed.
- Every administrator change is written to the notification audit log.
- Suppression is evaluated at enqueue, at claim and immediately before provider hand-off, so a queued
  notification or a retry obeys the current switch rather than the value it was created with.

## 11. Preparing and publishing copy

The workflow is three separate, named steps on the Notifications page. Each step reports its own
result and the last result stays on the page, so a step never looks like it did nothing.

1. **Prepare drafts** creates a reviewable draft for every supported template that has no published
   version and no draft. It never sends, publishes or enables anything, and it never overwrites a
   draft or a published version.
2. **Approve operational drafts** records one explicit approval per draft whose channel approvals are
   already complete. Finance, legal, security, dedicated-authority and unmapped-provider copy is
   skipped and listed with the reason it is held.
3. **Publish approved drafts** creates a new immutable version for each approved draft. It never
   enables a delivery channel and never changes a notification that already froze a version.

Approval is bound to one exact draft: editing the copy, discarding the draft or publishing it
invalidates the approval automatically, so modified copy always needs a fresh approval.

**Provider approval is separate.** A WhatsApp draft is only approved when the route is positively
mapped. Not mapped, rejected, pending re-approval, ambiguous, contract-mismatched or unrecognised
provider states are refused; a locally prepared draft is never presented as an approved Meta template.

Completeness is reported separately from readiness. A notification can have complete Persian and
English copy and still be unpublished, awaiting an approval, awaiting a provider template, or
impossible to raise because no producer is registered. Those are different facts and the
administration surface must not merge them into one badge.

### Status vocabulary

| Status | Meaning | Next action |
|---|---|---|
| Enabled | The channel is on and nothing blocks it | none |
| Disabled | An administrator switched it off | switch it on when intended |
| Awaiting approval | Sensitive canonical copy needs its finance, legal or security owner | publish it from the editor after that sign-off |
| Missing template | No usable copy exists for this route | prepare and approve a draft |
| Missing provider | Provider configuration or approval is absent | configure the provider, or wait for Meta approval |
| Not offered | This notification does not use this delivery method | none |
| Notification disabled | The event-wide switch is off | switch the notification on |
| No producer | No service raises this event yet | implement the producer |
