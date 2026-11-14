<?php

declare(strict_types=1);

namespace Webong\Proxy\Contracts;

use Webong\Proxy\WebhookExecutionContextPayload;

/**
 * Resolves the webhook proxy context for the current execution scope on
 * demand. Integrators implement this contract using their own routing
 * primitives, keeping the web proxy package context agnostic.
 */
interface WebhookContextProvider
{
    /**
     * Resolve the webhook context payload for the active execution scope.
     */
    public function resolve(): WebhookExecutionContextPayload;
}
