<?php

declare(strict_types=1);

namespace Webong\Proxy\Contracts;

use Closure;

interface ExecutionContext
{
    /** @return array<string, mixed> */
    public function capture(): array;

    /**
     * @template TResult
     *
     * @param Closure(): TResult $closure
     * @param array<string, mixed> $context
     * @return TResult
     */
    public function run(Closure $closure, array $context = []): mixed;
}
