#!/usr/bin/env bash
# Controlled bootstrap/repair for the post-introductory automation identity.
# Synthetic/local runtime only. The principal is created by an explicit administrator
# (user 1) through the actor's provisioning entry point, never by ordinary runtime code.
set -euo pipefail
source "$(dirname "$BASH_SOURCE")/common.sh"

dzn_wp_env DZN_POST_INTRO_ACTOR_BOOTSTRAP=1 -- eval 'Delnavazan\Platform\Core\Application\PostIntroSystemActor::provision();'
echo 'Post-intro automation identity: PASS'
