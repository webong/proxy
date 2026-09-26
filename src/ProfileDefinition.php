<?php

declare(strict_types=1);

namespace Webong\WebProxy;

use InvalidArgumentException;

final readonly class ProfileDefinition
{
    /** @param array<string, mixed> $configuration */
    public function __construct(
        public string $name,
        public array $configuration,
        public string $driver = 'guzzle',
    ) {
        if ($this->name === '' || $this->driver === '') {
            throw new InvalidArgumentException('Proxy profile names and drivers must be non-empty strings.');
        }
    }
}
