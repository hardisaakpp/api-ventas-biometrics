<?php
    /**
     * Ventas → KOACH360 (JWT). Solo días completos (Fecha < hoy). Ventana 8 días.
     * Recurrencia sugerida: cada hora en horario laboral (cron / Task Scheduler).
     */
    require_once(__DIR__ . '/../cx/bd_scs.php'); // debe contener la función cx() para conectar
    require_once(__DIR__ . '/../cws/f_put_ventas.php'); // enviarVentasKoach360()
    require_once('f_tareas.php');
    date_default_timezone_set('America/Guayaquil');

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
                                    ELSE '-'
                                  END,
            lider_cedula        = CASE da.CodigoBodega
                                    WHEN 'RL-PSC'  THEN '1722759469'
                                    WHEN 'RL-QSS2' THEN '1716633001'
                                    WHEN 'RL-SCA'  THEN '1715033674'
                                    ELSE '9999999999'
                                  END,
            lider_correo        = CASE da.CodigoBodega
                                    WHEN 'RL-PSC'  THEN 'rolportjefe@mabeltrading.com.ec'
                                    WHEN 'RL-QSS2' THEN 'rolquisurjefe@mabeltrading.com.ec'
                                    WHEN 'RL-SCA'  THEN 'scala@mabeltrading.com.ec'
                                    ELSE 'sin-lider@mabeltrading.com.ec'
                                  END,
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
    // enviar ventas a KOACH360 (días completos únicamente: Fecha < hoy en SQL)
    $resp = enviarVentasKoach360($ventas);
    $logLine = date('Y-m-d H:i:s') . ' - ventas filas=' . count($ventas) . ' resp=' . (is_string($resp) ? substr(preg_replace('/\s+/', ' ', $resp), 0, 500) : '') . "\n";
    file_put_contents(tareas_log_path('syn_put_ventas.log'), $logLine, FILE_APPEND);
?>