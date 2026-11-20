# Final reconciliation checkpoint — 16 November 2026 (Australia/Brisbane)

This report is a **point-in-time live reconciliation**; unlike the 12 November historical notes it uses GitHub and local machine observations. Do not infer future CI/deployment success from this snapshot.

## Git and pull requests

- Latest verified `main`: `7bbc34899edc9ab4d3e6ef6312d1886feb7af9fc`.
- Handover PR **#601**: `docs/ai-handover-20261112`, head `7cac361a791c00a308386e57d6ca042e73fbc720`; **draft, open, BEHIND main, REVIEW_REQUIRED**. Prior checks green, but release gate is not met. Contains 22 documentation files.
- Booking stress **#626**: head `a98ce40c5b9ea947b2c443100fb67bfc3f0147b7`, open, REVIEW_REQUIRED; reports allocation fairness and lead-time policy findings. No automatic merge.
- Notifications **#638**: head `49cf57b8696bbf45236fa9f1e760413d3c4ab93c`, open, REVIEW_REQUIRED; provider approvals and consented post-deploy tests remain. No automatic merge.
- Consent **#640**: head `293baf128138f9208b9f56e025a5a0e61e6b1792`, draft, REVIEW_REQUIRED; no automatic merge.
- DeepSeek availability/golden-hour changes: inspect merged main history and source before assuming a particular PR represents all changes. This checkpoint does not certify functional equivalence.
- The active local `~/Projects/delnavazan` worktree is on `fix/notification-workflow-usability-20261116` and was left untouched.

## CI and production

- Main PR runtime gate run **43781577435** for `7bbc34899edc...` was **in_progress** when queried. CodeQL run 43781576980 was also in progress. Therefore a new deployment is **not authorised** until required checks complete successfully.
- Most recent listed successful production deployment: deployment **7804772449**, SHA `58c3780e3319...`, status `success`, 15 November 2026. This is **not** the verified current main SHA. No new deploy should be claimed from this report.
- Protected `main` and production workflow controls must be respected. User authorisation for unattended deployment does not waive reviews, approvals, CI or provider verification.

## Backup and portability

- Original `~/Documents/Delnavazan-AI-Handover-2026-11-12.zip` exists (~131 MB) and `unzip -t` reported no errors. Its extracted directory was absent at the originally documented path; original ZIP was not modified.
- A new independent export is assembled under `~/Documents/Delnavazan-AI-Handover-Final-2026-11-16/` with an exact `main` snapshot and the 22 PR #601 audit files. The final packaging process must record ZIP path, SHA256 and integrity check separately.
- Never confuse the historical 12 November snapshot with current main.

## Release decision

**BLOCKED for merging and deploying at this checkpoint.** Reasons: PR #601 draft/behind/review-required, other PRs review-required, and main required checks not yet complete. Complete the portable documentation backup regardless; only deploy later if each independent gate becomes satisfied. Do not blindly merge unrelated PRs.
