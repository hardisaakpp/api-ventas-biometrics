<?php
require_once __DIR__ . '/../dat_cws.php';
require_once __DIR__ . '/../f_store_scope.php';

date_default_timezone_set('America/Guayaquil');

function connect_sql(string $host, string $db, string $user, string $pass, bool $encrypt = true)
{
    if ($host === '' || $db === '' || $user === '') {
        return null;
    }
    $opts = [
        'Database' => $db,
        'UID' => $user,
        'PWD' => $pass,
        'MultipleActiveResultSets' => true,
        'TrustServerCertificate' => true,
        'CharacterSet' => 'UTF-8',
    ];
    if ($encrypt) {
        $opts['Encrypt'] = 'yes';
    }
    $conn = @sqlsrv_connect($host, $opts);
    return $conn === false ? null : $conn;
}

$sapHost = trim((string) cws_env('SQL_SERVER_SAP_SERVER'));
$sapDb = trim((string) cws_env('SQL_SERVER_SAP_DATABASE', 'SCMaestros'));
$sapUser = trim((string) cws_env('SQL_SERVER_SAP_UID'));
$sapPass = (string) cws_env('SQL_SERVER_SAP_PWD');
$sapEncrypt = trim((string) cws_env('SQL_SERVER_SAP_ENCRYPT', '1')) !== '0';

$cfgHost = trim((string) cws_env('STORE_EMPLOYEES_SQL_HOST', '210.17.1.54'));
$cfgDb = trim((string) cws_env('STORE_EMPLOYEES_SQL_DATABASE', 'store_control'));
$cfgUser = trim((string) cws_env('STORE_EMPLOYEES_SQL_USER'));
$cfgPass = (string) cws_env('STORE_EMPLOYEES_SQL_PASSWORD');

$sap = connect_sql($sapHost, $sapDb, $sapUser, $sapPass, $sapEncrypt);
if ($sap === null) {
    fwrite(STDERR, "store_scope_sync_from_sap: no se pudo conectar a SAP.\n");
    exit(1);
}
$cfg = connect_sql($cfgHost, $cfgDb, $cfgUser, $cfgPass, false);
if ($cfg === null) {
    sqlsrv_close($sap);
    fwrite(STDERR, "store_scope_sync_from_sap: no se pudo conectar a BD de configuración.\n");
    exit(1);
}

$fase1 = array_fill_keys(store_scope_fallback_codes(), true);

$qSap = "
    SELECT cod_almacen, nombre
    FROM dbo.almacenes
    WHERE inactivo = 0
      AND cod_almacen IS NOT NULL
      AND LTRIM(RTRIM(cod_almacen)) <> ''
    ORDER BY cod_almacen;
";
$stSap = sqlsrv_query($sap, $qSap);
if ($stSap === false) {
    sqlsrv_close($cfg);
    sqlsrv_close($sap);
    fwrite(STDERR, "store_scope_sync_from_sap: error leyendo dbo.almacenes.\n");
    exit(1);
}

$rows = [];
while ($r = sqlsrv_fetch_array($stSap, SQLSRV_FETCH_ASSOC)) {
    $code = strtoupper(trim((string) ($r['cod_almacen'] ?? '')));
    $name = trim((string) ($r['nombre'] ?? ''));
    if ($code === '' || !preg_match('/^[A-Z0-9._\\-]+$/', $code)) {
        continue;
    }
    $rows[$code] = $name;
}
sqlsrv_free_stmt($stSap);
sqlsrv_close($sap);

$sql = "
    MERGE dbo.integration_store_scope AS tgt
    USING (SELECT ? AS codigo_tienda, ? AS nombre_tienda, ? AS integration, ? AS es_fase1, CAST(1 AS bit) AS activo) AS src
       ON UPPER(tgt.codigo_tienda) = UPPER(src.codigo_tienda)
      AND UPPER(tgt.integration) = UPPER(src.integration)
    WHEN MATCHED THEN
       UPDATE SET nombre_tienda = src.nombre_tienda, es_fase1 = src.es_fase1, activo = src.activo, updated_at = SYSUTCDATETIME()
    WHEN NOT MATCHED THEN
       INSERT (codigo_tienda, nombre_tienda, integration, es_fase1, activo)
       VALUES (src.codigo_tienda, src.nombre_tienda, src.integration, src.es_fase1, src.activo);
";

$integrations = ['ALL', 'KOACH360_VENTAS', 'KOACH360_ASISTENCIAS', 'KOACH360_ASISTENCIAS_PROGRAMADAS'];
$total = 0;
foreach ($rows as $code => $name) {
    $isFase1 = isset($fase1[$code]) ? 1 : 0;
    foreach ($integrations as $integration) {
        $ok = sqlsrv_query($cfg, $sql, [$code, $name, $integration, $isFase1]);
        if ($ok !== false) {
            $total++;
        }
    }
}
sqlsrv_close($cfg);

fwrite(STDOUT, date('Y-m-d H:i:s') . " store_scope_sync_from_sap: upserts={$total} tiendas=" . count($rows) . "\n");
