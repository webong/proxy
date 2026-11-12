<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Webong\Proxy\Http\GuzzleOptions;
use Webong\Proxy\Http\HttpProfileNormalizer;

it('keeps caller headers and redirect policy outside the portable transport', function (): void {
    $configuration = ['proxy' => 'http://proxy.example:8080', 'options' => ['timeout' => 2, 'connect_timeout' => 1, 'headers' => ['X-Caller-Policy' => 'retained']]];
    $profile = (new HttpProfileNormalizer)->normalize($configuration);
    expect($profile->toArray())->not->toHaveKey('headers')->not->toHaveKey('options');
    $calls = 0;
    $terminal = static function ($request, array $options) use (&$calls) {
        $calls++;
        expect($request->getHeaderLine('X-Caller-Policy'))->toBe('retained')
            ->and($options['proxy'])->toBe('http://proxy.example:8080');

        return Create::promiseFor(new Response(302, ['Location' => 'http://blocked.invalid/']));
    };
    $options = GuzzleOptions::fromProfile($profile, $terminal);
    $options['headers'] = $configuration['options']['headers'];
    $options['allow_redirects'] = ['on_redirect' => static function (): never {
        throw new RuntimeException('Caller denied redirect.');
    }];
    $client = new Client($options);
    expect(fn () => $client->get('http://service.example/'))->toThrow(RuntimeException::class, 'Caller denied redirect.');
    expect($calls)->toBe(1);
});
