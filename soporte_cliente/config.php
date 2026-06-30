<?php
define('SOPORTE_CLIENTE_PATH', __DIR__);

(function () {
    $sep      = DIRECTORY_SEPARATOR;
    $default  = SOPORTE_CLIENTE_PATH . $sep . 'klienteV1';
    $parent   = dirname(SOPORTE_CLIENTE_PATH);   // repo root cuando soporte_cliente es subdirectorio
    $linuxOpt = '/opt/klienteV1';

    if (is_readable($parent . $sep . '.env')) {
        // soporte_cliente está dentro del repo klienteV1 (dev local)
        define('KLIENTEV1_PATH', $parent);
    } elseif (PHP_OS_FAMILY !== 'Windows'
        && !is_readable($default . $sep . '.env')
        && is_dir($linuxOpt)
    ) {
        // producción Linux sin .env junto al repo
        define('KLIENTEV1_PATH', $linuxOpt);
    } else {
        define('KLIENTEV1_PATH', $default);
    }
})();

function get_php_cli_binary(): string {
    $candidates = [];

    if (defined('PHP_BINARY') && PHP_BINARY) {
        $candidates[] = PHP_BINARY;
    }
    if (defined('PHP_BINDIR') && PHP_BINDIR) {
        $sep = DIRECTORY_SEPARATOR;
        $candidates[] = PHP_BINDIR . $sep . 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $candidates[] = PHP_BINDIR . $sep . 'php' . PHP_MAJOR_VERSION;
        $candidates[] = PHP_BINDIR . $sep . 'php.exe';
        $candidates[] = PHP_BINDIR . $sep . 'php';
    }

    $candidates[] = 'C:\\xampp\\php\\php.exe';
    $candidates[] = '/usr/bin/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $candidates[] = '/usr/bin/php' . PHP_MAJOR_VERSION;
    $candidates[] = '/usr/bin/php';
    $candidates[] = '/usr/local/bin/php';

    // Palabras que identifican binarios de servidor (no CLI)
    $serverKeywords = ['httpd', 'apache', 'nginx', 'fpm', 'cgi'];

    foreach (array_unique($candidates) as $bin) {
        if (!is_string($bin) || $bin === '') {
            continue;
        }

        $name = strtolower(basename(str_replace('\\', '/', $bin)));
        $isServer = false;
        foreach ($serverKeywords as $kw) {
            if (str_contains($name, $kw)) {
                $isServer = true;
                break;
            }
        }
        if ($isServer) {
            continue;
        }

        if (is_file($bin)) {
            return $bin;
        }
    }

    return 'php';
}

function read_last_log_line(string $script): string {
    $base = pathinfo($script, PATHINFO_FILENAME);
    $logsDir = KLIENTEV1_PATH . DIRECTORY_SEPARATOR . 'tareas' . DIRECTORY_SEPARATOR . 'logs';
    $candidates = [
        $logsDir . DIRECTORY_SEPARATOR . $base . '.log',
        $logsDir . DIRECTORY_SEPARATOR . 'syn_put_ventas.log',
        $logsDir . DIRECTORY_SEPARATOR . 'syn_put_asistencias.log',
        $logsDir . DIRECTORY_SEPARATOR . 'syn_put_asistencias_prog.log',
        $logsDir . DIRECTORY_SEPARATOR . 'syn_consumo_vcount.log',
    ];

    foreach (array_unique($candidates) as $logFile) {
        if (!is_file($logFile) || !is_readable($logFile)) {
            continue;
        }

        $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines) && $lines !== []) {
            $last = trim((string) $lines[count($lines) - 1]);
            if ($last !== '') {
                return 'Ultimo log (' . basename($logFile) . '): ' . $last;
            }
        }
    }

    return 'No se encontro log legible para esta tarea.';
}

function run_task(string $script, array $args = [], array $env = []): array {
    // Evita timeout del request web cuando la tarea tarda varios minutos.
    if (PHP_SAPI !== 'cli') {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
    }

    $t0  = microtime(true);
    $scriptPath = KLIENTEV1_PATH . DIRECTORY_SEPARATOR . 'tareas' . DIRECTORY_SEPARATOR . $script;

    if (!is_dir(KLIENTEV1_PATH)) {
        return [
            'output' => 'No existe el directorio de trabajo: ' . KLIENTEV1_PATH,
            'exit' => 1,
            'elapsed' => round(microtime(true) - $t0, 2),
        ];
    }

    if (!is_file($scriptPath)) {
        return [
            'output' => 'No existe el script de tarea: ' . $scriptPath,
            'exit' => 1,
            'elapsed' => round(microtime(true) - $t0, 2),
        ];
    }

    $phpBin = get_php_cli_binary();
    $phpBinCmd = is_file($phpBin) ? escapeshellarg($phpBin) : $phpBin;
    $cmd = $phpBinCmd . ' ' . escapeshellarg($scriptPath);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);

    $procEnv = null;
    if ($env !== []) {
        $procEnv = array_merge(getenv(), $env);
    }

    $proc = proc_open($cmd, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, KLIENTEV1_PATH, $procEnv);
    if (!is_resource($proc)) {
        return [
            'output' => 'No se pudo iniciar el proceso para ejecutar: ' . $script . ' (PHP CLI: ' . $phpBin . ')',
            'exit' => 1,
            'elapsed' => round(microtime(true) - $t0, 2),
        ];
    }

    $out  = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);

    $out = preg_replace('/\x1B\[[0-9;]*[mGKHF]/u', '', $out); // strip ANSI
    $out = trim((string) $out);
    if ($out === '') {
        $out = 'Sin salida en consola. Exit=' . $code . '. ' . read_last_log_line($script);
    }

    return ['output' => $out, 'exit' => $code, 'elapsed' => round(microtime(true) - $t0, 2)];
}
