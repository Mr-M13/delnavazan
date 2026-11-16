#!/usr/bin/env bash
# Source contract for the audited article publish workflow.
#
# Static, hermetic and offline: it reads the workflow YAML, the runner-side
# parser and the host-side processor, and never contacts GitHub or production.
# It asserts the authority boundaries that make the dispatch safe:
#
#   - workflow_dispatch only, from main, in the protected production environment;
#   - bound to the exact current origin/main SHA and an exact confirm token;
#   - a checkout pinned to that SHA, with no rolling branch ref;
#   - default mode is dry_run (never publish by default);
#   - only the three documented production SSH secrets, pinned known-hosts,
#     BatchMode, and the private key registered as a mask, never printed;
#   - it can update post_content + the Rank Math meta + tags of an EXISTING post
#     located by slug, and nothing else: no post creation, no slug change, no
#     post-status change, no delete/update HTTP verb, no hardcoded credential;
#   - every `run:` block-scalar script in the workflow is extracted and proven to
#     parse with `bash -n`, so a YAML/heredoc indentation break fails this gate
#     rather than a production dispatch;
#   - every article under docs/articles/improved parses to a complete payload
#     (slug + SEO block present, honesty note and SEO block excluded).
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
workflow="$ROOT/.github/workflows/publish-articles.yml"
plan="$ROOT/deployment/articles/publish-articles-plan.php"
host="$ROOT/deployment/articles/publish-articles-host.php"
articles_dir="$ROOT/docs/articles/improved"
pr_gate="$ROOT/.github/workflows/pr-runtime-gate.yml"
agents="$ROOT/AGENTS.md"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

pass(){ printf 'PASS  %s\n' "$1"; }
fail(){ printf 'FAIL  %s\n' "$1" >&2; exit 1; }
need(){ grep -Fq -- "$2" "$1" || fail "$(basename "$1") is missing required boundary: $2"; }
forbid(){ if grep -Fq -- "$2" "$1"; then fail "$(basename "$1") must not contain: $2"; fi; }
forbid_w(){ if grep -Fqw -- "$2" "$1"; then fail "$(basename "$1") must not contain the word: $2"; fi; }
forbid_re(){ if grep -Eqi -- "$2" "$1"; then fail "$(basename "$1") must not contain the pattern: $2"; fi; }

# --------------------------------------------------------------- presence ---
[[ -f "$workflow" ]] || fail '.github/workflows/publish-articles.yml is missing'
[[ -f "$plan" ]] || fail 'deployment/articles/publish-articles-plan.php is missing'
[[ -f "$host" ]] || fail 'deployment/articles/publish-articles-host.php is missing'
[[ -d "$articles_dir" ]] || fail 'docs/articles/improved is missing'
[[ -f "$pr_gate" ]] || fail '.github/workflows/pr-runtime-gate.yml is missing'
[[ -f "$agents" ]] || fail 'AGENTS.md is missing'
php -l "$plan" >/dev/null || fail 'publish-articles-plan.php is not valid PHP'
php -l "$host" >/dev/null || fail 'publish-articles-host.php is not valid PHP'
pass 'the workflow, the parser, the host processor and the documents are present and parse'

# --------------------------------------------- every run: block parses --------
# Extract every `run:` block-scalar script from the workflow and prove each one
# parses with `bash -n`. A YAML/heredoc indentation break must fail this gate, not
# a production dispatch. The block content is the literal `|` scalar: lines more
# indented than the `run:` key, with the block's own indentation stripped, so a
# heredoc terminator written at the block's base column is restored to column 0.
run_count="$(grep -cE '^[[:space:]]*run:[[:space:]]*\|' "$workflow" || true)"
[[ "$run_count" =~ ^[0-9]+$ && "$run_count" -ge 8 ]] \
  || fail "expected at least 8 run: blocks in the workflow, found ${run_count:-0}"
