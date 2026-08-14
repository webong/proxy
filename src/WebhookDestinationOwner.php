<?php

declare(strict_types=1);

namespace Webong\WebProxy;

use Illuminate\Support\Facades\Context;
use RuntimeException;

final class WebhookDestinationOwner
{
    public const string CONTEXT_KEY = 'webhook_owner_id';

    public function current(): string
    {
        $payload = Context::getHidden(WebhookContextKeys::EXECUTION_PAYLOAD->value);
        $ownerId = $payload instanceof WebhookExecutionContextPayload
            ? $payload->ownerId
            : Context::getHidden(self::CONTEXT_KEY);

        if (! is_string($ownerId) || $ownerId === '') {
            throw new RuntimeException('A destination owner context is required to register a webhook destination.');
        }

        return $ownerId;
    }
}
