# Registry SQL storage

Proxy retains ownership of endpoint/destination registries and stored profiles.
The registry suite covers SQLite 3, PostgreSQL 18, MySQL 8.4 and MariaDB 11.4.
The MySQL/MariaDB tests use utf8mb4 with utf8mb4_unicode_ci; changing to latin1
is not a supported workaround for index-size failures. CI runs the full PHP
suite against each server backend; the PHP version matrix also covers SQLite.

## Subscription uniqueness

The original seven-column registration key exceeds InnoDB's 3072-byte limit.
New installations omit that impossible key on MySQL/MariaDB. An additive
migration backfills a 64-character SHA-256 registration_hash, makes it non-null
and unique, then removes the old composite unique index on existing databases.
Original IDs, values, metadata and delivery state are retained. Column lengths
are not reduced and Unicode input is preserved.

The hash uses length-prefixed UTF-8 bytes of endpoint_id, owner_id,
registration_id, webhook_group, routing_scope, routing_key and target_type, in
that order. Registration identity is byte-exact across backends, including case
and trailing spaces; it is no longer determined by a database's text collation.
Other route/registry queries retain their database collation behavior. SHA-256
collisions fail closed through the unique key. The model computes the hash on
save, and the registrar looks up that identity for repeat registration.

Use the registry API or WebProxyDestination model when writing subscriptions.
Raw query-builder inserts/updates bypass model events and must supply/recompute
the same hash when identity fields change. registration_hash is internal and
omitted from model serialization. Do not bulk-update identity fields without
maintaining it.

## Existing installations

Back up the database and stop subscription writers/queue workers during the
upgrade. Deploy matching code and migrations together: older code does not
populate the required hash. Publish the package migrations with the existing
proxy-migrations tag (without overwriting locally customized files), review
any already-published subscriptions creation migration, then run migrations.
Existing installations receive the additive migration; migrations already
recorded as applied are not rerun. The historical creation migration has a
narrow MySQL/MariaDB correction because a later migration alone cannot unblock
a fresh install that fails before reaching it.

If an earlier MySQL migration failed with 1071, it may have left an empty
subscriptions table without a migration-history row. The corrected creation
migration recognizes the complete empty table and repairs its routing index;
the additive migration then installs uniqueness. An incomplete or nonempty
unrecorded table is rejected for operator inspection rather than deleted.
For previously published copies, apply the same creation-migration correction
or republish only after reviewing local customizations. Table names continue
to respect proxy.tables configuration.

The additive migration is resumable after partial DDL, but must run with writers
stopped. It restores the legacy key on PostgreSQL/SQLite rollback. MySQL/MariaDB
rollback fails before changing schema because restoring the old key would fail;
use a forward fix or restore the pre-upgrade backup. Do not roll the application
back to a version that omits the hash while keeping the new schema.

## Local verification

Tests default to in-memory SQLite. Server tests are destructive and must target
an isolated disposable test database, never application data:

```sh
PROXY_TEST_DB=mysql PROXY_TEST_DB_PORT=3306 composer test
PROXY_TEST_DB=mariadb PROXY_TEST_DB_PORT=3306 composer test
PROXY_TEST_DB=pgsql PROXY_TEST_DB_PORT=5432 composer test
```

Optional PROXY_TEST_DB_HOST, PROXY_TEST_DB_DATABASE, PROXY_TEST_DB_USERNAME and
PROXY_TEST_DB_PASSWORD configure that test database (defaults: loopback,
proxy_test, root for MySQL/MariaDB or postgres for PostgreSQL, empty password).
PDO extensions for the selected backend are required. Coverage includes fresh
migrations, populated upgrades, custom table names, repeat registration,
metadata/encrypted profiles, routing and webhook delivery. This certifies Proxy's
registry behavior, not a consuming application's database portability.
