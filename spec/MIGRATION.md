# Migrating to the v0.3.0 transport candidate

## From released v0.2.1

The portable transport APIs are additions to the existing PHP package. Keep using
`GuzzleClientFactory::make()` / `options()` for native, registry-backed Guzzle
configuration. Those methods keep the `guzzle` driver and native option behavior.
The Go module and portable HTTP/TCP/UDP APIs were added on the experimental branch;
they are not present in the existing v0.2.1 tag.

To export connection settings, use `GuzzleClientFactory::profile()` (active
`guzzle` or `http` driver) or `HttpProfileNormalizer::normalize()`. The normalizer
extracts `proxy`, `connect_timeout` and `timeout`. Top-level nonnull `proxy` wins
over `options.proxy`; legacy `no` becomes `no_proxy`. Headers, cookies, TLS verify,
authentication to the target, redirect policy and other Guzzle-only options are
not exported or recreated by the normalizer. Preserve and apply them separately.
Do not serialize them as unknown portable fields.

```php
$profile = (new HttpProfileNormalizer)->normalize($configuration);
$options = GuzzleOptions::fromProfile($profile);
$options['headers'] = $configuration['options']['headers'] ?? [];
$options['allow_redirects'] = false; // Example application decision.
$client = new \GuzzleHttp\Client($options);
```

A bare PHP `new HttpProfile()` / `new TcpProfile()` / `new UdpProfile()` defaults
to version 1. Wire JSON must include `version: 1`; that wire discriminator is unchanged and
is separate from the v0.3.0 package release. Timeouts explicitly set to
zero/null or above 300 seconds, invalid proxy URL fields and unsupported bypass
rules now fail portable validation. Omitted HTTP timeouts keep backend defaults;
omitted raw setup timeouts use 10 seconds. Portable clients do not inherit proxy
environment variables.

## From earlier experimental commits

1. **TCP profile identity:** `tcpdial.Profile` is now an alias of
   `socketprofile.TCPProfile`, not `socketprofile.Profile`. Convert an existing
   shared value with `tcpdial.Profile(shared)` when passing it to `tcpdial.New`.
   Shared/UDP profiles remain SOCKS-only. Prefer `tcpdial.Parse` for TCP JSON.
2. **HTTPS proxy trust:** Go `tcpdial.New` accepts variadic runtime options;
   existing three-argument calls still work. `WithProxyRootCAs(pool)` clones a
   nonnil pool. Its signature as a function value is now variadic; update any
   explicit constructor function types. PHP `TcpDialer` gained a fourth optional
   named argument `proxyCaFile`. Both use verified TLS 1.2+ for HTTPS proxy hops.
3. **PHP inherited CA settings:** do not put `ssl.cafile` / `ssl.capath` on the
   connector/default stream context. Without explicit `proxyCaFile`, those keys
   fail before TLS/CONNECT, even when empty/null or the relative name `0`.
   Explicit CA files replace connector files and inherited/INI CA directories.
4. **HTTP errors:** malformed/truncated raw CONNECT responses in Go match
   `tcpdial.ErrConnectProtocol`, not the SOCKS `ErrProtocol`. Truncation retains
   EOF matching. Rejected statuses use `*tcpdial.ConnectError{StatusCode}`;
   PHP uses the HTTP status as `DialException::getCode()`. Avoid error-string tests.
5. **UDP deadlines:** Go pending destination DNS is interrupted by Close and
   write deadlines. Match `os.ErrDeadlineExceeded` for I/O deadlines and
   `net.ErrClosed` for closure; setup still uses context errors. Clearing/extending
   a write deadline updates pending DNS.
6. **Authentication:** portable HTTP credentials reject decoded C0 bytes. Raw
   SOCKS retains binary credentials and requires both username/password (1–255
   bytes each), with no unauthenticated downgrade. Raw CONNECT Basic additionally
   rejects DEL and username colons; its password can be empty when supplied.
7. **API boundaries:** use public dialers/profiles, not `Connection` wire helpers
   or the `@internal` UDP constructor/resolver. Go public interfaces and PHP named
   arguments are recorded in `api/`; patch releases in v0.3 must preserve this baseline. Future minor candidates may
   evolve it with an explicit migration guide; a v1 API is not being declared.

## Runtime and deployment requirements

| Runtime | Minimum/verified behavior |
| --- | --- |
| PHP | 8.3+, CI covers 8.3/8.4/8.5; Laravel 12 on all three, Laravel 13 on 8.4/8.5 |
| Go | Module minimum 1.23; CI tests 1.23 and current stable |
| PHP raw HTTPS proxy | ext-openssl; system or explicit CA trust; no verification-off switch |
| PHP SOCKS HTTP | ext-curl; authenticated SOCKS HTTP additionally requires libcurl 8.23+ |
| PHP raw SOCKS TCP/UDP | Streams; no cURL dependency |

PHP raw HTTPS-proxy streams provide proxy-hop TLS only. Do not try to create
independent target TLS by calling `stream_socket_enable_crypto()` again. Use
Guzzle/cURL for HTTPS requests through an HTTPS proxy, or another transport with
nested TLS support. Go callers may wrap the returned tunnel with `tls.Client`.
Private CA paths/pools stay local runtime configuration and are not exported to
another host. Exported JSON may contain proxy credentials; the existing PHP
registry encrypts stored configuration, but JSON export itself is plain data.

## Consumer handoff (Gateway remains unchanged)

The first consumer slice can use PHP normalization/export and Go HTTP client
creation. Retain separately carried headers, native TLS policy, destination and
redirect checks. Pass a policy-aware base transport to Go; do not assume proxy
DNS exposes the target IP to that dialer. Include explicit proxy/relay grants
where deployment policy requires them. Keep TLS trust roots runtime-local.

Pin the exact reviewed commit (or a later verified release) in both dependency
managers and lock it before integration tests. Compare existing and new profile
translation/bypass behavior, validate deployed PHP extensions/libcurl, and run
consumer end-to-end policy/connection-lifecycle tests before rollout. Those are
consumer integration checks, not missing Proxy transport features. Raw TCP/UDP
need not be adopted as part of the initial HTTP change.
