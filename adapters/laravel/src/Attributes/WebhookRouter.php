<?php

declare(strict_types=1);

namespace Webong\Proxy\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class WebhookRouter
{
    public function __construct(
        public readonly string $client,
    ) {
    }
}
