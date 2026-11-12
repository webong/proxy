# Portable HTTP profile v1

The [v0.3 candidate contract](CONTRACT.md) defines compatibility and the
[adoption guide](ADOPTING.md) documents released and experimental API changes.

`http-profile.schema.json` describes the language-neutral connection settings.
The `version` field is mandatory and currently must be `1`. Unknown fields and
explicit null values are rejected. Missing timeout fields use client defaults;
explicit timeouts must be finite, greater than zero, and at most 300 seconds.
Both client adapters round positive timeout values upward to whole milliseconds,
so tiny values never become an unlimited timeout. Go applies the connect setting as a separate bound to dialing and the TLS
handshake. Guzzle/cURL may apply it cumulatively across connection setup. These
are native backend mechanics, not an identical whole-connection stopwatch;
configure `request_timeout_seconds` for the overall request bound. Custom Go
dialers must honor context cancellation. Legacy `Dial` and `DialTLS` callbacks
are rejected when a connect timeout is configured; use `DialContext` and
`DialTLSContext` instead. A nil Go base uses package-owned standard defaults;
process changes to `http.DefaultTransport` are not inherited. Pass an explicit
base to retain custom transport settings and policies.

Proxy URLs support HTTP, HTTPS, SOCKS5, and SOCKS5h, including URL-encoded
credentials. SOCKS5 resolves destinations locally; SOCKS5h uses proxy DNS.
Proxy paths other than `/`, queries, and fragments are outside v1. Proxy hosts
must be ASCII hostnames/IPv4 addresses or bracketed IPv6 addresses. Ports are
one to five decimal digits in the range 1–65535; malformed percent escapes
and encoded hostnames are rejected. A missing
scheme entry means direct traffic for that scheme. Profiles never inherit proxy
environment variables.

`no_proxy` accepts ASCII hostnames, IPv4 addresses, leading-dot domains, and `*`.
A bare hostname matches itself and its subdomains; a leading-dot domain matches
subdomains only. Matching is case-insensitive, with domain boundaries, and
ignores a single trailing DNS root dot on request hostnames. Leading-dot IPv4
bypass rules are rejected. IPv4
addresses match exactly; `*` bypasses all proxies. Ports, CIDRs, IPv6 bypass
entries, and wildcard patterns other than `*` are outside v1.

Go uses zero timeout struct fields to represent absence; its JSON parser rejects
explicit zero timeouts. PHP retains absent fields in its array representation.
Empty optional proxy objects/lists may be omitted when Go serializes a profile.
Use Go `Parse` / `json.Unmarshal` and PHP `HttpProfile::fromJson` for strict
wire parsing; object/list distinctions are preserved. PHP `fromArray` accepts
native arrays, so an empty proxy array represents an empty object. Both
libraries' standard JSON serializers emit the portable document.

PHP endpoint configuration is normalized from the existing `proxy` and `options`
shape. Top-level `proxy` takes precedence over `options.proxy`; null falls back
to `options.proxy`. `connect_timeout` and `timeout` map to portable seconds.
`GuzzleClientFactory::profile($endpoint, $name)` explicitly exports an active
`guzzle` or `http` endpoint profile through this normalizer. Portable export
rejects invalid connection values, including zero/unbounded timeouts. Legacy
Guzzle-only options such as headers, TLS verification and redirects are omitted;
callers must preserve and apply them separately. SOCKS
credentials require both username and password, each 1–255 decoded bytes.
Portable HTTP proxy credentials reject decoded C0 control bytes (0x00–0x1F)
across all schemes; the raw TCP API retains support for binary credentials.
See [the TCP/SOCKS contract](tcp.md) for the new raw dialers and HTTP backend
requirements, including authenticated PHP HTTP cURL compatibility.

Existing `GuzzleClientFactory::make` and `options` retain their prior Guzzle-only
behavior, including PHP-specific options and proxy formats. They still require
the `guzzle` driver. To execute a portable profile in PHP, construct a Guzzle
client from `GuzzleOptions::fromProfile($profile)`. That adapter installs a proxy
selection middleware, avoiding version-specific Guzzle bypass and ambient
cURL `NO_PROXY` behavior. Its handler stack owns proxy selection; pass a custom
terminal handler as the second argument rather than replacing the stack.

Profiles are not a destination authorization boundary. Callers own redirect policy,
private-network grants, and destination checks. A remote proxy may resolve the
destination itself; local dial checks then see the proxy rather than the target.

The shared valid/invalid profile fixtures and bypass fixtures are executed by
both test suites. They are examples of the contract, not an exhaustive schema
validator. The schema describes structural constraints; runtime validation additionally
checks URL escapes, ports, IPv6 addresses, and leading-dot IPv4 bypass rules.
