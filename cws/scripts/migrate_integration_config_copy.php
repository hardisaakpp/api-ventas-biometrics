<?php
/**
 * Copia tablas de configuración de integración KOACH360 entre SQL Servers:
 *   - dbo.integration_client_config
 *   - dbo.integration_store_scope
 *
 * Usa mismas credenciales de migración que migrate_store_employees_copy.php:
 *   STORE_EMPLOYEES_MIGRATE_SOURCE_HOST, _DATABASE, _USER, _PASSWORD
 *   STORE_EMPLOYEES_MIGRATE_DEST_HOST, _DATABASE, _USER, _PASSWORD
 * Si MIGRATE_DEST_* no existe, usa STORE_EMPLOYEES_SQL_* como destino.
 *
 * Uso:
 *   php cws/scripts/migrate_integration_config_copy.php
 *   php cws/scripts/migrate_integration_config_copy.php --force
 *
 * --force:
 *   borra filas en destino de cada tabla antes de insertar.
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

function cfg_env(string $key, string $fallback = ''): string
{
    return trim((string) cws_env($key, $fallback));
}

function sql_connect_or_exit(string $host, string $db, string $user, string $pass)
{
    $cx = sqlsrv_connect($host, [
        'Database' => $db,
        'UID' => $user,
        'PWD' => $pass,
        'MultipleActiveResultSets' => true,
        'TrustServerCertificate' => true,
        'CharacterSet' => 'UTF-8',
    ]);
    if ($cx === false) {
        fwrite(STDERR, 'sqlsrv_connect falló: ' . json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }
    return $cx;
}

function table_exists($cx, string $table): bool
{
    $st = sqlsrv_query(
        $cx,
        "SELECT CASE WHEN OBJECT_ID(?, 'U') IS NULL THEN 0 ELSE 1 END AS ok",
        [$table]
    );
    if ($st === false) {
        fwrite(STDERR, 'Error validando tabla ' . $table . ': ' . json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }
    $row = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($st);
    return ((int) ($row['ok'] ?? 0)) === 1;
}

function table_count($cx, string $table): int
{
    $sql = "SELECT COUNT(1) AS c FROM {$table}";
    $st = sqlsrv_query($cx, $sql);
    if ($st === false) {
        fwrite(STDERR, 'Error contando ' . $table . ': ' . json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }
    $row = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($st);
    return (int) ($row['c'] ?? 0);
}

function insertable_columns($cx, string $table): array
{
    $sql = "
        SELECT c.name
        FROM sys.columns c
        WHERE c.object_id = OBJECT_ID(?)
          AND c.is_identity = 0
          AND c.is_computed = 0
        ORDER BY c.column_id
    ";
    $st = sqlsrv_query($cx, $sql, [$table]);
    if ($st === false) {
        fwrite(STDERR, 'Error leyendo columnas de ' . $table . ': ' . json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }

    $cols = [];
    while ($row = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name !== '') {
            $cols[] = $name;
        }
    }
    sqlsrv_free_stmt($st);
    return $cols;
}

function quote_ident(string $name): string
{
    return '[' . str_replace(']', ']]', $name) . ']';
}

function source_table_columns($cx, string $table): array
{
    $sql = "
        SELECT
            c.column_id,
            c.name AS column_name,
            t.name AS type_name,
            c.max_length,
            c.precision,
            c.scale,
            c.is_nullable,
            c.is_identity
        FROM sys.columns c
        INNER JOIN sys.types t
            ON c.user_type_id = t.user_type_id
        WHERE c.object_id = OBJECT_ID(?)
        ORDER BY c.column_id
    ";
    $st = sqlsrv_query($cx, $sql, [$table]);
    if ($st === false) {
        fwrite(STDERR, 'Error leyendo metadatos de ' . $table . ': ' . json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
    sqlsrv_free_stmt($st);
    return $rows;
}

function sql_type_decl(array $c): string
{
    $type = strtolower((string) ($c['type_name'] ?? ''));
    $maxLength = (int) ($c['max_length'] ?? 0);
    $precision = (int) ($c['precision'] ?? 0);
    $scale = (int) ($c['scale'] ?? 0);

    if (in_array($type, ['varchar', 'char', 'varbinary', 'binary'], true)) {
        $len = $maxLength === -1 ? 'MAX' : (string) $maxLength;
        return $type . '(' . $len . ')';
    }
    if (in_array($type, ['nvarchar', 'nchar'], true)) {
        $len = $maxLength === -1 ? 'MAX' : (string) (int) ($maxLength / 2);
        return $type . '(' . $len . ')';
    }
    if (in_array($type, ['decimal', 'numeric'], true)) {
        return $type . '(' . $precision . ',' . $scale . ')';
    }
    if (in_array($type, ['datetime2', 'datetimeoffset', 'time'], true)) {
        return $type . '(' . $scale . ')';
    }
    return $type;
}

function create_table_like_source($srcCx, $dstCx, string $table): void
{
    $cols = source_table_columns($srcCx, $table);
    if ($cols === []) {
        fwrite(STDERR, 'No se pudo inferir columnas para crear ' . $table . "\n");
        exit(1);
    }

    $defs = [];
    foreach ($cols as $c) {
        $name = quote_ident((string) $c['column_name']);
        $typeDecl = sql_type_decl($c);
        $nullable = ((int) ($c['is_nullable'] ?? 0)) === 1 ? 'NULL' : 'NOT NULL';
        $identity = ((int) ($c['is_identity'] ?? 0)) === 1 ? ' IDENTITY(1,1)' : '';
        $defs[] = $name . ' ' . $typeDecl . $identity . ' ' . $nullable;
    }

    $sql = "CREATE TABLE {$table} (\n    " . implode(",\n    ", $defs) . "\n);";
    $st = sqlsrv_query($dstCx, $sql);
    if ($st === false) {
        fwrite(STDERR, 'Error creando ' . $table . ' en destino: ' . json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }
    sqlsrv_free_stmt($st);
    fwrite(STDOUT, "{$table}: tabla creada en destino desde metadatos de origen.\n");
}

$srcHost = cfg_env('STORE_EMPLOYEES_MIGRATE_SOURCE_HOST');
$srcDb = cfg_env('STORE_EMPLOYEES_MIGRATE_SOURCE_DATABASE');
$srcUser = cfg_env('STORE_EMPLOYEES_MIGRATE_SOURCE_USER');
$srcPass = (string) cws_env('STORE_EMPLOYEES_MIGRATE_SOURCE_PASSWORD', '');

$dstHost = cfg_env('STORE_EMPLOYEES_MIGRATE_DEST_HOST', cfg_env('STORE_EMPLOYEES_SQL_HOST'));
$dstDb = cfg_env('STORE_EMPLOYEES_MIGRATE_DEST_DATABASE', cfg_env('STORE_EMPLOYEES_SQL_DATABASE'));
$dstUser = cfg_env('STORE_EMPLOYEES_MIGRATE_DEST_USER', cfg_env('STORE_EMPLOYEES_SQL_USER'));
$dstPass = (string) cws_env('STORE_EMPLOYEES_MIGRATE_DEST_PASSWORD', cws_env('STORE_EMPLOYEES_SQL_PASSWORD'));

if ($srcHost === '' || $srcDb === '' || $srcUser === '') {
    fwrite(STDERR, "Defina STORE_EMPLOYEES_MIGRATE_SOURCE_HOST, SOURCE_DATABASE, SOURCE_USER y SOURCE_PASSWORD en .env\n");
    exit(1);
}
if ($dstHost === '' || $dstDb === '' || $dstUser === '') {
    fwrite(STDERR, "Defina destino (MIGRATE_DEST_* o STORE_EMPLOYEES_SQL_*).\n");
    exit(1);
}

$src = sql_connect_or_exit($srcHost, $srcDb, $srcUser, $srcPass);
$dst = sql_connect_or_exit($dstHost, $dstDb, $dstUser, $dstPass);

$tables = [
    'dbo.integration_client_config',
    'dbo.integration_store_scope',
];

foreach ($tables as $table) {
    if (!table_exists($src, $table)) {
        fwrite(STDERR, "No existe {$table} en origen {$srcHost}/{$srcDb}.\n");
        sqlsrv_close($src);
        sqlsrv_close($dst);
        exit(1);
    }
    if (!table_exists($dst, $table)) {
        fwrite(STDOUT, "No existe {$table} en destino {$dstHost}/{$dstDb}. Se creará automáticamente.\n");
        create_table_like_source($src, $dst, $table);
    }

    $colsSrc = insertable_columns($src, $table);
    $colsDst = insertable_columns($dst, $table);
    $cols = array_values(array_intersect($colsDst, $colsSrc));

    if ($cols === []) {
        fwrite(STDERR, "No hay columnas insertables comunes para {$table}.\n");
        sqlsrv_close($src);
        sqlsrv_close($dst);
        exit(1);
    }

    $selectCols = implode(', ', array_map('quote_ident', $cols));
    $sqlRead = "SELECT {$selectCols} FROM {$table}";
    $stRead = sqlsrv_query($src, $sqlRead);
    if ($stRead === false) {
        fwrite(STDERR, 'Error leyendo origen ' . $table . ': ' . json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE) . "\n");
        sqlsrv_close($src);
        sqlsrv_close($dst);
        exit(1);
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($stRead, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stRead);

    $countSrc = count($rows);
    $countDst = table_count($dst, $table);
    fwrite(STDOUT, "{$table}: origen={$countSrc}, destino_actual={$countDst}\n");

    if ($countDst > 0 && !$force) {
        fwrite(STDERR, "{$table}: destino tiene datos. Use --force para reemplazar.\n");
        sqlsrv_close($src);
        sqlsrv_close($dst);
        exit(2);
    }

    if (!sqlsrv_begin_transaction($dst)) {
        fwrite(STDERR, "No se pudo iniciar transacción en destino.\n");
        sqlsrv_close($src);
        sqlsrv_close($dst);
        exit(1);
    }

    $ok = true;
    if ($force) {
        $del = sqlsrv_query($dst, "DELETE FROM {$table}");
        if ($del === false) {
            $ok = false;
        } else {
            sqlsrv_free_stmt($del);
        }
    }

    if ($ok && $countSrc > 0) {
        $insertCols = implode(', ', array_map('quote_ident', $cols));
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $sqlInsert = "INSERT INTO {$table} ({$insertCols}) VALUES ({$placeholders})";

        foreach ($rows as $row) {
            $params = [];
            foreach ($cols as $c) {
                $params[] = $row[$c] ?? null;
            }
            $stIns = sqlsrv_query($dst, $sqlInsert, $params);
            if ($stIns === false) {
                $ok = false;
                break;
            }
            sqlsrv_free_stmt($stIns);
        }
    }

    if (!$ok) {
        sqlsrv_rollback($dst);
        fwrite(STDERR, "{$table}: error insertando. Rollback aplicado. " . json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE) . "\n");
        sqlsrv_close($src);
        sqlsrv_close($dst);
        exit(1);
    }

    sqlsrv_commit($dst);
    $newCount = table_count($dst, $table);
    fwrite(STDOUT, "{$table}: migración OK, filas_destino={$newCount}\n");
}

sqlsrv_close($src);
sqlsrv_close($dst);
fwrite(STDOUT, "Listo: migración de configuración completada.\n");
exit(0);

