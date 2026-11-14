<?php

declare(strict_types=1);

// Real Composer consumers resolve immutable candidate ZIPs from a temporary
// repository. No consumer path repositories, source symlinks or root autoload.
$source = dirname(__DIR__, 2);
$lab = sys_get_temp_dir().'/proxy-packaging-'.bin2hex(random_bytes(6));
mkdir($lab, 0700, true);
$cache = getenv('PROXY_COMPOSER_CACHE') ?: $lab.'/cache';
$laravel = getenv('PROXY_PACKAGING_LARAVEL') ?: '12';
if (! in_array($laravel, ['12', '13'], true)) {
    throw new RuntimeException('PROXY_PACKAGING_LARAVEL must be 12 or 13.');
}
echo "Packaging fixtures: {$lab}\n";

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function writeJson(string $path, array $value): void
{
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
}
function command(array $args, string $cwd, string $home, array $extra = [], bool $success = true): string
{
    global $lab, $cache;
    $env = array_replace(getenv(), ['COMPOSER_HOME' => $home, 'COMPOSER_CACHE_DIR' => $cache,
        'COMPOSER_AUTH' => '{}', 'COMPOSER_ALLOW_SUPERUSER' => '1'], $extra);
    unset($env['COMPOSER']);
    $log = $lab.'/command-'.bin2hex(random_bytes(4)).'.log';
    $output = fopen($log, 'wb');
    $process = proc_open($args, [0 => ['pipe', 'r'], 1 => $output, 2 => $output], $pipes, $cwd, $env);
    check(is_resource($process), 'Cannot start '.implode(' ', $args));
    fclose($pipes[0]);
    $status = proc_close($process);
    fclose($output);
    $text = file_get_contents($log);
    check($success ? $status === 0 : $status !== 0, implode(' ', $args)." unexpected status ({$status}):\n".$text);

    return $text;
}
function copyCheckout(string $source, string $destination): void
{
    mkdir($destination, 0700, true);
    $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file) use ($source): bool {
            $relative = substr($file->getPathname(), strlen($source) + 1);
            return ! $file->isLink()
                && ! preg_match('~^(vendor|\.git|\.phpunit.cache)(/|$)~', $relative)
                && ! in_array($relative, ['composer.lock', 'composer.bootstrap.lock', 'composer-source.lock'], true);
        },
    ), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($files as $file) {
        $target = $destination.'/'.substr($file->getPathname(), strlen($source) + 1);
        $file->isDir() ? mkdir($target, 0700, true) : copy($file->getPathname(), $target);
    }
}

