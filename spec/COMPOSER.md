# Components, packaging and releases (unreleased 0.4.0)

```text
composer.json                  webong/proxy-workspace: development only
php/composer.json              webong/proxy: PHP + Guzzle, no Laravel
adapters/laravel/composer.json  webong/proxy-laravel: Laravel/Spatie + PHP core
go/go.mod                      github.com/webong/proxy/go: Go 1.23+
```

Transport and storage-neutral registry contracts are combined in the PHP core.
The Laravel adapter owns every Eloquent model, encrypted store, provider, config
file and migration. PHP namespaces remain `Webong\Proxy`; no duplicate classes or
aliases are introduced. Go has its own module root and import prefix ending in
`/go`. There is no root Go module or go.work file.

## Development workspace

```sh
php scripts/bootstrap-composer.php
composer test
go -C go test -race ./...
go -C go vet ./...
python3 scripts/sync_go_testdata.py --check
make packaging
make readiness PYTHON=/path/to/python-with-jsonschema
```

Composer Source Plugin ^0.4 is development tooling. Bootstrap seeds the plugin,
then resolves literal `php` and `adapters/laravel` workspaces. Their manifests use
candidate version 0.4.0, so fresh checkouts without Git metadata resolve the same
local graph. The root project is not a publishable library and has no version
requirement from either component. Update the ignored old composer.lock after a
layout change with `composer update --prefer-dist`.

Consumers use normal installed packages without the plugin, bootstrap script or
path repositories. Root dev dependencies and scripts are never inherited.

## Isolated distributions

PHP consumers resolve ZIPs whose root is the corresponding component directory.
Both Composer archives and Git subtree archives are tested. Each PHP component's
own .gitattributes and archive.exclude remove development vendor/lock/cache files.
Core archives contain no adapter, Go, configuration or migration directories.

Go module ZIPs contain only `go/` content, with paths relative to that directory.
All Go source, internal packages, contract tests, snapshot tooling, README and
LICENSE live there. Checked-in `go/testdata` mirrors make tests independent of
parent directories. The repository's `spec` and shared TLS fixtures are canonical;
`scripts/sync_go_testdata.py --check` rejects drift. Update canonical fixtures,
then run the sync script. The checker rejects linked files/directories, missing
canonical groups and stale mirrors; sync replaces file links without following
them and refuses linked directories. Isolated regression tests run in schema CI
and readiness. Standalone Go fixture suites also fail on missing/empty groups.
Packaging extracts the isolated Go subtree and runs
race tests and vet. A separate consumer downloads a correctly prefixed module ZIP
through a temporary file GOPROXY with an empty private module cache and no replace
or go.work. This validates Go's real module download/ZIP handling without creating
a public version or polluting the user's module cache. Internal consumer guards
also use GOPROXY=off and local replacement to isolate compile-time API checks.

## Optional PHP projections

After installing the optional Composer Source Plugin, consumers may select the
`php` surface for `webong/proxy` and, when installed, `webong/proxy-laravel`.
Core surfaces retain source, README and license; adapter surfaces also retain
configuration and migrations. Projection prunes files after dependency resolution;
it is not a substitute for choosing the framework-neutral core. Projection tests
use installed archives, not development workspace symlinks.

## PHP upgrade and schema ownership

Existing Laravel consumers replace their direct `webong/proxy:^0.3` requirement
with `webong/proxy-laravel:^0.4`. Update any retained direct core constraint to
^0.4 too. Rename an old `extra.laravel.dont-discover` entry for `webong/proxy` to
`webong/proxy-laravel`; wildcard opt-outs remain effective. The adapter explicitly
requires Laravel Framework ^12 or ^13, including Foundation traits used by jobs.

Provider namespaces, publish tags, config keys, table names and recorded migration
filenames are unchanged. Direct vendor resource paths move from
`vendor/webong/proxy` to `vendor/webong/proxy-laravel`. No data conversion is caused
by moving source directories. Existing app-published migrations remain valid.

The concurrency fix requires publishing/running
`2026_11_14_000001_create_subscription_locks_table` before using the new adapter.
Upgrade subscription writers together. Durable mutex rows store identity hashes;
stop writers before cleanup or rollback. The neutral core owns no schema; custom
adapters own theirs. See [SQL upgrades](SQL.md) and [registry contracts](REGISTRY.md).

## Go upgrade

Update imports from `github.com/webong/proxy/...` to
`github.com/webong/proxy/go/...`, require the new module, then run `go mod tidy`
in the consuming application. Old v0.3.x tags and imports remain immutable and
available. Transport API shapes and JSON profile version 1 remain unchanged.
The Go module uses tags such as **`go/v0.4.0`**. A root `v0.4.0` tag does not
publish the nested Go module. See [the Go guide](../go/README.md).

## Release prerequisites and order

1. Configure an independent Composer VCS repository or artifact distribution for
   the `php` subtree, retaining the package name **webong/proxy**. The existing
   Packagist package must point to that distribution rather than this repository's
   root development composer.json. Preserve all historical PHP versions and their
   original source/dist references: old v0.3.x tags must never be recreated against
   split subtree content. Verify fresh v0.3.1 installs and an existing locked v0.3.1
   consumer both before and after changing the package's distribution configuration.
   If the chosen distribution cannot retain historical versions, resolve that
   publication strategy before retargeting; local 0.4 fixture tests do not prove a
   future registry configuration preserves them. Packagist does not discover subdirectory
   manifests automatically. Tag the PHP component distribution **v0.4.0** and
   verify a public core-only install.
2. Publish the `adapters/laravel` subtree as **webong/proxy-laravel**, with its
   manifest at the published root and **v0.4.0** tag. It requires core ^0.4. Verify
   a public fresh production install, Laravel boot, migrations and a v0.3.1 upgrade.
3. Tag this repository **go/v0.4.0** at the validated commit. In a fresh external
   module, run `go get github.com/webong/proxy/go@v0.4.0`, then exercise HTTP/TCP/UDP
   with no replace directive, go.work, parent files or local module cache shortcut.

Do not publish the root development Composer project as webong/proxy. PHP and Go
have separate module/package tags and publication steps. No tag, repository,
Packagist change or release is created by this layout implementation. Local ZIPs
and local-replace consumer tests establish readiness, not public publication.

## Verification

`make packaging` requires Composer, Git, Go, PHP ZIP and PDO SQLite. It creates
real Composer and Git-subtree artifacts for both PHP components and an isolated
Go archive. It tests neutral transport/registry use, no-dev production solves
without Testbench, Laravel 12/13 discovery and opt-outs, encryption and stored
profiles, fresh plugin-free lock clones, projections, cold two-workspace bootstrap
and portable lock recreation. A public v0.3.1 consumer is seeded and upgraded;
raw rows, ciphertexts, IDs and migration history are preserved, with only the
pending mutex migration applied. Exported Go tests retain the frozen API-shape
baseline and include independent-consumer compilation/execution on the new path.
