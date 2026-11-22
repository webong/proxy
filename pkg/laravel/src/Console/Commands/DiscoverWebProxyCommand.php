<?php

declare(strict_types=1);

namespace Webong\Proxy\Console\Commands;

use Illuminate\Console\Command;
use Webong\Proxy\WebhookDiscovery;

final class DiscoverWebProxyCommand extends Command
{
    protected $signature = 'web-proxy:discover
        {--output= : Config file to generate}';

    protected $description = 'Discover webhook routers and attributed targets';

    public function handle(WebhookDiscovery $discovery): int
    {
        $result = $discovery->discover($this->option('output') ?: null);
        $this->info(sprintf(
            'Discovered %d webhook router(s) and %d webhook target(s) into %s.',
            $result['routers'],
            $result['targets'],
            $result['output'],
        ));

        return self::SUCCESS;
    }
}
