#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
workflow="$ROOT/.github/workflows/diagnose-production-google-oauth.yml"
diagnostic="$ROOT/deployment/qa/production-google-oauth-diagnostic.sh"
need(){ grep -Fq -- "$2" "$1" || { echo "FAIL $1 missing required diagnostic boundary: $2" >&2; exit 1; }; }
bash -n "$diagnostic"
need "$workflow" 'environment: production'
need "$workflow" 'persist-credentials: false'
need "$workflow" 'refs/heads/main'
need "$workflow" 'git rev-parse HEAD'
need "$workflow" 'bash deployment/qa/production-google-oauth-diagnostic.sh "$COMMIT_SHA"'
need "$diagnostic" 'GOOGLE_OAUTH_LIFECYCLE='
need "$diagnostic" 'settings_ready'
need "$diagnostic" 'transport_configured'
need "$diagnostic" 'provider_reachability'
need "$diagnostic" 'connection_counts'
need "$diagnostic" 'authorization_counts'
need "$diagnostic" 'latest_connection'
need "$diagnostic" 'latest_authorization'
if grep -Eiq '\b(access_token|refresh_token|client_secret|client_id|state_digest|verifier_digest|credential_material|teacher_id|DELETE FROM|INSERT INTO|UPDATE .* SET|wp_update|wp_insert|maintenance-mode|rsync|scp .*deploy)' "$diagnostic"; then
  echo 'FAIL production Google OAuth diagnostic exposes protected data or is not read-only' >&2
  exit 1
fi
echo 'PASS production Google OAuth diagnostic contract'
