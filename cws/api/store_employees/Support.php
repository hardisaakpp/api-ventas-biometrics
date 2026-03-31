<?php
declare(strict_types=1);

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

function store_employees_cargos_permitidos(): array
{
    return ['Administrador de tienda', 'Jefe de tienda', 'Director de tienda', 'Asesor de ventas'];
}

function json_cargo_invalido(): void
{
    json_response(422, [
        'ok' => false,
        'error' => 'cargo debe ser uno de: Administrador de tienda, Jefe de tienda, Director de tienda, Asesor de ventas',
        'cargos_permitidos' => store_employees_cargos_permitidos(),
    ]);
}

function assert_cargo_permitido(?string $cargo): void
{
    if ($cargo === null || !in_array($cargo, store_employees_cargos_permitidos(), true)) {
        json_cargo_invalido();
    }
}

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

function store_almacen_table_qualified(): string
{
    $db = trim((string) cws_env('STORE_ALMACEN_DATABASE', 'STORECONTROL'));
    $db = str_replace(']', ']]', $db);
    return '[' . $db . '].[dbo].[almacenes]';
}

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
