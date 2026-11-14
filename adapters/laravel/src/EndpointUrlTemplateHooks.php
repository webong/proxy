<?php

declare(strict_types=1);

namespace Webong\Proxy;

final class EndpointUrlTemplateHooks
{
    /**
     * @var list<callable(string, string): string>
     */
    private array $resolvers = [];

    /**
     * @param callable(string, string): string $resolver
     */
    public function register(callable $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    public function resolve(string $template, string $type): string
    {
        foreach ($this->resolvers as $resolver) {
            $template = $resolver($template, $type);
        }

        return '/'.mb_rtrim(mb_trim($template, '/'), '/');
    }
}
