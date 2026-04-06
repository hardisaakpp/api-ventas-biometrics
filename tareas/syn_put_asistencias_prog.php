<?php
    require_once(__DIR__ . '/../cx/bd_scs.php'); // debe contener la función cx() para conectar
    require_once(__DIR__ . '/../cws/f_put_asistencias_prog.php');
    require_once __DIR__ . '/f_tareas.php';
    date_default_timezone_set('America/Guayaquil');

    function quote_sql_identifier_prog(string $value): string
    {
        return '[' . str_replace(']', ']]', $value) . ']';
    }

    function env_sql_identifier_prog(string $envKey, string $default): string
    {
        $value = trim((string) cws_env($envKey, $default));
        if ($value === '') {
            $value = $default;
        }
        if (!preg_match('/^[A-Za-z0-9._\\\\-]+$/', $value)) {
            fwrite(STDERR, "syn_put_asistencias_prog: valor inválido en {$envKey}.\n");
            exit(1);
        }
        return quote_sql_identifier_prog($value);
    }

    // Programadas usa la fuente de nómina/horarios.
    $nominaServer = env_sql_identifier_prog('ASIST_NOMINA_LINKED_SERVER', '10.10.100.12');
    $nominaDb = env_sql_identifier_prog('ASIST_NOMINA_DATABASE', 'BDDNOMINAMABEL19');

    // Reglas de ejecución del requerimiento:
    // - Diario los días 1..8 del mes.
    // - Semanal todos los lunes cuando el día del mes es > 8.
    $hoy = new DateTimeImmutable('today');
    $diaMes = (int) $hoy->format('j');
    $diaSemana = (int) $hoy->format('N'); // 1=lunes ... 7=domingo
    $enVentanaDiaria = ($diaMes >= 1 && $diaMes <= 8);
    $enVentanaSemanal = ($diaMes > 8 && $diaSemana === 1);
    $debeEjecutar = ($enVentanaDiaria || $enVentanaSemanal);

    if (!$debeEjecutar) {
        $motivo = 'skip_frecuencia fecha=' . $hoy->format('Y-m-d')
            . ' dia_mes=' . $diaMes
            . ' dia_semana=' . $diaSemana;
        $logLine = date('Y-m-d H:i:s') . ' - ' . $motivo . "\n";
        file_put_contents(tareas_log_path('syn_put_asistencias_prog.log'), $logLine, FILE_APPEND);
        exit;
    }

    $query = "
        SET NOCOUNT ON;
        DECLARE @hoy DATE = CAST(GETDATE() AS DATE);
        DECLARE @fin_mes DATE = EOMONTH(@hoy);

        SELECT
            asesor_nombre = P.strDPe_Apellidos + ' ' + P.strDPe_Nombres,
            asesor_cedula = P.strDPe_Cedula,
            asesor_cargo  = '-',
            asesor_correo = '-',
            fecha         = CONVERT(VARCHAR(10), CONVERT(DATE, H.Fecha), 23),
            hora          = LEFT(CONVERT(VARCHAR(8), T.hora_src, 108), 5),
            local_nombre  = H.CodigoTienda,
            local_codigo  = H.CodigoTienda
        FROM {$nominaServer}.{$nominaDb}.[dbo].[Tbl_DatosPersonales] P
        INNER JOIN {$nominaServer}.{$nominaDb}.[dbo].[HorariosDiarios] H
            ON H.IdEmpleado = P.intDPe_Id
        CROSS APPLY (
            VALUES
                (CONVERT(TIME, H.Hora1)),
                (CONVERT(TIME, H.Hora4))
        ) T(hora_src)
        WHERE
            CONVERT(DATE, H.Fecha) >= @hoy
            AND CONVERT(DATE, H.Fecha) <= @fin_mes
            AND H.DiaLibre = 0
            AND H.CodigoTienda IN ('RL-PSC', 'RL-QSS2', 'RL-SCA')
            AND T.hora_src IS NOT NULL
        ORDER BY
            fecha, hora, asesor_cedula;
    ";
    $asistencias_programadas = resp_simdim($query);
    if (!is_array($asistencias_programadas)) {
        $asistencias_programadas = [];
    }
    // Enviar asistencias programadas (solo hoy/futuro) a KOACH360
    if ($asistencias_programadas === []) {
        $resp = 'sin_registros';
    } else {
        $resp = enviarAsistenciasProgramadasKoach360($asistencias_programadas);
    }
    $logLine = date('Y-m-d H:i:s') . ' - prog bloques=' . count($asistencias_programadas) . ' resp=' . (is_string($resp) ? substr(preg_replace('/\s+/', ' ', $resp), 0, 500) : '') . "\n";
    file_put_contents(tareas_log_path('syn_put_asistencias_prog.log'), $logLine, FILE_APPEND);

?>