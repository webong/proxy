# TCP, SOCKS5, and HTTP CONNECT profiles

PHP `Webong\Proxy\Tcp\TcpProfile` and Go `tcpdial.Profile` share
`tcp-profile.schema.json`. The exact fields are:

```json
{
  "version": 1,
  "proxy": "socks5h://user:p%40ss@proxy.example:1080",
  "connect_timeout_seconds": 10
}
```

Omit `proxy` for direct TCP. A proxy uses `socks5` (resolve the destination
locally) or `socks5h` (send its hostname to the proxy). Literal IP addresses never
require DNS. The proxy hop itself is resolved locally in both modes. Missing
SOCKS ports default to 1080. HTTP CONNECT is supported with `http://` (port 80) and
`https://` (port 443) proxy URLs; SOCKS BIND remains unsupported.
[UDP ASSOCIATE](udp.md) is provided by separate owning UDP associations. Only Go's `tcp` network is accepted;
service names and scoped IPv6 addresses are outside the destination contract.
PHP takes an unbracketed IP/hostname and an integer port; Go takes host:port
with normal brackets around IPv6 literals. Ports must be 1–65535.

Username/password credentials are percent-decoded before framing, and each must
contain 1–255 bytes. Both must be supplied together. A configured credential pair
requires username/password authentication: server selection of no authentication
is rejected. Without credentials, only no-authentication is offered. Credentials
are sent over the proxy connection without encryption; transport protection for
that hop belongs to the deployment. The protocol follows
[RFC 1928](https://www.rfc-editor.org/rfc/rfc1928) and
[RFC 1929](https://www.rfc-editor.org/rfc/rfc1929).

The default setup timeout is 10 seconds, including local target resolution,
connecting to the proxy, and SOCKS negotiation. Explicit timeouts are positive,
finite, at most 300 seconds, and rounded upward to milliseconds. Setup failure
closes the connection. Successful connections do not retain setup deadlines.
Callers own subsequent read/write timeouts, TLS, cancellation, and closing the
connection. IPv4-mapped IPv6 destinations use IPv4 SOCKS address framing.
Local SOCKS resolution selects the first valid resolved address; it does not
retry different destinations automatically.

Go supports caller cancellation during setup and injects a `ContextDialer` and
`Resolver` when supplied. Those dependencies must honor their context. With
SOCKS, the base dialer sees the proxy address, not the ultimate destination.

PHP returns a blocking stream and supports optional connector and resolver
callbacks. The connector receives host, port, and remaining setup seconds; the
resolver receives hostname and remaining seconds and returns IP address strings.
Blocking PHP system DNS calls and user callbacks cannot be interrupted mid-call;
overbudget results fail after return. Use an appropriately bounded custom
connector/resolver, or numeric hop/destination addresses, when this matters.
PHP's default stream I/O timeout still applies after setup unless the caller
changes it. PHP does not provide Go-style context cancellation.

## Raw HTTP CONNECT

Use `http://proxy.example:8080` or `https://proxy.example:8443` in a TCP profile to open an HTTP/1.1 CONNECT
tunnel. The destination authority (hostname or bracketed IPv6 plus numeric port)
is sent to the proxy; target DNS is performed by that proxy, without invoking the
local destination resolver. The connector sees the proxy hop. UDP profiles still
accept only SOCKS proxies; TCP and UDP now have separate fixture directories.
Go `tcpdial.Profile` has the same fields as `socketprofile.Profile` but is a
separate type with TCP-specific parsing and validation. Code passing a shared
profile to `tcpdial.New` must now explicitly convert it:

```go
shared := socketprofile.Profile{Version: 1}
dialer, err := tcpdial.New(tcpdial.Profile(shared), nil, nil)
```

Use `tcpdial.Profile` / `tcpdial.Parse` directly for new TCP code. Converting a
TCP profile back to a shared/UDP profile does not permit HTTP proxies there;
shared and UDP validation remain SOCKS-only.

Optional URL credentials are percent-decoded and sent preemptively as Basic
`Proxy-Authorization`. Supply both username and password; the username must
contain 1–255 bytes, the password 0–255 bytes. Username colons and control bytes
(0x00–0x1F and 0x7F in either field) are rejected. Password colons are allowed.
No authentication challenge retries, Digest, NTLM, Negotiate, redirects, or
fallback to direct traffic are implemented. A 407 fails setup.

HTTP/1.0 and HTTP/1.1 responses are accepted. Any 2xx establishes the tunnel;
Content-Length and Transfer-Encoding on that response do not consume tunnel
bytes, following [RFC9110 section 9.3.6](https://www.rfc-editor.org/rfc/rfc9110#section-9.3.6).
Up to eight informational responses are accepted, excluding 101. Response
headers across all responses are bounded to 32,768 bytes. Malformed/truncated
responses, folded fields, bare LF, invalid control bytes, and non-2xx final
statuses fail and close the connection. Go returns `*ConnectError` for rejected
statuses; malformed/truncated responses match `ErrConnectProtocol` (truncated
responses also retain `io.EOF` / `io.ErrUnexpectedEOF`). `ErrProtocol` remains
the SOCKS5 error sentinel. PHP uses the status as `DialException`'s code. Errors do not include
credentials or untrusted response text. Bytes following the successful header
terminator belong to the returned raw connection and are preserved.

The existing setup timeout covers connecting, sending CONNECT, and reading the
response; Go context cancellation closes an in-progress handshake. Successful
tunnels have setup deadlines cleared. Callers own subsequent TLS and I/O.
HTTP/2 CONNECT, extended CONNECT, and CONNECT-UDP are not supported by these
raw dialers. Plain HTTP proxy credentials
and tunneled plaintext require deployment-level protection; Basic is not encryption.
Raw HTTP CONNECT in PHP uses streams and does not require cURL.

`http-connect-cases.json` runs against independent PHP and Go loopback peers,
covering authentication, status/header validation, fragmented responses,
coalesced tunnel data, deadline clearing, and setup failures.

## HTTPS proxy TLS

`https://` establishes verified TLS to the proxy before sending CONNECT or
credentials. Both dialers require TLS 1.2 or newer, check the proxy certificate
chain, expiry and hostname/IP, and send DNS SNI for the proxy hostname. A trailing
DNS root dot is omitted for TLS identity/SNI; mapped IPv6 identities normalize
to IPv4. The destination hostname is not used for proxy certificate verification. ALPN offers HTTP/1.1 only; proxies with
no ALPN also work. No verification-disable option is provided.

System trust is used by default. In PHP this includes the configured
`openssl.cafile` / `openssl.capath` and native OpenSSL defaults. A connector or default-stream
context must not supply `ssl.cafile` or `ssl.capath` when `proxyCaFile` is
omitted; the dialer rejects that ambiguous trust configuration before TLS or
CONNECT. This includes empty/null overrides and relative paths such as `0`.
Pass the desired CA bundle through `proxyCaFile` instead.
Explicit private roots replace other trust sources: Go snapshots the supplied
pool, and PHP overrides any connector CA file and disables inherited/INI CA
directories. Private proxy CAs are runtime configuration,
outside portable JSON profiles:

```go
dialer, err := tcpdial.New(profile, nil, nil, tcpdial.WithProxyRootCAs(roots))
// roots is an *x509.CertPool; New clones it. Omit the option for system roots.
```

```php
$dialer = new TcpDialer($profile, proxyCaFile: '/etc/my-app/proxy-ca.pem');
```

PHP requires ext-openssl for HTTPS proxy hops; raw HTTP/SOCKS still needs no
cURL. A custom connector/dialer must return a plain TCP connection to the proxy;
Proxy performs the TLS handshake itself. The setup budget covers TCP, TLS, and
CONNECT as one operation. Cancellation/failure closes the raw hop without waiting
for TLS shutdown. PHP refreshes the remaining budget after every crypto step
before waiting for more TLS data. The established connection is reused for tunnel I/O, with setup
deadlines cleared. Go returns a `*tls.Conn` for HTTPS proxy tunnels; its native
TLS shutdown behavior applies when the caller closes it.

Proxy-hop TLS protects the connection to the proxy. It does not add end-to-end
TLS to the target. Go callers can wrap the tunnel with another `tls.Client`;
this nested TLS is tested. PHP returns a native TLS stream and does not provide
a second TLS layer inside it: re-enabling crypto on that stream is not a target
TLS adapter. Use the HTTP/Guzzle adapter for HTTPS requests through an HTTPS
proxy, or a stream transport that supports nested TLS for another target protocol.
This limitation does not affect binary/plain application traffic in the tunnel.

UDP and shared socket profiles remain SOCKS-only. HTTPS profiles use the same
Basic credential rules and CONNECT parsing limits as HTTP profiles. Loopback
suites test both schemes, private/system trust rejection, hostname mismatch,
expired certificates, IPv4/IPv6 hops, stalled TLS, SNI/ALPN, credential ordering,
and Go cancellation/nested TLS.

## HTTP adapters

Portable HTTP profiles also accept `socks5://` and `socks5h://` in their `http`
and `https` proxy entries. Existing HTTP bypass rules apply before SOCKS.
Go uses a separate underlying transport per request scheme whenever SOCKS is
configured, preserving local versus remote DNS and ordinary TLS verification.
The returned RoundTripper implements idle-connection cleanup; it is not always
an `*http.Transport`. Custom TLS dialers are rejected for SOCKS routes; use
`TLSClientConfig` instead, so the configured proxy cannot be bypassed. Both
HTTP adapters default SOCKS setup to 30 seconds when no connect timeout is given.

PHP's portable Guzzle adapter requires ext-curl for SOCKS and selects the cURL
multi handler. Authenticated SOCKS HTTP requires libcurl 8.23+ and
`CURLOPT_SOCKS5_AUTH` / `CURLAUTH_ONLY`, because older cURL releases can accept
unauthenticated SOCKS despite supplied credentials. Unsupported runtimes fail
before connecting. See [cURL's authentication contract](https://curl.se/libcurl/c/CURLOPT_SOCKS5_AUTH.html).
Portable HTTP proxy credentials exclude decoded C0 control bytes (0x00–0x1F),
which cURL cannot represent. This restriction applies in both languages and to
all HTTP proxy schemes; raw TCP profiles retain binary RFC1929 credentials.
The standalone PHP TCP dialer has no cURL dependency and enforces authentication
itself. Legacy Guzzle clients retain their original native behavior.

All TCP profile fixtures and `socks5-cases.json` run through both implementations.
The latter checks literal/local/remote addresses, authentication boundaries,
fragmentation, rejection, truncated responses, and setup timeout behavior.

The schema fixture check runs independently with Python's draft-2020-12
`jsonschema` validator (`python scripts/check_profile_schemas.py`). Schemas check
URL escapes, SOCKS credential presence/byte limits, ports, and HTTP credential
byte restrictions; full IP-address semantics are checked by the runtime parsers.

Mandatory PHP HTTP authentication is also verified in the isolated, pinned
[PHP/libcurl 8.23 runtime](../tests/runtime/README.md). Its CI job runs the full
suite with `--fail-on-skipped`; the downgrade test includes a deliberately
relaxed client to prove that its malicious proxy can otherwise return HTTP 204.
