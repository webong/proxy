<?php

declare(strict_types=1);

namespace Webong\Proxy\Http;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlMultiHandler;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;

final class GuzzleOptions
{
    /**
     * Build a handler stack that selects proxy URLs before the terminal handler.
     * This avoids differing bypass semantics across supported Guzzle versions.
     * Pass a custom terminal handler when needed, rather than replacing the stack.
     *
     * @return array<string, mixed>
     */
    public static function fromProfile(HttpProfile $profile, ?callable $handler = null): array
    {
        $data = $profile->toArray();
        $hasSocks = false;
        $authenticatedSocks = false;
        foreach ($data['proxy'] ?? [] as $scheme => $value) {
            if ($scheme !== 'no_proxy' && str_starts_with($value, 'socks5')) {
                $hasSocks = true;
                $authenticatedSocks = $authenticatedSocks || isset(parse_url($value)['user']);
            }
        }
        if ($authenticatedSocks && (! extension_loaded('curl')
            || ! defined('CURLOPT_SOCKS5_AUTH') || ! defined('CURLAUTH_ONLY')
            || version_compare(curl_version()['version'], '8.23.0', '<'))) {
            throw new InvalidArgumentException('Authenticated portable SOCKS HTTP requires libcurl 8.23 or newer to prevent authentication downgrade.');
        }
        if ($hasSocks && $handler === null) {
            if (! extension_loaded('curl') || ! defined('CURLPROXY_SOCKS5_HOSTNAME')) {
                throw new InvalidArgumentException('Portable SOCKS HTTP clients require ext-curl.');
            }
            $handler = new CurlMultiHandler();
        }
        $stack = HandlerStack::create($handler);
        $stack->push(static fn (callable $next): callable => static function (RequestInterface $request, array $options) use ($next, $profile) {
            $uri = $request->getUri();
            // Empty string explicitly disables environment-derived cURL proxies.
            $options['proxy'] = $profile->proxyFor($uri->getScheme(), $uri->getHost()) ?? '';
            if (str_starts_with($options['proxy'], 'socks5') && defined('CURLOPT_SOCKS5_AUTH')) {
                $authenticated = isset(parse_url($options['proxy'])['user']);
                $options['curl'][CURLOPT_SOCKS5_AUTH] = $authenticated ? CURLAUTH_BASIC | CURLAUTH_ONLY : CURLAUTH_NONE;
            }
            if (defined('CURLOPT_NOPROXY')) {
                $options['curl'][CURLOPT_NOPROXY] = '';
            }

            return $next($request, $options);
        }, 'proxy_profile');

        $options = ['proxy' => [], 'handler' => $stack];
        foreach (['connect_timeout_seconds' => 'connect_timeout', 'request_timeout_seconds' => 'timeout'] as $source => $target) {
            if (isset($data[$source])) {
                $seconds = $data[$source];
                $rounded = ceil($seconds * 1000) / 1000;
                $options[$target] = $rounded == $seconds ? $seconds : $rounded;
            }
        }

        return $options;
    }
}
