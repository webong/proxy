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

These are unreleased 0.4.0 candidates; the commands require public publication.
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

Composer Source Plugin links `php` and `adapters/laravel` as local development
workspaces. Consumers do not need the plugin. A checkout carrying the previous
layout's ignored composer.lock needs `composer update --prefer-dist` after seeding
the plugin. All language tests run without a root go.mod or go.work file.

Shared fixtures remain canonical under `spec/` and `tests/fixtures/tls`. The Go
module contains checked-in, byte-identical mirrors under `go/testdata` so its
published module ZIP and independent tests never need files outside `go/`. After
editing shared fixtures, run `python3 scripts/sync_go_testdata.py`; CI checks drift.

See [packaging, upgrades and release order](spec/COMPOSER.md),
[transport contracts](spec/CONTRACT.md), and [registry contracts](spec/REGISTRY.md).
No root Composer package or tag is a substitute for publishing the PHP component
subtrees through their configured Composer repositories.
