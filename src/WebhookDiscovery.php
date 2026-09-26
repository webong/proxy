<?php

declare(strict_types=1);

namespace Webong\WebProxy;

use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Webong\WebProxy\Attributes\WebhookRouter;
use Webong\WebProxy\Attributes\WebhookTarget;
use Webong\WebProxy\Contracts\Router;
use Webong\WebProxy\Contracts\WebhookEvent;
use Webong\WebProxy\Contracts\WebhookJob;
use Webong\WebProxy\Enums\WebhookProxyTargetType;

final class WebhookDiscovery
{
    /** @return array{routers:int,targets:int,output:string} */
    public function discover(?string $output = null): array
    {
        $path = base_path();
        if (! is_dir($path)) {
            throw new RuntimeException("Webhook target discovery root [{$path}] does not exist.");
        }

        $configured = config('proxy.targets', []);
        $configured = is_array($configured) ? $configured : [];
        $targets = [
            'event' => is_array($configured['event'] ?? null) ? $configured['event'] : [],
            'job' => is_array($configured['job'] ?? null) ? $configured['job'] : [],
        ];
        $routers = config('proxy.routers', []);
        $routers = is_array($routers) ? $routers : [];

        foreach ($this->phpFiles($path) as $file) {
            $class = $this->classFromFile($file);
            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            foreach ($reflection->getAttributes() as $attribute) {
                if (! is_a($attribute->getName(), WebhookTarget::class, true)) {
                    continue;
                }
                $target = $attribute->newInstance();
                $type = $target->type ?? match (true) {
                    is_a($class, WebhookJob::class, true) => WebhookProxyTargetType::JOB,
                    is_a($class, WebhookEvent::class, true) => WebhookProxyTargetType::EVENT,
                    default => null,
                };
                $valid = match ($type) {
                    WebhookProxyTargetType::JOB => is_a($class, WebhookJob::class, true),
                    WebhookProxyTargetType::EVENT => is_a($class, WebhookEvent::class, true),
                    WebhookProxyTargetType::REQUEST, null => false,
                };
                if (! $valid) {
                    throw new RuntimeException("Webhook target [{$class}] must implement a WebhookJob or WebhookEvent contract.");
                }
                $key = $type->value;
                if (isset($targets[$key][$target->key]) && $targets[$key][$target->key] !== $class) {
                    throw new RuntimeException("Webhook target key [{$target->key}] is declared by multiple classes.");
                }
                $targets[$key][$target->key] = $class;
            }

            foreach ($reflection->getAttributes() as $attribute) {
                if (! is_a($attribute->getName(), WebhookRouter::class, true)) {
                    continue;
                }
                $router = $attribute->newInstance();
                if (! is_a($class, Router::class, true)) {
                    throw new RuntimeException("Webhook router [{$class}] must implement the Router contract.");
                }
                if (isset($routers[$router->client]) && $routers[$router->client] !== $class) {
                    throw new RuntimeException("Webhook router client [{$router->client}] is declared by multiple classes.");
                }
                $routers[$router->client] = $class;
            }
        }

        foreach ($targets as &$typeTargets) {
            ksort($typeTargets);
        }
        unset($typeTargets);
        ksort($targets);
        ksort($routers);
        $output ??= base_path('bootstrap/cache/proxy.php');
        $directory = dirname($output);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create webhook discovery cache directory [{$directory}].");
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'routers' => [\n";
        foreach ($routers as $client => $router) {
            $contents .= "        ".var_export($client, true).' => '.$router."::class,\n";
        }
        $contents .= "    ],\n    'targets' => [\n";
        foreach ($targets as $type => $typeTargets) {
            $contents .= "        ".var_export($type, true)." => [\n";
            foreach ($typeTargets as $key => $class) {
                $contents .= "            ".var_export($key, true).' => '.$class."::class,\n";
            }
            $contents .= "        ],\n";
        }
        file_put_contents($output, $contents."    ],\n];\n");

        return ['routers' => count($routers), 'targets' => array_sum(array_map('count', $targets)), 'output' => $output];
    }

    /** @return iterable<string> */
    private function phpFiles(string $path): iterable
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = mb_ltrim(mb_substr($file->getPathname(), mb_strlen(base_path())), DIRECTORY_SEPARATOR);
            if ($this->isExcludedPath($relative)) {
                continue;
            }
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    private function classFromFile(string $file): ?string
    {
        $source = file_get_contents($file);
        if (! is_string($source)) {
            return null;
        }
        preg_match('/namespace\s+([^;]+);/i', $source, $namespaceMatch);
        preg_match('/(?:final\s+|abstract\s+)?class\s+(\w+)/i', $source, $classMatch);
        if (! isset($classMatch[1])) {
            return null;
        }
        $namespace = isset($namespaceMatch[1]) ? mb_trim($namespaceMatch[1]) : '';
        return $namespace === '' ? $classMatch[1] : $namespace.'\\'.$classMatch[1];
    }

    private function isExcludedPath(string $relativePath): bool
    {
        foreach (['vendor/', 'node_modules/', 'storage/', 'bootstrap/cache/', 'config/', 'database/', 'routes/', 'tests/'] as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                return true;
            }
        }
        return basename($relativePath) === '_ide_helper.php';
    }
}
