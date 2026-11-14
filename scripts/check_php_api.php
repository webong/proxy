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

// Inspect package signatures without booting Laravel or requiring a vendor
// tree built for this PHP minor. Dependency type names remain qualified strings.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Webong\\Proxy\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    $path = __DIR__.'/../php/src/'.$relative;
    if (! is_file($path)) {
        $path = __DIR__.'/../adapters/laravel/src/'.$relative;
    }
    if (is_file($path)) {
        require $path;
    }
});

// PHP 8.5 resolves self to a class name in Reflection while older minors may
// preserve the keyword. Freeze its meaning rather than that runtime spelling.
function proxyContractType(?ReflectionType $type, ReflectionClass $declaring): string
{
    if ($type instanceof ReflectionNamedType && in_array($type->getName(), ['self', 'parent'], true)) {
        $resolved = $type->getName() === 'self' ? $declaring->getName() : $declaring->getParentClass()->getName();

        return ($type->allowsNull() ? '?' : '').$resolved;
    }

    return (string) $type;
}

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
$registry = in_array('--registry', $argv, true);
if ($registry) {
    $classes = [
        \Webong\Proxy\EndpointDefinition::class,
        \Webong\Proxy\EndpointRecord::class,
        \Webong\Proxy\DestinationDefinition::class,
        \Webong\Proxy\DestinationRecord::class,
        \Webong\Proxy\ProfileDefinition::class,
        \Webong\Proxy\ProfileRecord::class,
        \Webong\Proxy\ProvisionedEndpoint::class,
        \Webong\Proxy\WebhookRoute::class,
        \Webong\Proxy\Enums\WebhookProxyTargetType::class,
        \Webong\Proxy\Registry\Contracts\EndpointRepository::class,
        \Webong\Proxy\Registry\Contracts\DestinationRepository::class,
        \Webong\Proxy\Registry\Contracts\ProfileRepository::class,
        \Webong\Proxy\Contracts\EndpointProvider::class,
        \Webong\Proxy\DatabaseEndpointProvider::class,
        \Webong\Proxy\LaravelRegistryStore::class,
    ];
}
$api = [];
foreach ($classes as $class) {
    $reflection = new ReflectionClass($class);
    $parent = $reflection->getParentClass();
    $entry = ['abstract' => $reflection->isAbstract(), 'final' => $reflection->isFinal(), 'readonly' => $reflection->isReadOnly(), 'parent' => $parent ? $parent->getName() : null, 'interfaces' => $reflection->getInterfaceNames(), 'properties' => [], 'constants' => [], 'methods' => []];
    sort($entry['interfaces']);
    foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        $entry['properties'][$property->getName()] = ['type' => proxyContractType($property->getType(), $property->getDeclaringClass()), 'readonly' => $property->isReadOnly(), 'static' => $property->isStatic()];
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
            $parameters[] = ['name' => $parameter->getName(), 'type' => proxyContractType($parameter->getType(), $method->getDeclaringClass()), 'optional' => $parameter->isOptional(), 'variadic' => $parameter->isVariadic(), 'by_reference' => $parameter->isPassedByReference(), 'default' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null];
        }
        $entry['methods'][$method->getName()] = ['abstract' => $method->isAbstract(), 'final' => $method->isFinal(), 'returns_reference' => $method->returnsReference(), 'static' => $method->isStatic(), 'return_type' => proxyContractType($method->getReturnType(), $method->getDeclaringClass()), 'parameters' => $parameters];
    }
    foreach (['properties', 'constants', 'methods'] as $key) {
        ksort($entry[$key]);
    }
    $api[$class] = $entry;
}
ksort($api);
// Enum cases in constants and defaults compare by their JSON wire value.
$api = json_decode(json_encode($api, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
$baseline = $registry ? 'php-registry-v0.4.json' : 'php-v0.3.json';
$path = __DIR__.'/../spec/api/'.$baseline;
if (in_array('--dump', $argv, true)) {
    echo json_encode($api, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n";
} elseif (in_array('--write', $argv, true)) {
    file_put_contents($path, json_encode($api, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n");
} elseif ($api !== json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)) {
    $expected = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    foreach (array_unique(array_merge(array_keys($api), array_keys($expected))) as $class) {
        if (($api[$class] ?? null) !== ($expected[$class] ?? null)) {
            fwrite(STDERR, 'Changed API: '.$class."\n");
        }
    }
    fwrite(STDERR, "Public PHP API changed; review spec/CONTRACT.md before updating spec/api/{$baseline}.\n");
    exit(1);
} else {
    echo $registry ? "PHP registry API matches v0.4 candidate baseline.\n" : "PHP transport API matches frozen v0.3 baseline.\n";
}
