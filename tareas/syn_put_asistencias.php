<?php
    require_once(__DIR__ . '/../cx/bd_scs.php'); // debe contener la función cx() para conectar
    require_once(__DIR__ . '/../cws/f_put_asistencias.php'); // enviarAsistenciasRealesKoach360()
    require_once('f_tareas.php');
    date_default_timezone_set('America/Guayaquil');

    // Debug opcional: ASIST_DEBUG=1 solo imprime contadores de la consulta base.
    $asistDebug = cws_env('ASIST_DEBUG', '0');

    if (trim((string)$asistDebug) === '1') {
        $queryDebug = "
            DECLARE @hoy DATE = CAST(GETDATE() AS DATE);
            ;WITH AsistUnicas AS (
                SELECT DISTINCT
                    USERID,
                    Fecha = CONVERT(DATE, CHECKTIME),
                    Hora  = CONVERT(TIME, CHECKTIME)
                FROM ElRayoBiometricos.dbo.VistaRegistrosT
                WHERE CHECKTIME < CAST(@hoy AS DATETIME2)
            )
            SELECT
                cnt_unicas = (SELECT COUNT(*) FROM AsistUnicas),
                min_fecha  = (SELECT MIN(Fecha) FROM AsistUnicas),
                max_fecha  = (SELECT MAX(Fecha) FROM AsistUnicas),
                cnt_join_nomina = (
                    SELECT COUNT(*)
                    FROM AsistUnicas A
                    JOIN BDDNOMINAMABEL19..Tbl_DatosPersonales P
                        ON A.USERID COLLATE SQL_Latin1_General_CP1_CI_AS = P.strDPe_numtarjeta COLLATE SQL_Latin1_General_CP1_CI_AS
                    JOIN plataforma_web.dbo.tmp_kliente k
                        ON k.kli_txt_cedula COLLATE SQL_Latin1_General_CP1_CI_AS = P.strDPe_Cedula COLLATE SQL_Latin1_General_CP1_CI_AS
                    WHERE
                        k.kli_txt_cargo IN ('ASESOR DE VENTAS', 'ASESOR VARIOS')
                        AND k.kli_sts_estado = 1
                        AND k.id_empresa = 1
                        AND k.id_tienda IS NOT NULL
                ),
                cnt_join_tmp_by_cedula = (
                    SELECT COUNT(*)
                    FROM AsistUnicas A
                    JOIN plataforma_web.dbo.tmp_kliente k
                        ON k.kli_txt_cedula COLLATE SQL_Latin1_General_CP1_CI_AS = CAST(A.USERID AS VARCHAR(64)) COLLATE SQL_Latin1_General_CP1_CI_AS
                    WHERE
                        k.kli_txt_cargo IN ('ASESOR DE VENTAS', 'ASESOR VARIOS')
                        AND k.kli_sts_estado = 1
                        AND k.id_empresa = 1
                        AND k.id_tienda IS NOT NULL
                )
            ;
        ";

        $debugRows = resp_simdim($queryDebug);
        $row0 = is_array($debugRows) && isset($debugRows[0]) ? $debugRows[0] : [];
        $logLine = date('Y-m-d H:i:s')
            . ' - asistDebug cnt_rows=' . count($debugRows)
            . ' row0=' . json_encode($row0, JSON_UNESCAPED_UNICODE)
            . ' debugRows=' . json_encode($debugRows, JSON_UNESCAPED_UNICODE)
            . "\n";
        file_put_contents(tareas_log_path('syn_put_asistencias.log'), $logLine, FILE_APPEND);
        exit;
    }

    $query = "
        SET NOCOUNT ON;
        DECLARE @hoy DATE = CAST(GETDATE() AS DATE);

        -- Tabla temporal: evita CTE y simplifica sp_executesql
        IF OBJECT_ID('tempdb..#AsistUnicas') IS NOT NULL DROP TABLE #AsistUnicas;

        SELECT DISTINCT
            USERID,
            Fecha = CONVERT(DATE, CHECKTIME),
            Hora  = CONVERT(TIME, CHECKTIME)
        INTO #AsistUnicas
        FROM ElRayoBiometricos.dbo.VistaRegistrosT
        WHERE CHECKTIME < CAST(@hoy AS DATETIME2);

        DECLARE @sqlNomina NVARCHAR(MAX) = N'
            SELECT
                asesor_nombre = k.kli_txt_nombre,
                asesor_cedula = k.kli_txt_cedula,
                asesor_cargo  = k.kli_txt_cargo,
                asesor_correo = COALESCE(
                                    NULLIF(LTRIM(RTRIM(CAST(P.strDPe_Email AS VARCHAR(320)))), ''''),
                                    NULLIF(LTRIM(RTRIM(CAST(P.EmailEmpresa AS VARCHAR(320)))), ''''),
                                    ''-''
                                 ),
                fecha = CONVERT(VARCHAR(10), A.Fecha, 23),
                hora  = LEFT(CONVERT(VARCHAR(8), A.Hora, 108), 5),
                local_nombre = k.kli_txt_tienda,
                local_codigo = CONVERT(VARCHAR(64), k.id_tienda)
            FROM #AsistUnicas A
            JOIN BDDNOMINAMABEL19..Tbl_DatosPersonales P
                ON A.USERID COLLATE SQL_Latin1_General_CP1_CI_AS = P.strDPe_numtarjeta COLLATE SQL_Latin1_General_CP1_CI_AS
            INNER JOIN plataforma_web.dbo.tmp_kliente AS k
                ON k.kli_txt_cedula COLLATE SQL_Latin1_General_CP1_CI_AS = P.strDPe_Cedula
                AND k.kli_sts_estado = 1
            WHERE
                k.kli_txt_cargo IN (''ASESOR DE VENTAS'', ''ASESOR VARIOS'')
                AND k.id_empresa = 1
                AND k.id_tienda IS NOT NULL
            ORDER BY
                k.id_tienda, k.kli_txt_cedula, A.Fecha, A.Hora;
        ';

        -- Fallback: no usa BDDNOMINAMABEL19 (para evitar fallo cuando está offline).
        -- Intenta unir A.USERID contra una columna de tarjeta del tmp_kliente (si existe),
        -- y si no, cae a kli_txt_cedula.
        DECLARE @fallbackJoinCol SYSNAME =
            CASE
                WHEN COL_LENGTH('plataforma_web.dbo.tmp_kliente', 'kli_txt_numtarjeta') IS NOT NULL THEN 'kli_txt_numtarjeta'
                WHEN COL_LENGTH('plataforma_web.dbo.tmp_kliente', 'kli_txt_tarjeta') IS NOT NULL THEN 'kli_txt_tarjeta'
                WHEN COL_LENGTH('plataforma_web.dbo.tmp_kliente', 'kli_txt_idtarjeta') IS NOT NULL THEN 'kli_txt_idtarjeta'
                ELSE 'kli_txt_cedula'
            END;

        DECLARE @sqlFallback NVARCHAR(MAX) =
        N'
            SELECT
                asesor_nombre = k.kli_txt_nombre,
                asesor_cedula = k.kli_txt_cedula,
                asesor_cargo  = k.kli_txt_cargo,
                asesor_correo = ''-'',
                fecha = CONVERT(VARCHAR(10), A.Fecha, 23),
                hora  = LEFT(CONVERT(VARCHAR(8), A.Hora, 108), 5),
                local_nombre = k.kli_txt_tienda,
                local_codigo = CONVERT(VARCHAR(64), k.id_tienda)
            FROM #AsistUnicas A
            INNER JOIN plataforma_web.dbo.tmp_kliente AS k
                ON k.' + QUOTENAME(@fallbackJoinCol) + N' COLLATE SQL_Latin1_General_CP1_CI_AS =
                   CAST(A.USERID AS VARCHAR(64)) COLLATE SQL_Latin1_General_CP1_CI_AS
                AND k.kli_sts_estado = 1
            WHERE
                k.kli_txt_cargo IN (''ASESOR DE VENTAS'', ''ASESOR VARIOS'')
                AND k.id_empresa = 1
                AND k.id_tienda IS NOT NULL
            ORDER BY
                k.id_tienda, k.kli_txt_cedula, A.Fecha, A.Hora;
        ';

        BEGIN TRY
            -- Prioridad: intentar siempre con BDDNOMINAMABEL19 (si está online).
            EXEC sp_executesql @sqlNomina;
        END TRY
        BEGIN CATCH
            -- Si falla (p.ej. database offline), usar fallback sin BDDNOMINAMABEL19.
            EXEC sp_executesql @sqlFallback;
        END CATCH;
    ";
    $asistencias = resp_simdim($query);
    if (!is_array($asistencias)) {
        $asistencias = [];
    }

    // Enviar las asistencias a Kliente (evitar POST con array vacío)
    if ($asistencias === []) {
        $resp = 'sin_registros';
    } else {
        $resp = enviarAsistenciasRealesKoach360($asistencias);
    }
    $logLine = date('Y-m-d H:i:s') . ' - asistencias filas=' . count($asistencias) . ' resp=' . (is_string($resp) ? substr(preg_replace('/\s+/', ' ', $resp), 0, 500) : '') . "\n";
    file_put_contents(tareas_log_path('syn_put_asistencias.log'), $logLine, FILE_APPEND);
?>