<?php

declare(strict_types=1);
use Webong\Proxy\Connection\SocketProfile;
use Webong\Proxy\GuzzleClientFactory;
use Webong\Proxy\Http\GuzzleOptions;
use Webong\Proxy\Http\HttpProfile;
use Webong\Proxy\Http\HttpProfileNormalizer;
use Webong\Proxy\Tcp\DialException;
use Webong\Proxy\Tcp\TcpDialer;
use Webong\Proxy\Tcp\TcpProfile;
use Webong\Proxy\Udp\Datagram;
use Webong\Proxy\Udp\Socks5Datagram;
use Webong\Proxy\Udp\UdpAssociation;
use Webong\Proxy\Udp\UdpDialer;
use Webong\Proxy\Udp\UdpException;
use Webong\Proxy\Udp\UdpProfile;

require __DIR__.'/../vendor/autoload.php';

// Only the supported transport surface is frozen. Connection wire helpers and
// UdpAssociation's @internal constructor/resolve method are implementation details.
$classes = [
    HttpProfile::class,
    HttpProfileNormalizer::class,
    GuzzleOptions::class,
    SocketProfile::class,
    TcpProfile::class,
    TcpDialer::class,
    DialException::class,
    UdpProfile::class,
    UdpDialer::class,
    UdpAssociation::class,
    Datagram::class,
    Socks5Datagram::class,
    UdpException::class,
    GuzzleClientFactory::class,
];
$api = [];
foreach ($classes as $class) {
    $reflection = new ReflectionClass($class);
    $parent = $reflection->getParentClass();
    $entry = ['final' => $reflection->isFinal(), 'readonly' => $reflection->isReadOnly(), 'parent' => $parent ? $parent->getName() : null, 'interfaces' => $reflection->getInterfaceNames(), 'properties' => [], 'constants' => [], 'methods' => []];
    sort($entry['interfaces']);
    foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        $entry['properties'][$property->getName()] = ['type' => (string) $property->getType(), 'readonly' => $property->isReadOnly(), 'static' => $property->isStatic()];
    }
    foreach ($reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
        $entry['constants'][$constant->getName()] = $constant->getValue();
    }
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (str_contains($method->getDocComment() ?: '', '@internal')) {
            continue;
        }
        // Freeze package methods and inherited SocketProfile factories, not the
        // PHP runtime's Exception/Throwable API (which can change between minors).
        if (! str_starts_with($method->getDeclaringClass()->getName(), 'Webong\\Proxy\\')) {
            continue;
        }
        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $parameters[] = ['name' => $parameter->getName(), 'type' => (string) $parameter->getType(), 'optional' => $parameter->isOptional(), 'variadic' => $parameter->isVariadic(), 'by_reference' => $parameter->isPassedByReference(), 'default' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null];
        }
        $entry['methods'][$method->getName()] = ['static' => $method->isStatic(), 'return_type' => (string) $method->getReturnType(), 'parameters' => $parameters];
    }
    foreach (['properties', 'constants', 'methods'] as $key) {
        ksort($entry[$key]);
    }
    $api[$class] = $entry;
}
ksort($api);
$path = __DIR__.'/../spec/api/php-v0.3.json';
if (in_array('--write', $argv, true)) {
    file_put_contents($path, json_encode($api, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n");
} elseif ($api !== json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)) {
    fwrite(STDERR, "Public PHP API changed; review spec/CONTRACT.md before updating spec/api/php-v0.3.json.\n");
    exit(1);
} else {
    echo "PHP transport API matches frozen v0.3 baseline.\n";
}
