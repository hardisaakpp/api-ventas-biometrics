<?php
    require_once(__DIR__ . '/../cx/bd_scs.php'); // debe contener la función cx() para conectar
    require_once(__DIR__ . '/../cws/f_put_asistencias_prog.php');
    require_once __DIR__ . '/f_tareas.php';
    date_default_timezone_set('America/Guayaquil');

    $query = "
        DECLARE @FechaBase DATE = GETDATE(); -- Fecha base para el mes actual
                
        SET DATEFIRST 1; -- Lunes es día 1

        ;WITH DatosBase AS (
            SELECT 
                H.IdEmpleado,
                P.strDPe_Cedula,
                CONVERT(date, H.Fecha) AS FechaDate,
                DATEPART(WEEKDAY, CONVERT(date, H.Fecha)) AS DiaSemana
            FROM BDDNOMINAMABEL19.dbo.HorariosDiarios H
            INNER JOIN BDDNOMINAMABEL19.dbo.Tbl_DatosPersonales P 
                ON P.intDPe_Id = H.IdEmpleado
            WHERE 
                CONVERT(date, H.Fecha) >= CONVERT(date, @FechaBase)
                AND CONVERT(date, H.Fecha) <= EOMONTH(@FechaBase)
                AND H.DiaLibre = 0
        ),
        AgrupadoSemanas AS (
            SELECT 
                IdEmpleado,
                strDPe_Cedula,
                FechaDate,
                DiaSemana,
                DATEADD(DAY, 1 - DATEPART(WEEKDAY, FechaDate), FechaDate) AS FechaInicioSemana,
                DATEADD(DAY, 7 - DATEPART(WEEKDAY, FechaDate), FechaDate) AS FechaFinSemana,
                EOMONTH(FechaDate) AS FinDeMes
            FROM DatosBase
        ),
        AjusteSemanas AS (
            SELECT 
                IdEmpleado,
                strDPe_Cedula,
                FechaDate,
                DiaSemana,
                CASE 
                    WHEN DATEADD(DAY, 1 - DATEPART(WEEKDAY, FechaDate), FechaDate) < CONVERT(date, @FechaBase)
                        THEN CONVERT(date, @FechaBase)
                    ELSE DATEADD(DAY, 1 - DATEPART(WEEKDAY, FechaDate), FechaDate)
                END AS fecha_desde,
                CASE 
                    WHEN DATEADD(DAY, 7 - DATEPART(WEEKDAY, FechaDate), FechaDate) > EOMONTH(FechaDate)
                        THEN EOMONTH(FechaDate)
                    ELSE DATEADD(DAY, 7 - DATEPART(WEEKDAY, FechaDate), FechaDate)
                END AS fecha_hasta
            FROM AgrupadoSemanas
        ),
        ResumenHorarios AS (
            SELECT 
                IdEmpleado,
                strDPe_Cedula,
                fecha_desde,
                fecha_hasta,
                STRING_AGG(CAST(DiaSemana AS varchar), ';') 
                    WITHIN GROUP (ORDER BY FechaDate) AS dias_semana
            FROM AjusteSemanas
            GROUP BY 
                IdEmpleado,
                strDPe_Cedula,
                fecha_desde,
                fecha_hasta
        )

        SELECT 
            local_nombre = ISNULL(k.kli_txt_tienda, 'No Definido'),
            local_codigo = CONVERT(VARCHAR(64), k.id_tienda),
            asesor_nombre = k.kli_txt_nombre,
            asesor_correo = CAST('' AS VARCHAR(320)),
            asesor_cedula = k.kli_txt_cedula, 
            asesor_cargo = k.kli_txt_cargo,
            CONVERT(VARCHAR(8), H.fecha_desde, 112) AS fecha_desde,
            CONVERT(VARCHAR(8), H.fecha_hasta, 112) AS fecha_hasta,
            H.dias_semana
        FROM ResumenHorarios H
        INNER JOIN plataforma_web.dbo.tmp_kliente AS k ON k.kli_txt_cedula COLLATE SQL_Latin1_General_CP1_CI_AS = H.strDPe_Cedula
        WHERE k.kli_txt_cargo IN ('ASESOR DE VENTAS', 'ASESOR VARIOS') AND k.kli_sts_estado=1
        ORDER BY k.kli_txt_cedula, H.fecha_desde;
    ";
    $asistencias_programadas = resp_simdim($query);
    if (!is_array($asistencias_programadas)) {
        $asistencias_programadas = [];
    }
    // Enviar las asistencias programadas a Kliente
    $resp = enviarAsistenciasProgramadasKoach360($asistencias_programadas);
    $logLine = date('Y-m-d H:i:s') . ' - prog bloques=' . count($asistencias_programadas) . ' resp=' . (is_string($resp) ? substr(preg_replace('/\s+/', ' ', $resp), 0, 500) : '') . "\n";
    file_put_contents(tareas_log_path('syn_put_asistencias_prog.log'), $logLine, FILE_APPEND);

?>