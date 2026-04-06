<?php
/**
 * Muestra la instancia SQL a la que conecta PHP (SQL_SCS_*) y los linked servers que ve.
 * Útil cuando ASIST_NOMINA_LINKED_SERVER da 7202 en SSMS "sí existe" pero PHP no.
 *
 *   php cws/scripts/sql_scs_linked_servers.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../dat_cws.php';
require_once __DIR__ . '/../../cx/bd_scs.php';

$host = trim(cws_env('SQL_SCS_HOST'));
$db = trim(cws_env('SQL_SCS_DATABASE'));
$nominaLs = trim(cws_env('ASIST_NOMINA_LINKED_SERVER', ''));
if ($nominaLs === '') {
    $nominaLs = '(vacío → default 10.10.100.12 en syn_put_asistencias_prog)';
}

echo "Conexión PHP (misma que syn_put_* y ventas):\n";
echo "  SQL_SCS_HOST     = {$host}\n";
echo "  SQL_SCS_DATABASE = {$db}\n";
echo "  ASIST_NOMINA_LINKED_SERVER (.env) = {$nominaLs}\n\n";

$info = resp_simdim(
    'SELECT @@SERVERNAME AS servername, @@SERVICENAME AS servicename, DB_NAME() AS db_actual'
);
if ($info === [] || !isset($info[0])) {
    echo "No se pudo leer @@SERVERNAME (sin filas).\n";
    exit(1);
}
$row = $info[0];
echo "En esa conexión, SQL Server responde:\n";
echo '  @@SERVERNAME   = ' . ($row['servername'] ?? '?') . "\n";
echo '  @@SERVICENAME  = ' . ($row['servicename'] ?? '?') . "\n";
echo '  DB_NAME()      = ' . ($row['db_actual'] ?? '?') . "\n\n";

$linked = resp_simdim('SELECT name FROM sys.servers WHERE is_linked = 1 ORDER BY name');
echo 'Linked servers en ESTA instancia (COUNT=' . count($linked) . "):\n";
foreach ($linked as $r) {
    $n = $r['name'] ?? '';
    echo "  - {$n}\n";
}

echo "\nCompare estos nombres con la sesión de SSMS donde ejecutó SELECT name FROM sys.servers.\n";
echo "Si 210.17.1.24 no aparece arriba, PHP no está entrando al mismo motor que esa sesión.\n";
