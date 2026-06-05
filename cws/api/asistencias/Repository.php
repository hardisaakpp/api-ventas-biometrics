<?php
declare(strict_types=1);

/**
 * Lee el log de syn_put_asistencias y retorna la entrada más reciente de sincronización real.
 * Ignora líneas de debug (asistDebug1 / asistDebug2).
 */
function asistencias_repo_last_log_entry(): ?array
{
    $logPath = __DIR__ . '/../../../tareas/logs/syn_put_asistencias.log';
    if (!is_readable($logPath)) {
        return null;
    }

    $content = file_get_contents($logPath);
    if ($content === false || trim($content) === '') {
        return null;
    }

    $lines = array_values(array_reverse(
        array_filter(array_map('trim', explode("\n", $content)))
    ));

    foreach ($lines as $line) {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) - asistencias filas=(\d+)/', $line, $m)) {
            continue;
        }

        $respPart = '';
        if (preg_match('/ resp=(.+)$/', $line, $rm)) {
            $respPart = $rm[1];
        }

        $isError = str_contains($respPart, '"error"')
                || preg_match('/HTTP [45]\d{2}/', $respPart) === 1;

        return [
            'timestamp'   => $m[1],
            'records'     => (int) $m[2],
            'no_records'  => str_contains($respPart, 'sin_registros'),
            'status'      => $isError ? 'error' : 'success',
            'raw_preview' => mb_substr($line, 0, 400),
        ];
    }

    return null;
}

/**
 * Retorna las últimas $n líneas del log (incluyendo debug).
 * @return string[]
 */
function asistencias_repo_recent_log_lines(int $n = 10): array
{
    $logPath = __DIR__ . '/../../../tareas/logs/syn_put_asistencias.log';
    if (!is_readable($logPath)) {
        return [];
    }

    $content = file_get_contents($logPath);
    if ($content === false || trim($content) === '') {
        return [];
    }

    $lines = array_values(array_reverse(
        array_filter(array_map('trim', explode("\n", $content)))
    ));

    return array_slice($lines, 0, $n);
}

/**
 * Parsea las últimas $limit entradas de sincronización real del log.
 * Cada entrada incluye timestamp, registros, estadísticas y advertencias.
 *
 * @return array<int, array{
 *   timestamp: string,
 *   fecha: string,
 *   records: int,
 *   lookback: int,
 *   status: string,
 *   estadisticas: array<string,int>|null,
 *   advertencias: string[]
 * }>
 */
function asistencias_repo_log_entries(int $limit = 30): array
{
    $logPath = __DIR__ . '/../../../tareas/logs/syn_put_asistencias.log';
    if (!is_readable($logPath)) {
        return [];
    }

    $content = file_get_contents($logPath);
    if ($content === false || trim($content) === '') {
        return [];
    }

    $lines = array_values(array_reverse(
        array_filter(array_map('trim', explode("\n", $content)))
    ));

    $entries = [];
    foreach ($lines as $line) {
        if (count($entries) >= $limit) {
            break;
        }

        if (!preg_match(
            '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) - asistencias filas=(\d+) hoy_ec=(\S+) lookback=(\d+) resp=(.+)$/',
            $line,
            $m
        )) {
            continue;
        }

        [, $timestamp, $filas, $hoyEc, $lookback, $respRaw] = $m;

        $resp      = json_decode($respRaw, true);
        $mensajes  = (is_array($resp) && isset($resp['mensajes']) && is_array($resp['mensajes']))
            ? $resp['mensajes']
            : [];
        $stats     = (is_array($resp) && isset($resp['estadisticas']) && is_array($resp['estadisticas']))
            ? $resp['estadisticas']
            : null;

        $lotesFallidos = is_array($stats) ? (int) ($stats['LotesFallidos'] ?? 0) : 0;
        $hasError      = $lotesFallidos > 0
            || (is_array($resp) && array_key_exists('error', $resp))
            || (!is_array($resp) && str_contains($respRaw, '"error"'));

        // Filtrar mensajes de advertencia (contienen ⚠)
        $advertencias = [];
        foreach ($mensajes as $msg) {
            if (is_string($msg) && mb_strpos($msg, "\u{26A0}") !== false) {
                $advertencias[] = trim($msg);
            }
        }

        if ($hasError) {
            $status = 'error';
        } elseif (count($advertencias) > 0) {
            $status = 'warning';
        } else {
            $status = 'success';
        }

        $entries[] = [
            'timestamp'    => $timestamp,
            'fecha'        => $hoyEc,
            'records'      => (int) $filas,
            'lookback'     => (int) $lookback,
            'status'       => $status,
            'estadisticas' => $stats,
            'advertencias' => $advertencias,
        ];
    }

    return $entries;
}
