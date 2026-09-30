#!/usr/bin/env bash
# Offline regression coverage for the runtime path-boundary guards.
#
# The runtime contract requires every worktree, database, WordPress and
# plugin-source path to be resolved and validated before use, symlinked
# worktree paths to be refused, any path resolving outside the canonical state
# directory to be refused, and an externally supplied DZN_PLUGIN_SOURCE to be
# unable to bypass that validation.  This file proves each clause:
#
#   * a pre-existing symlink or alias below the state directory is refused even
#     though its lexical path looks disposable;
#   * a symlinked worktree path is refused even when it resolves inside the
#     state directory, and the shared checkout can never be reused as source;
#   * the guards run again at the container entry points, so a path retargeted
#     after `common.sh` was sourced still cannot reach docker;
#   * the state directory itself is never a disposable path, a broad shared
#     directory (`/`, `/tmp`, `/var`, the home directory, an ancestor of the
#     shared checkout) is refused as runtime state, and destructive cleanup only
#     ever deletes the children this harness itself creates;
#   * genuinely disposable paths still work.
#
# No cached image, container, network call or shared-checkout write is
# involved: the only writes are inside one private temporary directory and
# `docker` is replaced by a recording stub.
set -euo pipefail

root="$(cd "$(dirname "$BASH_SOURCE")/.." && pwd -P)"
tmp="$(mktemp -d "${TMPDIR:-/tmp}/dzn-runtime-guards.XXXXXX")"
tmp="$(cd "$tmp" && pwd -P)"
trap 'rm -rf "$tmp"' EXIT
state="$tmp/state"
outside="$tmp/outside"
stub="$tmp/bin"
mkdir -p "$state" "$outside/db" "$stub"

docker_log="$tmp/docker.log"
printf '#!/usr/bin/env bash\nprintf "%%s\\n" "$*" >>"%s"\n' "$docker_log" >"$stub/docker"
chmod 755 "$stub/docker"

expect_rejected(){ # expect_rejected <label> <command...>
  local label="$1"; shift
  if "$@" >/dev/null 2>&1; then
    echo "FAIL: accepted $label" >&2
    exit 1
  fi
  echo "REJECTED  $label"
}

expect_accepted(){ # expect_accepted <label> <command...>
  local label="$1"; shift
  if ! "$@" >/dev/null 2>&1; then
    echo "FAIL: rejected $label" >&2
    exit 1
  fi
  echo "ACCEPTED  $label"
}

expect_rejected_match(){ # expect_rejected_match <label> <required message> <command...>
  local label="$1" needle="$2" err="$tmp/rejection.err"; shift 2
  if "$@" >"$err" 2>&1; then
    echo "FAIL: accepted $label" >&2
    exit 1
  fi
  if ! grep -q -F -- "$needle" "$err"; then
    echo "FAIL: refused $label without the expected reason ($needle)" >&2
    cat "$err" >&2
    exit 1
  fi
  echo "REJECTED  $label"
}

guarded(){ # guarded <env assignment>... -- <shell snippet run after sourcing common.sh>
  local -a envs=()
  while [ "$#" -gt 0 ] && [ "$1" != -- ]; do envs+=("$1"); shift; done
  [ "$#" -gt 0 ] && shift
  env "${envs[@]}" bash -c 'source "$1/runtime/bin/common.sh"; eval "$2"' _ "$root" "$1"
}

docker_reached(){ [ -s "$docker_log" ]; }
reset_docker_log(){ : >"$docker_log"; }

# --- a symlink below the state directory resolves outside it -----------------
# The lexical path looks disposable, so only a physical comparison can catch it.
ln -s "$outside" "$state/escape"

expect_rejected 'disposable-path guard that is bypassed by a symlink' \
  guarded DZN_RUNTIME_STATE_DIR="$state" -- 'dzn_assert_disposable_path "$DZN_RUNTIME_STATE_DIR/escape/file"'

# --- database and WordPress paths are validated, not only the state directory -
ln -s "$outside" "$state/mariadb"
expect_rejected 'database directory that resolves outside the state directory' \
  guarded DZN_RUNTIME_STATE_DIR="$state" DZN_PLUGIN_SOURCE="$state/plugin-src" -- ': sourced'
