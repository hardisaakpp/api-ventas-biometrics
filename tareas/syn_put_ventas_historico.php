<?php

/**
 * Ventas historicas (filtros por tienda/fecha) ? KOACH360.
 * Mismo envio que syn_put_ventas.php (enviarVentasKoach360, CLI_PVENTAS). Ventas continuas: syn_put_ventas.php retroactivo|intradia.
 * En historico lider_* van siempre como '-'.
 * Filtro SQL: ajustar fechas en WHERE.
 *
 * Carga puntual: Roland GYE tiendas GYE — enero 2026
 *   RL-SMA  Roland San Marino Guayaquil
 *   RL-RCD  Roland Dorado Guayaquil
 *   RL-MNO  Roland Mall del Norte Guayaquil
 *   RL-MSU  Roland Mall del Sur Guayaquil
 *   RL-CMA  Roland City Mall Guayaquil
 *   RL-RCE  Roland Ceibos Guayaquil
 *   RL-RNO  Roland Riocentro Norte Guayaquil
 *   RL-REN  Roland Riocentro Entre Rios Guayaquil
 *
 * Ejecucion: php tareas/syn_put_ventas_historico.php
 */
require_once __DIR__ . '/../cx/bd_scs.php';
require_once __DIR__ . '/../cws/f_put_ventas.php';
require_once __DIR__ . '/f_tareas.php';

date_default_timezone_set('America/Guayaquil');

// =====================================================================
// CONFIGURAR ANTES DE CADA EJECUCION
// =====================================================================
$periodo_desde = '2025-06-01';   // primer día del mes
$periodo_hasta = '2025-06-30';   // último día del mes
// =====================================================================

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
            lider_cedula        = '9999999999',
            lider_correo        = 'sin-lider@mabeltrading.com.ec',
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
            -- Roland GYE tiendas GYE — carga puntual enero 2026
            -- =================================================================
            da.CodigoBodega IN (
                'RL-SMA', -- Roland San Marino Guayaquil
                'RL-RCD', -- Roland Dorado Guayaquil
                'RL-MNO', -- Roland Mall del Norte Guayaquil
                'RL-MSU', -- Roland Mall del Sur Guayaquil
                'RL-CMA', -- Roland City Mall Guayaquil
                'RL-RCE', -- Roland Ceibos Guayaquil
                'RL-RNO', -- Roland Riocentro Norte Guayaquil
                'RL-REN'  -- Roland Riocentro Entre Rios Guayaquil
            )
            AND dt.Fecha >= '$periodo_desde' AND dt.Fecha <= '$periodo_hasta'
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
$periodo_log = substr($periodo_desde, 0, 7); // yyyy-mm
$logLine = date('Y-m-d H:i:s')
    . ' - ventas_historico tiendas=RL-SMA,RL-RCD,RL-MNO,RL-MSU,RL-CMA,RL-RCE,RL-RNO,RL-REN periodo=' . $periodo_log . ' filas=' . count($ventas)
    . ' clienteId=' . CLI_PVENTAS
    . ' resp=' . (is_string($resp) ? substr(preg_replace('/\s+/', ' ', $resp), 0, 5000) : '')
    . "\n";
file_put_contents(tareas_log_path('syn_put_ventas_historico.log'), $logLine, FILE_APPEND);
