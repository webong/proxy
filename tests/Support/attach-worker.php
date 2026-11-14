<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

$case = new class('worker') extends \Webong\Proxy\Tests\TestCase {
    public function worker(): void {}
};
$app = $case->createApplication();
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->make(\Webong\Proxy\WebProxy::class)->register('test-router', \Webong\Proxy\Tests\Support\TestRouter::class);
$endpoint = \Webong\Proxy\Models\WebProxyEndpoint::findOrFail($argv[1]);
\Illuminate\Support\Facades\DB::connection()->beforeExecuting(function (string $sql) use ($argv): void {
    if (str_contains(strtolower($sql), 'for update') || str_contains($sql, 'subscriptions') || str_contains($sql, 'subscription_locks')) {
        file_put_contents($argv[2], 'querying');
    }
});
try {
    $app->make(\Webong\Proxy\DatabaseDestinationAttacher::class)->handle($endpoint, new \Webong\Proxy\DestinationDefinition(
        $argv[3] ?? 'second-owner', $argv[4] ?? 'second-registration', 'events', 'account', 'race', 'https://receiver.test',
    ));
    echo 'attached';
} catch (RuntimeException $e) {
    if (! str_contains($e->getMessage(), 'another owner')) {
        throw $e;
    }
    echo 'exclusive-owner-rejected';
}
