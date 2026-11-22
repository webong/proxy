# Proxy

Proxy provides networking tools for PHP and Go, plus registry contracts for storing
and finding connection settings in your own application.

- Make HTTP requests through direct connections, HTTP/HTTPS proxies or SOCKS5.
- Open TCP connections and send UDP datagrams, including SOCKS5 UDP ASSOCIATE.
- Share portable connection profiles between PHP and Go.
- Build a PHP registry using your own storage, or use the optional Laravel adapter
  for Eloquent persistence, migrations and Spatie webhooks.

The PHP core requires PHP 8.3+ and Guzzle. Laravel is optional.

## Setup

PHP applications can install both libraries from one checkout using Composer
Source Plugin. Follow the [PHP setup guide](spec/COMPOSER.md), then see the
[PHP API](src/php/README.md) or [Laravel integration](pkg/laravel/README.md).
Existing public PHP 0.4.0 download links need repair; use the checkout setup.

For Go, use `go get github.com/webong/proxy/src/go@v0.5.0`.
The module is tagged `src/go/v0.5.0`; older versions keep their original paths.
See the [Go guide](src/go/README.md) for examples.

## Repository

```text
proxy/
├── composer.json        webong/proxy: neutral PHP package
├── src/php/             PHP transports and registry contracts
├── pkg/laravel/         webong/proxy-laravel: Laravel and Spatie integration
├── src/go/              github.com/webong/proxy/src/go
├── spec/                shared contracts and fixtures
├── tests/               PHP and integration tests
└── scripts/             development tools
```

## Development

```sh
php scripts/bootstrap-composer.php
composer test
go -C src/go test -race ./...
go -C src/go vet ./...
make packaging
make workspace-check
```

See [setup and upgrades](spec/COMPOSER.md), [transport contracts](spec/CONTRACT.md)
and [registry contracts](spec/REGISTRY.md).
