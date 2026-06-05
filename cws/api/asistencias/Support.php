<?php
declare(strict_types=1);

if (!function_exists('json_response')) {
    function json_response(int $status, array $payload): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('sql_error_payload')) {
    function sql_error_payload($errors): array
    {
        if (!is_array($errors)) {
            return ['message' => 'Error SQL no especificado'];
        }
        $items = [];
        foreach ($errors as $error) {
            $items[] = [
                'sqlstate' => $error['SQLSTATE'] ?? null,
                'code'     => $error['code'] ?? null,
                'message'  => $error['message'] ?? 'Error SQL',
            ];
        }
        return ['message' => 'Error SQL Server', 'details' => $items];
    }
}

function asistencias_quote_identifier(string $value): string
{
    return '[' . str_replace(']', ']]', $value) . ']';
}

/**
 * Conexión segura al servidor SQL principal (mismos datos que cx(), sin die() en falla).
 * Depende de las constantes HOST, BD, USUARIO, PSW definidas por cx/dat_scs.php.
 */
function asistencias_main_connection()
{
    $host = defined('HOST')    ? (string) HOST    : '';
    $db   = defined('BD')      ? (string) BD      : '';
    $user = defined('USUARIO') ? (string) USUARIO : '';
    $pass = defined('PSW')     ? (string) PSW     : '';

    if ($host === '' || $db === '' || $user === '') {
        return null;
    }

    $conn = @sqlsrv_connect($host, [
        'Database'                 => $db,
        'UID'                      => $user,
        'PWD'                      => $pass,
        'MultipleActiveResultSets' => true,
        'TrustServerCertificate'   => 'TRUE',
        'CharacterSet'             => 'UTF-8',
    ]);

    return $conn === false ? null : $conn;
}
