<?php
declare(strict_types=1);

require_once __DIR__ . '/../../cx/bd_scs.php';
require_once __DIR__ . '/../dat_cws.php';
require_once __DIR__ . '/../f_koach360.php';
require_once __DIR__ . '/asistencias/Support.php';
require_once __DIR__ . '/asistencias/Repository.php';
require_once __DIR__ . '/asistencias/Service.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-User');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$health = isset($_GET['health']) ? (string) $_GET['health'] : '';
$logs   = isset($_GET['logs'])   ? (string) $_GET['logs']   : '';

if ($method === 'GET' && $health === '1') {
    $conn   = asistencias_main_connection();
    $result = asistencias_service_health($conn);
    if ($conn !== null) {
        sqlsrv_close($conn);
    }
    json_response((int) $result['status'], (array) $result['payload']);
}

if ($method === 'GET' && $logs === '1') {
    $limit  = min(100, max(1, (int) ($_GET['limit'] ?? 30)));
    $result = asistencias_service_logs($limit);
    json_response((int) $result['status'], (array) $result['payload']);
}

json_response(400, ['ok' => false, 'error' => 'Parámetro requerido: health=1 o logs=1']);
