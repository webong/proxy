<?php

declare(strict_types=1);

// Seed the plugin before the main solve so unpublished workspaces can resolve.
$root = dirname(__DIR__);
$pluginOnly = in_array('--plugin-only', $argv, true);
$compatible = static fn (array $package): bool => ($package['name'] ?? '') === 'webong/composer-source-plugin'
    && preg_match('/^v?0\\.5\\.[0-9]+$/D', $package['version'] ?? '') === 1;
$read = static fn (string $file): array => is_file($file)
    ? json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : [];
$data = $read($root.'/vendor/composer/installed.json');
$installed = false;
foreach ($data['packages'] ?? $data as $package) {
    $installed = $installed || $compatible($package);
}
$filesPresent = is_file($root.'/vendor/autoload.php')
    && is_file($root.'/vendor/composer/autoload_real.php')
    && is_file($root.'/vendor/composer/autoload_static.php')
    && is_file($root.'/vendor/composer/ClassLoader.php')
    && is_file($root.'/vendor/webong/composer-source-plugin/src/ComposerSourcePlugin.php')
    && is_file($root.'/vendor/webong/composer-source-plugin/src/bootstrap.php');
$binary = getenv('PROXY_COMPOSER_BINARY') ?: 'composer';
$run = static function (string $manifest, array $arguments) use ($root, $binary): void {
    $environment = getenv();
    $environment['COMPOSER'] = $root.'/'.$manifest;
    $process = proc_open([$binary, ...$arguments, '--no-interaction'],
        [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root, $environment);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Composer.');
    }
    $status = proc_close($process);
    if ($status !== 0) {
        exit($status);
    }
};
if (! $installed || ! $filesPresent) {
    $lock = $read($root.'/composer.bootstrap.lock');
    $locked = false;
    foreach ($lock['packages'] ?? [] as $package) {
        $locked = $locked || $compatible($package);
    }
    // Preserve valid seed locks; update only an incompatible old seed graph.
    $run('composer.bootstrap.json', [$locked ? 'install' : 'update', '--prefer-dist', '--no-plugins']);
    if (! is_file($root.'/vendor/webong/composer-source-plugin/src/ComposerSourcePlugin.php')
        || ! is_file($root.'/vendor/webong/composer-source-plugin/src/bootstrap.php')) {
        // Composer's installed metadata can survive deleted package files.
        $run('composer.bootstrap.json', ['reinstall', 'webong/composer-source-plugin', '--prefer-dist', '--no-plugins']);
    }
}
if (! $pluginOnly) {
    // Never silently update the full developer lock.
    $run('composer.json', ['install', '--prefer-dist']);
}
