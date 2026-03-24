<?php

declare(strict_types=1);

/**
 * Ajustes Intelephense en ClassLoader::findFile (APCu) tras composer dump-autoload / update.
 */
$path = dirname(__DIR__) . '/vendor/composer/ClassLoader.php';
if (!is_readable($path)) {
    exit(0);
}

$content = file_get_contents($path);
if ($content === false || str_contains($content, '\apcu_fetch')) {
    exit(0);
}

$a = <<<'PHP'
        if (null !== $this->apcuPrefix) {
            $file = apcu_fetch($this->apcuPrefix.$class, $hit);
            if ($hit) {
                return $file;
            }
        }
PHP;
$b = <<<'PHP'
        if (null !== $this->apcuPrefix) {
            $hit = false;
            /** @disregard P1010 */
            $file = \apcu_fetch($this->apcuPrefix.$class, $hit);
            if ($hit) {
                return $file;
            }
        }
PHP;

$c = <<<'PHP'
        if (null !== $this->apcuPrefix) {
            apcu_add($this->apcuPrefix.$class, $file);
        }
PHP;
$d = <<<'PHP'
        if (null !== $this->apcuPrefix) {
            /** @disregard P1010 */
            \apcu_add($this->apcuPrefix.$class, $file);
        }
PHP;

$content = str_replace($a, $b, $content, $n1);
$content = str_replace($c, $d, $content, $n2);
if ($n1 === 0 && $n2 === 0) {
    exit(0);
}

file_put_contents($path, $content);
