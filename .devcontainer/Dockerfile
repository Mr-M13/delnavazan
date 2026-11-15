# Matches the PHP version and extensions used by .github/workflows/pr-runtime-gate.yml.
# OS differs from the Ubuntu runner (Debian bookworm here); PHP lint and source
# contracts do not depend on the distribution.
FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# curl, mbstring, pdo_sqlite and sqlite3 are already compiled into php:8.3-cli;
# the PR runtime gate installs the same set for its service tests.
RUN php -r 'foreach (["curl","mbstring","pdo_sqlite","sqlite3"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing extension: $e\n"); exit(1); } }'

WORKDIR /workspaces/delnavazan
