<?php
require_once __DIR__ . '/dat_cws.php';

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

if (!function_exists('store_scope_connection')) {
    function store_scope_connection()
    {
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
     * Devuelve los códigos de tienda activos para una integración dada.
     * Fuente única: dbo.integration_store_scope (sc_kliente).
     *
     * Columnas relevantes:
     *  - codigo_tienda  varchar   código bodega (ej. RL-CCI)
     *  - nombre_tienda  nvarchar  nombre legible (opcional)
     *  - integration    varchar   KOACH360_VENTAS / KOACH360_ASISTENCIAS / ALL / etc.
     *  - activo         bit       1 = incluida en envíos automáticos
     *
     * Si la BD no está disponible o la tabla no existe, retorna [] y escribe
     * en error_log — el proceso que llama debe decidir si abortar o continuar.
     */
    function store_scope_resolver_codigos(string $integration): array
    {
        static $cache = [];
        $integration = strtoupper(trim($integration));
        if ($integration === '') {
            $integration = 'ALL';
        }
        if (array_key_exists($integration, $cache)) {
            return $cache[$integration];
        }

        $conn = store_scope_connection();
        if ($conn === null) {
            error_log("store_scope_resolver_codigos: no se pudo conectar a sc_kliente (integration={$integration})");
            $cache[$integration] = [];
            return [];
        }

        if (!store_scope_table_available($conn)) {
            error_log("store_scope_resolver_codigos: tabla dbo.integration_store_scope no existe en sc_kliente");
            sqlsrv_close($conn);
            $cache[$integration] = [];
            return [];
        }

        $sql = "
            SELECT DISTINCT UPPER(LTRIM(RTRIM(codigo_tienda))) AS codigo_tienda
            FROM dbo.integration_store_scope
            WHERE activo = 1
              AND UPPER(ISNULL(integration, 'ALL')) IN ('ALL', ?)
            ORDER BY UPPER(LTRIM(RTRIM(codigo_tienda)));
        ";
        $stmt = @sqlsrv_query($conn, $sql, [$integration]);
        if ($stmt === false) {
            error_log("store_scope_resolver_codigos: error al consultar integration_store_scope (integration={$integration})");
            sqlsrv_close($conn);
            $cache[$integration] = [];
            return [];
        }

        $codes = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $codes[] = (string) ($row['codigo_tienda'] ?? '');
        }
        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);

        $codes = store_scope_normalize_codes($codes);
        $cache[$integration] = $codes;
        return $codes;
    }
}

if (!function_exists('store_scope_desc_to_code_map')) {
    /**
     * Mapa nombre_tienda → codigo_tienda leído desde BD.
     * Solo incluye registros con activo=1 y nombre_tienda no nulo.
     * Retorna [] si la BD no está disponible.
     */
    function store_scope_desc_to_code_map(): array
    {
        $conn = store_scope_connection();
        if ($conn === null) {
            return [];
        }

        $sql = "
            SELECT UPPER(LTRIM(RTRIM(codigo_tienda))) AS codigo_tienda,
                   LTRIM(RTRIM(nombre_tienda))        AS nombre_tienda
            FROM dbo.integration_store_scope
            WHERE activo = 1
              AND nombre_tienda IS NOT NULL
              AND LTRIM(RTRIM(nombre_tienda)) <> ''
            ORDER BY UPPER(LTRIM(RTRIM(codigo_tienda)));
        ";
        $stmt = @sqlsrv_query($conn, $sql);
        if ($stmt === false) {
            sqlsrv_close($conn);
            return [];
        }

        $map = [];
        $seen = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $code = strtoupper(trim((string) ($row['codigo_tienda'] ?? '')));
            $desc = trim((string) ($row['nombre_tienda'] ?? ''));
            if ($code === '' || $desc === '' || isset($seen[$code])) {
                continue;
            }
            $map[$desc] = $code;
            $seen[$code] = true;
        }
        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);
        return $map;
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
