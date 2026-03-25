<?php
/**
 * Prueba conexión SQL Server vía sqlsrv (mismo stack que syn_put_*).
 * Ejecutar desde cualquier sitio si la CWD es la raíz del repo (como en cron):
 *
 *   cd /opt/backend/kliente-v1 && php cws/scripts/check_sqlsrv.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
chdir($root);

require_once $root . '/cx/dat_scs.php';
require_once $root . '/cx/bd_scs.php';

if (!function_exists('sqlsrv_connect')) {
    fwrite(STDERR, "Extensión sqlsrv no cargada. Instalar ODBC + PECL (ver README).\n");
    exit(1);
}

$cx = cx();

$stmt = sqlsrv_query($cx, 'SELECT 1 AS ok');
if ($stmt === false) {
    fwrite(STDERR, print_r(sqlsrv_errors(), true));
    sqlsrv_close($cx);
    exit(1);
}
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);
sqlsrv_close($cx);

$ok = isset($row['ok']) ? (string) $row['ok'] : '';
echo 'OK: sqlsrv conectado a ' . HOST . ' / base ' . BD . ' (SELECT 1 => ' . $ok . ')' . PHP_EOL;
exit(0);
