#!/usr/bin/env bash
set -Eeuo pipefail

fail(){ printf 'FAIL %s\n' "$1" >&2; exit 2; }
[[ $# == 2 ]] || fail 'usage: deployment-risk-classifier.sh <base-ref> <head-ref>'

base_ref="$1"
head_ref="$2"
changed=()
while IFS= read -r path; do
  changed+=("$path")
done < <(git diff --name-only --diff-filter=ACMRTUXB "$base_ref" "$head_ref")
(( ${#changed[@]} > 0 )) || { printf 'RISK=STANDARD\nREASON=no changed paths detected\n'; exit 0; }

risk=FAST
reasons=()
for path in "${changed[@]}"; do
  case "$path" in
    .github/workflows/*|deployment/*|platform/runtime/*|services/*|\
    *migration*|*Migration*|*schema*|*Schema*|*booking*|*Booking*|\
    *enrol*|*Enrol*|*attendance*|*Attendance*|*auth*|*Auth*|*oauth*|*OAuth*|\
    *google*|*Google*|*meet*|*Meet*|*stripe*|*Stripe*|*payment*|*Payment*|\
    *recording*|*Recording*|*notification*|*Notification*|*calendar*|*Calendar*|\
    *consent*|*Consent*|platform/delnavazan-platform.php)
      risk=CRITICAL
      reasons+=("$path is a sensitive or shared runtime path")
      ;;
    theme/assets/css/*|theme/assets/images/*|theme/**/*.css|theme/**/*.scss|\
    theme/**/*.po|theme/**/*.mo|theme/**/*.json|theme/style.css|theme/theme.json)
      reasons+=("$path is isolated presentation content")
      ;;
    *)
      [[ "$risk" == CRITICAL ]] || risk=STANDARD
      reasons+=("$path is application or non-isolated content")
      ;;
  esac
done

printf 'RISK=%s\n' "$risk"
printf '%s\n' "${reasons[@]}" | sed 's/^/REASON=/'
