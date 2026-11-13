# Changelog

## Unreleased

- Adopt Composer Source Plugin ^0.4 workspaces for local package development.
- Prepare a separate Laravel-free proxy-transport package while retaining
  webong/proxy as the full registry/persistence/integration entry point. Public
  PHP namespaces, transport signatures and the Go module remain unchanged.
- Add archive-consumer, no-plugin/no-dev, projection and workspace bootstrap
  installation checks. The new package must be published before the parent
  release can be installed from the default registry; see spec/COMPOSER.md.

## v0.3.1 — 2026-11-13

- Fix subscription registration uniqueness on utf8mb4 MySQL/MariaDB without
  shortening stored values. Add a resumable migration for existing registries
  and recovery for empty tables left by the original failed MySQL migration.
- Preserve identity through provider updates, partial/quiet model saves and
  PostgreSQL UUID normalization; align exact registration predicates across SQL
  collations. Handle genuine legacy index names and atomic populated rollback.
- Test registry migrations and runtime behavior on PostgreSQL 18, MySQL 8.4,
  MariaDB 11.4 and SQLite. See [SQL upgrade requirements](spec/SQL.md) before
  deploying: writers must be stopped and MySQL rollback requires a forward fix
  or a pre-upgrade backup.

## v0.3.0 — 2026-11-12

- Add portable HTTP profiles shared by PHP and Go, PHP normalization/export and
  Guzzle options, and the Go HTTP client factory.
- Add direct TCP and SOCKS5 CONNECT, HTTP CONNECT and verified HTTPS proxy hops,
  with optional SOCKS credentials/CONNECT Basic authentication and private CA trust.
- Add direct UDP and SOCKS5 UDP ASSOCIATE, preserving datagram boundaries and
  controlling association lifetime, deadlines, DNS modes and wire-size limits.
- Freeze the v0.3 source API and existing wire-version-1 schemas, with compatibility
  guards, negative mutation tests, public-consumer policy tests and a readiness gate.
- Preserve the existing Laravel endpoint registry, stored profiles, webhooks and
  native Guzzle API. No database migrations or stored-profile conversion are needed.

PHP raw HTTPS-proxy streams provide the proxy TLS layer only; Go can nest target
TLS. PHP blocking DNS/callbacks cannot be preempted. UDP fragmentation and HTTP/2
CONNECT are outside this release. Authenticated PHP SOCKS HTTP needs libcurl 8.23+.
See [the public contract](spec/CONTRACT.md) for all supported limits and
[the adoption guide](spec/ADOPTING.md) for optional use and earlier experimental
API adjustments. Package v0.3.0 is distinct from the unchanged JSON `version: 1`.
