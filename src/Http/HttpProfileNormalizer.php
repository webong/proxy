<?php

declare(strict_types=1);

namespace Webong\Proxy\Http;

use InvalidArgumentException;

final class HttpProfileNormalizer
{
    /** Normalize the existing endpoint configuration; Guzzle-only options stay outside the portable profile. */
    public function normalize(array $configuration): HttpProfile
    {
        $options = $configuration['options'] ?? [];
        if (! is_array($options)) {
            throw new InvalidArgumentException('Profile options must be an array.');
        }
        $data = ['version' => 1];
        foreach (['connect_timeout' => 'connect_timeout_seconds', 'timeout' => 'request_timeout_seconds'] as $source => $target) {
            if (array_key_exists($source, $options)) {
                $data[$target] = $options[$source];
            }
        }
        $proxy = $configuration['proxy'] ?? ($options['proxy'] ?? null);
        if ($proxy !== null) {
            if (is_string($proxy)) {
                $proxy = ['http' => $proxy, 'https' => $proxy];
            }
            if (! is_array($proxy) || array_diff(array_keys($proxy), ['http', 'https', 'no'])) {
                throw new InvalidArgumentException('Profile proxy must be a non-empty HTTP proxy string or map.');
            }
            if (array_key_exists('no', $proxy)) {
                $proxy['no_proxy'] = $proxy['no'];
                unset($proxy['no']);
            }
            $data['proxy'] = $proxy;
        }
        return HttpProfile::fromArray($data);
    }
}