rm "$state/mariadb"

ln -s "$outside" "$state/wordpress"
expect_rejected 'WordPress directory that resolves outside the state directory' \
  guarded DZN_RUNTIME_STATE_DIR="$state" DZN_PLUGIN_SOURCE="$state/plugin-src" -- ': sourced'
rm "$state/wordpress"

# --- an externally supplied plugin source cannot escape ----------------------
expect_rejected 'plugin source set to the shared checkout' \
  guarded DZN_RUNTIME_STATE_DIR="$state" DZN_PLUGIN_SOURCE="$root" -- ': sourced'

expect_rejected 'plugin source set to a directory outside the state directory' \
  guarded DZN_RUNTIME_STATE_DIR="$state" DZN_PLUGIN_SOURCE="$outside" -- ': sourced'

expect_rejected 'plugin source that escapes through a symlink' \
  guarded DZN_RUNTIME_STATE_DIR="$state" DZN_PLUGIN_SOURCE="$state/escape/plugin" -- ': sourced'

expect_rejected 'plugin source that escapes through a lexical .. alias' \
  guarded DZN_RUNTIME_STATE_DIR="$state" DZN_PLUGIN_SOURCE="$state/../outside" -- ': sourced'

ln -s "$outside/missing" "$state/dangling"
expect_rejected 'plugin source that is an unresolved symlink' \
  guarded DZN_RUNTIME_STATE_DIR="$state" DZN_PLUGIN_SOURCE="$state/dangling" -- ': sourced'
rm "$state/dangling"

ln -s "$root" "$state/checkout-alias"
expect_rejected 'plugin source reaching the shared checkout through a symlink' \
  guarded DZN_RUNTIME_STATE_DIR="$state" DZN_PLUGIN_SOURCE="$state/checkout-alias" -- ': sourced'
rm "$state/checkout-alias"

# --- pre-existing worktree paths ---------------------------------------------
# A `state/candidate` symlink into the shared checkout must never be reused as
# the mounted plugin source, even when its Git HEAD matches the wanted ref.
ln -s "$root" "$state/candidate"
expect_rejected 'candidate worktree path symlinked to the shared checkout' \
  guarded DZN_RUNTIME_STATE_DIR="$state" -- ': sourced'
rm "$state/candidate"

# The same path is refused when the symlink target stays inside the state
# directory: a worktree path must be a real directory, not an alias.
mkdir -p "$state/real-candidate"
ln -s "$state/real-candidate" "$state/candidate"
expect_rejected 'existing worktree path that is a symlink inside the state directory' \
  guarded DZN_RUNTIME_STATE_DIR="$state" DZN_PLUGIN_SOURCE="$state/plugin-src" \
  -- 'dzn_prepare_worktree "$DZN_RUNTIME_STATE_DIR/candidate" HEAD'
rm "$state/candidate"

ln -s "$root" "$state/historical-v"
expect_rejected 'historical worktree path symlinked to the shared checkout' \
  guarded DZN_RUNTIME_STATE_DIR="$state" DZN_PLUGIN_SOURCE="$state/plugin-src" \
  -- 'dzn_prepare_worktree "$DZN_RUNTIME_STATE_DIR/historical-v" HEAD'
rm "$state/historical-v"

# --- the guards run again before a container is started ----------------------
# Retargeting after `common.sh` loads must still be caught before docker runs.
reset_docker_log
expect_rejected 'compose start after the database directory was retargeted' \
  guarded PATH="$stub:$PATH" DZN_RUNTIME_STATE_DIR="$state" DZN_PLUGIN_SOURCE="$state/plugin-src" \
  -- 'ln -s "$DZN_RUNTIME_STATE_DIR/../outside/db" "$DZN_DB_DIR"; dzn_compose config'
if docker_reached; then
  echo 'FAIL: compose was invoked with a non-disposable database directory' >&2
  exit 1
fi
rm "$state/mariadb"

