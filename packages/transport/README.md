# Proxy Transport

Framework-neutral PHP connection tools from Proxy: portable HTTP profiles,
Guzzle options, direct/SOCKS5/HTTP CONNECT/HTTPS TCP dialers, and direct/SOCKS5 UDP
associations. The package has no Laravel service provider and does not require
Illuminate or Spatie packages. Public namespaces remain `Webong\Proxy\Http`,
`Connection`, `Tcp` and `Udp`.

This is an **unreleased 0.4.0 package candidate**. It must be published before a
release of the parent package can depend on it. Do not expect the following
command to resolve from Packagist until that publication is complete:

```sh
composer require webong/proxy-transport:^0.4
```

```php
use Webong\Proxy\Http\HttpProfileNormalizer;
use Webong\Proxy\Http\GuzzleOptions;

$profile = (new HttpProfileNormalizer())->normalize([
    'options' => ['timeout' => 15, 'connect_timeout' => 5],
]);
$client = new GuzzleHttp\Client(GuzzleOptions::fromProfile($profile));
```

Keep clients reusable and close TCP streams / UDP associations when finished.
The caller owns destination authorization, redirects and orchestration. Raw PHP
HTTPS-proxy streams cannot nest independent target TLS; authenticated Guzzle
SOCKS HTTP requires libcurl 8.23+. Raw TCP/UDP SOCKS needs no cURL extension.

The parent repository's `spec/CONTRACT.md`, protocol schemas and shared fixtures
remain authoritative. The JSON wire version stays 1 and the frozen v0.3 public
transport interface is preserved. Go remains `github.com/webong/proxy` and is
unaffected by Composer packaging.

Optional Composer Source Plugin consumers may select the `php` surface. It
retains this README, LICENSE, Composer metadata and every advertised PHP class.
A normal installation works without the plugin or projection configuration.
