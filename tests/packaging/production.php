<?php

declare(strict_types=1);

require getcwd().'/vendor/autoload.php';
if (! class_exists(Webong\Proxy\WebProxyServiceProvider::class)
    || ! class_exists(Webong\Proxy\GuzzleClientFactory::class)
    || ! class_exists(Webong\Proxy\Http\HttpProfileNormalizer::class)
    || class_exists(Orchestra\Testbench\TestCase::class)
    || Composer\InstalledVersions::isInstalled('webong/composer-source-plugin')) {
    throw new RuntimeException('Production install depends on development tooling or is missing public classes.');
}
foreach ([
    Webong\Proxy\Jobs\ForwardWebhookJob::class,
    Webong\Proxy\Jobs\ProcessWebhookJob::class,
    Webong\Proxy\Events\WebhookDeliveryFailed::class,
    Webong\Proxy\Events\WebhookDeliverySucceeded::class,
] as $class) {
    if (! class_exists($class)) {
        throw new RuntimeException('Missing runtime job/event: '.$class);
    }
}
$root = Composer\InstalledVersions::getInstallPath('webong/proxy-laravel');
foreach (['config/proxy.php', 'database/migrations', 'database/legacy-migrations'] as $path) {
    if (! file_exists($root.'/'.$path)) {
        throw new RuntimeException('Missing runtime resource: '.$path);
    }
}
echo "Production install: providers, resources and transport remain usable without dev tooling.\n";