reset_docker_log
expect_rejected 'container run after the plugin source was retargeted outside the state directory' \
  guarded PATH="$stub:$PATH" DZN_RUNTIME_STATE_DIR="$state" \
  -- 'DZN_PLUGIN_SOURCE="$DZN_RUNTIME_STATE_DIR/escape/plugin-src"; dzn_wp core version'
if docker_reached; then
  echo 'FAIL: docker was invoked with a non-disposable plugin source' >&2
  exit 1
fi
echo 'CHECKED   no container is started while a validated path escapes'

# --- genuinely disposable paths still work -----------------------------------
expect_accepted 'missing disposable path below the state directory' \
  guarded DZN_RUNTIME_STATE_DIR="$state" -- 'dzn_assert_disposable_path "$DZN_RUNTIME_STATE_DIR/candidate/sub"'

mkdir -p "$state/plugin-src"
ln -s "$state/plugin-src" "$state/alias"
expect_accepted 'symlink that resolves inside the state directory' \
  guarded DZN_RUNTIME_STATE_DIR="$state" DZN_PLUGIN_SOURCE="$state/alias" \
  -- 'test "$DZN_PLUGIN_SOURCE" = "$DZN_RUNTIME_STATE_DIR/plugin-src"'

reset_docker_log
expect_accepted 'compose start with fully disposable paths' \
  guarded PATH="$stub:$PATH" DZN_RUNTIME_STATE_DIR="$state" DZN_PLUGIN_SOURCE="$state/plugin-src" \
  -- 'dzn_compose config'
docker_reached || { echo 'FAIL: compose was not started for disposable paths' >&2; exit 1; }

reset_docker_log
expect_accepted 'container run with a disposable plugin source' \
  guarded PATH="$stub:$PATH" DZN_RUNTIME_STATE_DIR="$state" DZN_PLUGIN_SOURCE="$state/plugin-src" \
  -- 'dzn_wp core version'
docker_reached || { echo 'FAIL: docker was not started for a disposable plugin source' >&2; exit 1; }
echo 'CHECKED   disposable paths are still accepted and reach docker'

# --- destructive scope: dedicated state directory, owned children only --------
# `bin/destroy.sh YES` is the only destructive entry point.  The state directory
# itself is never a disposable path and is never `rm -rf`'d, a broad shared
# directory is refused outright, and only the children this harness creates are
# ever deleted.
expect_rejected_match 'the state directory used as a disposable path' \
  'refusing the state directory itself as a disposable path' \
  guarded DZN_RUNTIME_STATE_DIR="$state" -- 'dzn_assert_disposable_path "$DZN_RUNTIME_STATE_DIR"'

for broad in / /tmp /var /var/tmp /etc /opt /Users; do
  [ -d "$broad" ] || continue
  expect_rejected_match "broad state directory $broad" 'DZN_RUNTIME_STATE_DIR' \
    guarded DZN_RUNTIME_STATE_DIR="$broad" -- ': sourced'
done
expect_rejected_match 'state directory inside the shared checkout' \
  'outside the shared checkout' \
  guarded DZN_RUNTIME_STATE_DIR="$root/runtime/state" -- ': sourced'
expect_rejected_match 'state directory that contains the shared checkout' \
  'must not contain the shared checkout' \
  guarded DZN_RUNTIME_STATE_DIR="$(dirname "$root")" -- ': sourced'
expect_rejected_match 'state directory that is the home directory' \
  'must not be a user data directory' \
  guarded HOME="$tmp/fake-home" DZN_RUNTIME_STATE_DIR="$tmp/fake-home" -- ': sourced'
expect_rejected_match 'state directory that is an ancestor of the home directory' \
  'must not be a user data directory' \
  guarded HOME="$tmp/fake-home/deeper" DZN_RUNTIME_STATE_DIR="$tmp/fake-home" -- ': sourced'
expect_accepted 'the documented default state directory' \
  guarded DZN_RUNTIME_STATE_DIR=/tmp/dzn-platform-schema33-local -- ': sourced'

