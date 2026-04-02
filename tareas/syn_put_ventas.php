<?php
    /**
     * Ventas continuas → KOACH360 (JWT). Solo tiendas Fase 1 (CodigoBodega).
     *
     * Modos (CLI, argv[1]):
     *   retroactivo — últimos 7 días calendario hasta hoy (inclusive). Cron sugerido: 1×/día.
     *   intradia    — solo hoy, desde 00:00 hasta antes de (hora_tope+1):00 (acumulativo por hora).
     *                 Cron sugerido: cada hora 09:00–22:00 en punto.
     *
     * argv[2] opcional (intradia): hora entera 0–23 como tope de ventana (pruebas; por defecto usa la hora actual).
     *
     * Go-live: con VENTAS_CONTINUO_DESDE=2026-04-01 (por defecto si no está en .env), si hoy < esa fecha no consulta ni envía.
     * Para pruebas antes: VENTAS_CONTINUO_DESDE= vacío en .env.
     *
     * Intradia fuera de 09–22: no envía (exit 0). Forzar prueba: VENTAS_INTRADIA_FORCE=1 en .env.
     *
     * Cron ejemplo (ajustar ruta y TZ del servidor a America/Guayaquil si aplica):
     *   0 6 * * * cd /ruta/klienteV1 && php tareas/syn_put_ventas.php retroactivo
     *   0 9-22 * * * cd /ruta/klienteV1 && php tareas/syn_put_ventas.php intradia
     *
     * Sin argv[1] en CLI: se usa retroactivo (compatibilidad).
     */
    require_once(__DIR__ . '/../cx/bd_scs.php');
    require_once(__DIR__ . '/../cws/f_put_ventas.php');
    require_once('f_tareas.php');
    date_default_timezone_set('America/Guayaquil');

    /**
     * Códigos de bodega Fase 1 (deben coincidir con catálogo KOACH / DWH).
     */
    function ventas_continuo_codigos_fase1(): array
    {
        return [
            'RL-CCI', 'RL-CON', 'RL-PSC', 'RL-QSN', 'RL-QSS2',
            'RL-REC2', 'RL-SCA', 'RL-SNL', 'RL-MSO3', 'RL-BMB',
        ];
    }

    function ventas_continuo_sql_in_bodegas(): string
    {
        $codes = array_map(static function (string $c): string {
            return "'" . str_replace("'", "''", $c) . "'";
        }, ventas_continuo_codigos_fase1());

        return implode(',', $codes);
    }

    /**
     * @param 'retroactivo'|'intradia' $modo
     * @param string|null $horaExclusiveEnd 'HH:00:00' límite superior exclusivo para HoraEmision (intradia)
     */
    function ventas_continuo_build_query(string $modo, string $fechaDesdeYmd, string $fechaHastaYmd, ?string $horaExclusiveEnd): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesdeYmd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHastaYmd)) {
            throw new InvalidArgumentException('Rango de fechas inválido.');
        }
        if ($modo === 'intradia') {
            if ($horaExclusiveEnd === null || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $horaExclusiveEnd)) {
                throw new InvalidArgumentException('Hora límite intradia inválida.');
            }
            $condFecha = "
            dt.Fecha = CAST('{$fechaHastaYmd}' AS DATE)
            AND CAST(df.HoraEmision AS TIME) >= CAST('00:00:00' AS TIME)
            AND CAST(df.HoraEmision AS TIME) < CAST('{$horaExclusiveEnd}' AS TIME)";
        } else {
            $condFecha = "
            dt.Fecha >= CAST('{$fechaDesdeYmd}' AS DATE)
            AND dt.Fecha <= CAST('{$fechaHastaYmd}' AS DATE)";
        }

        $inBodegas = ventas_continuo_sql_in_bodegas();

        return "
        SELECT
            factura_numero      = df.NumeroFactura,
            factura_fecha       = CONVERT(VARCHAR(10), dt.Fecha, 23),
            factura_hora        = LEFT(CONVERT(VARCHAR(8), df.HoraEmision, 108), 5),
            factura_origen      = IIF(dtd.Nombre_TipoDocumento = 'Factura', 'FAC', 'NC'),
            asesor_nombre       = dv.NombreVendedor,
            asesor_cedula       = dv.DocumentoVendedor,
            asesor_correo       = '',
            lider_nombre        = '-',
            lider_cedula        = '-',
            lider_correo        = '-',
            local_nombre        = da.NombreBodega,
            local_codigo        = da.CodigoBodega,
            valor_transaccion   = CONVERT(DECIMAL(18,2), SUM(fv.TotalPagado)),
            cantidad_unidades   = SUM(fv.CantidadArticulos)
        FROM DWHE.dbo.Fact_Ventas fv
        INNER JOIN DWHE.dbo.Dim_Factura df
            ON fv.Key_Factura = df.Key_Factura
        INNER JOIN DWHE.dbo.Dim_Empresa de
            ON fv.Key_Empresa = de.Key_Empresa
        INNER JOIN DWHE.dbo.Dim_Tiempo dt
            ON fv.Key_Tiempo = dt.Key_Tiempo
        INNER JOIN DWHE.dbo.Dim_Vendedor dv
            ON fv.Key_Vendedor = dv.Key_Vendedor
        INNER JOIN DWHE.dbo.Dim_Almacen da
            ON fv.Key_Almacen = da.Key_Almacen
        INNER JOIN DWHE.dbo.Dim_TipoDocumento dtd
            ON fv.Key_TipoDocumento = dtd.Key_TipoDocumento
        INNER JOIN DWHE.dbo.Dim_Producto dp
            ON fv.Key_Producto = dp.Key_Producto
        WHERE
            dv.CodigoInterno <> '114'
            AND dp.TipoProducto = 'PRODUCTO'
            AND {$condFecha}
            AND da.CodigoBodega IN ({$inBodegas})
        GROUP BY
            df.NumeroFactura,
            dt.Fecha,
            df.HoraEmision,
            dtd.Nombre_TipoDocumento,
            dv.NombreVendedor,
            dv.DocumentoVendedor,
            da.NombreBodega,
            da.CodigoBodega
        ORDER BY
            da.CodigoBodega,
            dt.Fecha,
            df.NumeroFactura;
    ";
    }

    /**
     * Trae líder activo/encargado por código de tienda desde SCNexus (store_control).
     * Retorna mapa [codigo_tienda => ['nombre' => ..., 'cedula' => ..., 'correo' => ...]].
     */
    function obtener_lideres_scnexus_por_tienda(array $codigosTienda): array
    {
        $codigos = [];
        foreach ($codigosTienda as $codigo) {
            $c = trim((string) $codigo);
            if ($c !== '') {
                $codigos[$c] = true;
            }
        }
        $codigos = array_keys($codigos);
        if ($codigos === []) {
            return [];
        }

        $host = cws_env('STORE_EMPLOYEES_SQL_HOST', '210.17.1.54');
        $db   = cws_env('STORE_EMPLOYEES_SQL_DATABASE', 'store_control');
        $user = cws_env('STORE_EMPLOYEES_SQL_USER');
        $pass = cws_env('STORE_EMPLOYEES_SQL_PASSWORD');
        if ($host === '' || $db === '' || $user === '' || $pass === '') {
            return [];
        }

        $cxScnexus = sqlsrv_connect($host, [
            'Database' => $db,
            'UID' => $user,
            'PWD' => $pass,
            'MultipleActiveResultSets' => true,
            'TrustServerCertificate' => true,
            'CharacterSet' => 'UTF-8',
        ]);
        if ($cxScnexus === false) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($codigos), '?'));
        $sqlLideres = "
            SELECT
                codigo_tienda,
                nombre,
                cedula_identidad,
                correo,
                id
            FROM dbo.store_employees
            WHERE estado = 1
              AND encargado = 1
              AND codigo_tienda IN ($placeholders)
            ORDER BY codigo_tienda, id DESC;
        ";

        $stmt = sqlsrv_query($cxScnexus, $sqlLideres, $codigos);
        if ($stmt === false) {
            sqlsrv_close($cxScnexus);
            return [];
        }

        $mapa = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $codigo = trim((string) ($row['codigo_tienda'] ?? ''));
            if ($codigo === '' || isset($mapa[$codigo])) {
                continue;
            }
            $mapa[$codigo] = [
                'nombre' => trim((string) ($row['nombre'] ?? '')),
                'cedula' => trim((string) ($row['cedula_identidad'] ?? '')),
                'correo' => trim((string) ($row['correo'] ?? '')),
            ];
        }

        sqlsrv_free_stmt($stmt);
        sqlsrv_close($cxScnexus);
        return $mapa;
    }

    // ---------- CLI / go-live ----------

    $modo = 'retroactivo';
    if (PHP_SAPI === 'cli' && isset($argv[1]) && trim((string) $argv[1]) !== '') {
        $arg1 = strtolower(trim((string) $argv[1]));
        if ($arg1 === '-h' || $arg1 === '--help') {
            fwrite(STDOUT, "Uso: php tareas/syn_put_ventas.php [retroactivo|intradia] [hora_opcional_0_23]\n");
            exit(0);
        }
        if ($arg1 === 'retroactivo' || $arg1 === 'intradia') {
            $modo = $arg1;
        } else {
            fwrite(STDERR, "syn_put_ventas: modo desconocido '{$argv[1]}'. Use retroactivo o intradia.\n");
            exit(1);
        }
    }

    $desdeGo = trim((string) cws_env('VENTAS_CONTINUO_DESDE', '2026-04-01'));
    if ($desdeGo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desdeGo) && date('Y-m-d') < $desdeGo) {
        $logLine = date('Y-m-d H:i:s') . " - modo={$modo} skip go-live antes_de={$desdeGo}\n";
        file_put_contents(tareas_log_path('syn_put_ventas.log'), $logLine, FILE_APPEND);
        exit(0);
    }

    $hoy = date('Y-m-d');
    $fechaDesde = $hoy;
    $fechaHasta = $hoy;
    $horaExclusiveEnd = null;
    $metaLog = $modo;

    try {
        if ($modo === 'retroactivo') {
            $fechaDesde = date('Y-m-d', strtotime('-6 days', strtotime($hoy)));
            $metaLog .= " desde={$fechaDesde} hasta={$fechaHasta}";
        } else {
            $horaTop = (int) date('G');
            if (isset($argv[2]) && trim((string) $argv[2]) !== '' && ctype_digit(trim((string) $argv[2]))) {
                $horaTop = (int) trim((string) $argv[2]);
            }
            if ($horaTop < 0 || $horaTop > 23) {
                fwrite(STDERR, "syn_put_ventas: hora tope inválida.\n");
                exit(1);
            }
            $force = trim((string) cws_env('VENTAS_INTRADIA_FORCE', '')) === '1';
            if (($horaTop < 9 || $horaTop > 22) && !$force) {
                $logLine = date('Y-m-d H:i:s') . " - modo=intradia skip fuera_ventana hora={$horaTop} (use 09-22 o VENTAS_INTRADIA_FORCE=1)\n";
                file_put_contents(tareas_log_path('syn_put_ventas.log'), $logLine, FILE_APPEND);
                exit(0);
            }
            $siguiente = $horaTop + 1;
            if ($siguiente > 23) {
                fwrite(STDERR, "syn_put_ventas intradia: tope hora 23 no soportado para ventana mismo día.\n");
                exit(1);
            }
            $horaExclusiveEnd = sprintf('%02d:00:00', $siguiente);
            $metaLog .= " fecha={$hoy} hora_tope={$horaTop} exclusivo_lt={$horaExclusiveEnd}";
        }

        $query = ventas_continuo_build_query($modo, $fechaDesde, $fechaHasta, $horaExclusiveEnd);
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, 'syn_put_ventas: ' . $e->getMessage() . "\n");
        exit(1);
    }

    $ventas = resp_simdim($query);
    if (!is_array($ventas)) {
        $ventas = [];
    }
    $codigosTienda = array_values(array_unique(array_map(static function ($venta) {
        return trim((string) ($venta['local_codigo'] ?? ''));
    }, $ventas)));
    $lideresPorTienda = obtener_lideres_scnexus_por_tienda($codigosTienda);
    foreach ($ventas as &$venta) {
        $codigo = trim((string) ($venta['local_codigo'] ?? ''));
        $lider = $lideresPorTienda[$codigo] ?? null;
        $venta['lider_nombre'] = ($lider && $lider['nombre'] !== '') ? $lider['nombre'] : '-';
        $venta['lider_cedula'] = ($lider && $lider['cedula'] !== '') ? $lider['cedula'] : '-';
        $venta['lider_correo'] = ($lider && $lider['correo'] !== '') ? $lider['correo'] : '-';
    }
    unset($venta);

    $resp = enviarVentasKoach360($ventas);
    $logLine = date('Y-m-d H:i:s') . ' - ' . $metaLog . ' filas=' . count($ventas) . ' resp=' . (is_string($resp) ? substr(preg_replace('/\s+/', ' ', $resp), 0, 500) : '') . "\n";
    file_put_contents(tareas_log_path('syn_put_ventas.log'), $logLine, FILE_APPEND);
?>
