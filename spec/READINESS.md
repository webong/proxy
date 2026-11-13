# Release readiness

The v0.3.0 transport contract is frozen by [CONTRACT.md](CONTRACT.md); API changes and
opt-in adoption are documented in [ADOPTING.md](ADOPTING.md). The v0.3.1 tag identifies the reviewed SQL portability release; its transport
API retains the frozen v0.3.0 baseline. It remains a pre-1.0 public API,
with patch-line compatibility as defined in the contract. Consumers should retain
the resolved commit SHA in their dependency lockfiles.

The automated gate comprises:

- Frozen Go source/API checks, external consumer compile/runtime composition,
  Go race tests and vet on Go 1.23 and current stable.
- Frozen PHP signatures/named arguments plus the complete Pest suite on PHP
  8.3–8.5 / supported Laravel 12–13 combinations.
- Independent draft-2020-12 schema validation, shared cross-language fixtures,
  and unchanged wire-version-1 schema fingerprints (distinct from package v0.3).
- The full PHP suite on pinned libcurl 8.23 with `--fail-on-skipped`, so mandatory
  SOCKS HTTP authentication cannot be skipped into a green release.
- Direct/SOCKS TCP and UDP, HTTP/HTTPS CONNECT, DNS modes, authentication downgrade
  rejection, TLS trust/name/expiry checks, setup cancellation/deadlines, cleanup,
  binary/empty datagrams, source filtering and fragmentation/size boundaries.

Run the same checks locally using `make readiness`. It requires Composer vendor
dependencies, Go, Python with `jsonschema>=4,<5`, and Docker with the
`proxy-curl823` image built from `tests/runtime/Dockerfile.curl823`. It does not
install dependencies, change host PHP, or publish a release.
The host PHP suite may skip only the known authenticated SOCKS HTTP test on an
older cURL; the mandatory isolated run permits no skips.

Consumer policy composition tests prove that request headers, redirect rejection
and the supplied Go destination dialer remain caller-owned. They do not certify
a consuming application's policy implementation or deployed environment. Each
consumer needs separate end-to-end integration and rollout verification.

A release/handoff is ready only when the exact candidate head has all these CI
jobs green, the API/schema baselines are reviewed, and the working tree is clean.
No performance, broad external-proxy interoperability, or non-Linux platform
certification is claimed by the loopback suites. Native backend differences and
unsupported transports remain explicit in CONTRACT.md.
