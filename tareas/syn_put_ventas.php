<?php
    /**
     * Ventas → KOACH360 (JWT). Solo días completos (Fecha < hoy). Ventana 8 días.
     * Recurrencia sugerida: cada hora en horario laboral (cron / Task Scheduler).
     */
    require_once(__DIR__ . '/../cx/bd_scs.php'); // debe contener la función cx() para conectar
    require_once(__DIR__ . '/../cws/f_put_ventas.php'); // debe contener la función generarFacturasJson()
    require_once('f_tareas.php');
    require_once __DIR__ . '/ventas_query_helpers.php';
    date_default_timezone_set('America/Guayaquil');

    $correoFrag = ventas_syn_asesor_correo_fragments();

    $query = "
        SELECT
            factura_numero      = V.Documento,
            factura_fecha       = CONVERT(VARCHAR(10), V.Fecha, 23),
            factura_hora        = LEFT(CONVERT(VARCHAR(8), V.Hora, 108), 5),
            factura_origen      = CASE V.TipoDocumento
                                    WHEN 'FC' THEN 'FAC'
                                    WHEN 'NC' THEN 'NC'
                                    ELSE 'ND'
                                    END,
            asesor_nombre       = CASE V.TipoDocumento
                                    WHEN 'FC' THEN ISNULL(E.Apellido1 + ' ' + E.Apellido2 + ' ' + E.Nombre1 + ' ' + E.Nombre2, LTRIM(RTRIM(V.Vendedor)))
                                    WHEN 'NC' THEN 'NA'
                                    ELSE 'ND'
                                    END,
            asesor_cedula       = CASE V.TipoDocumento
                                    WHEN 'FC' THEN ISNULL(E.Identificacion, LTRIM(RTRIM(V.CodVendedor)))
                                    WHEN 'NC' THEN 'NA'
                                    ELSE 'ND'
                                    END,
            -- Correo: BDDNOMINAMABEL19..Tbl_DatosPersonales por cédula; columna = VENTAS_ASESOR_CORREO_COLUMN (.env), p. ej. strDPe_Email.
            asesor_correo       = {$correoFrag['asesorCorreoExpr']},
            lider_nombre        = CASE V.CodTienda
                                    WHEN 'RL-PSC'  THEN 'GOMEZ IMBAQUINGO SILVIA ANDREA'
                                    WHEN 'RL-QSS2' THEN 'CUEVA MESA CHRISTIAN ANTONIO'
                                    WHEN 'RL-SCA'  THEN 'TUTILLO BENAVIDES DIGNA EVELIN'
                                END,
            lider_cedula        = CASE V.CodTienda
                                    WHEN 'RL-PSC'  THEN '1722759469'
                                    WHEN 'RL-QSS2' THEN '1716633001'
                                    WHEN 'RL-SCA'  THEN '1715033674'
                                END,
            lider_correo        = CASE V.CodTienda
                                    WHEN 'RL-PSC'  THEN 'rolportjefe@mabeltrading.com.ec'
                                    WHEN 'RL-QSS2' THEN 'rolquisurjefe@mabeltrading.com.ec'
                                    WHEN 'RL-SCA'  THEN 'scala@mabeltrading.com.ec'
                                END,
            local_nombre        = V.Tienda,
            local_codigo        = V.CodTienda,
            valor_transaccion   = CONVERT(DECIMAL(18,2), V.Neto),
			cantidad_unidades = V.UnidadPorFactura
        FROM Analitica..DWH_VENTASGENERAL_VIEW V
        LEFT JOIN BISTAGING..STG_EMPLEADOS E 
            ON V.CodVendedor = E.CodVendedor AND E.IdEmpresa = 1
        {$correoFrag['applySql']}
        WHERE
            V.Fecha >= DATEADD(DAY, -8, CAST(GETDATE() AS DATE))
            AND V.Fecha < CAST(GETDATE() AS DATE)
            --V.Fecha >= '2025-07-01'
           -- AND V.Fecha < '2025-08-01'

            AND V.CodVendedor NOT IN ('114', '1150')
            AND V.CodTienda IN ('RL-PSC', 'RL-QSS2', 'RL-SCA')
        ORDER BY
            V.CodTienda,
            V.Fecha,
            V.Documento;
    ";

    $ventas = resp_simdim($query);
    if (!is_array($ventas)) {
        $ventas = [];
    }
    // enviar ventas a KOACH360 (días completos únicamente: Fecha < hoy en SQL)
    $resp = enviarFacturasKliente($ventas);
    $logLine = date('Y-m-d H:i:s') . ' - ventas filas=' . count($ventas) . ' resp=' . (is_string($resp) ? substr(preg_replace('/\s+/', ' ', $resp), 0, 500) : '') . "\n";
    file_put_contents(tareas_log_path('syn_put_ventas.log'), $logLine, FILE_APPEND);
?>