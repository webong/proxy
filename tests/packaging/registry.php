<?php

declare(strict_types=1);

require getcwd().'/vendor/autoload.php';
require __DIR__.'/SqliteProfileStore.php';

use Webong\Proxy\ProfileDefinition;
use Webong\Proxy\Registry\Contracts\EndpointRepository;
use Webong\Proxy\Registry\Contracts\DestinationRepository;
use Webong\Proxy\Registry\Contracts\ProfileRepository;

function registryCheck(bool $condition): void
{
    if (! $condition) {
        throw new RuntimeException('Standalone registry contract check failed.');
    }
}
// This app owns an entirely different schema; Proxy neither creates nor assumes it.
$db = new PDO('sqlite::memory:');
SqliteProfileStore::createSchema($db);
$store = new SqliteProfileStore($db);
$endpoint = new Webong\Proxy\EndpointRecord('app-id', 'app', 'external', 'key', null, null, null, [], true, true, 'https://app.test');
$first = $store->registerProfile($endpoint, new ProfileDefinition('api', ['timeout' => 1]));
$second = $store->registerProfile($endpoint, new ProfileDefinition('api', ['timeout' => 2], 'custom'));
registryCheck($first->id === $second->id && $second->configuration === ['timeout' => 2]);
registryCheck($store->profile($endpoint, 'api')?->driver === 'custom');
registryCheck($store->profile($endpoint, 'missing') === null);
$db->exec('UPDATE app_profiles SET active = 0');
registryCheck($store->profile($endpoint, 'api') === null);
foreach ([EndpointRepository::class, DestinationRepository::class, ProfileRepository::class] as $contract) {
    registryCheck(interface_exists($contract));
}
registryCheck(! class_exists(Illuminate\Support\Collection::class));
registryCheck(! class_exists(Webong\Proxy\Models\WebProxyEndpoint::class));
$root = Composer\InstalledVersions::getInstallPath('webong/proxy-registry');
registryCheck(! is_dir($root.'/database') && ! is_dir($root.'/config'));
registryCheck(str_contains((new ReflectionClass($endpoint))->getFileName(), 'vendor/webong/proxy-registry/'));
echo "Standalone registry: custom application-owned SQLite store, records and contracts; no framework or migrations.\n";
