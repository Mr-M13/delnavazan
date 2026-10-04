# Staging deployment recovery

Each run writes a private backup directory named with its UTC time and source SHA under the configured `STAGING_BACKUP_DIR`. Keep that directory outside `public_html`; it contains a full database export and copies of the Platform plugin and active theme.

## Application files

1. Keep staging in maintenance mode while investigating.
2. Read `before.json`, `deployment.json`, and any `failure.json` in that run's backup directory.
3. Restore the two application directories from `application-files.tar.gz` into the fixed staging `wp-content` directory, replacing the current `plugins/delnavazan-platform` and `themes/delnavazan-theme` directories.
4. Run WP-CLI checks for active plugin/theme and compare the Platform version, build, and schema option with `before.json`.
5. Keep maintenance mode on until the site is compatible with the current database and the read-only staging smoke passes.

For a manual file restore, substitute the exact private backup directory from the deployment record:

```bash
wp --path="$HOME/domains/staging.delnavazan.com/public_html" maintenance-mode activate
cd "$HOME/domains/staging.delnavazan.com/public_html/wp-content"
rm -rf plugins/delnavazan-platform themes/delnavazan-theme
tar -xzf "/absolute/private/backup/path/application-files.tar.gz"
```

The deploy script restores application files automatically after migration or post-deploy identity failure and intentionally leaves maintenance mode enabled. If the post-deploy HTTP smoke fails, the workflow enables maintenance mode but leaves the migrated application files in place for diagnosis. Do not run the mirror sync helper as a rollback method.

## Database recovery boundary

The Platform migration system is forward-only; the deploy workflow does not claim to reverse migrations. The database export is recovery evidence and a restore source only after an operator assesses writes made since the snapshot and accepts their loss. Do not automatically restore it: that would discard all later staging data. If a migration partially changed the database, keep staging in maintenance mode, retain the backup, inspect the migration ledger and schema, and choose either a forward repair or a coordinated database restore before reopening the site.

After a successful migration, restoring old application files may be incompatible with the newer schema. Check the migration compatibility contract before file rollback; if uncertain, keep maintenance mode on and repair forward.
