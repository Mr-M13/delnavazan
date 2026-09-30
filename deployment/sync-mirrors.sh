#!/bin/sh
set -eu

ROOT=$(git rev-parse --show-toplevel)
cd "$ROOT"

git fetch origin main
git checkout main
git pull --ff-only origin main

git branch -D deploy-platform 2>/dev/null || true
git branch -D deploy-theme 2>/dev/null || true

git subtree split --prefix=platform -b deploy-platform
git subtree split --prefix=theme -b deploy-theme

git push --force-with-lease origin deploy-platform deploy-theme

git remote get-url deploy-platform-mirror >/dev/null 2>&1 || git remote add deploy-platform-mirror https://github.com/Mr-M13/delnavazan-platform.git
git remote get-url deploy-theme-mirror >/dev/null 2>&1 || git remote add deploy-theme-mirror https://github.com/Mr-M13/delnavazan-theme.git

git push --force-with-lease deploy-platform-mirror deploy-platform:monorepo-deploy
git push --force-with-lease deploy-theme-mirror deploy-theme:monorepo-deploy
