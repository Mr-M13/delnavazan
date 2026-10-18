#!/usr/bin/env bash
set -Eeuo pipefail

readonly HOST="cp119.hosting-cloud.net"
readonly ROOT_REL="domains/delnavazan.com/public_html"
readonly SHA_RE='^[0-9a-f]{40}$'

fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
[[ $# == 1 && "$1" =~ $SHA_RE ]] || fail 'usage: production-google-oauth-diagnostic.sh <full-current-main-sha>'
: "${PRODUCTION_SSH_USER:?Set the protected production SSH user}"
[[ "$PRODUCTION_SSH_USER" =~ ^[A-Za-z0-9._-]+$ ]] || fail 'SSH username contains unsupported characters'

ssh -T "${PRODUCTION_SSH_USER}@${HOST}" bash -s -- "$ROOT_REL" <<'REMOTE'
set -Eeuo pipefail
root_rel="$1"
[[ "$root_rel" == domains/delnavazan.com/public_html ]] || { echo 'FAIL invalid diagnostic target' >&2; exit 1; }
root="$HOME/$root_rel"
[[ -f "$root/wp-load.php" ]] || { echo 'FAIL expected WordPress root is unavailable' >&2; exit 1; }
command -v wp >/dev/null || { echo 'FAIL WP-CLI is required' >&2; exit 1; }

# The query deliberately excludes teacher IDs, state/code digests, OAuth codes, client IDs,
# credentials and every secret. It establishes only whether the browser callback reached the
# authority and which safe lifecycle phase stopped progressing.
state="$(wp --path="$root" --skip-themes eval '
global $wpdb;
$prefix = $wpdb->prefix . "dzn_";
$connectionCounts = $wpdb->get_results("SELECT connection_state AS state, COUNT(*) AS count FROM {$prefix}integration_connections WHERE provider_code=\"google_calendar\" GROUP BY connection_state ORDER BY connection_state", ARRAY_A) ?: [];
$authorizationCounts = $wpdb->get_results("SELECT authorization_state AS state, COUNT(*) AS count FROM {$prefix}integration_oauth_authorizations WHERE provider_code=\"google_calendar\" GROUP BY authorization_state ORDER BY authorization_state", ARRAY_A) ?: [];
$latestConnection = $wpdb->get_row("SELECT connection_state, identity_state, active_slot, failure_reason_code, authorized_at, connected_at FROM {$prefix}integration_connections WHERE provider_code=\"google_calendar\" ORDER BY id DESC LIMIT 1", ARRAY_A);
$latestAuthorization = $wpdb->get_row("SELECT authorization_state, consumption_result, failure_reason_code, issued_at, expires_at, consumed_at FROM {$prefix}integration_oauth_authorizations WHERE provider_code=\"google_calendar\" ORDER BY id DESC LIMIT 1", ARRAY_A);
$settingsReady = false;
$transportConfigured = false;
$providerReachability = "unavailable";
try {
    $settings = new \\Delnavazan\\Platform\\Core\\Application\\IntegrationSettingsService();
    $settingsReady = $settings->ready("google");
    $transportConfigured = \\Delnavazan\\Platform\\Integrations\\GoogleOAuthTransport::isConfigured();
    $providerReachability = (new \\Delnavazan\\Platform\\Integrations\\IntegrationTestRunner())->run("google", []) ["code"] ?? "unavailable";
} catch (Throwable) {
    $providerReachability = "diagnostic_unavailable";
}
echo wp_json_encode([
    "settings_ready" => $settingsReady,
    "transport_configured" => $transportConfigured,
    "provider_reachability" => $providerReachability,
    "connection_counts" => $connectionCounts,
    "authorization_counts" => $authorizationCounts,
    "latest_connection" => $latestConnection ?: null,
    "latest_authorization" => $latestAuthorization ?: null,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
')"
printf 'GOOGLE_OAUTH_LIFECYCLE=%s\n' "$state"
REMOTE
