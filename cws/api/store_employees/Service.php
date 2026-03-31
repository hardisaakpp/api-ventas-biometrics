<?php
declare(strict_types=1);

function store_employees_service_health($conn): array
{
    $stmt = sqlsrv_query($conn, 'SELECT 1 AS ok');
    if ($stmt === false) {
        return [
            'status' => 503,
            'payload' => [
                'ok' => false,
                'service' => 'store_employees_api',
                'status' => 'down',
                'error' => sql_error_payload(sqlsrv_errors()),
            ],
        ];
    }
    sqlsrv_free_stmt($stmt);
    return [
        'status' => 200,
        'payload' => [
            'ok' => true,
            'service' => 'store_employees_api',
            'status' => 'up',
        ],
    ];
}

function store_employees_service_meta_almacenes($conn): array
{
    $like = trim((string) cws_env('STORE_ALMACEN_NOMBRE_LIKE', 'ROLAND%'));
    if (isset($_GET['nombre_like']) && trim((string) $_GET['nombre_like']) !== '') {
        $like = trim((string) $_GET['nombre_like']);
    }
    if (strlen($like) > 200) {
        $like = 'ROLAND%';
    }
    if (!preg_match('/^[%A-Za-z0-9_\s\-\.\p{L}]+$/u', $like)) {
        $like = 'ROLAND%';
    }

    $sapConn = store_sap_sql_connection();
    $usarSap = $sapConn !== null;
    $connAlm = $usarSap ? $sapConn : $conn;
    $rows = store_employees_repo_fetch_almacenes_catalogo($connAlm, $like, $usarSap);
    if ($usarSap) {
        sqlsrv_close($sapConn);
    }

    return [
        'ok' => true,
        'data' => $rows,
        'meta' => [
            'value_field' => 'cod_almacen',
            'label_field' => 'nombre',
            'hint' => 'En formularios use cod_almacen como valor de codigo_tienda; muestre nombre al usuario.',
            'source' => $usarSap ? 'sql_server_sap' : 'store_employees_connection',
        ],
    ];
}

function store_employees_escape_like(string $value): string
{
    return str_replace(
        ['[', '%', '_'],
        ['[[]', '[%]', '[_]'],
        $value
    );
}

function store_employees_query_tokens(string $value): array
{
    $value = preg_replace('/\s+/u', ' ', trim($value));
    if (!is_string($value) || $value === '') {
        return [];
    }
    return preg_split('/\s+/u', $value) ?: [];
}

function store_employees_service_list($conn, array $query): array
{
    $page = max(1, (int) ($query['page'] ?? 1));
    $pageSize = (int) ($query['page_size'] ?? 50);
    $pageSize = max(1, min(200, $pageSize));
    $offset = ($page - 1) * $pageSize;

    $where = [];
    $params = [];

    if (isset($query['q']) && trim((string) $query['q']) !== '') {
        $qRaw = trim((string) $query['q']);
        if (strlen($qRaw) > 120) {
            $qRaw = substr($qRaw, 0, 120);
        }

        $minLen = (int) cws_env('STORE_EMPLOYEES_Q_MIN_LEN', '1');
        if ($minLen < 1) {
            $minLen = 1;
        }
        if ($minLen > 10) {
            $minLen = 10;
        }

        if (mb_strlen($qRaw, 'UTF-8') >= $minLen) {
            $searchCols = ['nombre', 'cedula_identidad', 'correo', 'cargo', 'codigo_tienda', 'usuario_actual'];
            $qOrGroups = [];
            $tokenGroups = [];
            $qParams = [];
            $tokens = store_employees_query_tokens($qRaw);

            foreach ($tokens as $token) {
                if ($token === '') {
                    continue;
                }

                $tokenLike = '%' . store_employees_escape_like($token) . '%';
                $orParts = [];
                foreach ($searchCols as $col) {
                    $orParts[] = $col . ' LIKE ?';
                    $qParams[] = $tokenLike;
                }
                $tokenGroups[] = '(' . implode(' OR ', $orParts) . ')';
            }

            if ($tokenGroups !== []) {
                $qOrGroups[] = '(' . implode(' AND ', $tokenGroups) . ')';
            }

            if (preg_match('/^\d{1,18}$/', $qRaw)) {
                $qOrGroups[] = '(id = ?)';
                $qParams[] = (int) $qRaw;
            }

            $qNorm = mb_strtolower($qRaw, 'UTF-8');
            if ($qNorm === 'activo' || $qNorm === 'activa') {
                $qOrGroups[] = '(estado = 1)';
            } elseif ($qNorm === 'inactivo' || $qNorm === 'inactiva') {
                $qOrGroups[] = '(estado = 0)';
            } elseif ($qNorm === 'encargado') {
                $qOrGroups[] = '(encargado = 1)';
            }

            if ($qOrGroups !== []) {
                $where[] = '(' . implode(' OR ', $qOrGroups) . ')';
                $params = array_merge($params, $qParams);
            }
        }
    }
    if (isset($query['codigo_tienda']) && trim((string) $query['codigo_tienda']) !== '') {
        $where[] = 'codigo_tienda = ?';
        $params[] = trim((string) $query['codigo_tienda']);
    }
    if (isset($query['cargo']) && trim((string) $query['cargo']) !== '') {
        $c = trim((string) $query['cargo']);
        if (!in_array($c, store_employees_cargos_permitidos(), true)) {
            json_cargo_invalido();
        }
        $where[] = 'cargo = ?';
        $params[] = $c;
    }
    if (isset($query['estado']) && ($query['estado'] === '0' || $query['estado'] === '1')) {
        $where[] = 'estado = ?';
        $params[] = (int) $query['estado'];
    }
    if (isset($query['encargado']) && ($query['encargado'] === '0' || $query['encargado'] === '1')) {
        $where[] = 'encargado = ?';
        $params[] = (int) $query['encargado'];
    }

    $result = store_employees_repo_list($conn, $where, $params, $offset, $pageSize);
    return [
        'ok' => true,
        'data' => $result['rows'],
        'meta' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $result['total'],
        ],
    ];
}

