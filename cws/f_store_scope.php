<?php
require_once __DIR__ . '/dat_cws.php';

if (!function_exists('store_scope_default_phase1_codes')) {
    function store_scope_default_phase1_codes(): array
    {
        return [
            'RL-CCI', 'RL-CON', 'RL-PSC', 'RL-QSN', 'RL-QSS2',
            'RL-REC2', 'RL-SCA', 'RL-SNL', 'RL-MSO3', 'RL-BMB',
        ];
    }
}

if (!function_exists('store_scope_normalize_codes')) {
    function store_scope_normalize_codes(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $c = strtoupper(trim((string) $code));
            if ($c === '') {
                continue;
            }
            if (!preg_match('/^[A-Z0-9._\\-]+$/', $c)) {
                continue;
            }
            $out[$c] = true;
        }
        return array_keys($out);
    }
}

if (!function_exists('store_scope_fallback_codes')) {
    function store_scope_fallback_codes(): array
    {
        $raw = trim((string) cws_env('TIENDAS_FASE1_CODIGOS', ''));
        if ($raw !== '') {
            $codes = store_scope_normalize_codes(explode(',', $raw));
            if ($codes !== []) {
                return $codes;
            }
        }
        return store_scope_default_phase1_codes();
    }
}

if (!function_exists('store_scope_connection')) {
    function store_scope_connection()
    {
        // Reutiliza la misma conexión de configuración usada por KOACH360.
        if (!function_exists('koach360_config_connection')) {
            return null;
        }
        return koach360_config_connection();
    }
}

if (!function_exists('store_scope_table_available')) {
    function store_scope_table_available($conn): bool
    {
        $sql = "SELECT CASE WHEN OBJECT_ID('dbo.integration_store_scope', 'U') IS NULL THEN 0 ELSE 1 END AS ok";
        $stmt = @sqlsrv_query($conn, $sql);
        if ($stmt === false) {
            return false;
        }
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return ((int) ($row['ok'] ?? 0)) === 1;
    }
}

if (!function_exists('store_scope_resolver_codigos')) {
    /**
     * Devuelve códigos autorizados según integración y fase.
     *
     * Tabla esperada (store_control): dbo.integration_store_scope
     *  - codigo_tienda varchar(...)
     *  - integration varchar(...): KOACH360_VENTAS / KOACH360_ASISTENCIAS / KOACH360_ASISTENCIAS_PROGRAMADAS / ALL
     *  - es_fase1 bit
     *  - activo bit
     */
    function store_scope_resolver_codigos(string $integration, bool $fase1 = true): array
    {
        static $cache = [];
        $integration = strtoupper(trim($integration));
        if ($integration === '') {
            $integration = 'ALL';
        }
        $key = $integration . '|' . ($fase1 ? '1' : '0');
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $fallback = store_scope_fallback_codes();
        $conn = store_scope_connection();
        if ($conn === null || !store_scope_table_available($conn)) {
            $cache[$key] = $fallback;
            return $fallback;
        }

        $sql = "
            SELECT DISTINCT UPPER(LTRIM(RTRIM(codigo_tienda))) AS codigo_tienda
            FROM dbo.integration_store_scope
            WHERE activo = 1
              AND (? = 0 OR es_fase1 = 1)
              AND UPPER(ISNULL(integration, 'ALL')) IN ('ALL', ?)
            ORDER BY UPPER(LTRIM(RTRIM(codigo_tienda)));
        ";
        $params = [$fase1 ? 1 : 0, $integration];
        $stmt = @sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            sqlsrv_close($conn);
            $cache[$key] = $fallback;
            return $fallback;
        }

        $codes = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $codes[] = (string) ($row['codigo_tienda'] ?? '');
        }
        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);

        $codes = store_scope_normalize_codes($codes);
        if ($codes === []) {
            $codes = $fallback;
        }
        $cache[$key] = $codes;
        return $codes;
    }
}

if (!function_exists('store_scope_desc_to_code_map')) {
    function store_scope_desc_to_code_map(): array
    {
        return [
            'ROLAND-QUITO-CCI' => 'RL-CCI',
            'ROLAND-QUITO-CONDADO SHOPPING' => 'RL-CON',
            'ROLAND-QUITO-PORTAL SHOPPING' => 'RL-PSC',
            'ROLAND-QUITO-QUICENTRO NORTE' => 'RL-QSN',
            'ROLAND-QUITO-QUICENTRO SUR' => 'RL-QSS2',
            'ROLAND-QUITO-RECREO' => 'RL-REC2',
            'ROLAND-QUITO-SAN LUIS' => 'RL-SNL',
            'ROLAND-QUITO-SCALA SHOPPING' => 'RL-SCA',
            'ROLAND-GUAYAQUIL-MALL DEL SOL3' => 'RL-MSO3',
            'ROLAND-STO DOMINGO-BOMBOLI' => 'RL-BMB',
        ];
    }
}

if (!function_exists('store_scope_desc_map_filtrado_por_codigos')) {
    function store_scope_desc_map_filtrado_por_codigos(array $codigos): array
    {
        $allowed = array_fill_keys(store_scope_normalize_codes($codigos), true);
        $out = [];
        foreach (store_scope_desc_to_code_map() as $desc => $code) {
            if (isset($allowed[strtoupper($code)])) {
                $out[$desc] = strtoupper($code);
            }
        }
        return $out;
    }
}

