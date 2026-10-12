SHELL := /usr/bin/env bash
BIN := bin
.PHONY: smoke reset seed destroy check-cache

check-cache: ; @$(BIN)/check-cache.sh
reset: ; @$(BIN)/reset.sh
seed: ; @$(BIN)/seed-identities.sh
smoke: ; @$(BIN)/run-login-routing-smoke.sh
destroy: ; @$(BIN)/destroy.sh $(CONFIRM)
