#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
deploy="$ROOT/deployment/production-deploy.sh"
workflow="$ROOT/.github/workflows/deploy-production.yml"
smoke="$ROOT/deployment/qa/production-smoke.sh"
static_diagnostic="$ROOT/deployment/qa/production-static-serving-diagnostic.sh"
static_diagnostic_contract="$ROOT/deployment/qa/production-static-serving-diagnostic-contract.sh"
theme_assets="$ROOT/theme/assets/css"
theme_enqueue="$ROOT/theme/inc/assets.php"
booking_enqueue="$ROOT/theme/inc/booking.php"
need(){ grep -Fq -- "$2" "$1" || { echo "FAIL $1 missing required production boundary: $2" >&2; exit 1; }; }
need "$deploy" '[[ $1 == production ]]'
need "$deploy" "domains/delnavazan.com/public_html"
need "$deploy" 'git fetch --no-tags origin main'
need "$deploy" '[[ "$2" == "$main_sha" ]]'
need "$deploy" 'git archive "$2" platform'
need "$deploy" 'git archive "$2" theme'
need "$deploy" 'theme package is missing required assets/css/$asset'
need "$deploy" 'expected_theme_css_sha'
need "$deploy" 'expected_booking_css_sha'
need "$deploy" 'installed_theme_css_sha'
need "$deploy" 'installed_booking_css_sha'
need "$deploy" 'assets/js/$asset'
need "$deploy" 'expected_navigation_js_sha'
need "$deploy" 'expected_booking_js_sha'
need "$deploy" 'installed_navigation_js_sha'
need "$deploy" 'installed_booking_js_sha'
need "$deploy" 'sha256sum'
need "$deploy" "php -l >/dev/null"
need "$deploy" 'staged_plugin="$staged/wp-content/plugins/delnavazan-platform"'
need "$deploy" 'atomic installation'
need "$deploy" 'trap on_exit EXIT'
need "$deploy" 'deployment_mutation_started=1'
need "$deploy" 'mv "$plugin" "$plugin_previous"'
need "$deploy" 'mv "$staged_plugin" "$plugin"'
need "$deploy" 'chmod 755 "$root/wp-content" "$root/wp-content/plugins" "$root/wp-content/themes" "$plugin" "$theme"'
need "$deploy" 'find "$plugin" "$theme" -type d -exec chmod 755 {} +'
need "$deploy" 'find "$plugin" "$theme" -type f -exec chmod 644 {} +'
need "$deploy" 'public application directory is not web-traversable'
if grep -Fq 'tar -xzf "$upload" -C "$root"' "$deploy"; then
  echo 'FAIL production deploy must not extract an uploaded package directly into the live tree' >&2
  exit 1
fi
committed_line="$(grep -nF 'deployment_committed=1' "$deploy" | tail -1 | cut -d: -f1)"
cleanup_line="$(grep -nF 'rm -rf "$plugin_previous" "$theme_previous"' "$deploy" | tail -1 | cut -d: -f1)"
[[ -n "$committed_line" && -n "$cleanup_line" && "$cleanup_line" -gt "$committed_line" ]] || { echo 'FAIL production deploy must retain previous app directories until post-deploy verification commits' >&2; exit 1; }
need "$deploy" 'db export'
need "$deploy" 'Migrator::maybe_upgrade()'
need "$deploy" 'main-${2:0:12}'
need "$deploy" 'production URL/indexability preflight failed'
need "$workflow" 'environment: production'
need "$workflow" 'refs/heads/main'
need "$workflow" 'deployment/production-deploy.sh "$TARGET" "$COMMIT_SHA"'
need "$workflow" 'deployment/qa/production-smoke.sh https://delnavazan.com'
need "$workflow" 'PRODUCTION_KNOWN_HOSTS'
need "$smoke" 'https://delnavazan.com'
need "$smoke" '/enrol/'
need "$smoke" 'booking-options'
need "$smoke" 'check_css_asset theme'
need "$smoke" 'check_css_asset booking'
need "$smoke" 'text/css'
need "$smoke" "check_theme_asset '/wp-content/themes/delnavazan-theme/style.css'"
need "$smoke" "check_theme_asset '/wp-content/themes/delnavazan-theme/assets/js/navigation.js'"
need "$smoke" "check_theme_asset '/wp-content/themes/delnavazan-theme/assets/js/booking.js'"
need "$smoke" 'noindex'
need "$smoke" '/booking/?instrument=setar'
need "$smoke" 'expected 302 to /enrol/?instrument=setar'
if grep -Fq 'expected 301 to /enrol/' "$smoke"; then
  echo 'FAIL production smoke still expects the retired permanent booking redirect' >&2
  exit 1
fi
for asset in theme.css booking.css; do
  [[ -s "$theme_assets/$asset" ]] || { echo "FAIL required theme CSS source missing: theme/assets/css/$asset" >&2; exit 1; }
done
for asset in navigation.js booking.js; do
  [[ -s "$ROOT/theme/assets/js/$asset" ]] || { echo "FAIL required theme JavaScript source missing: theme/assets/js/$asset" >&2; exit 1; }
done
need "$theme_enqueue" "get_theme_file_uri( 'assets/css/theme.css' )"
need "$booking_enqueue" "get_theme_file_uri( 'assets/css/booking.css' )"
bash -n "$static_diagnostic"
bash "$static_diagnostic_contract"
if grep -Fq 'staging.delnavazan.com' "$deploy" "$workflow" "$smoke"; then
  echo 'FAIL staging host referenced in production deployment tooling' >&2
  exit 1
fi
if grep -Fq '162.255.118.68' "$deploy" "$workflow" "$smoke"; then
  echo 'FAIL retired origin referenced in production deployment tooling' >&2
  exit 1
fi
echo 'PASS production deployment source contract'

grep -Fq 'wp --path="$root" --skip-plugins --skip-themes maintenance-mode activate' "$deploy" || fail 'maintenance activation must survive an incompatible rolled-back plugin by skipping application code'
