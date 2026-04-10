<?php
/**
 * Copia filas de dbo.store_employees desde un servidor SQL (ej. pruebas 210.17.1.54)
 * hacia otro (ej. producción 192.168.2.211 / SCOdcsap).
 *
 * Requisitos: extensión sqlsrv, red a ambos hosts, tabla creada en destino
 * (ver cws/sql/scodcsap_store_employees_install.sql).
 *
 * Variables (opcionales; si faltan destino, usa STORE_EMPLOYEES_SQL_* del .env):
 *   STORE_EMPLOYEES_MIGRATE_SOURCE_HOST, _DATABASE, _USER, _PASSWORD
 *   STORE_EMPLOYEES_MIGRATE_DEST_HOST, _DATABASE, _USER, _PASSWORD
 *
 * Uso:
 *   php cws/scripts/migrate_store_employees_copy.php
 *   php cws/scripts/migrate_store_employees_copy.php --force   (borra datos en destino antes de copiar)
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
chdir($root);

require_once $root . '/cws/dat_cws.php';

if (!function_exists('sqlsrv_connect')) {
    fwrite(STDERR, "Extensión sqlsrv no disponible.\n");
    exit(1);
}

$force = in_array('--force', $argv, true);

function migrate_env(string $key, string $fallback = ''): string
{
    $v = trim((string) cws_env($key, $fallback));
    return $v;
}

$srcHost = migrate_env('STORE_EMPLOYEES_MIGRATE_SOURCE_HOST');
$srcDb = migrate_env('STORE_EMPLOYEES_MIGRATE_SOURCE_DATABASE');
$srcUser = migrate_env('STORE_EMPLOYEES_MIGRATE_SOURCE_USER');
$srcPass = (string) cws_env('STORE_EMPLOYEES_MIGRATE_SOURCE_PASSWORD', '');

$dstHost = migrate_env('STORE_EMPLOYEES_MIGRATE_DEST_HOST', migrate_env('STORE_EMPLOYEES_SQL_HOST'));
$dstDb = migrate_env('STORE_EMPLOYEES_MIGRATE_DEST_DATABASE', migrate_env('STORE_EMPLOYEES_SQL_DATABASE'));
$dstUser = migrate_env('STORE_EMPLOYEES_MIGRATE_DEST_USER', migrate_env('STORE_EMPLOYEES_SQL_USER'));
$dstPass = (string) cws_env('STORE_EMPLOYEES_MIGRATE_DEST_PASSWORD', cws_env('STORE_EMPLOYEES_SQL_PASSWORD'));

if ($srcHost === '' || $srcDb === '' || $srcUser === '') {
    fwrite(STDERR, "Defina STORE_EMPLOYEES_MIGRATE_SOURCE_HOST, SOURCE_DATABASE, SOURCE_USER y SOURCE_PASSWORD en .env\n");
    exit(1);
}
if ($dstHost === '' || $dstDb === '' || $dstUser === '') {
    fwrite(STDERR, "Defina destino (MIGRATE_DEST_* o STORE_EMPLOYEES_SQL_*).\n");
    exit(1);
}

$opts = [
    'MultipleActiveResultSets' => true,
    'TrustServerCertificate' => true,
    'CharacterSet' => 'UTF-8',
];

function sql_connect(string $host, string $db, string $user, string $pass, array $baseOpts)
{
    $o = $baseOpts + [
        'Database' => $db,
        'UID' => $user,
        'PWD' => $pass,
    ];
    $cx = sqlsrv_connect($host, $o);
    if ($cx === false) {
        fwrite(STDERR, 'sqlsrv_connect falló: ' . json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }
    return $cx;
}

$src = sql_connect($srcHost, $srcDb, $srcUser, $srcPass, $opts);
$dst = sql_connect($dstHost, $dstDb, $dstUser, $dstPass, $opts);

$sqlSelect = <<<'SQL'
SELECT cedula_identidad, correo, nombre, cargo, codigo_tienda,
       CAST(estado AS tinyint) AS estado, CAST(encargado AS tinyint) AS encargado,
       fecha_ingreso, fecha_ultima_actualizacion, usuario_ultima_actualizacion
FROM dbo.store_employees
ORDER BY id
SQL;

$stmt = sqlsrv_query($src, $sqlSelect);
if ($stmt === false) {
    fwrite(STDERR, 'Lectura origen: ' . json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

$rows = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $rows[] = $row;
}
sqlsrv_free_stmt($stmt);
sqlsrv_close($src);

$n = count($rows);
fwrite(STDOUT, "Origen {$srcHost}/{$srcDb}: {$n} filas.\n");

if ($n === 0) {
    fwrite(STDOUT, "Nada que copiar.\n");
    sqlsrv_close($dst);
    exit(0);
}

$checkStmt = sqlsrv_query($dst, 'SELECT COUNT(1) AS c FROM dbo.store_employees');
if ($checkStmt === false) {
    fwrite(STDERR, '¿Existe dbo.store_employees en destino? Ejecute cws/sql/scodcsap_store_employees_install.sql. ' .
        json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}
$cr = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($checkStmt);
$existing = (int) ($cr['c'] ?? 0);

if ($existing > 0 && !$force) {
    fwrite(STDERR, "Destino {$dstHost}/{$dstDb} ya tiene {$existing} filas. Use --force para truncar y volver a copiar.\n");
    sqlsrv_close($dst);
    exit(2);
}

if ($force) {
    $trunc = sqlsrv_query($dst, 'DELETE FROM dbo.store_employees;');
    if ($trunc === false) {
        fwrite(STDERR, 'DELETE destino: ' . json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }
    sqlsrv_free_stmt($trunc);
    fwrite(STDOUT, "Destino vaciado (--force).\n");
}

$sqlInsert = <<<'SQL'
INSERT INTO dbo.store_employees (
    cedula_identidad, correo, nombre, cargo, codigo_tienda, estado, encargado,
    fecha_ingreso, fecha_ultima_actualizacion, usuario_ultima_actualizacion
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL;

$inserted = 0;
foreach ($rows as $row) {
    $params = [
        $row['cedula_identidad'] ?? null,
        $row['correo'] ?? null,
        $row['nombre'] ?? null,
        $row['cargo'] ?? null,
        $row['codigo_tienda'] ?? null,
        (int) ($row['estado'] ?? 1) ? 1 : 0,
        (int) ($row['encargado'] ?? 0) ? 1 : 0,
        $row['fecha_ingreso'] ?? null,
        $row['fecha_ultima_actualizacion'] ?? null,
        $row['usuario_ultima_actualizacion'] ?? 'API_STORE_EMPLOYEES',
    ];
    if ($params[7] === null) {
        $params[7] = new DateTime();
    }
    if ($params[8] === null) {
        $params[8] = new DateTime();
    }
    $st = sqlsrv_query($dst, $sqlInsert, $params);
    if ($st === false) {
        fwrite(STDERR, 'Error insertando cédula ' . ($row['cedula_identidad'] ?? '?') . ': ' .
            json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE) . "\n");
        sqlsrv_close($dst);
        exit(1);
    }
    sqlsrv_free_stmt($st);
    $inserted++;
}

sqlsrv_close($dst);
fwrite(STDOUT, "Listo: {$inserted} filas insertadas en {$dstHost}/{$dstDb}.\n");
exit(0);
