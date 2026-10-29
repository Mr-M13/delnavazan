# Delnavazan notification editorial standard

Version: 2026-10-29 · applies to email, WhatsApp, SMS and website notifications.

## 1. Voice

Warm, respectful, concise and professional — the voice of a Persian music academy that teaches students
in Iran and internationally. Write to one person. Prefer a calm sentence over an administrative formula,
and never mix a friendly opening with a bureaucratic body.

- Address the recipient directly: «شما».
- Use «هنرجو»، «مدرس»، «کلاس» — not «دانشجو»، «استاد کلاس» or «معلم» in student-facing copy.
- Do not use honorific stacks («جنابعالی»، «به استحضار میرساند») in operational messages.
- Never blame the recipient. Describe the fact, the next step and the deadline.
- Do not use marketing urgency for an operational event.

## 2. Mandatory structure

Every message answers, in this order:

1. **What happened?** One sentence.
2. **What must the recipient do?** One sentence, only if action is required.
3. **When is the class/deadline?** The authoritative instant and timezone.
4. **Where can they act?** One primary link to the authoritative screen.

If a message does not need action, say so explicitly instead of inventing a button.

## 3. Terminology

| Use | Avoid |
|---|---|
| هنرجو | دانشجو، شاگرد، کاربر |
| مدرس | استاد (unless it is a name), معلم |
| کلاس | درس، سشن |
| کلاس آشنایی | جلسهٔ آزمایشی، کلاس تست |
| جلسهٔ جبرانی | کلاس جبرانی، معادل |
| تغییر زمان کلاس | جابه‌جایی، ری‌شجویل |
| تأیید زمان پیشنهادی | اوکی کردن، قبول زمان |
| حساب کاربری | اکانت، پروفایل (unless it means profile data) |
| پرداخت | تسویه (unless finance-internal) |
| تمدید دوره | شارژ مجدد، خرید دوباره |
| پرتال هنرجو / پرتال مدرس | پنل، داشبورد (in student/teacher copy) |

## 4. Time, date and timezone

- Use the recipient's configured timezone; never the server timezone.
- Respect the configured calendar preference (Persian or Gregorian).
- Always include the timezone when the recipient may be outside Iran.
- Avoid abbreviations such as «IRST»، «CET» or «UTC+3:30». Prefer «به وقت تهران».
- Persian digits and Persian month names are the default in Persian copy.
- Never write a bare «فردا» or «این هفته»; state the date.
- A deadline is a date plus a time, or explicitly «پایان روز».

## 5. Channel rules

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
- SMS is a constrained fallback, never an automatic consequence of a WhatsApp failure.

### Website inbox

- Title ≤ 60 characters; one or two sentences.
- Link to the authoritative class, booking, payment or task screen.
- Read/unread state is presentation only: clicking a notification never approves anything.
- No one-time secret is ever stored in the inbox.

## 6. Category rules

| Category | Tone | Opt-out |
|---|---|---|
| Security | Direct, factual, no marketing | Not permitted |
| Account | Calm and operational | Not permitted |
| Booking | Helpful, time-specific | Not permitted while a booking is live |
| Lesson | Operational, time-zone explicit | Not permitted for changes already made |
| Payment | Precise, neutral, no pressure | Not permitted; requires financial/legal review before activation |
| Optional | Warm but restrained | Requires marketing consent |

Operational authority comes from the teaching relationship, not from marketing consent. Marketing consent
is never used to justify a booking, class, payment or security message.

## 7. Examples

**Class confirmed (email, student)**

> سلام باران،
>
> کلاس آشنایی «تار» با استاد ندا تأیید شد.
>
> زمان کلاس: شنبه ۱۰ آبان ۱۴۰۵، ساعت ۱۷:۰۰ به وقت تهران
> لینک جلسه: …
>
> لطفاً کمی پیش از زمان کلاس آماده باشید. پیوند جلسه را در اختیار دیگران قرار ندهید.
>
> برای دیدن برنامهٔ کلاس‌ها به پرتال هنرجو بروید: …
>
> با احترام، دلنوازان

**Reschedule proposed (website, student)**

> برای شما یک زمان تازه برای کلاس پیشنهاد شد: …
> لطفاً در پرتال هنرجو آن را بپذیرید یا رد کنید. تا پاسخ شما، زمان فعلی کلاس تغییر نمی‌کند.

**Absence reported (email, teacher)**

> سلام استاد ندا،
>
> باران برای جلسهٔ «تار» در … اطلاع غیبت فرستاده است.
> لطفاً با هنرجو هماهنگ کنید و در صورت توافق، زمان جایگزین همین جلسه را در پرتال مدرس ثبت کنید: …

**Student account invitation (security)**

> کد یک‌بارمصرف زیر را در صفحهٔ دعوت‌نامه وارد کنید: …
> این کد فقط یک‌بار قابل استفاده است و برای دیگران قابل بازگویی نیست.

## 8. Review rules

- Financial, refund and renewal copy must be reviewed by the academy's finance/legal owner before the
  category is activated. The dashboard labels this explicitly.
- Security copy must be reviewed together with the claim/invitation flow; never add extra variables.
- Any change to a WhatsApp template requires a new Meta approval before sending.
- Persian and English variants must carry the same facts, deadline and action; they need not be literal
  translations.
- An editor may not change the recipient, the channel eligibility, the consent rule or the business
  action. Those are owned by the producing service.
