# Deployment

The monorepo contains two independently deployed WordPress components:

- `platform/` -> `wp-content/plugins/delnavazan-platform/`
- `theme/` -> `wp-content/themes/delnavazan/`

Staging is the only deployment target during frontend completion. Production DNS and the old live site are not changed until final acceptance.

Deployment branches are generated from the monorepo for compatibility with DirectAdmin:

- `deploy-platform` contains the contents of `platform/` at repository root.
- `deploy-theme` contains the contents of `theme/` at repository root.

These branches are deployment artefacts; normal development belongs on `main`.
