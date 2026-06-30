<?php
require_once __DIR__ . '/config.php';

$archivo = isset($_GET['archivo']) ? basename((string)$_GET['archivo']) : '';

if (!$archivo || !preg_match('/^[\w.-]+\.json$/', $archivo)) {
    http_response_code(400);
    exit('Nombre de archivo inválido.');
}

$path = KLIENTEV1_PATH . '/tareas/json/' . $archivo;

if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $archivo . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
