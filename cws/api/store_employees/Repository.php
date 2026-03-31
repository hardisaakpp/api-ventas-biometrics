<?php
declare(strict_types=1);

function store_employees_repo_format_row(array $row): array
{
    foreach ($row as $k => $v) {
        if ($v instanceof DateTime) {
            $row[$k] = $v->format('Y-m-d H:i:s');
        }
    }
    $row['estado'] = isset($row['estado']) ? (int) $row['estado'] : 0;
    $row['encargado'] = isset($row['encargado']) ? (int) $row['encargado'] : 0;
    return $row;
}

function store_employees_repo_fetch_almacenes_catalogo($conn, string $like, bool $almacenEnBdActual = false): array
{
    $from = $almacenEnBdActual ? 'dbo.almacenes' : store_almacen_table_qualified();
    $sql = 'SELECT TOP (1000) a.cod_almacen, a.nombre, a.inactivo, a.marca
            FROM ' . $from . ' a
            WHERE a.nombre LIKE ?
              AND a.inactivo = 0
              AND a.cod_almacen NOT IN (?, ?, ?)
            ORDER BY a.nombre';
    $stmt = sqlsrv_query($conn, $sql, [$like, 'RL-CD', 'RLCD-CA', 'RLCD-NV']);
    if ($stmt === false) {
        json_response(500, ['ok' => false, 'error' => sql_error_payload(sqlsrv_errors())]);
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $nombre = isset($row['nombre']) ? trim((string) $row['nombre']) : '';
        $cod = isset($row['cod_almacen']) ? trim((string) $row['cod_almacen']) : '';
        $rows[] = [
            'id' => $row['id'] ?? null,
            'cod_almacen' => $cod,
            'nombre' => $nombre,
            'label' => $nombre,
            'marca' => isset($row['marca']) ? trim((string) $row['marca']) : null,
        ];
    }
    sqlsrv_free_stmt($stmt);
    return $rows;
}

function store_employees_repo_fetch_one_by_id($conn, int $id): ?array
{
    $sql = 'SELECT id, cedula_identidad, correo, nombre, cargo, codigo_tienda, estado, encargado, fecha_actual, usuario_actual
            FROM ' . store_employees_table() . '
            WHERE id = ?';
    $stmt = sqlsrv_query($conn, $sql, [$id]);
    if ($stmt === false) {
        json_response(500, ['ok' => false, 'error' => sql_error_payload(sqlsrv_errors())]);
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    if (!$row) {
        return null;
    }
    return store_employees_repo_format_row($row);
}

function store_employees_repo_cedula_exists($conn, string $cedula, ?int $excludeId = null): bool
{
    $sql = 'SELECT TOP 1 id FROM ' . store_employees_table() . ' WHERE cedula_identidad = ?';
    $params = [$cedula];
    if ($excludeId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeId;
    }

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        json_response(500, ['ok' => false, 'error' => sql_error_payload(sqlsrv_errors())]);
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return is_array($row);
}

function store_employees_repo_encargado_activo_exists_por_tienda($conn, string $codigoTienda, ?int $excludeId = null): bool
{
    $sql = 'SELECT TOP 1 id
            FROM ' . store_employees_table() . '
            WHERE codigo_tienda = ? AND estado = 1 AND encargado = 1';
    $params = [$codigoTienda];
    if ($excludeId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeId;
    }

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        json_response(500, ['ok' => false, 'error' => sql_error_payload(sqlsrv_errors())]);
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return is_array($row);
}

function store_employees_repo_list($conn, array $where, array $params, int $offset, int $pageSize): array
{
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countSql = 'SELECT COUNT(1) AS total FROM ' . store_employees_table() . ' ' . $whereSql;
    $countStmt = sqlsrv_query($conn, $countSql, $params);
    if ($countStmt === false) {
        json_response(500, ['ok' => false, 'error' => sql_error_payload(sqlsrv_errors())]);
    }
    $countRow = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($countStmt);
    $total = (int) ($countRow['total'] ?? 0);

    $listSql = 'SELECT id, cedula_identidad, correo, nombre, cargo, codigo_tienda, estado, encargado, fecha_actual, usuario_actual
                FROM ' . store_employees_table() . '
                ' . $whereSql . '
                ORDER BY id DESC
                OFFSET ? ROWS FETCH NEXT ? ROWS ONLY';
    $listParams = array_merge($params, [$offset, $pageSize]);
    $listStmt = sqlsrv_query($conn, $listSql, $listParams);
    if ($listStmt === false) {
        json_response(500, ['ok' => false, 'error' => sql_error_payload(sqlsrv_errors())]);
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($listStmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = store_employees_repo_format_row($row);
    }
    sqlsrv_free_stmt($listStmt);

    return [
        'total' => $total,
        'rows' => $rows,
    ];
}

function store_employees_repo_insert($conn, array $params): int
{
    $sql = 'INSERT INTO ' . store_employees_table() . '
            (cedula_identidad, correo, nombre, cargo, codigo_tienda, estado, encargado, fecha_actual, usuario_actual)
            VALUES (?, ?, ?, ?, ?, ?, ?, SYSDATETIME(), ?)';
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        if (is_unique_violation($errors)) {
            json_response(409, [
                'ok' => false,
                'error' => 'Ya existe un registro con la misma cédula',
                'field' => 'cedula_identidad',
            ]);
        }
        json_response(409, ['ok' => false, 'error' => sql_error_payload($errors)]);
    }
    sqlsrv_free_stmt($stmt);

    $idStmt = sqlsrv_query($conn, 'SELECT CAST(SCOPE_IDENTITY() AS BIGINT) AS id');
    if ($idStmt === false) {
        json_response(500, ['ok' => false, 'error' => sql_error_payload(sqlsrv_errors())]);
    }
    $idRow = sqlsrv_fetch_array($idStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($idStmt);
    return (int) ($idRow['id'] ?? 0);
}

function store_employees_repo_update_by_id($conn, int $id, array $fields, array $params): void
{
    $sql = 'UPDATE ' . store_employees_table() . ' SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $params[] = $id;
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        if (is_unique_violation($errors)) {
            json_response(409, [
                'ok' => false,
                'error' => 'Ya existe un registro con la misma cédula',
                'field' => 'cedula_identidad',
            ]);
        }
        json_response(409, ['ok' => false, 'error' => sql_error_payload($errors)]);
    }
    sqlsrv_free_stmt($stmt);
}

function store_employees_repo_soft_delete($conn, int $id, string $actor): void
{
    $sql = 'UPDATE ' . store_employees_table() . '
            SET estado = 0, encargado = 0, fecha_actual = SYSDATETIME(), usuario_actual = ?
            WHERE id = ?';
    $stmt = sqlsrv_query($conn, $sql, [$actor, $id]);
    if ($stmt === false) {
        json_response(500, ['ok' => false, 'error' => sql_error_payload(sqlsrv_errors())]);
    }
    sqlsrv_free_stmt($stmt);
}
