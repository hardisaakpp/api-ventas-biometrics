<?php
declare(strict_types=1);

require_once __DIR__ . '/../../cx/bd_scs.php';
require_once __DIR__ . '/../dat_cws.php';
require_once __DIR__ . '/store_employees/Support.php';
require_once __DIR__ . '/store_employees/Repository.php';
require_once __DIR__ . '/store_employees/Service.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-User');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$conn = store_employees_connection();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$idParam = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$health = isset($_GET['health']) ? (string) $_GET['health'] : '';

if ($method === 'GET' && $health === '1') {
    $result = store_employees_service_health($conn);
    sqlsrv_close($conn);
    json_response((int) $result['status'], (array) $result['payload']);
}

if ($method === 'GET' && isset($_GET['meta']) && (string) $_GET['meta'] === 'cargos') {
    sqlsrv_close($conn);
    json_response(200, [
        'ok' => true,
        'data' => store_employees_cargos_permitidos(),
    ]);
}

if ($method === 'GET' && isset($_GET['meta']) && (string) $_GET['meta'] === 'almacenes') {
    $payload = store_employees_service_meta_almacenes($conn);
    sqlsrv_close($conn);
    json_response(200, $payload);
}

if ($method === 'GET') {
    if ($idParam > 0) {
        $item = store_employees_repo_fetch_one_by_id($conn, $idParam);
        if ($item === null) {
            sqlsrv_close($conn);
            json_response(404, ['ok' => false, 'error' => 'Registro no encontrado']);
        }
        sqlsrv_close($conn);
        json_response(200, ['ok' => true, 'data' => $item]);
    }

    $payload = store_employees_service_list($conn, $_GET);
    sqlsrv_close($conn);
    json_response(200, $payload);
}

if ($method === 'POST') {
    $result = store_employees_service_create($conn, get_request_json());
    sqlsrv_close($conn);
    json_response((int) $result['status'], (array) $result['payload']);
}

if ($method === 'PUT' || $method === 'PATCH') {
    $result = store_employees_service_update($conn, get_request_json(), $idParam);
    sqlsrv_close($conn);
    json_response((int) $result['status'], (array) $result['payload']);
}

if ($method === 'DELETE') {
    $result = store_employees_service_delete($conn, get_request_json(), $idParam);
    sqlsrv_close($conn);
    json_response((int) $result['status'], (array) $result['payload']);
}

sqlsrv_close($conn);
json_response(405, ['ok' => false, 'error' => 'Método no permitido']);
