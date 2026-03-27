<?php
declare(strict_types=1);

require_once __DIR__ . '/../../cx/bd_scs.php';
require_once __DIR__ . '/../dat_cws.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-User');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sql_error_payload($errors): array
{
    if (!is_array($errors)) {
        return ['message' => 'Error SQL no especificado'];
    }
    $items = [];
    foreach ($errors as $error) {
        $items[] = [
            'sqlstate' => $error['SQLSTATE'] ?? null,
            'code' => $error['code'] ?? null,
            'message' => $error['message'] ?? 'Error SQL',
        ];
    }
    return ['message' => 'Error SQL Server', 'details' => $items];
}

function get_request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_response(400, ['ok' => false, 'error' => 'JSON inválido']);
    }
    return $data;
}

function normalize_text(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $v = trim($value);
    return $v === '' ? null : $v;
}

function get_actor(array $body): string
{
    $headerUser = $_SERVER['HTTP_X_USER'] ?? '';
    $bodyUser = isset($body['usuario_actual']) ? (string) $body['usuario_actual'] : '';
    $actor = trim($bodyUser !== '' ? $bodyUser : $headerUser);
    return $actor !== '' ? $actor : 'API_STORE_EMPLOYEES';
}

function assert_max_length(string $field, ?string $value, int $max): void
{
    if ($value === null) {
        return;
    }
    if (strlen($value) > $max) {
        json_response(422, [
            'ok' => false,
            'error' => "El campo {$field} supera el máximo de {$max} caracteres",
            'field' => $field,
        ]);
    }
}

function assert_required_not_null(string $field, ?string $value): void
{
    if ($value === null) {
        json_response(422, [
            'ok' => false,
            'error' => "El campo {$field} no puede estar vacío",
            'field' => $field,
        ]);
    }
}

function normalize_and_validate_cedula(?string $cedula): ?string
{
    $cedula = normalize_text($cedula);
    if ($cedula === null) {
        return null;
    }
    // Normaliza cédula removiendo espacios y guiones.
    $cedula = str_replace([' ', '-'], '', $cedula);
    $minLen = (int) cws_env('STORE_EMPLOYEES_CEDULA_MIN_LEN', '10');
    $maxLen = (int) cws_env('STORE_EMPLOYEES_CEDULA_MAX_LEN', '10');
    if ($minLen <= 0) {
        $minLen = 10;
    }
    if ($maxLen < $minLen) {
        $maxLen = $minLen;
    }
    if (!preg_match('/^\d+$/', $cedula)) {
        json_response(422, [
            'ok' => false,
            'error' => 'La cédula debe contener solo números',
            'field' => 'cedula_identidad',
        ]);
    }
    $len = strlen($cedula);
    if ($len < $minLen || $len > $maxLen) {
        json_response(422, [
            'ok' => false,
            'error' => "La cédula debe tener entre {$minLen} y {$maxLen} dígitos",
            'field' => 'cedula_identidad',
        ]);
    }
    return $cedula;
}

function normalize_and_validate_email(?string $correo): ?string
{
    $correo = normalize_text($correo);
    if ($correo === null) {
        return null;
    }
    assert_max_length('correo', $correo, 320);
    if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
        json_response(422, [
            'ok' => false,
            'error' => 'El correo no tiene un formato válido',
            'field' => 'correo',
        ]);
    }
    return $correo;
}

/** Catálogo fijo de cargos (debe coincidir con opciones del frontend). */
function store_employees_cargos_permitidos(): array
{
    return ['Administrador de tienda', 'Jefe de tienda', 'Asesor de ventas'];
}

function json_cargo_invalido(): void
{
    json_response(422, [
        'ok' => false,
        'error' => 'cargo debe ser uno de: Administrador de tienda, Jefe de tienda, Asesor de ventas',
        'cargos_permitidos' => store_employees_cargos_permitidos(),
    ]);
}

function assert_cargo_permitido(?string $cargo): void
{
    if ($cargo === null || !in_array($cargo, store_employees_cargos_permitidos(), true)) {
        json_cargo_invalido();
    }
}

/** encargado=1 solo permitido si estado activo (1). */
function assert_encargado_coherente_con_estado(int $estado, int $encargado): void
{
    if ($estado === 0 && $encargado === 1) {
        json_response(422, [
            'ok' => false,
            'error' => 'Si el empleado está inactivo, Encargado debe ser No',
        ]);
    }
}

