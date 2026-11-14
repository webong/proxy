<?php

declare(strict_types=1);

use Webong\Proxy\EndpointRecord;
use Webong\Proxy\ProfileDefinition;
use Webong\Proxy\ProfileRecord;
use Webong\Proxy\Registry\Contracts\ProfileRepository;

/** Example application adapter: its schema and serialization belong to the app. */
final class SqliteProfileStore implements ProfileRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public static function createSchema(PDO $db): void
    {
        $db->exec('CREATE TABLE app_profiles (endpoint TEXT, name TEXT, driver TEXT, config TEXT, active INTEGER, PRIMARY KEY(endpoint, name))');
    }

    public function registerProfile(EndpointRecord $endpoint, ProfileDefinition $definition): ProfileRecord
    {
        $query = $this->db->prepare('INSERT INTO app_profiles VALUES (?, ?, ?, ?, 1) ON CONFLICT(endpoint, name) DO UPDATE SET driver = excluded.driver, config = excluded.config, active = 1');
        $query->execute([$endpoint->id, $definition->name, $definition->driver, json_encode($definition->configuration, JSON_THROW_ON_ERROR)]);

        return $this->profile($endpoint, $definition->name);
    }

    public function profile(EndpointRecord $endpoint, string $name): ?ProfileRecord
    {
        $query = $this->db->prepare('SELECT * FROM app_profiles WHERE endpoint = ? AND name = ? AND active = 1');
        $query->execute([$endpoint->id, $name]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : new ProfileRecord(
            id: hash('sha256', json_encode([$endpoint->id, $name], JSON_THROW_ON_ERROR)),
            endpoint_id: $endpoint->id, name: $name, driver: $row['driver'],
            configuration: json_decode($row['config'], true, flags: JSON_THROW_ON_ERROR), is_active: true,
        );
    }
}