mkdir -p "$tmp/blocks"
awk -v out="$tmp/blocks" '
  function emit() {
    if (n == 0) return
    c++
    f = sprintf("%s/block-%03d.sh", out, c)
    for (i = 1; i <= n; i++) print lines[i] > f
    close(f)
    n = 0
  }
  in_block {
    if ($0 ~ /^[[:space:]]*$/) { if (n > 0) { n++; lines[n] = "" } next }
    match($0, /^[[:space:]]*/)
    if (RLENGTH > run_indent) {
      if (content_indent == 0) content_indent = RLENGTH
      n++
      lines[n] = substr($0, content_indent + 1)
      next
    }
    in_block = 0
    emit()
  }
  /^[[:space:]]*run:[[:space:]]*\|/ {
    match($0, /^[[:space:]]*/)
    run_indent = RLENGTH
    in_block = 1
    n = 0
    content_indent = 0
    next
  }
  END { if (in_block) emit() }
' "$workflow"
block_n="$(find "$tmp/blocks" -name 'block-*.sh' | wc -l | tr -d ' ')"
[[ "$block_n" == "$run_count" ]] || fail "extracted $block_n run: blocks but the workflow declares $run_count"
for f in "$tmp/blocks"/block-*.sh; do
  [[ -f "$f" ]] || continue
  bash -n "$f" || fail "a workflow run: block does not parse with bash -n: $(basename "$f")"
done
pass "all $block_n workflow run: blocks parse with bash -n"

# ------------------------------------------------------- dispatch surface ---
need "$workflow" 'workflow_dispatch:'
need "$workflow" 'commit_sha:'
need "$workflow" 'confirm:'
need "$workflow" 'mode:'
need "$workflow" 'slugs:'
need "$workflow" 'options: [dry_run, publish]'
need "$workflow" 'default: dry_run'
need "$workflow" 'required: true'
need "$workflow" 'environment: production'
need "$workflow" 'permissions:'
need "$workflow" 'contents: read'
need "$workflow" 'group: delnavazan-publish-articles'
need "$workflow" 'cancel-in-progress: false'
need "$workflow" 'retention-days: 90'
forbid "$workflow" 'default: publish'
forbid "$workflow" '  push:'
forbid "$workflow" '  pull_request:'
forbid "$workflow" '  schedule:'
forbid "$workflow" '  workflow_call:'
forbid "$workflow" 'workflow_run:'
forbid "$workflow" 'continue-on-error'
pass 'dispatch is workflow_dispatch only, defaults to dry_run, in the protected production environment'

# ---------------------------------------------------------- main-only gate ---
need "$workflow" 'refs/heads/main'
need "$workflow" '^[0-9a-f]{40}$'
need "$workflow" 'git fetch --no-tags origin'
need "$workflow" "'+refs/heads/main:refs/remotes/origin/main'"
need "$workflow" 'origin_main="$(git rev-parse origin/main)"'
need "$workflow" '[[ "$origin_main" == "$COMMIT_SHA" ]]'
need "$workflow" 'must still be current origin/main'
need "$workflow" '[[ "$(git rev-parse HEAD)" == "$COMMIT_SHA" ]]'
need "$workflow" '[[ "$GITHUB_SHA" == "$COMMIT_SHA" ]]'
need "$workflow" 'ref: ${{ github.sha }}'
need "$workflow" 'persist-credentials: false'
need "$workflow" 'needs: verify'
forbid "$workflow" 'ref: main'
forbid "$workflow" 'ref: ${{ github.ref }}'
forbid "$workflow" 'ref: ${{ inputs.commit_sha }}'
pass 'the workflow refuses anything but the exact current origin/main SHA'

# ----------------------------------------------------------- confirm token ---
need "$workflow" "[[ \"\$CONFIRM\" == 'PUBLISH_ARTICLES' ]]"
[[ "$(grep -Fc 'PUBLISH_ARTICLES' "$workflow")" -ge 2 ]] \
  || fail 'the confirm token must be validated in the gate job and again before the host run'
pass 'the exact PUBLISH_ARTICLES confirm token is required and re-checked'

