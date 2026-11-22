<?php

declare(strict_types=1);

namespace Webong\Proxy\Attributes;

use Attribute;
use Webong\Proxy\Enums\DestinationTargetType;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class WebhookTarget
{
    public function __construct(
        public readonly string $key,
        public readonly ?DestinationTargetType $type = null,
    )
    {
    }
}
