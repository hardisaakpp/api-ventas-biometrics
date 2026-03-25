<?php
/**
 * Prueba KOACH360 login (JWT) sin SQL Server.
 *
 * Opción A — desde la raíz del repositorio klienteV1:
 *   php cws/scripts/test_koach360_login.php
 *
 * Opción B — desde cualquier carpeta (ruta absoluta al .php):
 *   php "C:\ruta\completa\klienteV1\cws\scripts\test_koach360_login.php"
 *
 * Carga .env desde la raíz del repo vía dat_cws (igual que el resto de cws).
 */
declare(strict_types=1);

require_once __DIR__ . '/../f_koach360.php';

$token = koach360_obtener_token();

if ($token === null || $token === '') {
    fwrite(STDERR, "KOACH360: no se obtuvo token (HTTP != 200, credenciales o respuesta sin campo token).\n");
    fwrite(STDERR, "Revisa KOACH360_USUARIO / KOACH360_PASSWORD (o US_PVENTAS / PS_PVENTAS) en .env en la raíz del repo.\n");
    exit(1);
}

echo "OK: token obtenido (" . strlen($token) . " caracteres).\n";
echo "Primeros 80 caracteres: " . substr($token, 0, 80) . "...\n";
exit(0);