# ------------------------------------------------- the three documented keys --
need "$workflow" 'PRODUCTION_SSH_USER'
need "$workflow" 'PRODUCTION_SSH_PRIVATE_KEY: ${{ secrets.PRODUCTION_SSH_PRIVATE_KEY }}'
need "$workflow" 'PRODUCTION_KNOWN_HOSTS: ${{ secrets.PRODUCTION_KNOWN_HOSTS }}'
need "$workflow" 'Known-hosts secret must pin cp119.hosting-cloud.net'
need "$workflow" 'cp119.hosting-cloud.net'
need "$workflow" 'BatchMode yes'
need "$workflow" '::add-mask::'
secrets_used="$(grep -o 'secrets\.[A-Za-z0-9_]*' "$workflow" | sed 's/^secrets\.//' | LC_ALL=C sort -u || true)"
expected_secrets="$(printf '%s\n' PRODUCTION_KNOWN_HOSTS PRODUCTION_SSH_PRIVATE_KEY PRODUCTION_SSH_USER | LC_ALL=C sort -u)"
[[ "$secrets_used" == "$expected_secrets" ]] \
  || fail "the workflow must reference exactly the three documented production SSH secrets; found: $(printf '%s ' $secrets_used)"
pass 'only the three documented SSH secrets are reachable, pinned, masked and never printed'

# ------------------------------------------------ update-by-slug only, never --
# The workflow and the host processor may update the content and SEO meta of an
# existing post located by slug; they must never create a post, change a slug,
# change a post status, or use an update/delete HTTP verb.
for target in "$workflow" "$host"; do
  forbid_w "$target" 'DELETE'
  forbid_w "$target" 'PUT'
  forbid_w "$target" 'PATCH'
  forbid "$target" 'wp post create'
  forbid "$target" 'wp_insert_post'
  forbid "$target" '--post_name'
  forbid_re "$target" '--post_status=(publish|draft|private|pending|trash|inherit|future)'
done
# The locate step is a read-only slug filter; the update step touches content only.
need "$host" '--name='
need "$host" '--post_content='
need "$host" 'rank_math_title'
need "$host" 'rank_math_description'
need "$host" 'rank_math_focus_keyword'
need "$host" 'post_tag'
need "$host" 'body_sha256'
need "$host" "hash('sha256'"
pass 'the run can update content + Rank Math meta + tags by slug only; never create, rename or re-status a post'

# ------------------------------------------------- no hardcoded credential ----
for target in "$workflow" "$host" "$plan"; do
  forbid_re "$target" 'BEGIN (RSA|OPENSSH|EC) PRIVATE KEY'
  forbid_re "$target" 'AKIA[0-9A-Z]{16}'
  forbid_re "$target" '(sk_live_|whsec_|ghp_|github_pat_)'
  forbid_re "$target" 'Bearer[[:space:]]+[A-Za-z0-9]'
  forbid_re "$target" "(password|passwd|client[_-]?secret|api[_-]?key)[[:space:]]*[:=][[:space:]]*[\"']"
done
pass 'no hardcoded credential, private key or literal bearer token is reachable'

# ----------------------------------------------------- article corpus parse --
# Every article under docs/articles/improved must parse to a complete payload:
# a slug derived from the URL, and a complete SEO block (title, meta, focus
# keyword, tags). The honesty note and the SEO block must be excluded from the
# emitted HTML, which the parser asserts itself before emitting anything.
parser_out="$(php "$plan")" || fail 'the article parser failed to run'
grep -qx 'MISSING=0' <<<"$parser_out" || fail 'the article parser reported files missing a slug or SEO field'
total="$(sed -n 's/^TOTAL=//p' <<<"$parser_out" | head -n 1)"
file_count="$(find "$articles_dir" -maxdepth 1 -name '*.md' | wc -l | tr -d ' ')"
[[ "$total" =~ ^[0-9]+$ ]] || fail 'the article parser did not report a numeric TOTAL'
[[ "$total" == "$file_count" ]] || fail "parser reported TOTAL=$total but found $file_count article files"
printf 'PASS  all %s articles parse to a complete payload (slug + SEO block, honesty/SEO excluded)\n' "$total"

# ------------------------------------------------ documentation agreement ---
need "$pr_gate" 'bash -n deployment/qa/publish-articles-contract.sh'
need "$pr_gate" 'bash deployment/qa/publish-articles-contract.sh'
need "$agents" 'publish-articles.yml'
need "$agents" 'publish-articles-contract.sh'
need "$agents" 'PUBLISH_ARTICLES'
pass 'the PR gate and AGENTS.md describe this workflow and its limits'

printf 'PASS article publish workflow contract\n'
