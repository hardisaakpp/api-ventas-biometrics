<?php

declare(strict_types=1);

/**
 * Reaplica supresiones Intelephense (P1056) en autoload_static.php tras composer dump-autoload.
 */
$path = dirname(__DIR__) . '/vendor/composer/autoload_static.php';
if (!is_readable($path)) {
    exit(0);
}

$content = file_get_contents($path);
if ($content === false || str_contains($content, '@disregard P1056')) {
    exit(0);
}

$pattern = '/(return \\\\Closure::bind\(function \(\) use \(\$loader\) \{\s*\n)'
    . '(            \$loader->prefixLengthsPsr4 = ComposerStaticInit[0-9a-f]+::\$prefixLengthsPsr4;\s*\n)'
    . '(            \$loader->prefixDirsPsr4 = ComposerStaticInit[0-9a-f]+::\$prefixDirsPsr4;\s*\n)'
    . '(            \$loader->classMap = ComposerStaticInit[0-9a-f]+::\$classMap;)/';

$replacement = '$1            /** @disregard P1056 */' . "\n" . '$2            /** @disregard P1056 */' . "\n" . '$3            /** @disregard P1056 */' . "\n" . '$4';

$newContent = preg_replace($pattern, $replacement, $content, 1, $count);
if ($count !== 1 || !is_string($newContent)) {
    exit(0);
}

file_put_contents($path, $newContent);
