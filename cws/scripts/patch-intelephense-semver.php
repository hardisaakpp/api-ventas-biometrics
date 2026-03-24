<?php

declare(strict_types=1);

/**
 * Suprime P1007 (normalizeDefaultBranch deprecado) en composer/semver para Intelephense.
 * Idempotente; ejecutar tras composer install/update si el paquete semver se regenera.
 */
$path = dirname(__DIR__) . '/vendor/composer/semver/src/Semver.php';
if (!is_readable($path)) {
    exit(0);
}

$content = file_get_contents($path);
if ($content === false) {
    exit(0);
}

$marker = '/** @disregard P1007 */' . "\n            \$normalizedVersion = \$versionParser->normalizeDefaultBranch";
if (str_contains($content, $marker)) {
    exit(0);
}

$old = <<<'PHP'
            $normalizedVersion = $versionParser->normalize($version);
            $normalizedVersion = $versionParser->normalizeDefaultBranch($normalizedVersion);
PHP;
$new = <<<'PHP'
            $normalizedVersion = $versionParser->normalize($version);
            /** @disregard P1007 */
            $normalizedVersion = $versionParser->normalizeDefaultBranch($normalizedVersion);
PHP;

if (!str_contains($content, $old)) {
    exit(0);
}

$count = 0;
$newContent = str_replace($old, $new, $content, $count);
if ($count !== 1) {
    exit(0);
}

file_put_contents($path, $newContent);
