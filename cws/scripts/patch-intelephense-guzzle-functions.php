<?php

declare(strict_types=1);

/**
 * Suprime P1007 (Utils::defaultCaBundle deprecado) en guzzle functions.php para Intelephense.
 */
$path = dirname(__DIR__) . '/vendor/guzzlehttp/guzzle/src/functions.php';
if (!is_readable($path)) {
    exit(0);
}

$content = file_get_contents($path);
if ($content === false) {
    exit(0);
}

$marker = '/** @disregard P1007 */' . "\n    return Utils::defaultCaBundle();";
if (str_contains($content, $marker)) {
    exit(0);
}

$old = <<<'PHP'
function default_ca_bundle(): string
{
    return Utils::defaultCaBundle();
}
PHP;
$new = <<<'PHP'
function default_ca_bundle(): string
{
    /** @disregard P1007 */
    return Utils::defaultCaBundle();
}
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
