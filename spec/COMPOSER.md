# Composer package layout (unreleased 0.4.0)

```text
webong/proxy (repository root; PHP + Guzzle)
├── src/Connection, Http, Tcp, Udp         portable transports
├── src/Registry/Contracts               storage ports
├── src/*Record, *Definition, WebhookRoute plain registry data
└── packages/laravel → webong/proxy-laravel (requires core ^0.4)
    ├── src/                              Laravel/Spatie implementation
    ├── config/                           Laravel configuration
    └── database/                         migrations and legacy adoption
```

There are exactly two PHP packages. Transport and registry code are combined in
`webong/proxy`, which has no Illuminate/Spatie dependency, provider discovery or
migration resources. `webong/proxy-laravel` owns all framework integration and
storage, while keeping its existing `Webong\Proxy` namespaces. The Go module path
and code are unchanged. Earlier experimental child manifests for proxy-transport
and proxy-registry are removed; the core rejects their coexistence to prevent
ambiguous class ownership if stale candidate archives are used.

## Development bootstrap

The root requires the adapter only in require-dev to test the complete integration.
The adapter requires core ^0.4; consumers never inherit this development cycle.
Both candidate manifests currently specify version 0.4.0 so fresh workspaces without
Git metadata resolve the same dependency graph. Keep manifests and release tags
aligned on future version changes.

```sh
php scripts/bootstrap-composer.php
composer test
make packaging
make readiness PYTHON=/path/to/python-with-jsonschema
```

The bootstrap seeds published Composer Source Plugin ^0.4.0 before installing the
real manifest. It discovers the literal `packages/laravel` workspace and records a
relative path, so fresh locked clones can recreate it. A checkout carrying the old
ignored development composer.lock needs `composer update --prefer-dist` after the
bootstrap seed; do not reuse that old graph as release evidence.

The plugin is development-only. Production consumers install normal archives
without a plugin, custom path repositories, symlinks or any bootstrap script.

## Optional projections

After installing the optional Composer Source Plugin, consumers may project `php`:

```json
{
  "extra": {
    "source-plugin": {
      "projections": {
        "webong/proxy": {"surfaces": ["php"]},
        "webong/proxy-laravel": {"surfaces": ["php"]}
      }
    }
  }
}
```

Core-only consumers omit the adapter entry. Surfaces preserve all runtime source,
README/license and, for the adapter, config/migration resources. Projection only
prunes files after dependency resolution; choose the core package itself to avoid
Laravel and Spatie dependencies. Projection cannot target a package that is also a
workspace; consumer projection tests therefore use installed ZIP copies.

## Upgrade and migration ownership

Existing Laravel consumers change their direct requirement from `webong/proxy:^0.3`
to `webong/proxy-laravel:^0.4`; update any retained direct core constraint to ^0.4.
If discovery was disabled for `webong/proxy`, rename that `dont-discover` entry to
`webong/proxy-laravel`; `*` continues to disable all package discovery.
Provider namespaces, publish tags, config keys, models, table names and recorded
migration filenames are preserved. No database conversion is caused by moving
files. Direct references to vendor config/migration paths must change from
`vendor/webong/proxy` to `vendor/webong/proxy-laravel`.

The previously introduced concurrency fix adds
`2026_11_14_000001_create_subscription_locks_table`. Publish/run it before using the
new adapter and upgrade subscription writers together. Durable mutex rows contain
only framed registration-identity hashes; stop writers before cleanup or rollback.
Rollback removes only mutex state. Earlier SQL migration safeguards still apply;
see [SQL upgrades](SQL.md). The core owns no schema. Custom applications implement
[registry ports](REGISTRY.md) and own their stores and migrations.

## Publication order

1. Publish/verify core `webong/proxy` v0.4.0 from the existing repository root.
2. Publish `packages/laravel` as independent `webong/proxy-laravel` 0.4.0, with its
   manifest at the published repository/archive root. Packagist does not discover
   subdirectory manifests automatically; configure a split repository or equivalent
   artifact registry before releasing the adapter.
3. Verify both packages from the intended public registry, plus a fresh Go module
   consumer at v0.4.0. Local ZIP tests do not establish public availability.

No new tag, repository, registry registration or release is created by this layout
implementation. Candidate publication remains a separate action.

## Verification

`make packaging` requires Git, Go, PHP ZIP and PDO SQLite support. It creates real Composer ZIP artifacts for the core and adapter.
Composer-archive guards reject vendored dependencies, tests and adapter resources.
A separate VCS archive test applies Git export rules rather than Composer filters:
`.gitattributes` excludes `/packages`, so root VCS dist archives omit the Laravel
adapter. Go sources, shared fixtures and scripts remain in that archive; its Go
module is race-tested and vetted after extraction. Source clones intentionally
contain the complete development repository; only runtime autoload/dependencies
are isolated there. Actual consumers resolve the VCS-style core ZIP, not a smaller
custom Composer archive.
Neutral consumers exercise HTTP/TCP/UDP/SOCKS and a custom-schema profile repository
in one installation, with no framework classes, migration resources or providers.
Laravel 12/13 consumers discover only the adapter's provider, migrate its installed
resources, use encrypted registries/profiles and transport clients, and verify
actual class ownership. Tests also cover no-dev production installs, plugin-free
lock clones, projections and fresh projected clones, old-release solver rejection,
cold development bootstrap and portable workspace lock recreation. A fresh
runtime-only adapter solve (without a Testbench-generated lock) loads queue jobs
and delivery events. An actual public v0.3.1 consumer is seeded with persisted
SQLite data and app-published migrations, upgraded to the adapter, and checked for
unchanged IDs, ciphertext, configuration and migration history; only the pending
mutex migration runs. Actual Laravel PackageManifest discovery checks cover
normal discovery, renamed opt-outs and wildcard opt-outs.