function store_employees_service_create($conn, array $body): array
{
    $cedula = normalize_and_validate_cedula($body['cedula_identidad'] ?? null);
    $correo = normalize_and_validate_email($body['correo'] ?? null);
    $nombre = normalize_text($body['nombre'] ?? null);
    $cargo = normalize_text($body['cargo'] ?? null);
    $codigoTienda = normalize_text($body['codigo_tienda'] ?? null);
    $estado = isset($body['estado']) ? (int) ((bool) $body['estado']) : 1;
    $encargado = isset($body['encargado']) ? (int) ((bool) $body['encargado']) : 0;
    $usuarioActual = get_actor($body);

    if ($cedula === null || $nombre === null || $cargo === null || $codigoTienda === null) {
        json_response(422, ['ok' => false, 'error' => 'Campos requeridos: cedula_identidad, nombre, cargo, codigo_tienda']);
    }

    assert_max_length('cedula_identidad', $cedula, 20);
    assert_max_length('nombre', $nombre, 200);
    assert_max_length('cargo', $cargo, 100);
    assert_max_length('codigo_tienda', $codigoTienda, 64);
    assert_max_length('usuario_actual', $usuarioActual, 64);
    assert_cargo_permitido($cargo);
    assert_encargado_coherente_con_estado($estado, $encargado);
    if ($estado === 0) {
        $encargado = 0;
    }
    if ($estado === 1 && $encargado === 1 && $codigoTienda !== null &&
        store_employees_repo_encargado_activo_exists_por_tienda($conn, $codigoTienda)) {
        json_response(409, [
            'ok' => false,
            'error' => 'Ya existe un encargado activo para esta tienda',
            'field' => 'encargado',
        ]);
    }
    if ($cedula !== null && store_employees_repo_cedula_exists($conn, $cedula)) {
        json_response(409, [
            'ok' => false,
            'error' => 'La cédula ya está registrada',
            'field' => 'cedula_identidad',
        ]);
    }

    $newId = store_employees_repo_insert($conn, [
        $cedula, $correo, $nombre, $cargo, $codigoTienda, $estado, $encargado, $usuarioActual,
    ]);
    $created = store_employees_repo_fetch_one_by_id($conn, $newId);

    return ['status' => 201, 'payload' => ['ok' => true, 'data' => $created]];
}

