# TCP and SOCKS5 profiles

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
SOCKS ports default to 1080. HTTP CONNECT, SOCKS BIND, and UDP ASSOCIATE are not
implemented by these raw TCP dialers. Only Go's `tcp` network is accepted;
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