function installed(string $consumer): array
{
    $data = json_decode(file_get_contents($consumer.'/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
    return array_column($data['packages'] ?? $data, null, 'name');
}

mkdir($lab.'/artifacts');
$packages = [];
foreach (['webong/proxy-registry' => $source.'/packages/registry', 'webong/proxy-transport' => $source.'/packages/transport', 'webong/proxy' => $source] as $name => $path) {
    $manifest = json_decode(file_get_contents($path.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $file = str_replace('/', '-', $name);
    command(['composer', 'archive', '--format=zip', '--dir='.$lab.'/artifacts', '--file='.$file, '--no-interaction'],
        $path, $lab.'/archive-home');
    $zip = $lab.'/artifacts/'.$file.'.zip';
    $manifest['version'] = '0.4.0';
    $manifest['dist'] = ['type' => 'zip', 'url' => 'file://'.$zip, 'reference' => hash_file('sha256', $zip), 'shasum' => sha1_file($zip)];
    $packages[$name]['0.4.0'] = $manifest;
}
writeJson($lab.'/artifacts/packages.json', ['packages' => $packages]);

foreach (['registry', 'neutral', 'full'] as $kind) {
    $dir = $lab.'/'.$kind;
    mkdir($dir);
    $manifest = ['name' => 'fixture/'.$kind, 'require' => [
        $kind === 'registry' ? 'webong/proxy-registry' : ($kind === 'neutral' ? 'webong/proxy-transport' : 'webong/proxy') => '0.4.0',
    ], 'repositories' => [['type' => 'composer', 'url' => 'file://'.$lab.'/artifacts']],
        'config' => ['allow-plugins' => false]];
    if ($kind === 'full') {
        $manifest['require-dev'] = ['orchestra/testbench' => $laravel === '12' ? '^10.0' : '^11.0'];
    }
    writeJson($dir.'/composer.json', $manifest);
    command(['composer', 'install', '--prefer-dist', '--no-interaction', '--no-plugins'], $dir, $dir.'/home');
    $resolved = installed($dir);
    if ($kind === 'registry') {
        check(array_keys($resolved) === ['webong/proxy-registry'], 'Registry-only install has unexpected dependencies.');
    }
    check(! isset($resolved['webong/composer-source-plugin']), 'Consumers must not inherit development tooling.');
    foreach ($resolved as $name => $package) {
        if (str_starts_with($name, 'webong/proxy')) {
            check($package['dist']['type'] === 'zip', 'Consumer accidentally resolved a workspace.');
            check(! is_link($dir.'/vendor/'.$name), 'Consumer must use archive copies.');
        }
        if ($kind !== 'full') {
            check(! preg_match('~^(illuminate|laravel|spatie)/~', $name), 'Framework dependency leaked: '.$name);
        }
    }
    command($kind !== 'full' ? [PHP_BINARY, $source.'/tests/packaging/'.($kind === 'registry' ? 'registry' : 'transport').'.php']
        : [PHP_BINARY, 'vendor/bin/phpunit', '--no-configuration', '--bootstrap', 'vendor/autoload.php', $source.'/tests/packaging/LaravelConsumerTest.php'],
        $dir, $dir.'/home');
    command(['composer', 'install', '--prefer-dist', '--no-interaction', '--no-plugins'], $dir, $dir.'/home');
    $clone = $dir.'-lock-clone';
    mkdir($clone);
    copy($dir.'/composer.json', $clone.'/composer.json');
    copy($dir.'/composer.lock', $clone.'/composer.lock');
    command(['composer', 'install', '--prefer-dist', '--no-interaction', '--no-plugins'], $clone, $clone.'/home');
    command($kind !== 'full' ? [PHP_BINARY, $source.'/tests/packaging/'.($kind === 'registry' ? 'registry' : 'transport').'.php']
        : [PHP_BINARY, 'vendor/bin/phpunit', '--no-configuration', '--bootstrap', 'vendor/autoload.php', $source.'/tests/packaging/LaravelConsumerTest.php'],
        $clone, $clone.'/home');
    if ($kind === 'full') {
        command(['composer', 'install', '--no-dev', '--no-plugins', '--no-interaction'], $clone, $clone.'/home');
        command([PHP_BINARY, $source.'/tests/packaging/production.php'], $clone, $clone.'/home');
    }
    echo "PASS {$kind}: ZIP resolution, runtime, fresh no-plugin lock clone\n";

    // The plugin is optional for consumers. Install it BEFORE enabling projection.
    $manifest['require']['webong/composer-source-plugin'] = '^0.4.0';
    $manifest['config']['allow-plugins'] = ['webong/composer-source-plugin' => true];
    writeJson($dir.'/composer.json', $manifest);
    command(['composer', 'update', '--prefer-dist', '--no-interaction'], $dir, $dir.'/home');
    $manifest['extra']['source-plugin']['projections'] = [
        ($kind === 'registry' ? 'webong/proxy-registry' : 'webong/proxy-transport') => ['surfaces' => ['php']],
    ];
    if ($kind === 'full') {
        $manifest['extra']['source-plugin']['projections']['webong/proxy'] = ['surfaces' => ['php']];
        $manifest['extra']['source-plugin']['projections']['webong/proxy-registry'] = ['surfaces' => ['php']];
    }
    writeJson($dir.'/composer.json', $manifest);
    command(['composer', 'update', '--prefer-dist', '--no-interaction'], $dir, $dir.'/home');
    foreach (array_keys($manifest['extra']['source-plugin']['projections']) as $name) {
        check(is_file($dir.'/vendor/'.$name.'/.source-plugin/projection.json'), 'Projection did not run: '.$name);
    }
    command($kind !== 'full' ? [PHP_BINARY, $source.'/tests/packaging/'.($kind === 'registry' ? 'registry' : 'transport').'.php']
        : [PHP_BINARY, 'vendor/bin/phpunit', '--no-configuration', '--bootstrap', 'vendor/autoload.php', $source.'/tests/packaging/LaravelConsumerTest.php'],
        $dir, $dir.'/home');
    command(['composer', 'install', '--prefer-dist', '--no-interaction'], $dir, $dir.'/home');
    $clone = $dir.'-projected-clone';
    mkdir($clone);
    foreach (['composer.json', 'composer.lock', 'composer-source.lock'] as $file) {
        copy($dir.'/'.$file, $clone.'/'.$file);
    }
    command(['composer', 'install', '--prefer-dist', '--no-interaction'], $clone, $clone.'/home');
    command(['composer', 'install', '--prefer-dist', '--no-interaction'], $clone, $clone.'/home');
    foreach (array_keys($manifest['extra']['source-plugin']['projections']) as $name) {
        check(is_file($clone.'/vendor/'.$name.'/.source-plugin/projection.json'), 'Fresh projection missing: '.$name);
    }
    command($kind !== 'full' ? [PHP_BINARY, $source.'/tests/packaging/'.($kind === 'registry' ? 'registry' : 'transport').'.php']
        : [PHP_BINARY, 'vendor/bin/phpunit', '--no-configuration', '--bootstrap', 'vendor/autoload.php', $source.'/tests/packaging/LaravelConsumerTest.php'],
        $clone, $clone.'/home');
    echo "PASS {$kind}: projection and fresh locked clone retain runtime and metadata\n";
}

// Older releases own the same transport classes: reject duplicate definitions.
foreach (['webong/proxy-transport', 'webong/proxy-registry'] as $child) {
    $dir = $lab.'/legacy-conflict-'.basename($child);
    mkdir($dir);
    writeJson($dir.'/composer.json', [
        'require' => ['webong/proxy' => '0.3.1', $child => '0.4.0'],
        'repositories' => [['type' => 'composer', 'url' => 'file://'.$lab.'/artifacts', 'canonical' => false]],
        'config' => ['allow-plugins' => false],
    ]);
    $output = command(['composer', 'update', '--no-install', '--no-plugins', '--no-interaction'], $dir, $dir.'/home', [], false);
    check(str_contains($output, 'conflicts with webong/proxy'), 'Legacy coexistence failed for an unrelated reason: '.$output);
}
echo "PASS legacy coexistence: duplicate transport and registry ownership rejected by solver\n";

// Bootstrap a genuinely fresh checkout: no vendor, lock or manually configured
// path repositories. Only the plugin may discover the local workspace.
$workspace = $lab.'/workspace';
copyCheckout($source, $workspace);
command([PHP_BINARY, 'scripts/bootstrap-composer.php'], $workspace, $workspace.'/home');
check(is_link($workspace.'/vendor/webong/proxy-transport'), 'Workspace was not linked by plugin.');
$resolved = installed($workspace);
check($resolved['webong/proxy-transport']['dist']['url'] === 'packages/transport', 'Workspace lock path is not portable.');
check(is_link($workspace.'/vendor/webong/proxy-registry'), 'Registry workspace was not linked.');
check($resolved['webong/proxy-registry']['dist']['url'] === 'packages/registry', 'Registry workspace path is not portable.');
command(['composer', 'source:workspaces', '--json'], $workspace, $workspace.'/home');
$clone = $lab.'/workspace-lock-clone';
copyCheckout($source, $clone);
copy($workspace.'/composer.lock', $clone.'/composer.lock');
command(['composer', 'install', '--no-plugins', '--no-interaction'], $clone, $clone.'/home');
check(is_link($clone.'/vendor/webong/proxy-transport')
    && realpath($clone.'/vendor/webong/proxy-transport') === realpath($clone.'/packages/transport'),
    'Fresh lock clone did not recreate a portable workspace link.');
check(realpath($clone.'/vendor/webong/proxy-registry') === realpath($clone.'/packages/registry'), 'Registry clone workspace link is not portable.');
command([PHP_BINARY, 'scripts/check_php_api.php'], $workspace, $workspace.'/home');
command([PHP_BINARY, 'scripts/check_php_api.php', '--registry'], $workspace, $workspace.'/home');
echo "PASS workspace: fresh bootstrap, relative lock, linked sources, frozen interfaces\n";
echo "All Composer packaging scenarios passed. Fixtures retained at {$lab}\n";
