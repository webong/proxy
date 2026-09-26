<?php

declare(strict_types=1);

namespace Webong\WebProxy;

use InvalidArgumentException;
use Webong\WebProxy\Enums\WebhookProxyTargetType;

final class WebhookTargetRegistry
{
    /**
     * @return class-string
     */
    public function resolve(WebhookProxyTargetType $type, string $key): string
    {
        $target = $this->all()[$type->value][$key] ?? null;

        if (! is_string($target) || $target === '') {
            throw new InvalidArgumentException("Webhook {$type->value} target [{$key}] is not registered.");
        }

        return $target;
    }

    /**
     * @return array{event: array<string, class-string>, job: array<string, class-string>}
     */
    public function all(): array
    {
        $targets = config('proxy.targets', []);
        $compiled = $this->compiledTargets();

        $targets = is_array($targets) ? $targets : [];

        return [
            'event' => array_merge($targets['event'] ?? [], $compiled['event'] ?? []),
            'job' => array_merge($targets['job'] ?? [], $compiled['job'] ?? []),
        ];
    }

    /**
     * @return array{event?: array<string, class-string>, job?: array<string, class-string>}
     */
    private function compiledTargets(): array
    {
        $path = base_path('bootstrap/cache/proxy.php');

        if (! is_file($path)) {
            return [];
        }

        $targets = require $path;

        if (! is_array($targets)) {
            return [];
        }

        $compiledTargets = $targets['targets'] ?? $targets;

        return is_array($compiledTargets) ? $compiledTargets : [];
    }
}
