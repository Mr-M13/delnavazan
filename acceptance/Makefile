SHELL := /usr/bin/env bash
BIN := bin
.PHONY: smoke teacher-onboarding student-account-claim booking-student-account protected-headers reset seed destroy check-cache

check-cache: ; @$(BIN)/check-cache.sh
reset: ; @$(BIN)/reset.sh
seed: ; @$(BIN)/seed-identities.sh
smoke: ; @$(BIN)/run-login-routing-smoke.sh
teacher-onboarding: ; @$(BIN)/run-teacher-onboarding-acceptance.sh
student-account-claim: ; @$(BIN)/run-student-account-claim-acceptance.sh
booking-student-account: ; @$(BIN)/run-booking-student-account-acceptance.sh
protected-headers: ; @$(BIN)/check-protected-redirect-headers.sh
destroy: ; @$(BIN)/destroy.sh $(CONFIRM)
