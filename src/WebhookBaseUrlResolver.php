<?php

declare(strict_types=1);

namespace Webong\WebProxy;

final readonly class WebhookBaseUrlResolver
{
    public function resolve(mixed ...$candidates): string
    {
        foreach ([
            ...$candidates,
            config('proxy.base_url'),
        ] as $candidate) {
            if (is_string($candidate) && mb_trim($candidate) !== '') {
                return mb_rtrim($candidate, '/');
            }
        }

        return '';
    }
}
