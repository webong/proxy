<?php

declare(strict_types=1);

use Webong\Proxy\Http\HttpProfile;
use Webong\Proxy\Http\HttpProfileNormalizer;
use Webong\Proxy\Http\GuzzleOptions;

it('accepts the shared valid profiles and rejects invalid profiles', function (): void {
    foreach (glob(__DIR__.'/../../../spec/fixtures/valid/*.json') as $file) {
        $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        expect(HttpProfile::fromJson(file_get_contents($file))->toArray())->toBe($data);
        expect(HttpProfile::fromJson(HttpProfile::fromArray($data)->toJson())->toArray())->toBe($data);
    }
    foreach (glob(__DIR__.'/../../../spec/fixtures/invalid/*.json') as $file) {
        $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        expect(fn () => HttpProfile::fromJson(file_get_contents($file)))->toThrow(InvalidArgumentException::class);
    }
});

it('normalizes legacy options without mixing Guzzle-only fields into the profile', function (): void {
    $profile = (new HttpProfileNormalizer())->normalize([
        'proxy' => 'http://user:pass@proxy.example:80',
        'options' => ['timeout' => 10, 'connect_timeout' => 2.5, 'verify' => false],
    ]);
    expect($profile->toArray())->toBe([
        'version' => 1, 'connect_timeout_seconds' => 2.5, 'request_timeout_seconds' => 10,
        'proxy' => ['http' => 'http://user:pass@proxy.example:80', 'https' => 'http://user:pass@proxy.example:80'],
    ])->and(GuzzleOptions::fromProfile($profile)['timeout'])->toBe(10);
});

it('applies the shared bypass rules through the portable selector', function (): void {
    $cases = json_decode(file_get_contents(__DIR__.'/../../../spec/fixtures/bypass.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($cases as $case) {
        $profile = new HttpProfile(['version' => 1, 'proxy' => [
            'http' => 'http://proxy.example:8080', 'no_proxy' => [$case['rule']],
        ]]);
        expect($profile->proxyFor('http', $case['host']) === null)->toBe($case['match']);
    }
});

it('enforces portable proxy selection at the Guzzle handler boundary', function (): void {
    $cases = json_decode(file_get_contents(__DIR__.'/../../../spec/fixtures/bypass.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($cases as $case) {
        $profile = new HttpProfile(['version' => 1, 'proxy' => [
            'http' => 'http://proxy.example:8080', 'no_proxy' => [$case['rule']],
        ]]);
        $seen = null;
        $handler = static function ($request, array $options) use (&$seen) {
            $seen = $options['proxy'];
            if (defined('CURLOPT_NOPROXY')) {
                expect($options['curl'][CURLOPT_NOPROXY])->toBe('');
            }
            return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(204));
        };
        $client = new \GuzzleHttp\Client(GuzzleOptions::fromProfile($profile, $handler));
        $authority = str_contains($case['host'], ':') ? '['.$case['host'].']' : $case['host'];
        $client->get('http://'.$authority.'/');
        expect($seen)->toBe($case['match'] ? '' : 'http://proxy.example:8080');
    }
});

it('rounds tiny portable timeouts up to milliseconds', function (): void {
    $profile = new HttpProfile(['version' => 1, 'connect_timeout_seconds' => 1e-12, 'request_timeout_seconds' => 0.0011]);
    $options = GuzzleOptions::fromProfile($profile);
    expect($options['connect_timeout'])->toBe(0.001)
        ->and($options['timeout'])->toBe(0.002);
});

it('serializes the portable document through standard JSON encoding', function (): void {
    $profile = new HttpProfile(['version' => 1, 'proxy' => []]);
    expect(json_encode($profile, JSON_THROW_ON_ERROR))->toBe('{"version":1,"proxy":{}}');
});

it('rejects nonfinite programmatic timeouts and invalid JSON containers', function (): void {
    foreach ([NAN, INF, -INF] as $value) {
        expect(fn () => new HttpProfile(['version' => 1, 'request_timeout_seconds' => $value]))->toThrow(InvalidArgumentException::class);
    }
    foreach (['[]', 'null', '{"version":1,"proxy":[]}'] as $json) {
        expect(fn () => HttpProfile::fromJson($json))->toThrow(InvalidArgumentException::class);
    }
});

it('disables ambient proxies for portable direct requests', function (): void {
    $seen = null;
    $handler = static function ($request, array $options) use (&$seen) {
        $seen = $options['proxy'];
            if (defined('CURLOPT_NOPROXY')) {
                expect($options['curl'][CURLOPT_NOPROXY])->toBe('');
            }
        return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(204));
    };
    $client = new \GuzzleHttp\Client(GuzzleOptions::fromProfile(new HttpProfile(), $handler));
    $client->get('https://example.test');
    expect($seen)->toBe('');
});

it('uses the shared portable HTTP credential byte subset', function (): void {
    $fixture = json_decode(file_get_contents(__DIR__.'/../../../spec/http-credential-cases.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($fixture['schemes'] as $scheme) {
        foreach ($fixture['cases'] as $case) {
            $data = ['version' => 1, 'proxy' => ['http' => $scheme.'://'.$case['userinfo'].'@proxy.example']];
            if ($case['valid']) {
                expect((new HttpProfile($data))->toArray())->toBe($data);
            } else {
                expect(fn () => new HttpProfile($data))->toThrow(InvalidArgumentException::class, 'C0 control');
            }
        }
    }
});

it('defaults SOCKS HTTP connect timeouts to 30 seconds and preserves explicit timeouts', function (): void {
    $data = ['version' => 1, 'proxy' => ['http' => 'socks5h://proxy.example']];
    expect(GuzzleOptions::fromProfile(new HttpProfile($data))['connect_timeout'])->toBe(30);
    $data['connect_timeout_seconds'] = 2.5;
    expect(GuzzleOptions::fromProfile(new HttpProfile($data))['connect_timeout'])->toBe(2.5);
    $data = ['version' => 1, 'proxy' => ['https' => 'http://proxy.example']];
    expect(array_key_exists('connect_timeout', GuzzleOptions::fromProfile(new HttpProfile($data))))->toBeFalse();
});
