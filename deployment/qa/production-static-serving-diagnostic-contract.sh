#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
workflow="$ROOT/.github/workflows/diagnose-production-static-serving.yml"
diagnostic="$ROOT/deployment/qa/production-static-serving-diagnostic.sh"
need(){ grep -Fq -- "$2" "$1" || { echo "FAIL $1 missing required diagnostic boundary: $2" >&2; exit 1; }; }
bash -n "$diagnostic"
need "$workflow" 'environment: production'
need "$workflow" 'persist-credentials: false'
need "$workflow" 'refs/heads/main'
need "$workflow" 'git rev-parse HEAD'
need "$workflow" 'production-static-serving-diagnostic.sh "$COMMIT_SHA"'
need "$diagnostic" 'get_stylesheet_directory()'
need "$diagnostic" 'get_stylesheet_directory_uri()'
need "$diagnostic" 'WP_CONTENT_DIR'
need "$diagnostic" 'realpath "$root"'
need "$diagnostic" 'HTACCESS_RULE'
need "$diagnostic" 'PUBLIC_ASSET'
need "$diagnostic" '--skip-plugins --skip-themes'
if grep -Eiq '\b(db export|db import|maintenance-mode|DELETE FROM|wp_update|wp_insert|rsync|scp .*deploy)' "$diagnostic"; then
  echo 'FAIL production static diagnostic must remain read-only' >&2
  exit 1
fi
echo 'PASS production static serving diagnostic contract'
