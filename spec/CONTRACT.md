# Proxy v0.3.0 public contract

Package release: **v0.3.0**. Contract revision: **1**. Status: **frozen for the
v0.3 patch line**. This pre-1.0 release does not declare a v1 API commitment. This contract
covers the transport work introduced after `v0.2.1`. It does not rename or replace
the existing Laravel registry/webhook APIs. See [adoption guide](ADOPTING.md) and
[readiness evidence](READINESS.md).

## Authoritative surface

| Surface | Authority |
| --- | --- |
| HTTP profile JSON v1 | `http-profile.schema.json`, [HTTP semantics](README.md), shared profile/bypass/credential fixtures |
| TCP profile JSON v1 | `tcp-profile.schema.json`, [TCP semantics](tcp.md), TCP/CONNECT fixtures |
| UDP profile JSON v1 | `udp-profile.schema.json`, [UDP semantics](udp.md), UDP/frame fixtures |
| Go source API | `api/go-v0.3.json`, external consumer tests in `tests/contract` |
| PHP source API | `api/php-v0.3.json`, standalone `scripts/check_php_api.php`, public consumer tests |
| Schema identity | `api/schemas-v0.3.json`, checked by the schema CI job |

The Go packages are `httpprofile`, `httpclient`, `socketprofile`, `tcpdial` and
`udpdial` under `github.com/webong/proxy`. The snapshot also records the fields
and methods of the internal types exposed through public Address/ReplyError
aliases; their internal import path is not a consumer API.

Subclassing the shared PHP SocketProfile outside the package is not covered by
this v0.3 contract; its protected hooks support the built-in profile types.

The supported PHP classes are `Http\HttpProfile`, `Http\HttpProfileNormalizer`,
`Http\GuzzleOptions`, `Connection\SocketProfile`, the public TCP/UDP profiles and
dialers, `Udp\UdpAssociation`, `Udp\Datagram`, `Udp\Socks5Datagram`, their exception
types, and `GuzzleClientFactory`, all under `Webong\Proxy`. Constructors and
methods explicitly marked `@internal` are excluded. Other `Connection` classes
are wire helpers, not supported consumer entrypoints. Framework-independent
transport classes need no Laravel boot, but the Composer package still installs
its Laravel dependencies.

The existing JSON discriminator remains `version: 1`. That is the wire format
version used throughout this work, not a package v1.0 release. **The target
package release is v0.3.0.** A future v1 public contract is explicitly deferred.

## Compatibility rules

- A valid v1 wire document keeps its documented meaning. Unknown fields and
  explicit nulls remain invalid. New wire fields or incompatible values require
  a new wire version/schema; do not silently extend this strict v1 shape.
- Source signatures, public field types, PHP named-argument names, defaults and
  ownership guarantees are frozen for v0.3 and its patch line.
  Removing/changing them requires a new minor candidate (such as v0.4) and a
  migration plan; this pre-1.0 policy does not promise a v1 stable API. Additive APIs require explicit review
  and an approved snapshot update; ordinary refactors must leave baselines alone.
- Security/correctness fixes may reject inputs already outside the documented
  contract. Changes affecting documented valid cases require compatibility review,
  fixture updates and release notes, not merely regenerating snapshots.
- JSON is interoperable data, not canonical bytes: object key order, numeric
  spelling and omission of empty optional Go fields may differ. Go zero timeout
  struct fields mean absent; explicit wire zero timeouts remain invalid.
- PHP exception types and documented Go error matching/typed fields are the API.
  Human-readable error text, stack traces and native OS error wording are not.
- TLS root material, callback implementations, clients, connection handles,
  headers, redirect policy and destination permissions are runtime concerns;
  they are not new portable profile fields.

## Ownership and policy

Reuse an HTTP client per effective profile/policy; close idle connections when
retiring it. The Go factory clones a supplied transport and preserves its
context-aware dialer/TLS settings. Assign redirect policy to the returned client.
The PHP adapter owns its handler stack's proxy selection; inject a terminal
handler and retain that stack. Keep application headers and supported native
client options separate from exported connection profiles.

TCP returns a caller-owned connection/stream; callers close it and own subsequent
application I/O deadlines. UDP returns an owning association, including its SOCKS
control connection. Go setup contexts stop governing the handle after successful
setup; UDP write deadlines and Close govern later DNS. PHP cannot preempt system
DNS or callbacks mid-call. Connector hooks see the proxy/relay hop, not necessarily
the ultimate destination. Profiles are not authorization or SSRF protection.

Both libraries provide direct TCP, SOCKS5 CONNECT, HTTP/HTTPS CONNECT, direct UDP
and SOCKS5 UDP ASSOCIATE. SOCKS credentials require authentication without downgrade;
CONNECT uses preemptive Basic only. HTTPS proxy identity and trust are verified
before sending credentials, using system trust or explicit private roots. PHP
rejects inherited stream CA overrides without explicit `proxyCaFile`.

Known supported asymmetries are part of v0.3: PHP native HTTPS-proxy streams cannot
nest target TLS; Go can. PHP detects idle UDP control closure on a subsequent
operation/state check; Go watches continuously. Native HTTP connect/TLS timeout
mechanics differ, so use an overall request timeout for an overall HTTP budget.

Unsupported: SOCKS BIND, HTTP/2 or extended CONNECT, CONNECT-UDP, SOCKS UDP
fragmentation, service-name ports/scoped IPv6, and CIDR/IPv6/port-specific HTTP
bypass entries. UDP has a 65,507-byte total wire ceiling and no delivery guarantees.
There is no listening proxy server, destination policy engine, or Go registry.
These are deliberate scope boundaries, not prerequisites for the first HTTP
consumer integration.

## Updating the baseline

Normal checks are `go test -race ./...`, `go vet ./...`, `composer test`, and
`python scripts/check_profile_schemas.py` (Python requires `jsonschema>=4,<5`).
Go source snapshots exclude implementation fields, bodies and error strings.
They record exported variable names; exact public error-variable types are
frozen by pointer-to-error assertions in the external consumer tests. Both
checks are required; the AST snapshot alone is not a semantic type checker.
PHP snapshots include class/method modifiers and reference returns, and exclude
PHP runtime Exception methods and `@internal` APIs. The
standalone guard uses package-only autoloading and normalizes Reflection
`self`/`parent` spelling across PHP minors; late-bound `static` remains distinct.
The external consumer tests supplement snapshots with error/interface and
composition checks; snapshots cannot prove runtime behavior or complete ABI
compatibility by themselves.

Only after an explicit contract review:

```sh
go run ./scripts/snapshot-api -write
php scripts/check_php_api.php --write
```

Review the resulting diff. Never update a v1 schema digest to bypass a breaking
wire change; introduce the next schema version instead. No automatic baseline
regeneration runs in CI. Consumers should pin the reviewed readiness commit and
commit their dependency lockfiles. Tagged releases must point to a head that passed the full readiness checks.
The v0.3.0 tag is the immutable package identity; consumers should also retain
the resolved commit in their dependency lockfiles.
