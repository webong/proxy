<?php

declare(strict_types=1);

namespace Webong\Proxy;

use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use Spatie\WebhookClient\WebhookConfig;

class WebhookProxySignatureValidator implements SignatureValidator
{
    public function __construct(
        private readonly WebProxyEndpointResolver $endpointResolver,
        private readonly EndpointWebhookConfigResolver $configResolver,
    ) {
    }

    public function isValid(Request $request, WebhookConfig $config): bool
    {
        $endpoint = $this->endpointResolver->resolve($request, $config->name);
        $endpointConfig = $this->configResolver->resolve($endpoint);

        if ($endpointConfig->signatureValidator instanceof self) {
            throw new \LogicException('A WebProxy client cannot use the proxy signature validator.');
        }

        $isValid = $endpointConfig->signatureValidator->isValid($request, $endpointConfig);

        if ($isValid) {
            $request->attributes->set(EndpointWebhookConfig::REQUEST_ATTRIBUTE, $endpointConfig);
            $request->headers->set(Headers::ENDPOINT, $endpoint->id);
        }

        return $isValid;
    }
}
