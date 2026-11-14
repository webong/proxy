<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Webong\Proxy\DatabaseDestinationAttacher;
use Webong\Proxy\DestinationDefinition;
use Webong\Proxy\Models\WebProxyDestination;
use Webong\Proxy\Models\WebProxyEndpoint;
use Webong\Proxy\Tests\Support\WebhookFixtures;

// SQLite has no SELECT FOR UPDATE; the SQL matrix exercises real row locking.
if (! in_array(getenv('PROXY_TEST_DB'), ['mysql', 'mariadb', 'pgsql'], true)) {
    return;
}

it('serializes concurrent attachments across route and registration identities', function (bool $crossEndpoint): void {
    $endpoint = WebhookFixtures::endpoint(externalId: 'concurrent-first-attach');
    $target = $crossEndpoint ? WebhookFixtures::endpoint(externalId: 'second-endpoint') : $endpoint;
    $marker = tempnam(sys_get_temp_dir(), 'proxy-attach-');
    unlink($marker);
    $process = null;
    $pipes = [];
    DB::beginTransaction();
    try {
        $model = WebProxyEndpoint::query()->lockForUpdate()->findOrFail($endpoint->record->id);
        if ($crossEndpoint) {
            \Webong\Proxy\Database\SubscriptionLock::acquire(new DestinationDefinition(
                'first-owner', 'first-registration', 'events', 'account', 'race', 'https://receiver.test',
            ));
        }
        $process = proc_open([PHP_BINARY, __DIR__.'/../Support/attach-worker.php', $target->record->id, $marker,
            $crossEndpoint ? 'first-owner' : 'second-owner', $crossEndpoint ? 'first-registration' : 'second-registration'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        expect(is_resource($process))->toBeTrue();
        fclose($pipes[0]);
        $deadline = microtime(true) + 15;
        while (! file_exists($marker) && proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($marker))->toBeTrue('Worker did not reach the database.');
        // Competing writers must wait even when no subscription rows exist.
        usleep(300000);
        expect(proc_get_status($process)['running'])->toBeTrue('Worker bypassed the registry write lock.');
        app(DatabaseDestinationAttacher::class)->handle($model, new DestinationDefinition(
            'first-owner', 'first-registration', 'events', 'account', 'race', 'https://receiver.test',
        ));
        DB::commit();
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = $errors = '';
        $deadline = microtime(true) + 15;
        do {
            $output .= stream_get_contents($pipes[1]);
            $errors .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (! $status['running']) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        expect($status['running'])->toBeFalse('Worker did not finish after the endpoint lock was released.');
        $output .= stream_get_contents($pipes[1]);
        $errors .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        expect($status['exitcode'] === 0 || $exit === 0)->toBeTrue($errors);
        $process = null;
        expect($output)->toBe($crossEndpoint ? 'attached' : 'exclusive-owner-rejected')
            ->and(WebProxyDestination::query()->where('is_active', true)->count())->toBe(1);
        if ($crossEndpoint) {
            expect(WebProxyDestination::query()->where('is_active', true)->value('endpoint_id'))->toBe($target->record->id);
        }
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if (is_resource($process)) {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
        }
        @unlink($marker);
    }
})->with(['first attachment to the same endpoint' => false, 'registration moving across endpoints' => true]);
