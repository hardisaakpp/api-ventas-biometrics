<?php
    /**
     * Ventas históricas por tiendas y fechas (Quito vs Guayaquil/Santo Domingo) → KOACH360.
     * Filtro actual: un día por región (ajustar en SQL si pasás a between / rangos).
     * Reutiliza enviarVentasKoach360() (mismo endpoint JWT que syn_put_ventas.php).
     *
     * Ejecución: php tareas/syn_put_ventas_historico.php
     */
    require_once __DIR__ . '/../cx/bd_scs.php';
    require_once __DIR__ . '/../cws/f_put_ventas.php';
    require_once __DIR__ . '/f_tareas.php';

    date_default_timezone_set('America/Guayaquil');

    /** Cliente KOACH360 para esta carga (según negocio: 21). */
    const VENTAS_HISTORICO_CLIENTE_ID = 21;

    $query = "
        SELECT
            factura_numero      = df.NumeroFactura,
            factura_fecha       = CONVERT(VARCHAR(10), dt.Fecha, 23),
            factura_hora        = LEFT(CONVERT(VARCHAR(8), df.HoraEmision, 108), 5),
            factura_origen      = IIF(dtd.Nombre_TipoDocumento = 'Factura', 'FAC', 'NC'),
            asesor_nombre       = dv.NombreVendedor,
            asesor_cedula       = dv.DocumentoVendedor,
            asesor_correo       = '',
            lider_nombre        = CASE da.CodigoBodega
                                    WHEN 'RL-PSC'  THEN 'GOMEZ IMBAQUINGO SILVIA ANDREA'
                                    WHEN 'RL-QSS2' THEN 'CUEVA MESA CHRISTIAN ANTONIO'
                                    WHEN 'RL-SCA'  THEN 'TUTILLO BENAVIDES DIGNA EVELIN'
                                    ELSE 'SIN LIDER'
                                  END,
            lider_cedula        = CASE da.CodigoBodega
                                    WHEN 'RL-PSC'  THEN '1722759469'
                                    WHEN 'RL-QSS2' THEN '1716633001'
                                    WHEN 'RL-SCA'  THEN '1715033674'
                                    ELSE '-'
                                  END,
            lider_correo        = CASE da.CodigoBodega
                                    WHEN 'RL-PSC'  THEN 'rolportjefe@mabeltrading.com.ec'
                                    WHEN 'RL-QSS2' THEN 'rolquisurjefe@mabeltrading.com.ec'
                                    WHEN 'RL-SCA'  THEN 'scala@mabeltrading.com.ec'
                                    ELSE '-'
                                  END,
            local_nombre        = da.NombreBodega,
            local_codigo        = da.CodigoBodega,
            valor_transaccion   = CONVERT(DECIMAL(18,2), SUM(fv.TotalPagado)),
            cantidad_unidades   = SUM(fv.CantidadArticulos),
            dv.CodigoInterno
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
            dv.CodigoInterno <> '114'
            AND da.CodigoBodega IN (
                'RL-CCI','RL-CON','RL-PSC','RL-QSN','RL-QSS2',
                'RL-REC2','RL-SCA','RL-SNL',
                'RL-MSO3','RL-BMB'
            )
            AND (
                (
                    -- Quito
                    da.CodigoBodega IN (
                        'RL-CCI','RL-CON','RL-PSC','RL-QSN',
                        'RL-QSS2','RL-REC2','RL-SCA','RL-SNL'
                    )
                    AND dt.Fecha = '2025-08-01'
                    -- AND dt.Fecha BETWEEN '2025-08-01' AND '2025-08-08'
                    -- AND dt.Fecha >= '2025-08-01'
                    -- AND dt.Fecha < '2025-08-31'
                )
                OR
                (
                    -- Guayaquil + Santo Domingo
                    da.CodigoBodega IN ('RL-MSO3','RL-BMB')
                    AND dt.Fecha = '2026-01-01'
                    -- AND dt.Fecha BETWEEN '2026-01-01' AND '2026-01-09'
                    -- AND dt.Fecha >= '2026-01-01'
                    -- AND dt.Fecha < '2026-01-31'
                )
            )
        GROUP BY
            df.NumeroFactura,
            dt.Fecha,
            df.HoraEmision,
            dv.NombreVendedor,
            dv.DocumentoVendedor,
            da.NombreBodega,
            da.CodigoBodega,
            dtd.Nombre_TipoDocumento,
            dv.CodigoInterno
        ORDER BY
            da.CodigoBodega,
            dt.Fecha,
            df.NumeroFactura;
    ";

    $ventas = resp_simdim($query);
    if (!is_array($ventas)) {
        $ventas = [];
    }

    $resp = enviarVentasKoach360($ventas, null, VENTAS_HISTORICO_CLIENTE_ID);
    $logLine = date('Y-m-d H:i:s')
        . ' - ventas_historico filas=' . count($ventas)
        . ' clienteId=' . VENTAS_HISTORICO_CLIENTE_ID
        . ' resp=' . (is_string($resp) ? substr(preg_replace('/\s+/', ' ', $resp), 0, 500) : '')
        . "\n";
    file_put_contents(tareas_log_path('syn_put_ventas_historico.log'), $logLine, FILE_APPEND);
