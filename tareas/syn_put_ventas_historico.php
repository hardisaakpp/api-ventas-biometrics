<?php

/**
 * Ventas historicas (filtros por tienda/fecha) ? KOACH360.
 * Mismo envio que syn_put_ventas.php (enviarVentasKoach360, CLI_PVENTAS). Ventas continuas: syn_put_ventas.php retroactivo|intradia.
 * En historico lider_* van siempre como '-'.
 * Filtro SQL: ajustar fechas en WHERE.
 *
 * Tiendas en el filtro: Fase 1 (mismo alcance que syn_put_ventas.php).
 *
 * Ejecucion: php tareas/syn_put_ventas_historico.php
 */
require_once __DIR__ . '/../cx/bd_scs.php';
require_once __DIR__ . '/../cws/f_put_ventas.php';
require_once __DIR__ . '/f_tareas.php';

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
        INNER JOIN DWHE.dbo.Dim_Producto dp
	        ON fv.Key_Producto = dp.Key_Producto
        WHERE
            dv.CodigoInterno <> '114' AND
            dp.TipoProducto = 'PRODUCTO' AND
            -- =================================================================
            -- AJUSTAR TIENDAS: incluir o excluir codigos segun la tarea.
            --   Quito (8)    : 'RL-CCI','RL-CON','RL-PSC','RL-QSN','RL-QSS2','RL-REC2','RL-SCA','RL-SNL'
            --   GYE + SD (2) : 'RL-MSO3','RL-BMB'

                --'RL-CCI','RL-CON','RL-PSC','RL-QSN','RL-QSS2','RL-REC2','RL-SCA','RL-SNL', -- Quito
               -- 'RL-MSO3','RL-BMB'                                                           -- GYE + Santo Domingo
		--	'RL-BHO','RL-MRC','RL-MDA','RL-RNO'
		--	'RL-SMA','RL-RCD','RL-MNO','RL-MSU','RL-CMA','RL-RCE','RL-RNO','RL-REN'  --FASE 3

            -- =================================================================
            da.CodigoBodega IN (
		'RL-BHO','RL-MRC','RL-MDA','RL-RNO'
            )
            -- =================================================================
            -- AJUSTAR FECHAS: cambiar el rango segun el periodo a reprocesar.
            -- =================================================================
            AND dt.Fecha >= '2026-06-01' AND dt.Fecha <= '2026-06-22'
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

$resp = enviarVentasKoach360($ventas);
$logLine = date('Y-m-d H:i:s')
    . ' - ventas_historico filas=' . count($ventas)
    . ' clienteId=' . CLI_PVENTAS
    . ' resp=' . (is_string($resp) ? substr(preg_replace('/\s+/', ' ', $resp), 0, 5000) : '')
    . "\n";
file_put_contents(tareas_log_path('syn_put_ventas_historico.log'), $logLine, FILE_APPEND);
