# PHP setup and upgrades

The repository root is `webong/proxy`. Its source lives in `php/src/` and its
runtime dependencies are PHP and Guzzle. `adapters/laravel/` is the optional
`webong/proxy-laravel` package, which requires the core and owns Laravel, Spatie,
configuration, persistence and migrations. PHP namespaces and public APIs stay
unchanged. There is no third workspace package.

## Install from one checkout

Provision a pinned checkout of this repository at `ext/proxy` inside your
application. Keep that checkout in place for subsequent installs and deployments.
A submodule or your deployment tooling can provide it; Composer Source Plugin
does not download nested packages automatically.

First install and explicitly trust the plugin in the application:

```sh
composer config allow-plugins.webong/composer-source-plugin true
composer require --dev webong/composer-source-plugin:^0.5.1
```

Then add this configuration to the application's root `composer.json`:

```json
{
    "extra": {
        "source-plugin": {
            "workspaces": {
                "include": ["ext/proxy", "ext/proxy/adapters/laravel"],
                "symlink": false
            }
        }
    }
}
```

For the neutral library, require `webong/proxy:^0.4`. For Laravel integration,
require `webong/proxy-laravel:^0.4`; it brings in the core:

```sh
composer require webong/proxy-laravel:^0.4
```

Neutral applications can omit the adapter workspace. The plugin reads workspace
configuration from the application's root manifest; configuration in dependencies
is not inherited. No manually configured Composer path repository is needed.

Both workspace directories must exist before dependency resolution and before a
cold locked install, including `composer install --no-dev --no-plugins`. Commit
the application's lock file and provision the same pinned checkout at the same
relative path on other machines. Do not distribute locks pointing at temporary or
machine-specific absolute paths. The plugin is development tooling; locked
production installs need the source checkout but can disable plugins.

With `symlink: false`, Composer copies the libraries into vendor. Reinstall them
after changing the checkout. Use `symlink: true` for local development if you want
source edits to take effect immediately.

## Work on Proxy

```sh
php scripts/bootstrap-composer.php
composer test
make packaging
make workspace-check
```

The bootstrap seeds a compatible plugin before resolving the root project. The
root supplies the core itself and links only `adapters/laravel`; there should be
no `vendor/webong/proxy` dependency inside this repository. When upgrading an old
checkout, run `composer update --prefer-dist` explicitly after bootstrap to refresh
its existing root lock. Bootstrap repairs missing seed files and upgrades an
incompatible plugin seed without silently replacing the root dependency lock.

The root and adapter declare version 0.4.0 so provisioned checkouts without Git
metadata satisfy their constraints. Validate these source manifests using
`composer validate --strict --no-check-version`. This version field does not
publish a release or change existing tags.

## Compatibility and migration ownership

Applications using Laravel persistence or webhooks require the adapter. The
neutral core contains registry interfaces and records; custom stores implement
those interfaces and own their own schema. The adapter continues to own its
existing migrations and encrypted Eloquent store. This layout change does not
move application data or require a new database migration. See the
[Laravel guide](../adapters/laravel/README.md) for installation and upgrades.

Go remains `github.com/webong/proxy/go`, with tags such as `go/v0.4.0`. Legacy
root Go versions remain immutable. Shared wire profiles retain JSON version 1.
Canonical fixtures live under `spec/` and `tests/fixtures/tls`; run
`python3 scripts/sync_go_testdata.py` after editing them. The checked-in Go copies
let the published module test independently of the repository root.

## Verification and existing public downloads

Packaging checks install real archives into neutral and Laravel applications,
exercise migrations and rollback, and replay locks without plugins. Workspace
checks install both libraries from one checkout with independent dependency
requirements. Bootstrap checks cover cold installs, old seeds and damaged files.

The deleted PHP distribution repositories are still referenced by existing
public 0.4.0 metadata. These checkout instructions avoid those download URLs;
they do not repair them. No new repository, registry entry, release or catalog is
created by this change. Historical tests use exact immutable monorepo trees and
frozen metadata in `tests/fixtures/composer/history.json`; passing those fixtures
is not evidence that old public download URLs work.
