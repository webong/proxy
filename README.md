# Proxy

`webong/proxy` is a Laravel package for registry-backed webhook endpoints and
outbound HTTP client profiles. An endpoint is a durable network identity: it
can receive and route webhooks through subscriptions, and it can own named
outbound profiles for clients such as Guzzle.

## Install

```bash
composer require webong/proxy
```

Laravel discovers `Webong\Proxy\WebProxyServiceProvider` automatically.
Publish the package configuration and normal migrations, then migrate:

```bash
php artisan vendor:publish --tag=proxy-config
php artisan vendor:publish --tag=proxy-migrations
php artisan migrate
```

The normal migration set creates:

- `endpoints`
- `subscriptions`
- `endpoint_registrations`
- `profiles`

All table names are configurable in `config/proxy.php` or through the matching
`PROXY_*_TABLE` environment variables.

## Configuration

The package config is `config/proxy.php` and is read through the `proxy.*`
namespace. Common environment variables are:

```dotenv
PROXY_URL=https://proxy.example.test
PROXY_SECRET=replace-with-an-internal-signing-secret
PROXY_CHANNEL=default
PROXY_REGISTRY=local
PROXY_ENDPOINTS_TABLE=endpoints
PROXY_SUBSCRIPTIONS_TABLE=subscriptions
PROXY_ENDPOINT_REGISTRATIONS_TABLE=endpoint_registrations
PROXY_PROFILES_TABLE=profiles
```

The built-in `local` registry uses the database driver and provider. Custom
registries can supply their own endpoint driver and provider through
`proxy.registries` and `proxy.providers`.

## Endpoints

Create or retrieve an endpoint through `EndpointRegistry`. The endpoint key is
the stable public identity, while `client` selects the webhook router that
handles incoming work.

```php
use Webong\Proxy\EndpointDefinition;
use Webong\Proxy\EndpointRegistry;

$endpoint = app(EndpointRegistry::class)->ensure(new EndpointDefinition(
    client: 'partner-webhooks',
    externalId: 'account-123',
    signingSecret: $partnerSigningSecret,
    verificationToken: $partnerVerificationToken,
    endpointKey: 'partner-account-123',
    credentialOwnerId: 'account-123',
));
```

An endpoint is not limited to webhook subscriptions. It is the registry-owned
parent for all endpoint attachments, including outbound profiles.

## Webhook subscriptions

Attach destinations when an inbound webhook route should dispatch to an HTTP
request, event, or job:

```php
use Webong\Proxy\DestinationDefinition;
use Webong\Proxy\Enums\WebhookProxyTargetType;

$endpoint->attach(new DestinationDefinition(
    ownerId: 'account-123',
    registrationId: 'partner-connection',
    webhookGroup: 'partner',
    routingScope: 'account',
    routingKey: 'account-123',
    target: 'https://receiver.example.test/webhooks/partner',
    targetType: WebhookProxyTargetType::REQUEST,
));
```

The package uses the configured channel and registry to resolve the endpoint,
match an incoming route, and dispatch active subscriptions. Delivery options
such as queue name, retries, backoff, idempotency TTL, and failure logging are
configured under `proxy.*`.

## Registry-backed Guzzle profiles

Profiles belong to an endpoint and are persisted by that endpoint's registry.
They are not global configuration entries. This lets each endpoint have its
own egress identity and keeps profile credentials in the registry store.

```php
use Webong\Proxy\GuzzleClientFactory;
use Webong\Proxy\ProfileDefinition;

$endpoint->registerProfile(new ProfileDefinition(
    name: 'partner-api',
    configuration: [
        'proxy' => [
            'https' => 'http://proxy-user:proxy-password@proxy.example.test:8080',
            'no' => ['.internal.example.test'],
        ],
        'options' => [
            'connect_timeout' => 5,
            'timeout' => 15,
        ],
    ],
));

$client = app(GuzzleClientFactory::class)->make($endpoint, 'partner-api');
$response = $client->get('https://api.partner.example.test/v1/status');
```

`GuzzleClientFactory` accepts Guzzle's usual `proxy` string or per-scheme map,
including the `no` bypass list. Other Guzzle options belong under `options`.
Profile configuration is encrypted at rest by the database provider.

## Migrating from `webong/web-proxy`

New applications should use only the normal migration tag. Existing
installations that already ran the historical prefixed migrations need one
explicit compatibility step before they migrate the renamed package:

```bash
php artisan vendor:publish --tag=proxy-legacy-migrations
php artisan vendor:publish --tag=proxy-migrations
php artisan migrate
```

The opt-in legacy migration renames the historical prefixed tables and updates
Laravel's `migrations` table to the new migration names. It is intentionally
not included in `proxy-migrations`.

## Testing

```bash
composer test
```

## Portable HTTP profiles (PHP and Go)

Proxy now includes a versioned HTTP connection profile shared by both libraries.
See [the profile contract](spec/README.md) for supported settings and validation.
The existing Laravel registry and webhook APIs remain in their current locations.
Existing `GuzzleClientFactory::make` and `options` preserve Guzzle-only behavior.
Use `$factory->profile($endpoint, 'partner-api')` to explicitly normalize an
endpoint profile for portable export; unsupported portable settings are rejected.
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

The Go module is `github.com/webong/proxy`:

```go
import (
    "github.com/webong/proxy/httpprofile"
    "github.com/webong/proxy/httpclient"
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
redirect and destination authorization policies. No queue, registry persistence,
or Gateway dependency is included in the Go library.

Run the Go checks with `go test -race ./...` and `go vet ./...`.

## TCP dialers and SOCKS5

These transport APIs are experimental on this branch.

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
import "github.com/webong/proxy/tcpdial"

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
[TCP contract](spec/tcp.md) for setup timeouts, PHP blocking DNS limitations,
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
import "github.com/webong/proxy/udpdial"

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
The [UDP contract](spec/udp.md) explains DNS modes, IPv6 bindings, payload limits,
control-connection lifetime, source filtering, and unsupported SOCKS fragmentation.
Gateway does not consume these experimental libraries yet.
