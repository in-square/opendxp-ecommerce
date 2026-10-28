<?php

declare(strict_types=1);


$sourceRoot = dirname(__DIR__, 2) . '/src/CoreShop';
$packageDirectory = realpath($argv[1] ?? '');
if (false === $packageDirectory
    || (!str_starts_with($packageDirectory, $sourceRoot . '/Bundle/')
        && !str_starts_with($packageDirectory, $sourceRoot . '/Component/'))
    || !is_file($packageDirectory . '/composer.json')) {
    throw new InvalidArgumentException('Provide a CoreShop bundle or component directory.');
}

$versions = [];
foreach (glob($sourceRoot . '/{Bundle,Component}/*/composer.json', GLOB_BRACE) as $manifest) {
    $package = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
    $versions[$package['name']] = '4.1-dev';
}

$manifest = $packageDirectory . '/composer.json';
$configuration = json_decode(file_get_contents($manifest), false, 512, JSON_THROW_ON_ERROR);
foreach ($configuration->repositories ?? [] as $repository) {
    if ('path' === $repository->type) {
        $repository->canonical = true;
        $repository->options ??= new stdClass();
        $repository->options->versions = (object) $versions;
    }
}

file_put_contents($manifest, json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
