<?php
declare(strict_types=1);

function asistencias_check_db_biometrico($conn): array
{
    if ($conn === null) {
        return ['status' => 'down', 'error' => 'Sin conexión principal SQL'];
    }

    $execAtRaw = trim((string) cws_env('ASIST_PROG_EXEC_AT_LINKED_SERVER', ''));
    $useExecAt = $execAtRaw !== '';
    $start     = microtime(true);

    if ($useExecAt) {
        // El servidor de analytics tiene acceso local al DB biométrico
        $dbQ          = asistencias_quote_identifier(trim((string) cws_env('ASIST_BIOMETRICO_DATABASE', 'ElrayoBiometricos')));
        $execAtServer = asistencias_quote_identifier($execAtRaw);
        $inner        = "SELECT TOP 1 1 AS ok FROM {$dbQ}.[dbo].[Device] WITH (NOLOCK)";
        $innerEsc     = str_replace("'", "''", $inner);
        $sql          = "EXEC (N'{$innerEsc}') AT {$execAtServer}";
    } else {
        $lsQ  = asistencias_quote_identifier(trim((string) cws_env('ASIST_BIOMETRICO_LINKED_SERVER', '10.10.100.12')));
        $dbQ  = asistencias_quote_identifier(trim((string) cws_env('ASIST_BIOMETRICO_DATABASE', 'ElrayoBiometricos')));
        $sql  = "SELECT TOP 1 1 AS ok FROM {$lsQ}.{$dbQ}.[dbo].[Device] WITH (NOLOCK)";
    }

    $stmt      = @sqlsrv_query($conn, $sql);
    $latencyMs = (int) round((microtime(true) - $start) * 1000);

    if ($stmt === false) {
        return [
            'status'     => 'down',
            'latency_ms' => $latencyMs,
            'error'      => sql_error_payload(sqlsrv_errors()),
        ];
    }
    sqlsrv_free_stmt($stmt);
    return ['status' => 'up', 'latency_ms' => $latencyMs];
}

function asistencias_check_db_nomina($conn): array
{
    if ($conn === null) {
        return ['status' => 'down', 'error' => 'Sin conexión principal SQL'];
    }

    $execAtRaw = trim((string) cws_env('ASIST_PROG_EXEC_AT_LINKED_SERVER', ''));
    $useExecAt = $execAtRaw !== '';

    // Misma resolución de linked server que syn_put_asistencias.php
    $nominaLsRaw = trim((string) cws_env('ASIST_REALES_NOMINA_LINKED_SERVER', ''));
    if ($nominaLsRaw === '') {
        $nominaLsRaw = $useExecAt
            ? trim((string) cws_env('ASIST_PROG_NOMINA_LINKED_SERVER', '210.17.1.24'))
            : trim((string) cws_env('ASIST_NOMINA_LINKED_SERVER', '210.17.1.24'));
        if ($nominaLsRaw === '') {
            $nominaLsRaw = '210.17.1.24';
        }
    }

    $nominaLsQ = asistencias_quote_identifier($nominaLsRaw);
    $nominaDbQ = asistencias_quote_identifier(trim((string) cws_env('ASIST_NOMINA_DATABASE', 'BDDNOMINAMABEL19')));
    $start     = microtime(true);

    if ($useExecAt) {
        $execAtServer = asistencias_quote_identifier($execAtRaw);
        $inner        = "SELECT TOP 1 1 AS ok FROM {$nominaLsQ}.{$nominaDbQ}.[dbo].[Tbl_DatosPersonales] WITH (NOLOCK)";
        $innerEsc     = str_replace("'", "''", $inner);
        $sql          = "EXEC (N'{$innerEsc}') AT {$execAtServer}";
    } else {
        $sql = "SELECT TOP 1 1 AS ok FROM {$nominaLsQ}.{$nominaDbQ}.[dbo].[Tbl_DatosPersonales] WITH (NOLOCK)";
    }

    $stmt      = @sqlsrv_query($conn, $sql);
    $latencyMs = (int) round((microtime(true) - $start) * 1000);

    if ($stmt === false) {
        return [
            'status'     => 'down',
            'latency_ms' => $latencyMs,
            'error'      => sql_error_payload(sqlsrv_errors()),
        ];
    }
    sqlsrv_free_stmt($stmt);
    return ['status' => 'up', 'latency_ms' => $latencyMs];
}

function asistencias_check_koach360_auth(): array
{
    $start = microtime(true);
    // forceRefresh=true para probar la API real, no el caché estático
    $token     = koach360_obtener_token(null, null, null, true);
    $latencyMs = (int) round((microtime(true) - $start) * 1000);

    if ($token === null || $token === '') {
        return ['status' => 'down', 'latency_ms' => $latencyMs];
    }
    return ['status' => 'up', 'latency_ms' => $latencyMs];
}

function asistencias_check_last_sync(): array
{
    $entry = asistencias_repo_last_log_entry();
    if ($entry === null) {
        return [
            'status'     => 'unknown',
            'timestamp'  => null,
            'records'    => null,
            'no_records' => null,
        ];
    }
    return [
        'status'     => $entry['status'],
        'timestamp'  => $entry['timestamp'],
        'records'    => $entry['records'],
        'no_records' => $entry['no_records'],
    ];
}

function asistencias_service_logs(int $limit = 30): array
{
    return [
        'status'  => 200,
        'payload' => [
            'ok'      => true,
            'service' => 'asistencias',
            'entries' => asistencias_repo_log_entries($limit),
        ],
    ];
}

function asistencias_service_health($conn): array
{
    $dbBiometrico = asistencias_check_db_biometrico($conn);
    $dbNomina     = asistencias_check_db_nomina($conn);
    $koach        = asistencias_check_koach360_auth();
    $lastSync     = asistencias_check_last_sync();

    $allUp = $dbBiometrico['status'] === 'up'
          && $dbNomina['status'] === 'up'
          && $koach['status'] === 'up';

    return [
        'status'  => $allUp ? 200 : 503,
        'payload' => [
            'ok'      => $allUp,
            'service' => 'asistencias',
            'status'  => $allUp ? 'up' : 'degraded',
            'checks'  => [
                'db_biometrico' => $dbBiometrico,
                'db_nomina'     => $dbNomina,
                'koach360_auth' => $koach,
                'last_sync'     => $lastSync,
            ],
        ],
    ];
}
