<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$composer = getenv('COMPOSER_BINARY') ?: 'composer';
$directory = sys_get_temp_dir().'/endurance-distribution-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
/** @param list<string> $arguments */
function runDistributionCommand(array $arguments, string $directory): void
{
    fflush(STDOUT);
    fflush(STDERR);
    $process = proc_open($arguments, [STDIN, ['file', 'php://stdout', 'a'], ['file', 'php://stderr', 'a']], $pipes, $directory);
    if (!is_resource($process) || 0 !== proc_close($process)) {
        throw new RuntimeException('Distribution command failed: '.implode(' ', $arguments));
    }
}
try {
    $manifest = json_decode(file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $lock = json_decode(file_get_contents($root.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    runDistributionCommand([$composer, '--no-plugins', '--no-scripts', 'archive', '--format=tar', '--file=candidate', '--dir='.$directory], $root);
    $archive = $directory.'/candidate.tar';
    $archivedFiles = [];
    foreach (new RecursiveIteratorIterator(new PharData($archive)) as $file) {
        $path = substr($file->getPathname(), strlen('phar://'.$archive.'/'));
        if (array_intersect(explode('/', $path), ['.git', '.github', 'tests', 'vendor', 'var', '.phpunit.cache'])
            || str_ends_with(strtolower($path), '.xlsx') || in_array($path, ['composer.lock', 'phpunit.xml.dist'], true)) {
            throw new RuntimeException('Development or private file in distribution: '.$path);
        }
        $archivedFiles[$path] = hash_file('sha256', $file->getPathname());
    }
    $requiredFiles = ['composer.json', 'LICENSE', 'README.md'];
    foreach (['src', 'migrations', 'examples'] as $part) {
        if (!is_dir($root.'/'.$part)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$part, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('Runtime source must not be a symlink.');
            }
            $requiredFiles[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    foreach ($requiredFiles as $path) {
        if (($archivedFiles[$path] ?? null) !== hash_file('sha256', $root.'/'.$path)) {
            throw new RuntimeException('Distribution omitted or changed a required file: '.$path);
        }
    }
    $package = array_intersect_key($manifest, array_flip(['name', 'description', 'type', 'license', 'require', 'conflict', 'provide', 'replace', 'autoload', 'bin']));
    $package['version'] = 'dev-distribution-check';
    $package['dist'] = ['type' => 'tar', 'url' => 'file://'.$archive, 'shasum' => sha1_file($archive)];
    $consumer = $directory.'/consumer';
    mkdir($consumer, 0700);
    file_put_contents($consumer.'/composer.json', json_encode([
        'name' => 'endurance/distribution-consumer', 'license' => 'MPL-2.0',
        'require' => [$package['name'] => $package['version']],
        'repositories' => [['type' => 'package', 'package' => [$package, ...$lock['packages']]], ['packagist.org' => false]],
        'config' => ['allow-plugins' => false],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    foreach (['COMPOSER', 'COMPOSER_VENDOR_DIR', 'COMPOSER_ROOT_VERSION'] as $variable) {
        putenv($variable);
    }
    runDistributionCommand([$composer, '--no-plugins', '--no-scripts', 'update', '--no-install', '--no-dev', '--no-interaction', '--no-audit'], $consumer);
    $lockHash = hash_file('sha256', $consumer.'/composer.lock');
    runDistributionCommand([$composer, '--no-plugins', '--no-scripts', 'install', '--no-dev', '--prefer-dist', '--no-interaction'], $consumer);
    runDistributionCommand([$composer, '--no-plugins', '--no-scripts', 'dump-autoload', '--no-dev', '--optimize', '--strict-psr', '--strict-ambiguous'], $consumer);
    if ($lockHash !== hash_file('sha256', $consumer.'/composer.lock')) {
        throw new RuntimeException('Consumer installation changed its resolved lock.');
    }
    $installed = json_decode(file_get_contents($consumer.'/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
    if (false !== $installed['dev'] || [] !== json_decode(file_get_contents($consumer.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR)['packages-dev']) {
        throw new RuntimeException('Consumer contains development dependencies.');
    }
    runDistributionCommand([PHP_BINARY, __DIR__.'/assert-distribution.php', $consumer.'/vendor/autoload.php', $package['name'], __DIR__.'/smoke.php'], $consumer);
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}
