<?php

declare(strict_types=1);

// A plugin cannot inject unpublished workspaces on the run that installs it.
// Seed only the plugin first, then let the real manifest resolve normally.
$root = dirname(__DIR__);
$pluginOnly = in_array('--plugin-only', $argv, true);
$installed = $root.'/vendor/composer/installed.json';
$ready = false;
if (is_file($installed)) {
    $data = json_decode(file_get_contents($installed), true, flags: JSON_THROW_ON_ERROR);
    foreach ($data['packages'] ?? $data as $package) {
        if ($package['name'] === 'webong/composer-source-plugin'
            && preg_match('/^v?0\.4\./', $package['version']) === 1) {
            $ready = true;
        }
    }
}
$binary = getenv('PROXY_COMPOSER_BINARY') ?: 'composer';
$run = static function (string $manifest) use ($root, $binary): void {
    $environment = getenv();
    $environment['COMPOSER'] = $root.'/'.$manifest;
    $process = proc_open([$binary, 'install', '--prefer-dist', '--no-interaction'],
        [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root, $environment);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Composer.');
    }
    $status = proc_close($process);
    if ($status !== 0) {
        exit($status);
    }
};
if (! $ready) {
    $run('composer.bootstrap.json');
}
if (! $pluginOnly) {
    $run('composer.json');
}
