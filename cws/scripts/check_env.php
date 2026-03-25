<?php
/**
 * Comprueba que .env tenga lo mínimo para SQL + KOACH360 (sin llamar a la red).
 *
 *   php cws/scripts/check_env.php
 *   php cws/scripts/check_env.php --no-sql    # solo KOACH360 (+ avisos V-Count); útil si el servidor aún no tiene SQL
 */
declare(strict_types=1);

require_once __DIR__ . '/../dat_cws.php';

$skipSql = in_array('--no-sql', $argv ?? [], true)
    || in_array('-n', $argv ?? [], true)
    || in_array(strtolower(trim((string) cws_env('CWS_CHECK_ENV_NO_SQL', ''))), ['1', 'true', 'yes', 'on'], true);

$errors = [];

if (!$skipSql) {
    foreach (['SQL_SCS_HOST', 'SQL_SCS_DATABASE', 'SQL_SCS_USER', 'SQL_SCS_PASSWORD'] as $key) {
        if (trim(cws_env($key)) === '') {
            $errors[] = "Falta o está vacío: {$key}";
        }
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

$okMsg = $skipSql
    ? 'OK: credenciales KOACH360 presentes (SQL no comprobado; usar sin --no-sql cuando tengáis SQL_SCS_*).'
    : 'OK: variables mínimas (SQL + credenciales KOACH360) presentes.';
echo $okMsg . PHP_EOL;
foreach ($warnings as $w) {
    fwrite(STDERR, 'Aviso: ' . $w . PHP_EOL);
}
exit(0);
