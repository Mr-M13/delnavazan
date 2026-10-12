SHELL := /usr/bin/env bash
BIN := bin
.PHONY: smoke teacher-onboarding reset seed destroy check-cache

check-cache: ; @$(BIN)/check-cache.sh
reset: ; @$(BIN)/reset.sh
seed: ; @$(BIN)/seed-identities.sh
smoke: ; @$(BIN)/run-login-routing-smoke.sh
teacher-onboarding: ; @$(BIN)/run-teacher-onboarding-acceptance.sh
destroy: ; @$(BIN)/destroy.sh $(CONFIRM)
