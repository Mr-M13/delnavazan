# Disposable acceptance smoke foundation

This is an isolated WordPress + MariaDB environment for exercising Delnavazan's
real HTTP login and portal routing. It mounts this worktree's `platform/` and
`theme/` read-only, binds WordPress only to `127.0.0.1:18081`, and stores WordPress files and test artifacts in `acceptance/.state/` (ignored by Git),
and MariaDB data in the fixed, project-scoped Docker named volume
`dzn-delnavazan-acceptance-local_mariadb_data`. Both are disposable and
removed by `make destroy CONFIRM=YES`.
It uses only synthetic `@example.invalid` users and local fixture passwords;
it has no production credentials, remote provider calls, payment actions, or
production/database mutations.

The first executable smoke test resets the environment, seeds one student, one
teacher, and one account holding both principal types, then performs the
browser-facing login form POST with cookies and a live nonce. It verifies final
HTTP destinations, rather than inspecting source strings:

- student -> `/student-portal/`
- teacher -> `/teacher-portal/`
- dual-role -> `/dashboard/` (the role chooser)

## Local run

Docker must be available to the current user and these images must already be
cached: `mariadb:11.4`, `wordpress:php8.3-apache`, and
`wordpress:cli-php8.3`. The compose definition and CLI calls use no-pull
policy, so a cache miss fails instead of fetching anything.

```sh
cd acceptance
make check-cache
make smoke
make destroy CONFIRM=YES
```

`make smoke` intentionally leaves the disposable runtime available for local
inspection. `bin/destroy.sh YES` refuses to remove an unexpected entry and
removes only the known children of `acceptance/.state/`; it refuses symlinked
state paths.

## CI and current gaps

Run `make smoke` only on an isolated Docker-capable CI worker with the three
images preloaded (or an explicitly managed image cache). It is not added to the
default GitHub source-contract workflow yet: that workflow currently does not
provide Docker access or a preloaded-image guarantee, and silently changing its
network/image policy would make the evidence non-reproducible.

This first slice covers login routing only. It does not yet provide browser
automation, seed courses/lessons/payments, exercise checkout/providers, or
assert portal content beyond successful role-aware navigation. Those require
separate fixtures and acceptance decisions.
