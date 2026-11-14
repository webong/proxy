# Proxy

Framework-neutral PHP transport tools and storage-neutral registry contracts,
with matching Go transport libraries. The PHP core requires PHP 8.3+ and Guzzle;
it does not install Laravel, Illuminate, Spatie, a service provider or migrations.

## Install

```sh
composer require webong/proxy:^0.4
```

Version 0.4.0 is an unreleased candidate. Fresh archive tests verify this layout;
public installation requires publishing the release first.

For Laravel persistence and Spatie webhook integration, install
`webong/proxy-laravel:^0.4` instead. That package requires this core and owns
Eloquent models, encryption, providers, configuration and migrations. See the
[Laravel integration guide](https://github.com/webong/proxy/blob/main/adapters/laravel/README.md).

## Registry contracts

The core includes endpoint, destination and profile records/definitions and
`Webong\Proxy\Registry\Contracts\EndpointRepository`, `DestinationRepository`
and `ProfileRepository`. Applications implement these ports using their own
storage, schema and transaction strategy. No production store is bundled in the
core. [Registry semantics](https://github.com/webong/proxy/blob/main/spec/REGISTRY.md) document identity and atomicity rules.
The Laravel store is the first adapter; its classes retain their existing
`Webong\Proxy` namespaces when the adapter is installed.

## Portable HTTP profiles (PHP and Go)

Proxy now includes a versioned HTTP connection profile shared by both libraries.
See [the profile contract](https://github.com/webong/proxy/blob/main/spec/README.md) for supported settings and validation.
Laravel registry/webhook APIs and the stored-profile `GuzzleClientFactory` live
in `webong/proxy-laravel`, retaining their existing namespaces. Core normalization
extracts portable connection settings; headers, TLS options and other Guzzle-only
fields are not exported.
The PHP classes under `Webong\Proxy\Http` do not require a Laravel application.

```php
use Webong\Proxy\Http\HttpProfileNormalizer;
use Webong\Proxy\Http\GuzzleOptions;

$profile = (new HttpProfileNormalizer())->normalize([
    'proxy' => ['https' => 'http://proxy.example.test:8080'],
    'options' => ['connect_timeout' => 5, 'timeout' => 15],
]);
$json = $profile->toJson();
$client = new \GuzzleHttp\Client(GuzzleOptions::fromProfile($profile));
```

The Go module is `github.com/webong/proxy/go`:

```go
import (
    "github.com/webong/proxy/go/httpprofile"
    "github.com/webong/proxy/go/httpclient"
)

profile, err := httpprofile.Parse(profileJSON)
if err != nil { return err }
client, err := httpclient.New(profile, nil)
if err != nil { return err }
defer client.CloseIdleConnections()
// Reuse client for requests made with this profile.
```

`httpclient.New` clones an optional base `*http.Transport`, preserving its TLS
settings and dialer. Connect deadlines wrap supplied context-aware dialers, including TLS dialers.
The adapters round positive deadlines up to milliseconds. Custom Go dialers
must honor their context; legacy `Dial` and `DialTLS` are rejected with a connect timeout. With a nil base,
it uses package-owned standard transport defaults, independent of later changes
to `http.DefaultTransport`. Missing timeouts use the underlying
transport defaults and no overall request deadline. Caller-supplied clients own
redirect and destination authorization policies. No queue or registry persistence is included in the Go library.

Run Go checks from the repository root with `go -C go test -race ./...` and
`go -C go vet ./...`. Go module release tags use `go/v0.4.0`.

## TCP dialers and SOCKS5

The v0.3.0 transport contract is frozen for its patch line. See [the public
contract](https://github.com/webong/proxy/blob/main/spec/CONTRACT.md), [adoption guide](https://github.com/webong/proxy/blob/main/spec/ADOPTING.md), and
[release notes](CHANGELOG.md). Package v0.3.0 retains wire `version: 1`;
it does not declare a package v1 API.

Both libraries now provide direct TCP, SOCKS5 CONNECT, and HTTP/HTTPS CONNECT dialers.
For HTTP CONNECT use `http://user:password@proxy.example:8080` in a TCP profile;
credentials are optional, Basic authentication is supported, and the proxy resolves
the target hostname.

Use `https://` to secure the proxy hop (default port 443); certificate and
hostname verification are always enabled. Private CAs use Go's
`tcpdial.WithProxyRootCAs(roots)` or PHP's `proxyCaFile` constructor argument.
Go supports nesting target TLS inside this tunnel; the PHP native stream API
provides the proxy TLS layer only. See the TCP contract for that limitation.

`socks5://` resolves destination names locally; `socks5h://` resolves them at the proxy.
Both support IPv4, IPv6, and optional username/password authentication.

```php
use Webong\Proxy\Tcp\TcpProfile;
use Webong\Proxy\Tcp\TcpDialer;

$profile = TcpProfile::fromArray([
    'version' => 1,
    'proxy' => 'socks5h://proxy.example.test:1080',
    'connect_timeout_seconds' => 5,
]);
$stream = (new TcpDialer($profile))->dial('service.example.test', 9000);
try {
    // Set application I/O timeouts and perform the application protocol here.
} finally {
    fclose($stream);
}
```

```go
import "github.com/webong/proxy/go/tcpdial"

profile := tcpdial.Profile{
    Version: 1,
    Proxy: "socks5h://proxy.example.test:1080",
    ConnectTimeoutSeconds: 5,
}
dialer, err := tcpdial.New(profile, nil, nil)
if err != nil { return err }
conn, err := dialer.DialContext(ctx, "tcp", "service.example.test:9000")
if err != nil { return err }
defer conn.Close()
// Set application deadlines and perform the application protocol here.
```

Go callers that previously passed `socketprofile.Profile` into `tcpdial.New`
should convert with `tcpdial.Profile(sharedProfile)`, or construct a
`tcpdial.Profile` directly. TCP profiles now validate HTTP CONNECT independently
of the shared SOCKS-only UDP profile.

The HTTP profile adapters also accept SOCKS proxy URLs. PHP SOCKS HTTP requires
ext-curl; mandatory authenticated SOCKS HTTP additionally requires libcurl 8.23+.
The raw PHP TCP dialer supports authentication without cURL. Read the
[TCP contract](https://github.com/webong/proxy/blob/main/spec/tcp.md) for setup timeouts, PHP blocking DNS limitations,
custom connectors/resolvers, TLS ownership, and protocol boundaries.

## UDP and SOCKS5 UDP ASSOCIATE

Both libraries provide direct UDP sockets and owning SOCKS5 UDP associations.
They preserve datagram boundaries, binary/empty payloads, and source addresses.
The TCP control connection remains open until the association closes.

```php
use Webong\Proxy\Udp\UdpProfile;
use Webong\Proxy\Udp\UdpDialer;

$profile = UdpProfile::fromArray([
    'version' => 1,
    'proxy' => 'socks5h://proxy.example.test:1080',
]);
$udp = (new UdpDialer($profile))->open();
try {
    $udp->sendTo('ping', 'service.example.test', 9000);
    $reply = $udp->receive(5); // payload, host, port
} finally {
    $udp->close();
}
```

```go
import "github.com/webong/proxy/go/udpdial"

profile := udpdial.Profile{Version: 1, Proxy: "socks5h://proxy.example.test:1080"}
dialer, err := udpdial.New(profile, nil, nil)
if err != nil { return err }
udp, err := dialer.Open(ctx, "")
if err != nil { return err }
defer udp.Close()
udp.SetDeadline(time.Now().Add(5 * time.Second))
_, err = udp.WriteTo([]byte("ping"), udpdial.Address{Host: "service.example.test", Port: 9000})
if err != nil { return err }
buffer := make([]byte, udpdial.MaxWireSize)
n, source, err := udp.ReadFrom(buffer)
// Handle err, buffer[:n], and source here.
```

Omit `proxy` for direct UDP. Raw UDP authentication needs no cURL extension.
The [UDP contract](https://github.com/webong/proxy/blob/main/spec/udp.md) explains DNS modes, IPv6 bindings, payload limits,
control-connection lifetime, source filtering, and unsupported SOCKS fragmentation.

## Development and upgrade

This `php/` directory is the `webong/proxy` Composer package. The repository root
is a development workspace linking both `php` and `adapters/laravel`. Ordinary
consumers require no Composer plugin. Run bootstrap and tests from the repository
root; see its README and the packaging guide.

PHP namespaces remain unchanged. Go now uses `github.com/webong/proxy/go`; its
import paths and release tags change while transport APIs and JSON version 1
remain compatible in shape.
Existing Laravel consumers must replace their old direct `webong/proxy:^0.3`
requirement with `webong/proxy-laravel:^0.4` (or update both constraints if retaining
a direct core requirement). The package split does not convert registry data.
The Laravel adapter still requires the subscription-lock migration introduced by
the concurrency fix; publish/run it before using the new adapter.
See [Composer packaging and release order](https://github.com/webong/proxy/blob/main/spec/COMPOSER.md),
[public contracts](https://github.com/webong/proxy/blob/main/spec/CONTRACT.md) and [SQL upgrades](https://github.com/webong/proxy/blob/main/spec/SQL.md).
