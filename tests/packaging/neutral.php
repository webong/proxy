<?php

declare(strict_types=1);

require __DIR__.'/transport.php';
require __DIR__.'/registry.php';

if (class_exists(Webong\Proxy\WebProxyServiceProvider::class)
    || class_exists(Webong\Proxy\Models\WebProxyEndpoint::class)
    || Composer\InstalledVersions::isInstalled('webong/proxy-laravel')) {
    throw new RuntimeException('The core package must not own Laravel classes or install its adapter.');
}

$root = Composer\InstalledVersions::getInstallPath('webong/proxy');
$manifest = json_decode(file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
if (isset($manifest['extra']['laravel']) || is_dir($root.'/packages')
    || class_exists(Webong\Proxy\GuzzleClientFactory::class)
    || interface_exists(Webong\Proxy\Contracts\EndpointProvider::class)) {
    throw new RuntimeException('The core archive leaked adapter code, discovery metadata or resources.');
}