# `docker`, `git`, `rm` and `rmdir` are recorded (and never destructive), so the
# cleanup command stream itself is the evidence.
scope="$tmp/scope"
scope_bin="$tmp/scope-bin"
scope_bin_delete="$tmp/scope-bin-delete"
scope_log="$tmp/scope.log"
mkdir -p "$scope_bin" "$scope_bin_delete"
printf '#!/usr/bin/env bash\nprintf "docker %%s\\n" "$*" >>"%s"\n' "$scope_log" >"$scope_bin/docker"
printf '#!/usr/bin/env bash\nprintf "git %%s\\n" "$*" >>"%s"\nexit 1\n' "$scope_log" >"$scope_bin/git"
printf '#!/usr/bin/env bash\nprintf "rm %%s\\n" "$*" >>"%s"\n' "$scope_log" >"$scope_bin/rm"
printf '#!/usr/bin/env bash\nprintf "rmdir %%s\\n" "$*" >>"%s"\n' "$scope_log" >"$scope_bin/rmdir"
printf '#!/usr/bin/env bash\nprintf "docker %%s\\n" "$*" >>"%s"\n' "$scope_log" >"$scope_bin_delete/docker"
printf '#!/usr/bin/env bash\nprintf "git %%s\\n" "$*" >>"%s"\nexit 1\n' "$scope_log" >"$scope_bin_delete/git"
chmod 755 "$scope_bin/docker" "$scope_bin/git" "$scope_bin/rm" "$scope_bin/rmdir" "$scope_bin_delete/docker" "$scope_bin_delete/git"

