<?php
    require_once(__DIR__ . '/../cx/bd_scs.php'); // debe contener la función cx() para conectar
    require_once(__DIR__ . '/../cws/f_put_asistencias.php'); // enviarAsistenciasRealesKoach360()
    require_once('f_tareas.php');
    date_default_timezone_set('America/Guayaquil');

    function quote_sql_identifier(string $value): string
    {
        return '[' . str_replace(']', ']]', $value) . ']';
    }

    function env_sql_identifier(string $envKey, string $default): string
    {
        $value = trim((string) cws_env($envKey, $default));
        if ($value === '') {
            $value = $default;
        }
        if (!preg_match('/^[A-Za-z0-9._\\\\-]+$/', $value)) {
            fwrite(STDERR, "syn_put_asistencias: valor inválido en {$envKey}.\n");
            exit(1);
        }
        return quote_sql_identifier($value);
    }

    $biometricServer = env_sql_identifier('ASIST_BIOMETRICO_LINKED_SERVER', '10.10.100.12');
    $biometricDb = env_sql_identifier('ASIST_BIOMETRICO_DATABASE', 'ElrayoBiometricos');
    $nominaServer = env_sql_identifier('ASIST_NOMINA_LINKED_SERVER', '10.10.100.12');
    $nominaDb = env_sql_identifier('ASIST_NOMINA_DATABASE', 'BDDNOMINAMABEL19');

    // Debug opcional: ASIST_DEBUG=1 solo imprime contadores de la consulta base.
    $asistDebug = cws_env('ASIST_DEBUG', '0');

    if (trim((string)$asistDebug) === '1') {
        $queryDebug = "
            DECLARE @hoy DATE = CAST(GETDATE() AS DATE);
            SELECT
                cnt_total = COUNT(1),
                min_fecha = MIN(CONVERT(DATE, R.RecordTime)),
                max_fecha = MAX(CONVERT(DATE, R.RecordTime))
            FROM {$biometricServer}.{$biometricDb}.[dbo].[Record] R
            INNER JOIN {$biometricServer}.{$biometricDb}.[dbo].[Device] D
                ON R.MachineNumber = D.MachineNumber
            INNER JOIN {$nominaServer}.{$nominaDb}.[dbo].[Tbl_DatosPersonales] P
                ON P.strDPe_numtarjeta = CONVERT(NVARCHAR(64), R.IdUser)
            WHERE
                R.RecordTime >= DATEADD(DAY, -7, CAST(@hoy AS DATETIME2))
                AND R.RecordTime < CAST(@hoy AS DATETIME2)
                AND D.Description IN
                (
                    'ROLAND-QUITO-CCI',
                    'ROLAND-QUITO-CONDADO SHOPPING',
                    'ROLAND-QUITO-PORTAL SHOPPING',
                    'ROLAND-QUITO-QUICENTRO NORTE',
                    'ROLAND-QUITO-QUICENTRO SUR',
                    'ROLAND-QUITO-RECREO',
                    'ROLAND-QUITO-SAN LUIS',
                    'ROLAND-QUITO-SCALA SHOPPING',
                    'ROLAND-GUAYAQUIL-MALL DEL SOL3',
                    'ROLAND-STO DOMINGO-BOMBOLI'
                );
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

        SELECT DISTINCT
            asesor_nombre = P.strDPe_Apellidos + ' ' + P.strDPe_Nombres,
            asesor_cedula = P.strDPe_Cedula,
            asesor_cargo  = '-',
            asesor_correo = '-',
            fecha         = CONVERT(VARCHAR(10), CONVERT(DATE, R.RecordTime), 23),
            hora          = LEFT(CONVERT(VARCHAR(8), CONVERT(TIME, R.RecordTime), 108), 5),
            local_nombre  = D.Description,
            local_codigo  = CASE D.Description
                                WHEN 'ROLAND-QUITO-CCI'                    THEN 'RL-CCI'
                                WHEN 'ROLAND-QUITO-CONDADO SHOPPING'       THEN 'RL-CON'
                                WHEN 'ROLAND-QUITO-PORTAL SHOPPING'        THEN 'RL-PSC'
                                WHEN 'ROLAND-QUITO-QUICENTRO NORTE'        THEN 'RL-QSN'
                                WHEN 'ROLAND-QUITO-QUICENTRO SUR'          THEN 'RL-QSS2'
                                WHEN 'ROLAND-QUITO-RECREO'                 THEN 'RL-REC2'
                                WHEN 'ROLAND-QUITO-SAN LUIS'               THEN 'RL-SNL'
                                WHEN 'ROLAND-QUITO-SCALA SHOPPING'         THEN 'RL-SCA'
                                WHEN 'ROLAND-GUAYAQUIL-MALL DEL SOL3'      THEN 'RL-MSO3'
                                WHEN 'ROLAND-STO DOMINGO-BOMBOLI'          THEN 'RL-BMB'
                                ELSE 'N/A'
                            END
        FROM {$biometricServer}.{$biometricDb}.[dbo].[Record] R
        INNER JOIN {$biometricServer}.{$biometricDb}.[dbo].[Device] D
            ON R.MachineNumber = D.MachineNumber
        INNER JOIN {$nominaServer}.{$nominaDb}.[dbo].[Tbl_DatosPersonales] P
            ON P.strDPe_numtarjeta = CONVERT(NVARCHAR(64), R.IdUser)
        WHERE
            R.RecordTime >= DATEADD(DAY, -7, CAST(@hoy AS DATETIME2))
            AND R.RecordTime < CAST(@hoy AS DATETIME2)
            AND D.Description IN
            (
                'ROLAND-QUITO-CCI',
                'ROLAND-QUITO-CONDADO SHOPPING',
                'ROLAND-QUITO-PORTAL SHOPPING',
                'ROLAND-QUITO-QUICENTRO NORTE',
                'ROLAND-QUITO-QUICENTRO SUR',
                'ROLAND-QUITO-RECREO',
                'ROLAND-QUITO-SAN LUIS',
                'ROLAND-QUITO-SCALA SHOPPING',
                'ROLAND-GUAYAQUIL-MALL DEL SOL3',
                'ROLAND-STO DOMINGO-BOMBOLI'
            )
        ORDER BY
            fecha, hora;
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