function store_employees_service_update($conn, array $body, int $idParam): array
{
    $id = isset($body['id']) ? (int) $body['id'] : $idParam;
    if ($id <= 0) {
        json_response(422, ['ok' => false, 'error' => 'Se requiere id']);
    }

    $current = store_employees_repo_fetch_one_by_id($conn, $id);
    if ($current === null) {
        json_response(404, ['ok' => false, 'error' => 'Registro no encontrado']);
    }

    $newEstado = array_key_exists('estado', $body) ? (int) ((bool) $body['estado']) : (int) $current['estado'];
    $newEncargado = array_key_exists('encargado', $body)
        ? (int) ((bool) $body['encargado'])
        : (int) ($current['encargado'] ?? 0);
    $newCodigoTienda = array_key_exists('codigo_tienda', $body)
        ? normalize_text((string) $body['codigo_tienda'])
        : normalize_text((string) ($current['codigo_tienda'] ?? ''));

    if ($newEstado === 0) {
        if (array_key_exists('encargado', $body) && (int) ((bool) $body['encargado']) === 1) {
            json_response(422, [
                'ok' => false,
                'error' => 'Si el empleado está inactivo, Encargado debe ser No',
            ]);
        }
        $newEncargado = 0;
    }

    if ($newEstado === 1 && $newEncargado === 1) {
        assert_required_not_null('codigo_tienda', $newCodigoTienda);
        if ($newCodigoTienda !== null &&
            store_employees_repo_encargado_activo_exists_por_tienda($conn, $newCodigoTienda, $id)) {
            json_response(409, [
                'ok' => false,
                'error' => 'Ya existe un encargado activo para esta tienda',
                'field' => 'encargado',
            ]);
        }
    }

    $fields = [];
    $params = [];
    $map = [
        'cedula_identidad' => 'cedula_identidad',
        'correo' => 'correo',
        'nombre' => 'nombre',
        'cargo' => 'cargo',
        'codigo_tienda' => 'codigo_tienda',
    ];

    foreach ($map as $input => $column) {
        if (!array_key_exists($input, $body)) {
            continue;
        }

        if ($input === 'cedula_identidad') {
            $value = normalize_and_validate_cedula((string) $body[$input]);
            assert_required_not_null('cedula_identidad', $value);
            assert_max_length('cedula_identidad', $value, 20);
            if ($value !== null && store_employees_repo_cedula_exists($conn, $value, $id)) {
                json_response(409, [
                    'ok' => false,
                    'error' => 'La cédula ya está registrada',
                    'field' => 'cedula_identidad',
                ]);
            }
            $fields[] = $column . ' = ?';
            $params[] = $value;
            continue;
        }

        if ($input === 'correo') {
            $value = normalize_and_validate_email((string) $body[$input]);
            $fields[] = $column . ' = ?';
            $params[] = $value;
            continue;
        }

        if ($input === 'cargo') {
            $value = normalize_text((string) $body[$input]);
            assert_required_not_null('cargo', $value);
            assert_max_length('cargo', $value, 100);
            assert_cargo_permitido($value);
            $fields[] = $column . ' = ?';
            $params[] = $value;
            continue;
        }

        $value = normalize_text((string) $body[$input]);
        if ($input === 'nombre') {
            assert_required_not_null('nombre', $value);
            assert_max_length('nombre', $value, 200);
        }
        if ($input === 'codigo_tienda') {
            assert_required_not_null('codigo_tienda', $value);
            assert_max_length('codigo_tienda', $value, 64);
        }
        $fields[] = $column . ' = ?';
        $params[] = $value;
    }

    if (array_key_exists('estado', $body)) {
        $fields[] = 'estado = ?';
        $params[] = $newEstado;
        $fields[] = 'encargado = ?';
        $params[] = $newEncargado;
    } elseif (array_key_exists('encargado', $body)) {
        assert_encargado_coherente_con_estado($newEstado, $newEncargado);
        $fields[] = 'encargado = ?';
        $params[] = $newEncargado;
    }

    if ($fields === []) {
        json_response(422, ['ok' => false, 'error' => 'No hay campos para actualizar']);
    }

    $fields[] = 'fecha_actual = SYSDATETIME()';
    $fields[] = 'usuario_actual = ?';
    $actor = get_actor($body);
    assert_max_length('usuario_actual', $actor, 64);
    $params[] = $actor;

    store_employees_repo_update_by_id($conn, $id, $fields, $params);
    $updated = store_employees_repo_fetch_one_by_id($conn, $id);
    return ['status' => 200, 'payload' => ['ok' => true, 'data' => $updated]];
}

function store_employees_service_delete($conn, array $body, int $idParam): array
{
    $id = isset($body['id']) ? (int) $body['id'] : $idParam;
    if ($id <= 0) {
        json_response(422, ['ok' => false, 'error' => 'Se requiere id']);
    }
    if (store_employees_repo_fetch_one_by_id($conn, $id) === null) {
        json_response(404, ['ok' => false, 'error' => 'Registro no encontrado']);
    }

    store_employees_repo_soft_delete($conn, $id, get_actor($body));
    return ['status' => 200, 'payload' => ['ok' => true, 'message' => 'Registro inactivado']];
}
