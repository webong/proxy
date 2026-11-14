<?php

declare(strict_types=1);

namespace Webong\Proxy\Registry\Contracts;

use Webong\Proxy\EndpointRecord;
use Webong\Proxy\ProfileDefinition;
use Webong\Proxy\ProfileRecord;

/**
 * Storage port; implementations own persistence, transactions and schema.
 * See the package README for identity, atomicity and error semantics.
 */
interface ProfileRepository
{
    public function registerProfile(EndpointRecord $endpoint, ProfileDefinition $definition): ProfileRecord;

    public function profile(EndpointRecord $endpoint, string $name): ?ProfileRecord;
}
