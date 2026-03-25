<?php
/**
 * Comprueba que .env tenga lo mínimo para SQL + KOACH360 (sin llamar a la red).
 *
 *   php cws/scripts/check_env.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../dat_cws.php';

$errors = [];

foreach (['SQL_SCS_HOST', 'SQL_SCS_DATABASE', 'SQL_SCS_USER', 'SQL_SCS_PASSWORD'] as $key) {
    if (trim(cws_env($key)) === '') {
        $errors[] = "Falta o está vacío: {$key}";
    }
}

$koachUser = trim(cws_env('KOACH360_USUARIO')) !== '' ? cws_env('KOACH360_USUARIO') : cws_env('US_PVENTAS');
$koachPass = trim(cws_env('KOACH360_PASSWORD')) !== '' ? cws_env('KOACH360_PASSWORD') : cws_env('PS_PVENTAS');
if (trim($koachUser) === '' || trim($koachPass) === '') {
    $errors[] = 'KOACH360: defina KOACH360_USUARIO y KOACH360_PASSWORD (o US_PVENTAS y PS_PVENTAS como respaldo)';
}

$warnings = [];
if (trim(cws_env('US_VCOUNT')) === '' || trim(cws_env('PS_VCOUNT')) === '') {
    $warnings[] = 'US_VCOUNT / PS_VCOUNT vacíos: syn_fetch_vcount y similares fallarán hasta configurarlos';
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo 'OK: variables mínimas (SQL + credenciales KOACH360) presentes.' . PHP_EOL;
foreach ($warnings as $w) {
    fwrite(STDERR, 'Aviso: ' . $w . PHP_EOL);
}
exit(0);
