<?php

declare(strict_types=1);

/** Isolate mutations from the real checkout and its reviewed baseline. */
function withTransportApiFixture(callable $test): void
{
    $root = sys_get_temp_dir().'/proxy-api-fixture-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);
    try {
        foreach (['scripts/check_php_api.php', 'spec/api/php-v0.3.json', 'spec/api/php-registry-v0.4.json'] as $file) {
            if (! is_dir(dirname($root.'/'.$file))) {
                mkdir(dirname($root.'/'.$file), 0700, true);
            }
            copy(__DIR__.'/../../../'.$file, $root.'/'.$file);
        }
        foreach (['src', 'packages/transport/src', 'packages/registry/src'] as $directory) {
            $source = __DIR__.'/../../../'.$directory;
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $target = $root.'/'.$directory.'/'.substr($file->getPathname(), strlen($source) + 1);
                if (! is_dir(dirname($target))) {
                    mkdir(dirname($target), 0700, true);
                }
                copy($file->getPathname(), $target);
            }
        }
        $test($root);
    } finally {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    }
}

function runTransportApiGuard(string $root, bool $registry = false): array
{
    $process = proc_open(array_merge([PHP_BINARY, $root.'/scripts/check_php_api.php'], $registry ? ['--registry'] : []), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start API guard.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output];
}

it('rejects source-breaking PHP API mutations', function (string $path, string $before, string $after): void {
    withTransportApiFixture(function (string $root) use ($path, $before, $after): void {
        [$status, $output] = runTransportApiGuard($root);
        expect($status)->toBe(0);
        $file = $root.'/'.$path;
        $original = file_get_contents($file);
        $changed = str_replace($before, $after, $original, $count);
        expect($count)->toBeGreaterThan(0);
        file_put_contents($file, $changed);
        [$status, $output] = runTransportApiGuard($root);
        expect($status)->toBe(1)->and($output)->toContain('Public PHP API changed');
    });
})->with([
    'abstract class' => ['packages/transport/src/Connection/SocketProfile.php', 'readonly class SocketProfile', 'abstract readonly class SocketProfile'],
    'reference return' => ['packages/transport/src/Http/HttpProfile.php', 'public function toArray(): array', 'public function &toArray(): array'],
    'named argument' => ['packages/transport/src/Http/HttpProfile.php', 'proxyFor(string $scheme', 'proxyFor(string $changedName'],
]);


it('guards neutral repository and legacy record signatures', function (): void {
    withTransportApiFixture(function (string $root): void {
        expect(runTransportApiGuard($root, true)[0])->toBe(0);
        $file = $root.'/packages/registry/src/Registry/Contracts/DestinationRepository.php';
        file_put_contents($file, str_replace('): array;', '): iterable;', file_get_contents($file)));
        [$status, $output] = runTransportApiGuard($root, true);
        expect($status)->toBe(1)->and($output)->toContain('DestinationRepository');
    });
});
