<?php
/**
 * Ejecuta cws/sql/store_employees_audit_columns.sql contra STORE_EMPLOYEES_SQL_* (.env).
 * Uso: php cws/scripts/run_store_employees_audit_sql.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../dat_cws.php';

$host = trim(cws_env('STORE_EMPLOYEES_SQL_HOST'));
$db = trim(cws_env('STORE_EMPLOYEES_SQL_DATABASE'));
$user = trim(cws_env('STORE_EMPLOYEES_SQL_USER'));
$pass = (string) cws_env('STORE_EMPLOYEES_SQL_PASSWORD');

if ($host === '' || $db === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "Faltan STORE_EMPLOYEES_SQL_HOST|DATABASE|USER|PASSWORD en .env\n");
    exit(1);
}

$sqlPath = __DIR__ . '/../sql/store_employees_audit_columns.sql';
if (!is_readable($sqlPath)) {
    fwrite(STDERR, "No se encuentra: {$sqlPath}\n");
    exit(1);
}

$raw = file_get_contents($sqlPath);
if ($raw === false) {
    fwrite(STDERR, "No se pudo leer el SQL\n");
    exit(1);
}

$batches = preg_split('/^\s*GO\s*$/mi', $raw) ?: [];
$batches = array_values(array_filter(array_map('trim', $batches), static fn (string $s): bool => $s !== ''));

if (function_exists('sqlsrv_configure')) {
    call_user_func('sqlsrv_configure', 'WarningsReturnAsErrors', 0);
}

$conn = sqlsrv_connect($host, [
    'Database' => $db,
    'UID' => $user,
    'PWD' => $pass,
    'MultipleActiveResultSets' => true,
    'TrustServerCertificate' => 'TRUE',
    'CharacterSet' => 'UTF-8',
]);

if ($conn === false) {
    fwrite(STDERR, 'sqlsrv_connect falló: ' . json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

$n = count($batches);
echo "Conectado a {$host} / {$db}. Lotes: {$n}\n";

foreach ($batches as $i => $batch) {
    $idx = $i + 1;
    $stmt = sqlsrv_query($conn, $batch);
    if ($stmt === false) {
        fwrite(STDERR, "Error en lote {$idx}/{$n}: " . json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE) . "\n");
        sqlsrv_close($conn);
        exit(1);
    }
    sqlsrv_free_stmt($stmt);
    echo "Lote {$idx}/{$n} OK\n";
}

sqlsrv_close($conn);
echo "Migración store_employees auditoría completada.\n";
