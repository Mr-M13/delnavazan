# ChatGPT Ads Conversions API — introductory bookings

This integration is **off until a key is configured**. In the production server's `wp-config.php`, before the WordPress bootstrap line, add:

```php
define('DZN_CHATGPT_ADS_API_KEY', getenv('DZN_CHATGPT_ADS_API_KEY'));
define('DZN_CHATGPT_ADS_PIXEL_ID', '6wyWvkz5rmQXEPAC4T2Zm9');
```

Set `DZN_CHATGPT_ADS_API_KEY` in the web/PHP environment through the host's secret-management mechanism. Alternatively set the key directly in the private `wp-config.php` (never in the Git repository). Do not expose it in JavaScript or dashboard HTML.

The hourly WP-Cron worker checks provider-confirmed introductory bookings, requires the canonical request and delivery to be confirmed, excludes cancelled lessons and God Mode test sessions (which have a separate test-session data model), and posts `appointment_scheduled` to `https://bzr.openai.com/v1/events`. It uses a stable `dzn-intro-<confirmation UID>` event ID and records delivery/retry state in non-autoloaded WordPress options (`dzn_ads_intro_<confirmation ID>`). It deliberately does not backfill historical conversions: the first plugin load sets the eligibility watermark. Only bookings confirmed in the last 30 days are scanned. A 2xx HTTP response is treated as accepted; inspect actual API response semantics before production enablement.

The source URL is the site's home URL, **not a claimed per-booking landing URL**. No `oppref` attribution or browser pixel deduplication is implemented yet. These require explicit attribution storage and verified API field support. This implementation is a backend-only first phase. It must be validated against the live provider sandbox/validate-only flow before enabling real delivery. WP-Cron must be running reliably.

Operations: `wp option get dzn_chatgpt_ads_started_at`; inspect `dzn_ads_intro_<id>` for delivery state without exposing the API key. A key is never logged. To disable outbound requests, remove the key from configuration. To reset a failed event, delete its `dzn_ads_intro_<id>` option (do not delete delivered records, which would risk duplicate reporting).
