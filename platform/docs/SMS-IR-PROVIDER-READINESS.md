# SMS provider routing and SMS.ir activation

SMS is an independently enabled notification channel. It is never a fallback for WhatsApp or another SMS provider.

## Administrator configuration

Settings > Integrations > SMS contains two saved provider preferences:

- Iranian mobile destinations (`+989` followed by nine digits): **SMS.ir** by default.
- All other destinations: **Telnyx** by default.

Routing depends on the normalised destination number, regardless of whether the recipient is a teacher or student. Admins with provider-integration management access can select either provider for either destination group. The settings are stored in `dzn_sms_provider_routing`; existing settings default to the choices above. Selecting an unavailable provider does not silently use the other provider. This is an explicit configuration choice, not automatic fallback.

## SMS.ir approved templates

SMS.ir carries the Iranian (`+98`) route. The account has two approved templates, and every
event-owned SMS in the booking, lesson and renewal queues is mapped to one of them:

| Template | Body | Used for |
|---|---|---|
| Account referral | `دلنوازان: #NAME# عزیز، لطفا به حساب کاربری خود مراجعه کنید.` | Every event except a Teacher's new introductory booking |
| New intro booking request | `دلنوازان : درود #NAME# عزیز` / `هنرجویی برای کلاس آشنایی #INSTRUMENT# در تاریخ #DATE# ساعت #TIME# از شما درخواست کرده.` / `لطفا برای تأیید یا رد به حساب کاربری سر بزنید.` / `با تشکر،` | `booking.new.teacher` only |

The mapping is closed and tested in `SmsIrTemplateRegistry` and is shown in the notification
editor's SMS tab. Dedicated one-time-secret messages, such as the Teacher invitation SMS, keep
their own secure body and are not routed through the generic template.

## SMS.ir readiness

1. Verify the SMS.ir account, sender/line, API endpoint, authentication and Iran delivery rules
   against current provider documentation.
2. Save the write-only API key, sender and endpoint under Integrations → SMS.ir.
3. Optionally save the two provider template IDs (`template_account_referral_id` and
   `template_intro_booking_id`) and a template endpoint. When an ID is present, event SMS uses
   the SMS.ir template endpoint with the uppercase parameters `NAME`, `INSTRUMENT`, `DATE` and
   `TIME`; when it is blank, the adapter sends the exact approved body through the bulk endpoint.
4. Run the explicit provider test and confirm a received message on an Iranian number.
5. Test overseas destinations through Telnyx, provider unavailability, consent, suppression,
   retries and duplicate prevention.

SMS.ir never falls back to Telnyx, and Telnyx never falls back to SMS.ir. A destination mapped to
an unconfigured SMS.ir binding stays unavailable until setup is completed; an administrator may
deliberately change the route under Settings → Integrations → SMS.
