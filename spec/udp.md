# UDP and SOCKS5 UDP ASSOCIATE

Both languages support direct UDP and SOCKS5 UDP ASSOCIATE on the experimental
branch. They share the raw socket profile with TCP: `version: 1`, optional
`socks5://` or `socks5h://` proxy URL, and optional `connect_timeout_seconds`.
See `udp-profile.schema.json` and [the raw socket profile](tcp.md) for validation,
authentication, and PHP's non-preemptible system DNS/callback constraints.
Go also exposes the common type through `socketprofile.Profile`.

## API and binding

PHP uses `UdpDialer::open(?string $bindHost = null, int $bindPort = 0)` and returns
an owning `UdpAssociation`. It exposes `sendTo(payload, host, port, timeout)`,
`receive(timeout)`, `localAddress()`, `isClosed()`, and idempotent `close()`.
A received `Datagram` contains `payload`, source `host`, and source `port`.
Each PHP send/receive has a default 10-second operation budget; explicit values
must be finite, positive, and at most 300 seconds, rounded up to milliseconds.

Go uses `udpdial.New(profile, tcpDialer, resolver)` and `Open(ctx, bindAddress)`.
It returns `*udpdial.Conn`, implementing `net.PacketConn`, with `Done()` for
closure notification. `udpdial.Address{Host, Port}` carries either a domain name
or a numeric address; `*net.UDPAddr` also works with `WriteTo`. Use
`SetReadDeadline`, `SetWriteDeadline`, or `SetDeadline` for application I/O.
Write deadlines and Close also interrupt pending context-aware DNS resolution;
clearing/extending a write deadline updates the pending operation. Setup's
context/timeout does not limit the returned socket's lifetime or later I/O.

Direct sockets default to IPv4 (`0.0.0.0:0`). Explicit IPv6 numeric bindings are
supported (`::1` in PHP, `[::1]:0` in Go). Bind names, service-name ports, and
scoped IPv6 addresses are not accepted. Direct destination names resolve locally
in the socket's family. Numeric destinations must be compatible with that family.

## Association and lifetime

SOCKS creates and authenticates a TCP control connection first, then sends
command 3 with an all-zero address/port: the datagram endpoint has not yet been
allocated. Credentials require username/password negotiation without downgrade.
The TCP connection stays open for the association lifetime.

The returned relay endpoint is validated. An unspecified numeric BND.ADDR is
replaced by the TCP peer IP; a relay domain is resolved locally in the control
connection's address family. A zero relay port, invalid address, or incompatible
relay family fails setup. UDP binds to the same local IP as the control
connection, and connects to the exact relay IP/port. The kernel therefore rejects
UDP from other peers. Explicit SOCKS binding must use the TCP connection's IP.
The relay may legitimately differ from the TCP peer; caller deployment policy
must account for that. The TCP connector hook governs the TCP hop only.

Go watches the control connection continuously and closes UDP on EOF, error,
or unexpected control data. PHP watches it before operations and alongside
blocking receive/send waits; `isClosed()` refreshes its state. PHP has no
background execution, so an otherwise idle object detects closure on the next
operation/state check. Closing an association closes both resources. Failures
and setup cancellation clean up partially opened resources. After Open succeeds,
Go's setup context may be cancelled without closing the association.

## Datagrams and DNS

`socks5` resolves each destination locally and encodes an IP address. `socks5h`
encodes domain names for proxy-side resolution. Literal IPv4/IPv6 destinations
never trigger DNS; IPv4-mapped IPv6 is encoded canonically as IPv4. One
association can send to multiple destinations. Received packets retain the
source address supplied by the relay without another DNS lookup.

Payloads are binary-safe, including zero-length datagrams. Direct traffic has no
SOCKS header; proxied traffic uses RSV=0, FRAG=0 and a typed destination address.
Malformed packets and nonzero FRAG packets are dropped without resetting the
operation deadline. SOCKS fragmentation/reassembly is not implemented, as
permitted by [RFC1928](https://www.rfc-editor.org/rfc/rfc1928#section-7).

The conservative wire ceiling is 65,507 bytes for both families, including any
SOCKS header. Go `MaxPayloadSize(address)` and PHP
`Socks5Datagram::maxPayloadSize(host, port)` report application capacity for an
encoded destination. IPv4 headers cost 10 bytes, IPv6 22, and domains 7 plus the
hostname byte length. Local resolution may change the actual encoded header.
Oversized sends fail before transmission. Network MTU and OS limits can still
reject smaller packets; UDP does not guarantee delivery, ordering, or replies.

PHP receives a complete datagram object. Go reads complete wire packets internally
and reports `io.ErrShortBuffer` with copied bytes/source when the caller buffer
is too small; the remainder of that datagram is consumed. Raw datagram framing
has no encryption; application payload protection remains the caller's job.

`udp-datagrams.json` is executed by both codec suites, along with independent
loopback relay fixtures testing authentication, IPv4/IPv6, empty/binary payloads,
source filtering, malformed/fragmented packets, timeouts, and TCP lifetime.