reset_scope_log(){ : >"$scope_log"; }
destroy(){ # destroy <state dir> <stub bin path>
  env PATH="$2:$PATH" DZN_RUNTIME_STATE_DIR="$1" DZN_PLUGIN_SOURCE="$1/candidate" HOME="$tmp/fake-home" \
    bash "$root/runtime/bin/destroy.sh" YES
}
assert_scope_log(){ # assert_scope_log <state dir>
  local dir="$1" line field
  while IFS= read -r line; do
    case "$line" in
      'rm '*) for field in $line; do
                case "$field" in
                  rm|-rf|-r|-f) continue;;
                  "$dir"/*) ;;
                  *) echo "FAIL: cleanup deleted something outside the state directory: $line" >&2; exit 1;;
                esac
              done;;
      "rmdir $dir") ;;
      'git '*) ;;
      'docker '*) ;;
      *) echo "FAIL: unexpected cleanup command: $line" >&2; exit 1;;
    esac
  done <"$scope_log"
}

for broad in /tmp /; do
  reset_scope_log
  expect_rejected_match "destroy with $broad as the state directory" 'DZN_RUNTIME_STATE_DIR' \
    destroy "$broad" "$scope_bin"
  if [ -s "$scope_log" ]; then
    echo "FAIL: destroy reached docker/git/rm for a broad state directory ($broad)" >&2
    cat "$scope_log" >&2
    exit 1
  fi
done
echo 'CHECKED   destroy refuses / and /tmp without invoking docker, git, rm or rmdir'

mkdir -p "$scope/unrecognised"
printf 'keep\n' >"$scope/unrecognised/stray-file"
reset_scope_log
expect_rejected_match 'destroy with an unrecognised entry in the state directory' 'unrecognised entry' \
  destroy "$scope/unrecognised" "$scope_bin"
if [ -s "$scope_log" ]; then
  echo 'FAIL: destroy acted on a state directory holding unrecognised content' >&2
  cat "$scope_log" >&2
  exit 1
fi
[ -f "$scope/unrecognised/stray-file" ] || { echo 'FAIL: destroy removed unrecognised content' >&2; exit 1; }
echo 'CHECKED   destroy refuses unrecognised entries and deletes nothing'

mkdir -p "$scope/owned/candidate/plugin" "$scope/owned/mariadb" "$scope/owned/wordpress" "$scope/owned/no-pull-bin"
printf 'snapshot\n' >"$scope/owned/rehearsal-after.json"
reset_scope_log
expect_accepted 'destroy with a dedicated state directory holding only owned children' \
  destroy "$scope/owned" "$scope_bin"
assert_scope_log "$scope/owned"
grep -q -F "rmdir $scope/owned" "$scope_log" || { echo 'FAIL: the state directory itself was not removed with rmdir' >&2; cat "$scope_log" >&2; exit 1; }
grep -q -F 'candidate' "$scope_log" || { echo 'FAIL: the candidate worktree was not cleaned' >&2; cat "$scope_log" >&2; exit 1; }
grep -q -F 'compose' "$scope_log" || { echo 'FAIL: compose down was not invoked' >&2; cat "$scope_log" >&2; exit 1; }
echo 'CHECKED   destroy deletes only validated children and removes the state directory with rmdir'

mkdir -p "$scope/e2e/candidate" "$scope/e2e/wordpress"
printf 'core\n' >"$scope/e2e/wordpress/wp-load.php"
destroy "$scope/e2e" "$scope_bin_delete" >/dev/null || { echo 'FAIL: destroy failed on a dedicated state directory' >&2; exit 1; }
[ ! -e "$scope/e2e" ] || { echo 'FAIL: destroy left the dedicated state directory behind' >&2; exit 1; }
echo 'CHECKED   destroy removes a dedicated state directory end to end'

# --- the discriminating control ----------------------------------------------
# The pre-correction guard compared lexical strings, so aliases of this shape
# were accepted.  Recording the shape keeps the physical comparison honest.
lexical_only_accepts(){ case "$1" in "$2"/*) return 0;; *) return 1;; esac; }
lexical_only_accepts "$state/escape/file" "$state" || {
  echo 'FAIL: control alias is not of the shape a lexical-only check accepts' >&2
  exit 1
}
echo 'CHECKED   a lexical-only comparison would accept <state>/escape/file while the guard refuses it'

# --- the offline transport policy must stay intact ---------------------------
images="$(grep -c 'pull_policy: never' "$root/runtime/compose.yaml")"
if [ "$images" != 2 ]; then
  echo "FAIL: compose no longer pins pull_policy: never for both services" >&2
  exit 1
fi
echo "CHECKED   compose keeps pull_policy: never for both services"

unpulled="$(grep -n 'docker run' "$root"/runtime/bin/*.sh \
  | grep -v -E ':[0-9]+:[[:space:]]*#' \
  | grep -v -- '--pull' || true)"
if [ -n "$unpulled" ]; then
  echo 'FAIL: container start without a no-pull policy' >&2
  echo "$unpulled" >&2
  exit 1
fi

unpulled_compose="$(grep -rn 'dzn_compose up' "$root/runtime/bin" | grep -v -- '--pull never' || true)"
if [ -n "$unpulled_compose" ]; then
  echo 'FAIL: compose start without --pull never' >&2
  echo "$unpulled_compose" >&2
  exit 1
fi
echo 'CHECKED   every docker run keeps --pull=never and every compose start keeps --pull never'

# --- the harness must validate the schema this package declares -----------------
# The package declares Schema 33, so the stored-state check, the fresh install, the
# retained migration and both upgrade rehearsals must all target 33, the Schema-032
# notification and Schema-033 readiness storage must be asserted, and no check may
# still demand Schema 31 or 32 as the current identity.
grep -q 'dzn-platform-schema33-local' "$root/runtime/bin/common.sh" || {
  echo 'FAIL: the default state directory is not the Schema-33 one' >&2
  exit 1
}
grep -q 'expected Schema 33' "$root/runtime/bin/verify-schema33.sh" "$root/runtime/bin/verify-schema32.sh" || {
  echo 'FAIL: the stored-state check does not require Schema 33' >&2
  exit 1
}
grep -q '032_notification_communications_authority' "$root/runtime/bin/verify-schema33.sh" "$root/runtime/bin/verify-schema32.sh" || {
  echo 'FAIL: the stored-state check does not require the Schema-032 migration' >&2
  exit 1
}
grep -q '033_core_dataset_technical_prerequisites' "$root/runtime/bin/verify-schema33.sh" "$root/runtime/bin/verify-schema32.sh" || {
  echo 'FAIL: the stored-state check does not require the Schema-033 migration' >&2
  exit 1
}
grep -q 'core_dataset_reconciliation_runs' "$root/runtime/bin/verify-schema33.sh" "$root/runtime/bin/verify-schema32.sh" || {
  echo 'FAIL: the stored-state check does not assert the Schema-033 readiness storage' >&2
  exit 1
}
grep -q 'failure_reason_code' "$root/runtime/bin/verify-schema33.sh" "$root/runtime/bin/verify-schema32.sh" || {
  echo 'FAIL: the stored-state check does not assert the added outbox columns' >&2
  exit 1
}
grep -q 'verify-schema33.sh' "$root/runtime/bin/run-fresh-install.sh" || {
  echo 'FAIL: the fresh install does not verify current Schema 33' >&2
  exit 1
}
grep -q 'verify-schema33.sh' "$root/runtime/bin/run-retained-migration.sh" || {
  echo 'FAIL: the retained migration does not verify current Schema 33' >&2
  exit 1
}
grep -q "'33|33'" "$root/runtime/bin/run-retained-migration.sh" || {
  echo 'FAIL: the retained migration does not pin the complete Schema-33 ledger' >&2
  exit 1
}
for rehearsal in run-schema30-to-32-rehearsal.sh run-schema31-to-32-rehearsal.sh; do
  test -s "$root/runtime/bin/$rehearsal" || { echo "FAIL: missing upgrade rehearsal: $rehearsal" >&2; exit 1; }
done
grep -q 'wp_dzn_portal_access_denials' "$root/runtime/bin/run-schema30-to-32-rehearsal.sh" || {
  echo 'FAIL: the 30->32 rehearsal does not declare the Schema-031 portal tables' >&2
  exit 1
}
grep -q 'wp_dzn_notification_workflows' "$root/runtime/bin/run-schema30-to-32-rehearsal.sh" || {
  echo 'FAIL: the 30->32 rehearsal does not declare the Schema-032 notification tables' >&2
  exit 1
}
grep -q 'wp_dzn_notification_privacy_tombstones' "$root/runtime/bin/run-schema31-to-32-rehearsal.sh" || {
  echo 'FAIL: the 31->32 rehearsal does not declare the Schema-032 notification tables' >&2
  exit 1
}
grep -q 'wp_dzn_core_dataset_reconciliation_runs' "$root/runtime/bin/run-schema30-to-32-rehearsal.sh" || {
  echo 'FAIL: the 30->32 rehearsal does not declare the Schema-033 readiness tables' >&2
  exit 1
}
grep -q 'wp_dzn_core_dataset_reconciliation_runs' "$root/runtime/bin/run-schema31-to-32-rehearsal.sh" || {
  echo 'FAIL: the 31->32 rehearsal does not declare the Schema-033 readiness tables' >&2
  exit 1
}
if grep -rn -E 'verify-schema31|run-schema30-to-31|expected Schema 31' "$root/runtime/bin" "$root/runtime/Makefile"; then
  echo 'FAIL: the harness still targets a Schema-31 check' >&2
  exit 1
fi
pure_list="$(grep -E '^tests=\(' "$root/runtime/bin/run-pure-tests.sh")"
case "$pure_list" in *tests/phase-2a2w-contract.php*) ;; *) {
  echo 'FAIL: the no-runtime guard list dropped the Phase-W contract guard' >&2
  exit 1
};; esac
case "$pure_list" in *tests/schema-contract.php*) {
  echo 'FAIL: the stale Phase-1 schema guard would abort the acceptance sequence' >&2
  exit 1
};; esac
grep -q 'verify-schema33.sh' "$root/runtime/bin/schema-upgrade-rehearsal.sh" || {
  echo 'FAIL: the upgrade rehearsal does not route to current Schema 33 verification' >&2
  exit 1
}
echo 'CHECKED   the harness validates the declared Schema 33 (stored state, retained ledger and both rehearsals)'

# --- the documented `.env` copy workflow must keep the Schema-33 isolation -----
# `common.sh` sources `.env` *before* it applies its Schema-33 defaults, so an
# example still naming the Schema-32 state directory or Compose project silently
# overrides both and points a documented run at a previous Schema-32 disposable
# environment.  The example must therefore carry the Schema-33 names itself, no
# Schema-32 isolation name may survive anywhere in the harness, and the
# documented copy-to-`.env` workflow must still resolve to the Schema-33
# isolation once it has been sourced.
stale_env_example="$(grep -rn 'dzn-platform-schema32-local' "$root/runtime" || true)"
if [ -n "$stale_env_example" ]; then
  echo 'FAIL: the harness still names the Schema-32 isolation (state directory or Compose project)' >&2
  echo "$stale_env_example" >&2
  exit 1
fi
grep -q -E '^DZN_RUNTIME_STATE_DIR=/tmp/dzn-platform-schema33-local$' "$root/runtime/.env.example" || {
  echo 'FAIL: runtime/.env.example does not declare the Schema-33 state directory' >&2
  exit 1
}
grep -q -E '^DZN_COMPOSE_PROJECT=dzn-platform-schema33-local$' "$root/runtime/.env.example" || {
  echo 'FAIL: runtime/.env.example does not declare the Schema-33 Compose project' >&2
  exit 1
}

# The workflow is exercised as data: the example is copied into a private replica
# of the runtime layout and sourced there, so this test still writes nothing into
# the shared checkout.  `.env` is sourced ahead of the defaults, so the resolved
# isolation can only be right when the example itself is right.
env_replica="$tmp/env-replica"
mkdir -p "$env_replica/runtime/bin"
cp "$root/runtime/bin/common.sh" "$env_replica/runtime/bin/common.sh"
cp "$root/runtime/.env.example" "$env_replica/runtime/.env"
env_copied="$(env -u DZN_RUNTIME_STATE_DIR -u DZN_COMPOSE_PROJECT -u DZN_PLUGIN_SOURCE \
  bash -c 'source "$1/runtime/bin/common.sh"; printf "%s\n%s\n%s\n" "$DZN_RUNTIME_STATE_DIR" "$DZN_COMPOSE_PROJECT" "$DZN_NETWORK"' \
  _ "$env_replica")" || {
  echo 'FAIL: the documented .env copy workflow does not source cleanly' >&2
  exit 1
}
case "$env_copied" in
  */dzn-platform-schema33-local$'\n'dzn-platform-schema33-local$'\n'dzn-platform-schema33-local_runtime) ;;
  *)
    echo 'FAIL: copying .env.example to .env does not keep the Schema-33 state directory, project and network' >&2
    printf '%s\n' "$env_copied" >&2
    exit 1
    ;;
