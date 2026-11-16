## Scope

<!-- One concern. What changes, what deliberately does not, and which files carry the risk. -->

## Session

<!-- Required: which OpenCode session executed this. Example: `Session: opencode/governance-20261116` -->

Session:

## Checks

<!-- Exact commands and the environment that ran them. A green local run never replaces CI. -->

- [ ] `tools/ci/check.sh doctor` (state PHP 8.3 native or container image)
- [ ] `tools/ci/check.sh lint`
- [ ] `tools/ci/check.sh contracts platform/tests/<area>-contract.php` (if applicable)
- [ ] `tools/ci/check.sh services` (if applicable)
- Required PR runtime gate checks: pending until green on the exact head SHA.

## Self-review record

<!-- Required before merge. What was read, what was verified at which SHA, and what was not. -->

- Head SHA reviewed:
- Diff read in full: yes/no
- Deliberately not verified, and why:

## Risk and rollback

<!-- User-visible, data, payment, identity, calendar or deployment risk. How to undo. -->

- Risk:
- Rollback:

## External advisory review (optional)

<!-- Codex, Copilot, ChatGPT, human. Advice only; re-verified before acting. -->

- Source:
- SHA reviewed:
- Outcome:

## Production impact

<!-- Does this need a deploy? If not, say which SHA production continues to serve. -->

- Needs production dispatch: yes/no
- Notes:
