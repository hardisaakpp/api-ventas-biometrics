<?php
    /**
     * Ventas → KOACH360 (JWT). Solo días completos (Fecha < hoy). Ventana 8 días.
     * Recurrencia sugerida: cada hora en horario laboral (cron / Task Scheduler).
     *
     * Tiendas en el filtro SQL: Fase 1 (alcance inicial del proyecto); por eso solo esos CodigoBodega.
     */
    require_once(__DIR__ . '/../cx/bd_scs.php'); // debe contener la función cx() para conectar
    require_once(__DIR__ . '/../cws/f_put_ventas.php'); // enviarVentasKoach360()
    require_once('f_tareas.php');
    date_default_timezone_set('America/Guayaquil');

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

    $query = "
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
        WHERE
            dt.Fecha >= DATEADD(DAY, -8, CAST(GETDATE() AS DATE))
            AND dt.Fecha < CAST(GETDATE() AS DATE)
            AND da.CodigoBodega IN (
                'RL-CCI','RL-CON','RL-PSC','RL-QSN','RL-QSS2',
                'RL-REC2','RL-SCA','RL-SNL','RL-MSO3','RL-BMB'
            )
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
    // enviar ventas a KOACH360 (días completos únicamente: Fecha < hoy en SQL)
    $resp = enviarVentasKoach360($ventas);
    $logLine = date('Y-m-d H:i:s') . ' - ventas filas=' . count($ventas) . ' resp=' . (is_string($resp) ? substr(preg_replace('/\s+/', ' ', $resp), 0, 500) : '') . "\n";
    file_put_contents(tareas_log_path('syn_put_ventas.log'), $logLine, FILE_APPEND);
?>