esac
echo 'CHECKED   the documented .env copy workflow keeps the Schema-33 isolation names'

# --- the destructive invariant is also enforced statically --------------------
outside="$(grep -rn -- 'rm -rf' "$root/runtime/bin" | grep -v '/common.sh:' | grep -v -E ':[0-9]+:[[:space:]]*#' || true)"
if [ -n "$outside" ]; then
  echo 'FAIL: destructive deletion outside the validated deletion primitive' >&2
  echo "$outside" >&2
  exit 1
fi
if [ "$(grep -n -- 'rm -rf' "$root/runtime/bin/common.sh" | grep -v -E ':[0-9]+:[[:space:]]*#' | wc -l | tr -d ' ')" != 1 ] \
  || ! grep -q 'rm -rf "\$path"' "$root/runtime/bin/common.sh"; then
  echo 'FAIL: common.sh must hold exactly one validated rm -rf primitive' >&2
  exit 1
fi
if grep -n 'rm -rf "\$DZN_RUNTIME_STATE_DIR"' "$root"/runtime/bin/*.sh; then
  echo 'FAIL: the state directory itself must never be rm -rf-ed' >&2
  exit 1
fi
grep -q 'rmdir "\$DZN_RUNTIME_STATE_DIR"' "$root/runtime/bin/destroy.sh" || {
  echo 'FAIL: destroy.sh must remove the state directory with rmdir' >&2
  exit 1
}
echo 'CHECKED   the only rm -rf is the validated deletion primitive and destroy removes state with rmdir'

echo 'runtime path guards: PASS'