function store_employees_table(): string
{
    $fallbackDb = trim((string) cws_env('SQL_SCS_DATABASE'));
    $db = trim((string) cws_env('STORE_EMPLOYEES_DB', $fallbackDb));
    if ($db === '') {
        $db = $fallbackDb;
    }
    // Escape closing bracket to keep identifier safe in SQL Server.
    $db = str_replace(']', ']]', $db);
    return '[' . $db . '].[dbo].[store_employees]';
}

function store_employees_connection()
{
    $host = trim((string) cws_env('STORE_EMPLOYEES_SQL_HOST'));
    $fallbackDb = trim((string) cws_env('SQL_SCS_DATABASE'));
    $db = trim((string) cws_env('STORE_EMPLOYEES_SQL_DATABASE', cws_env('STORE_EMPLOYEES_DB', $fallbackDb)));
    $user = trim((string) cws_env('STORE_EMPLOYEES_SQL_USER'));
    $pass = (string) cws_env('STORE_EMPLOYEES_SQL_PASSWORD');

    // If dedicated credentials are not configured, fall back to shared project connection.
    if ($host === '' || $db === '' || $user === '' || $pass === '') {
        return cx();
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
        json_response(500, ['ok' => false, 'error' => sql_error_payload(sqlsrv_errors())]);
    }
    return $conn;
}

/** Base de datos donde está dbo.Almacen (ej. STORECONTROL) al usar conexión cruzada. */
function store_almacen_table_qualified(): string
{
    $db = trim((string) cws_env('STORE_ALMACEN_DATABASE', 'STORECONTROL'));
    $db = str_replace(']', ']]', $db);
    return '[' . $db . '].[dbo].[Almacen]';
}

/**
 * Si SQL_SERVER_SAP_* está definido, conecta al servidor SAP y abre STORECONTROL (catálogo Almacen).
 * Si no, devuelve null y se usa la conexión principal (cross-database con nombre calificado).
 *
 * @return resource|null
 */
function store_sap_sql_connection()
{
    $host = trim((string) cws_env('SQL_SERVER_SAP_SERVER'));
    $db = trim((string) cws_env('SQL_SERVER_SAP_DATABASE'));
    $uid = trim((string) cws_env('SQL_SERVER_SAP_UID'));
    if ($host === '' || $db === '' || $uid === '') {
        return null;
    }
    $pwd = (string) cws_env('SQL_SERVER_SAP_PWD');
    $opts = [
        'Database' => $db,
        'UID' => $uid,
        'PWD' => $pwd,
        'MultipleActiveResultSets' => true,
        'TrustServerCertificate' => 'TRUE',
        'CharacterSet' => 'UTF-8',
    ];
    if (trim((string) cws_env('SQL_SERVER_SAP_ENCRYPT', '1')) !== '0') {
        $opts['Encrypt'] = 'yes';
    }
    $cx = sqlsrv_connect($host, $opts);
    if ($cx === false) {
        json_response(500, ['ok' => false, 'error' => sql_error_payload(sqlsrv_errors())]);
    }
    return $cx;
}

/**
 * Catálogo para combo "código tienda": mostrar nombre, guardar cod_almacen en codigo_tienda.
 * Misma lógica que el query acordado (ROLAND% + inactivo = 0).
 *
 * @param resource $conn
 * @param bool $almacen_en_bd_actual true si la conexión ya está en la BD que contiene dbo.Almacen
 * @return list<array{id:int|string,cod_almacen:string,nombre:string,label:string,marca:?string}>
 */
function fetch_almacenes_catalogo($conn, bool $almacen_en_bd_actual = false): array
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

    $from = $almacen_en_bd_actual ? 'dbo.Almacen' : store_almacen_table_qualified();
    $sql = 'SELECT TOP (1000) id, cod_almacen, nombre, inactivo, marca
            FROM ' . $from . '
            WHERE nombre LIKE ?
              AND inactivo = 0
              AND cod_almacen NOT IN (?, ?, ?)
            ORDER BY nombre';
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

