<?php

declare(strict_types=1);

namespace Webong\Proxy\Contracts;

use Webong\Proxy\EndpointDefinition;
use Webong\Proxy\ProvisionedEndpoint;

interface EndpointDriver
{
    public function provision(EndpointDefinition $definition): ProvisionedEndpoint;

    public static function getIdentifier(): string;

    public function supports(string $driver): bool;
}
