#!/usr/bin/env bash
# Synthetic, offline regression bundle for the paid student lifecycle.
# It deliberately exercises existing authority tests rather than adding a
# competing payment, fulfilment, portal, or provider implementation.
set -euo pipefail

bin="$(cd "$(dirname "$BASH_SOURCE")" && pwd)"

# Covers DST handling, Calendar/Meet projection recovery, and resilient Teacher
# Portal rendering without a WordPress database.
"$bin/run-pure-tests.sh"

# Covers signed Stripe test-mode intake: refusal, verified settlement and replay
# convergence. The runner uses deterministic local provider fixtures only.
"$bin/run-stripe-checkout-test-mode-e2e.sh"

# Covers booking through delivered intro, settlement, 12 canonical Lessons,
# Student/Teacher visibility, partial provisioning recovery, and ownership.
"$bin/run-post-intro-runtime.sh"

echo 'Student lifecycle regression bundle: PASS'