function fetch_one_by_id($conn, int $id): ?array
{
    $sql = "SELECT id, cedula_identidad, correo, nombre, cargo, codigo_tienda, estado, encargado, fecha_actual, usuario_actual
            FROM " . store_employees_table() . "
            WHERE id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$id]);
    if ($stmt === false) {
        json_response(500, ['ok' => false, 'error' => sql_error_payload(sqlsrv_errors())]);
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    if (!$row) {
        return null;
    }
    foreach ($row as $k => $v) {
        if ($v instanceof DateTime) {
            $row[$k] = $v->format('Y-m-d H:i:s');
        }
    }
    $row['estado'] = (int) $row['estado'];
    $row['encargado'] = isset($row['encargado']) ? (int) $row['encargado'] : 0;
    return $row;
}

function cedula_exists($conn, string $cedula, ?int $excludeId = null): bool
{
    $sql = "SELECT TOP 1 id FROM " . store_employees_table() . " WHERE cedula_identidad = ?";
    $params = [$cedula];
    if ($excludeId !== null) {
        $sql .= " AND id <> ?";
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

function is_unique_violation($errors): bool
{
    if (!is_array($errors)) {
        return false;
    }
    foreach ($errors as $error) {
        $code = isset($error['code']) ? (int) $error['code'] : 0;
        if ($code === 2601 || $code === 2627) {
            return true;
        }
    }
    return false;
}

$conn = store_employees_connection();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$idParam = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$health = isset($_GET['health']) ? (string) $_GET['health'] : '';

if ($method === 'GET' && $health === '1') {
    $stmt = sqlsrv_query($conn, "SELECT 1 AS ok");
    if ($stmt === false) {
        sqlsrv_close($conn);
        json_response(503, [
            'ok' => false,
            'service' => 'store_employees_api',
            'status' => 'down',
            'error' => sql_error_payload(sqlsrv_errors()),
        ]);
    }
    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
    json_response(200, [
        'ok' => true,
        'service' => 'store_employees_api',
        'status' => 'up',
    ]);
}

if ($method === 'GET' && isset($_GET['meta']) && (string) $_GET['meta'] === 'cargos') {
    sqlsrv_close($conn);
    json_response(200, [
        'ok' => true,
        'data' => store_employees_cargos_permitidos(),
    ]);
}

if ($method === 'GET' && isset($_GET['meta']) && (string) $_GET['meta'] === 'almacenes') {
    $sapConn = store_sap_sql_connection();
    $usarSap = $sapConn !== null;
    $connAlm = $usarSap ? $sapConn : $conn;
    $rows = fetch_almacenes_catalogo($connAlm, $usarSap);
    if ($usarSap) {
        sqlsrv_close($sapConn);
    }
    sqlsrv_close($conn);
    json_response(200, [
        'ok' => true,
        'data' => $rows,
        'meta' => [
            'value_field' => 'cod_almacen',
            'label_field' => 'nombre',
            'hint' => 'En formularios use cod_almacen como valor de codigo_tienda; muestre nombre al usuario.',
            'source' => $usarSap ? 'sql_server_sap' : 'store_employees_connection',
        ],
    ]);
}

if ($method === 'GET') {
    if ($idParam > 0) {
        $item = fetch_one_by_id($conn, $idParam);
        if ($item === null) {
            sqlsrv_close($conn);
            json_response(404, ['ok' => false, 'error' => 'Registro no encontrado']);
        }
        sqlsrv_close($conn);
        json_response(200, ['ok' => true, 'data' => $item]);
    }

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = (int) ($_GET['page_size'] ?? 50);
    $pageSize = max(1, min(200, $pageSize));
    $offset = ($page - 1) * $pageSize;

    $where = [];
    $params = [];

    if (isset($_GET['q']) && trim((string) $_GET['q']) !== '') {
        $where[] = '(nombre LIKE ? OR cedula_identidad LIKE ? OR correo LIKE ?)';
        $q = '%' . trim((string) $_GET['q']) . '%';
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
    }
    if (isset($_GET['codigo_tienda']) && trim((string) $_GET['codigo_tienda']) !== '') {
        $where[] = 'codigo_tienda = ?';
        $params[] = trim((string) $_GET['codigo_tienda']);
    }
    if (isset($_GET['cargo']) && trim((string) $_GET['cargo']) !== '') {
        $c = trim((string) $_GET['cargo']);
        if (!in_array($c, store_employees_cargos_permitidos(), true)) {
            sqlsrv_close($conn);
            json_cargo_invalido();
        }
        $where[] = 'cargo = ?';
        $params[] = $c;
    }
    if (isset($_GET['estado']) && ($_GET['estado'] === '0' || $_GET['estado'] === '1')) {
        $where[] = 'estado = ?';
        $params[] = (int) $_GET['estado'];
    }
    if (isset($_GET['encargado']) && ($_GET['encargado'] === '0' || $_GET['encargado'] === '1')) {
        $where[] = 'encargado = ?';
        $params[] = (int) $_GET['encargado'];
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countSql = "SELECT COUNT(1) AS total FROM " . store_employees_table() . " $whereSql";
    $countStmt = sqlsrv_query($conn, $countSql, $params);
    if ($countStmt === false) {
        sqlsrv_close($conn);
        json_response(500, ['ok' => false, 'error' => sql_error_payload(sqlsrv_errors())]);
    }
    $countRow = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($countStmt);
    $total = (int) ($countRow['total'] ?? 0);

    $listSql = "SELECT id, cedula_identidad, correo, nombre, cargo, codigo_tienda, estado, encargado, fecha_actual, usuario_actual
                FROM " . store_employees_table() . "
                $whereSql
                ORDER BY id DESC
                OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
    $listParams = array_merge($params, [$offset, $pageSize]);
    $listStmt = sqlsrv_query($conn, $listSql, $listParams);
    if ($listStmt === false) {
        sqlsrv_close($conn);
        json_response(500, ['ok' => false, 'error' => sql_error_payload(sqlsrv_errors())]);
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($listStmt, SQLSRV_FETCH_ASSOC)) {
        foreach ($row as $k => $v) {
            if ($v instanceof DateTime) {
                $row[$k] = $v->format('Y-m-d H:i:s');
            }
        }
        $row['estado'] = (int) $row['estado'];
        $row['encargado'] = isset($row['encargado']) ? (int) $row['encargado'] : 0;
        $rows[] = $row;
    }
    sqlsrv_free_stmt($listStmt);
    sqlsrv_close($conn);

    json_response(200, [
        'ok' => true,
        'data' => $rows,
        'meta' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
        ],
    ]);
}

if ($method === 'POST') {
    $body = get_request_json();
    $cedula = normalize_and_validate_cedula($body['cedula_identidad'] ?? null);
    $correo = normalize_and_validate_email($body['correo'] ?? null);
    $nombre = normalize_text($body['nombre'] ?? null);
    $cargo = normalize_text($body['cargo'] ?? null);
    $codigoTienda = normalize_text($body['codigo_tienda'] ?? null);
    $estado = isset($body['estado']) ? (int) ((bool) $body['estado']) : 1;
    $encargado = isset($body['encargado']) ? (int) ((bool) $body['encargado']) : 0;
    $usuarioActual = get_actor($body);

    if ($cedula === null || $nombre === null || $cargo === null || $codigoTienda === null) {
        sqlsrv_close($conn);
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
    if ($cedula !== null && cedula_exists($conn, $cedula)) {
        sqlsrv_close($conn);
        json_response(409, [
            'ok' => false,
            'error' => 'La cédula ya está registrada',
            'field' => 'cedula_identidad',
        ]);
    }

    $sql = "INSERT INTO " . store_employees_table() . "
            (cedula_identidad, correo, nombre, cargo, codigo_tienda, estado, encargado, fecha_actual, usuario_actual)
            VALUES (?, ?, ?, ?, ?, ?, ?, SYSDATETIME(), ?)";
    $params = [$cedula, $correo, $nombre, $cargo, $codigoTienda, $estado, $encargado, $usuarioActual];
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        if (is_unique_violation($errors)) {
            sqlsrv_close($conn);
            json_response(409, [
                'ok' => false,
                'error' => 'Ya existe un registro con la misma cédula',
                'field' => 'cedula_identidad',
            ]);
        }
        $error = sql_error_payload($errors);
        sqlsrv_close($conn);
        json_response(409, ['ok' => false, 'error' => $error]);
    }
    sqlsrv_free_stmt($stmt);

    $idStmt = sqlsrv_query($conn, "SELECT CAST(SCOPE_IDENTITY() AS BIGINT) AS id");
    if ($idStmt === false) {
        sqlsrv_close($conn);
        json_response(500, ['ok' => false, 'error' => sql_error_payload(sqlsrv_errors())]);
    }
    $idRow = sqlsrv_fetch_array($idStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($idStmt);
    $newId = (int) ($idRow['id'] ?? 0);

    $created = fetch_one_by_id($conn, $newId);
    sqlsrv_close($conn);
    json_response(201, ['ok' => true, 'data' => $created]);
}

if ($method === 'PUT' || $method === 'PATCH') {
    $body = get_request_json();
    $id = isset($body['id']) ? (int) $body['id'] : $idParam;
    if ($id <= 0) {
        sqlsrv_close($conn);
        json_response(422, ['ok' => false, 'error' => 'Se requiere id']);
    }
    $current = fetch_one_by_id($conn, $id);
    if ($current === null) {
        sqlsrv_close($conn);
        json_response(404, ['ok' => false, 'error' => 'Registro no encontrado']);
    }

    $newEstado = array_key_exists('estado', $body) ? (int) ((bool) $body['estado']) : (int) $current['estado'];
    $newEncargado = array_key_exists('encargado', $body)
        ? (int) ((bool) $body['encargado'])
        : (int) ($current['encargado'] ?? 0);

    if ($newEstado === 0) {
        if (array_key_exists('encargado', $body) && (int) ((bool) $body['encargado']) === 1) {
            sqlsrv_close($conn);
            json_response(422, [
                'ok' => false,
                'error' => 'Si el empleado está inactivo, Encargado debe ser No',
            ]);
        }
        $newEncargado = 0;
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
            if ($value !== null && cedula_exists($conn, $value, $id)) {
                sqlsrv_close($conn);
                json_response(409, [
                    'ok' => false,
                    'error' => 'La cédula ya está registrada',
                    'field' => 'cedula_identidad',
                ]);
            }
            $fields[] = "$column = ?";
            $params[] = $value;
            continue;
        }
        if ($input === 'correo') {
            $value = normalize_and_validate_email((string) $body[$input]);
            $fields[] = "$column = ?";
            $params[] = $value;
            continue;
        }
        if ($input === 'cargo') {
            $value = normalize_text((string) $body[$input]);
            assert_required_not_null('cargo', $value);
            assert_max_length('cargo', $value, 100);
            assert_cargo_permitido($value);
            $fields[] = "$column = ?";
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
        $fields[] = "$column = ?";
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
        sqlsrv_close($conn);
        json_response(422, ['ok' => false, 'error' => 'No hay campos para actualizar']);
    }

    $fields[] = 'fecha_actual = SYSDATETIME()';
    $fields[] = 'usuario_actual = ?';
    $actor = get_actor($body);
    assert_max_length('usuario_actual', $actor, 64);
    $params[] = $actor;
    $params[] = $id;

    $sql = "UPDATE " . store_employees_table() . " SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        if (is_unique_violation($errors)) {
            sqlsrv_close($conn);
            json_response(409, [
                'ok' => false,
                'error' => 'Ya existe un registro con la misma cédula',
                'field' => 'cedula_identidad',
            ]);
        }
        $error = sql_error_payload($errors);
        sqlsrv_close($conn);
        json_response(409, ['ok' => false, 'error' => $error]);
    }
    sqlsrv_free_stmt($stmt);

    $updated = fetch_one_by_id($conn, $id);
    sqlsrv_close($conn);
    json_response(200, ['ok' => true, 'data' => $updated]);
}

if ($method === 'DELETE') {
    $body = get_request_json();
    $id = isset($body['id']) ? (int) $body['id'] : $idParam;
    if ($id <= 0) {
        sqlsrv_close($conn);
        json_response(422, ['ok' => false, 'error' => 'Se requiere id']);
    }
    if (fetch_one_by_id($conn, $id) === null) {
        sqlsrv_close($conn);
        json_response(404, ['ok' => false, 'error' => 'Registro no encontrado']);
    }

    $sql = "UPDATE " . store_employees_table() . "
            SET estado = 0, encargado = 0, fecha_actual = SYSDATETIME(), usuario_actual = ?
            WHERE id = ?";
    $stmt = sqlsrv_query($conn, $sql, [get_actor($body), $id]);
    if ($stmt === false) {
        sqlsrv_close($conn);
        json_response(500, ['ok' => false, 'error' => sql_error_payload(sqlsrv_errors())]);
    }
    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
    json_response(200, ['ok' => true, 'message' => 'Registro inactivado']);
}

sqlsrv_close($conn);
json_response(405, ['ok' => false, 'error' => 'Método no permitido']);
