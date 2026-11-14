<?php

declare(strict_types=1);

namespace Webong\Proxy;

final class EndpointKeyResolver
{
    private const HASH_LENGTH = 16;

    private const MAX_LENGTH = 64;

    public function resolve(EndpointDefinition $definition): EndpointDefinition
    {
        if ($definition->managed) {
            return $definition;
        }

        $base = mb_trim(
            $definition->endpointKey ?: $definition->client,
            " \t\n\r\0\x0B/\\\\",
        );
        $hash = mb_substr(hash('sha256', $definition->externalId), 0, self::HASH_LENGTH);
        $suffix = "-{$hash}";

        if (str_ends_with($base, $suffix)) {
            return $definition;
        }

        $base = mb_substr($base, 0, self::MAX_LENGTH - mb_strlen($suffix));

        return $definition->withEndpointKey($base.$suffix);
    }
}
