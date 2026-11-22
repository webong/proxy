<?php

declare(strict_types=1);

namespace Webong\Proxy\Laravel;

use Webong\Proxy\LaravelRegistryStore;

/**
 * @internal Container fallback, distinguished from application repository overrides.
 * The legacy provider rebuilds this default from its own constructor dependencies
 * so makeWith, contextual bindings and inherited constructors retain their meaning.
 */
final class DefaultRegistryStore extends LaravelRegistryStore
{
}
