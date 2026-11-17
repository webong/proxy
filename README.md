# Proxy

Transport libraries and storage-neutral registry contracts, with an optional
Laravel adapter. The repository is a development workspace, not a Composer library
package at its root.

```text
proxy/
├── php/                 webong/proxy: PHP transports + registry contracts
├── adapters/laravel/     webong/proxy-laravel: Laravel, Spatie, persistence, migrations
├── go/                  github.com/webong/proxy/go: Go transports
├── spec/                canonical shared wire contracts and fixtures
├── tests/               PHP/integration tests and shared TLS fixtures
└── scripts/             workspace bootstrap, API/schema and fixture-sync tools
```

- PHP core: `composer require webong/proxy:^0.4` — [guide](php/README.md).
- Laravel: `composer require webong/proxy-laravel:^0.4` — [guide](adapters/laravel/README.md).
- Go: `go get github.com/webong/proxy/go@v0.4.0` — [guide](go/README.md).

The former PHP distribution repositories have been deleted. Packagist still
references them, so public PHP installs are blocked pending monorepo publication
and historical-reference recovery. The catalog tooling below is not a published
replacement yet. Go remains independently available through its nested module.
PHP namespaces remain unchanged. Go imports move from `github.com/webong/proxy/...`
to `github.com/webong/proxy/go/...`; Go releases use `go/v0.4.0` tags. Existing
v0.3.x tags remain immutable. Wire profiles remain JSON version 1.

## Development

```sh
php scripts/bootstrap-composer.php
composer test
go -C go test -race ./...
go -C go vet ./...
python3 scripts/sync_go_testdata.py --check
make packaging
make readiness PYTHON=/path/to/python-with-jsonschema
```

Composer Source Plugin ^0.5 links `php` and `adapters/laravel` as local development
workspaces and prepares future same-repository PHP releases. Run
`make publication-check` for its disposable publication/consumer tests. Current
0.4.0 consumers do not need the plugin. The proposed catalog and correction plan
are documented in [packaging](spec/COMPOSER.md); they are not publicly active yet. A checkout carrying the previous
layout's ignored composer.lock needs `composer update --prefer-dist` after seeding
the plugin. All language tests run without a root go.mod or go.work file.

Shared fixtures remain canonical under `spec/` and `tests/fixtures/tls`. The Go
module contains checked-in, byte-identical mirrors under `go/testdata` so its
published module ZIP and independent tests never need files outside `go/`. After
editing shared fixtures, run `python3 scripts/sync_go_testdata.py`; CI checks drift.

See [packaging, upgrades and release order](spec/COMPOSER.md),
[transport contracts](spec/CONTRACT.md), and [registry contracts](spec/REGISTRY.md).
The future root catalog advertises the index; namespaced package tags and retained
split commits supply the actual PHP libraries. Catalog setup is not live yet.
