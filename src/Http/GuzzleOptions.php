<?php

declare(strict_types=1);

namespace Webong\Proxy\Http;

use GuzzleHttp\HandlerStack;
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
        $stack = HandlerStack::create($handler);
        $stack->push(static fn (callable $next): callable => static function (RequestInterface $request, array $options) use ($next, $profile) {
            $uri = $request->getUri();
            // Empty string explicitly disables environment-derived cURL proxies.
            $options['proxy'] = $profile->proxyFor($uri->getScheme(), $uri->getHost()) ?? '';
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
