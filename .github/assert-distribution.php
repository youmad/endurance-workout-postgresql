<?php

declare(strict_types=1);

$autoload = $argv[1] ?? throw new InvalidArgumentException('Expected consumer autoloader.');
$name = $argv[2] ?? throw new InvalidArgumentException('Expected package name.');
$smoke = $argv[3] ?? throw new InvalidArgumentException('Expected smoke test.');
$loader = require $autoload;
$vendor = realpath(dirname($autoload)).DIRECTORY_SEPARATOR;
$ensure = static function (bool $condition): void {
    if (!$condition) {
        throw new RuntimeException('Distribution consumer assertion failed.');
    }
};
$installation = Composer\InstalledVersions::getInstallPath($name);
if (!is_string($installation) || is_link($installation) || false === ($package = realpath($installation))) {
    throw new RuntimeException('Package is not an installed distribution.');
}
$ensure(str_starts_with($package.DIRECTORY_SEPARATOR, $vendor));
$ensure(is_file($package.'/LICENSE') && is_file($package.'/README.md'));
foreach ($loader->getPrefixesPsr4() as $directories) {
    foreach ($directories as $directory) {
        $ensure(str_starts_with(realpath($directory).DIRECTORY_SEPARATOR, $vendor));
    }
}
$count = 0;
foreach ($loader->getClassMap() as $class => $file) {
    if (str_starts_with(realpath($file), $package.'/src/')) {
        $reflection = new ReflectionClass($class);
        $ensure(str_starts_with(realpath($reflection->getFileName()), $package.'/src/'));
        ++$count;
    }
}
$ensure($count > 0);
$check = require $smoke;
$check($ensure, $autoload, $package);
printf("%s: distribution-only autoload (%d types), license and behavior passed.\n", $name, $count);
