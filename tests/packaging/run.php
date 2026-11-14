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
foreach (['webong/proxy' => $source.'/php', 'webong/proxy-laravel' => $source.'/adapters/laravel'] as $name => $path) {
    $manifest = json_decode(file_get_contents($path.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $file = str_replace('/', '-', $name);
    command(['composer', 'archive', '--format=zip', '--dir='.$lab.'/artifacts', '--file='.$file, '--no-interaction'],
        $path, $lab.'/archive-home');
    $zip = $lab.'/artifacts/'.$file.'.zip';
    $archive = new ZipArchive();
    check($archive->open($zip) === true, 'Cannot inspect candidate ZIP.');
    for ($entry = 0; $entry < $archive->numFiles; $entry++) {
        $entryName = $archive->getNameIndex($entry);
        check(! preg_match('~^(vendor|tests|\.git|\.phpunit.cache)(/|$)~', $entryName),
            'Development files leaked into '.$name.': '.$entryName);
        if ($name === 'webong/proxy') {
            check(! preg_match('~^(adapters|go|packages|database|config)(/|$)~', $entryName),
                'Adapter resources leaked into core ZIP: '.$entryName);
        }
    }
    $archive->close();
    $manifest['version'] = '0.4.0';
    $manifest['dist'] = ['type' => 'zip', 'url' => 'file://'.$zip, 'reference' => hash_file('sha256', $zip), 'shasum' => sha1_file($zip)];
    $packages[$name]['0.4.0'] = $manifest;
}
// Exercise VCS distribution semantics too: Composer archive.exclude is not a
// filter applied to a remote VCS dist ZIP. Use an isolated tree from this worktree.
$snapshot = $lab.'/vcs-snapshot';
copyCheckout($source, $snapshot);
command(['git', 'init', '--quiet'], $snapshot, $lab.'/archive-home');
command(['git', 'add', '--all'], $snapshot, $lab.'/archive-home');
$tree = trim(command(['git', 'write-tree'], $snapshot, $lab.'/archive-home'));
// Publishable PHP distributions are the component subtrees, not the workspace.
foreach (['webong/proxy' => 'php', 'webong/proxy-laravel' => 'adapters/laravel'] as $name => $subtree) {
    $zip = $lab.'/artifacts/'.str_replace('/', '-', $name).'-vcs.zip';
    command(['git', 'archive', '--format=zip', '--output='.$zip, $tree.':'.$subtree], $snapshot, $lab.'/archive-home');
    $archive = new ZipArchive();
    check($archive->open($zip) === true, 'Cannot inspect component VCS ZIP.');
    check($archive->locateName('composer.json') !== false, 'Component manifest missing.');
    for ($entry = 0; $entry < $archive->numFiles; $entry++) {
        $entryName = $archive->getNameIndex($entry);
        check(! preg_match('~^(vendor|tests|adapters|go|php|packages)(/|$)~', $entryName), 'Unrelated component leaked into '.$name);
        if ($name === 'webong/proxy') {
            check(! preg_match('~^(config|database)(/|$)~', $entryName), 'Adapter resources leaked into PHP core.');
        }
    }
    $archive->close();
    $packages[$name]['0.4.0']['dist'] = [
        'type' => 'zip', 'url' => 'file://'.$zip, 'reference' => hash_file('sha256', $zip), 'shasum' => sha1_file($zip),
    ];
}
$goZip = $lab.'/artifacts/proxy-go-vcs.zip';
command(['git', 'archive', '--format=zip', '--output='.$goZip, $tree.':go'], $snapshot, $lab.'/archive-home');
$archive = new ZipArchive();
check($archive->open($goZip) === true, 'Cannot inspect Go module ZIP.');
foreach (['go.mod', 'httpclient/factory.go', 'testdata/tls/ca.pem', 'testdata/spec/api/go-v0.3.json'] as $resource) {
    check($archive->locateName($resource) !== false, 'Go module lost resource '.$resource);
}
check($archive->locateName('composer.json') === false, 'Go module contains PHP metadata.');
$goCheckout = $lab.'/vcs-go';
mkdir($goCheckout);
$archive->extractTo($goCheckout);
$archive->close();
check(str_contains(file_get_contents($goCheckout.'/go.mod'), 'module github.com/webong/proxy/go'), 'Go module path is incorrect.');
command(['go', 'test', '-race', './...'], $goCheckout, $lab.'/go-home',
    ['GOCACHE' => getenv('GOCACHE') ?: $lab.'/go-cache', 'GOWORK' => 'off']);
command(['go', 'vet', './...'], $goCheckout, $lab.'/go-home',
    ['GOCACHE' => getenv('GOCACHE') ?: $lab.'/go-cache', 'GOWORK' => 'off']);
// Exercise the Go module ZIP/proxy protocol with an empty module cache and no
// replace directive. Never write synthetic candidate versions to the user's cache.
$module = 'github.com/webong/proxy/go';
$proxy = $lab.'/go-proxy/'.$module.'/@v';
mkdir($proxy, 0700, true);
writeJson($proxy.'/v0.4.0.info', ['Version' => 'v0.4.0', 'Time' => gmdate('Y-m-d\TH:i:s\Z')]);
copy($goCheckout.'/go.mod', $proxy.'/v0.4.0.mod');
$download = new ZipArchive();
check($download->open($proxy.'/v0.4.0.zip', ZipArchive::CREATE) === true, 'Cannot create module ZIP.');
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($goCheckout, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->isFile()) {
        $relative = substr($file->getPathname(), strlen($goCheckout) + 1);
        $download->addFile($file->getPathname(), $module.'@v0.4.0/'.$relative);
    }
}
$download->close();
$consumer = $lab.'/go-consumer';
mkdir($consumer);
file_put_contents($consumer.'/go.mod', "module fixture/consumer\n\ngo 1.23.0\n\nrequire {$module} v0.4.0\n");
foreach (glob($goCheckout.'/tests/contract/*_test.go') as $file) {
    copy($file, $consumer.'/'.basename($file));
}
$goEnvironment = ['GOPROXY' => 'file://'.$lab.'/go-proxy', 'GOSUMDB' => 'off',
    'GOMODCACHE' => $lab.'/go-module-cache', 'GOCACHE' => getenv('GOCACHE') ?: $lab.'/go-cache',
    'GOWORK' => 'off', 'GOTOOLCHAIN' => 'local'];
command(['go', 'mod', 'tidy'], $consumer, $lab.'/go-home', $goEnvironment);
command(['go', 'test', '-race', './...'], $consumer, $lab.'/go-home', $goEnvironment);
$moduleInfo = json_decode(command(['go', 'list', '-m', '-json', $module], $consumer, $lab.'/go-home', $goEnvironment), true, flags: JSON_THROW_ON_ERROR);
check(! isset($moduleInfo['Replace']) && str_starts_with($moduleInfo['Dir'], $lab.'/go-module-cache/'),
    'Go consumer bypassed the fresh module download.');
echo "PASS Go module ZIP: empty cache download and independent consumer without replace\n";
writeJson($lab.'/artifacts/packages.json', ['packages' => $packages]);
echo "PASS component VCS exports: isolated PHP packages and standalone Go race/vet\n";

// Fresh runtime-only solve: no Testbench lock can supply Foundation accidentally.
$production = $lab.'/fresh-production';
mkdir($production);
writeJson($production.'/composer.json', [
    'name' => 'fixture/production', 'require' => ['webong/proxy-laravel' => '0.4.0'],
    // Bound the major without supplying a missing runtime dependency ourselves.
    'conflict' => ['laravel/framework' => $laravel === '12' ? '>=13' : '<13'],
    'repositories' => [['type' => 'composer', 'url' => 'file://'.$lab.'/artifacts']],
    'config' => ['allow-plugins' => false, 'platform' => ['php' => $laravel === '12' ? '8.3.0' : '8.5.0']],
]);
command(['composer', 'install', '--no-dev', '--no-plugins', '--prefer-dist', '--no-interaction'], $production, $production.'/home');
command([PHP_BINARY, $source.'/tests/packaging/production.php'], $production, $production.'/home');
check(str_starts_with(ltrim(installed($production)['laravel/framework']['version'], 'v'), $laravel.'.'), 'Production solve selected the wrong Laravel major.');
echo "PASS fresh production: independent runtime solve loads jobs/events without Testbench\n";


foreach (['neutral', 'full'] as $kind) {
    $dir = $lab.'/'.$kind;
    mkdir($dir);
    $manifest = ['name' => 'fixture/'.$kind, 'require' => [
        $kind === 'neutral' ? 'webong/proxy' : 'webong/proxy-laravel' => '0.4.0',
    ], 'repositories' => [['type' => 'composer', 'url' => 'file://'.$lab.'/artifacts']],
        'config' => ['allow-plugins' => false]];
    if ($kind === 'full') {
        $manifest['require-dev'] = ['orchestra/testbench' => $laravel === '12' ? '^10.0' : '^11.0'];
    }
    writeJson($dir.'/composer.json', $manifest);
    command(['composer', 'install', '--prefer-dist', '--no-interaction', '--no-plugins'], $dir, $dir.'/home');
    $resolved = installed($dir);
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
    command($kind !== 'full' ? [PHP_BINARY, $source.'/tests/packaging/neutral.php']
        : [PHP_BINARY, 'vendor/bin/phpunit', '--no-configuration', '--bootstrap', 'vendor/autoload.php', $source.'/tests/packaging/LaravelConsumerTest.php'],
        $dir, $dir.'/home');
    command(['composer', 'install', '--prefer-dist', '--no-interaction', '--no-plugins'], $dir, $dir.'/home');
    $clone = $dir.'-lock-clone';
    mkdir($clone);
    copy($dir.'/composer.json', $clone.'/composer.json');
    copy($dir.'/composer.lock', $clone.'/composer.lock');
    command(['composer', 'install', '--prefer-dist', '--no-interaction', '--no-plugins'], $clone, $clone.'/home');
    command($kind !== 'full' ? [PHP_BINARY, $source.'/tests/packaging/neutral.php']
        : [PHP_BINARY, 'vendor/bin/phpunit', '--no-configuration', '--bootstrap', 'vendor/autoload.php', $source.'/tests/packaging/LaravelConsumerTest.php'],
        $clone, $clone.'/home');
    command(['composer', 'install', '--no-dev', '--no-plugins', '--no-interaction'], $clone, $clone.'/home');
    if ($kind === 'full') {
        command([PHP_BINARY, $source.'/tests/packaging/production.php'], $clone, $clone.'/home');
    } else {
        command([PHP_BINARY, $source.'/tests/packaging/neutral.php'], $clone, $clone.'/home');
    }
    echo "PASS {$kind}: ZIP resolution, runtime, fresh no-plugin lock clone\n";

    // The plugin is optional for consumers. Install it BEFORE enabling projection.
    $manifest['require']['webong/composer-source-plugin'] = '^0.4.0';
    $manifest['config']['allow-plugins'] = ['webong/composer-source-plugin' => true];
    writeJson($dir.'/composer.json', $manifest);
    command(['composer', 'update', '--prefer-dist', '--no-interaction'], $dir, $dir.'/home');
    $manifest['extra']['source-plugin']['projections'] = [
        'webong/proxy' => ['surfaces' => ['php']],
    ];
    if ($kind === 'full') {
        $manifest['extra']['source-plugin']['projections']['webong/proxy-laravel'] = ['surfaces' => ['php']];
    }
    writeJson($dir.'/composer.json', $manifest);
    command(['composer', 'update', '--prefer-dist', '--no-interaction'], $dir, $dir.'/home');
    foreach (array_keys($manifest['extra']['source-plugin']['projections']) as $name) {
        check(is_file($dir.'/vendor/'.$name.'/.source-plugin/projection.json'), 'Projection did not run: '.$name);
    }
    command($kind !== 'full' ? [PHP_BINARY, $source.'/tests/packaging/neutral.php']
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
    command($kind !== 'full' ? [PHP_BINARY, $source.'/tests/packaging/neutral.php']
        : [PHP_BINARY, 'vendor/bin/phpunit', '--no-configuration', '--bootstrap', 'vendor/autoload.php', $source.'/tests/packaging/LaravelConsumerTest.php'],
        $clone, $clone.'/home');
    echo "PASS {$kind}: projection and fresh locked clone retain runtime and metadata\n";
}

// Upgrade a real public 0.3.1 consumer with application-owned published migrations.
$upgrade = $lab.'/upgrade';
mkdir($upgrade);
touch($upgrade.'/upgrade.sqlite');
$manifest = [
    'name' => 'fixture/upgrade', 'require' => ['webong/proxy' => '0.3.1'],
    'require-dev' => ['orchestra/testbench' => $laravel === '12' ? '^10.0' : '^11.0'],
    'repositories' => [['type' => 'composer', 'url' => 'file://'.$lab.'/artifacts', 'canonical' => false]],
    'config' => ['allow-plugins' => false],
];
writeJson($upgrade.'/composer.json', $manifest);
command(['composer', 'install', '--prefer-dist', '--no-plugins', '--no-interaction'], $upgrade, $upgrade.'/home');
command([PHP_BINARY, 'vendor/bin/phpunit', '--no-configuration', '--bootstrap', 'vendor/autoload.php', $source.'/tests/packaging/UpgradeConsumerTest.php'],
    $upgrade, $upgrade.'/home', ['PROXY_UPGRADE_PHASE' => 'seed']);
$manifest['require'] = ['webong/proxy-laravel' => '0.4.0'];
writeJson($upgrade.'/composer.json', $manifest);
command(['composer', 'update', '--with-all-dependencies', '--prefer-dist', '--no-plugins', '--no-interaction'], $upgrade, $upgrade.'/home');
command([PHP_BINARY, 'vendor/bin/phpunit', '--no-configuration', '--bootstrap', 'vendor/autoload.php', $source.'/tests/packaging/UpgradeConsumerTest.php'],
    $upgrade, $upgrade.'/home', ['PROXY_UPGRADE_PHASE' => 'verify']);
echo "PASS upgrade: public 0.3.1 data, ciphertext, identities and migration history preserved\n";

// The adapter cannot coexist with legacy Proxy releases owning its classes.
$dir = $lab.'/legacy-conflict';
mkdir($dir);
writeJson($dir.'/composer.json', [
    'require' => ['webong/proxy' => '0.3.1', 'webong/proxy-laravel' => '0.4.0'],
    'repositories' => [['type' => 'composer', 'url' => 'file://'.$lab.'/artifacts', 'canonical' => false]],
    'config' => ['allow-plugins' => false],
]);
$output = command(['composer', 'update', '--no-install', '--no-plugins', '--no-interaction'], $dir, $dir.'/home', [], false);
check(str_contains($output, 'webong/proxy ^0.4.0'), 'Legacy coexistence failed for an unrelated reason: '.$output);
echo "PASS legacy coexistence: adapter rejects old Proxy class ownership\n";

// Bootstrap a genuinely fresh checkout: no vendor, lock or manually configured
// path repositories. Only the plugin may discover the local workspace.
$workspace = $lab.'/workspace';
copyCheckout($source, $workspace);
command([PHP_BINARY, 'scripts/bootstrap-composer.php'], $workspace, $workspace.'/home');
check(is_link($workspace.'/vendor/webong/proxy'), 'PHP core workspace was not linked.');
check(is_link($workspace.'/vendor/webong/proxy-laravel'), 'Laravel workspace was not linked.');
$resolved = installed($workspace);
check($resolved['webong/proxy']['dist']['url'] === 'php', 'PHP core workspace path is not portable.');
check($resolved['webong/proxy-laravel']['dist']['url'] === 'adapters/laravel', 'Workspace path is not portable.');
check(! isset($resolved['webong/proxy-transport']) && ! isset($resolved['webong/proxy-registry']), 'Obsolete child packages remain.');
command(['composer', 'source:workspaces', '--json'], $workspace, $workspace.'/home');
$clone = $lab.'/workspace-lock-clone';
copyCheckout($source, $clone);
copy($workspace.'/composer.lock', $clone.'/composer.lock');
command(['composer', 'install', '--no-plugins', '--no-interaction'], $clone, $clone.'/home');
check(is_link($clone.'/vendor/webong/proxy-laravel')
    && realpath($clone.'/vendor/webong/proxy-laravel') === realpath($clone.'/adapters/laravel'),
    'Fresh clone did not recreate the portable adapter workspace link.');
check(realpath($clone.'/vendor/webong/proxy') === realpath($clone.'/php'), 'PHP core clone workspace link is not portable.');
command([PHP_BINARY, 'scripts/check_php_api.php'], $workspace, $workspace.'/home');
command([PHP_BINARY, 'scripts/check_php_api.php', '--registry'], $workspace, $workspace.'/home');
echo "PASS workspace: fresh bootstrap, relative lock, linked sources, frozen interfaces\n";
echo "All Composer packaging scenarios passed. Fixtures retained at {$lab}\n";
