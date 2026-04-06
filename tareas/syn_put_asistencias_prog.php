<?php
    require_once __DIR__ . '/../cx/bd_scs.php'; // debe contener la función cx() para conectar
    require_once __DIR__ . '/../cws/f_put_asistencias_prog.php';
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

    /**
     * Linked server SGP (nómina) para asistencias programadas.
     * Por defecto 210.17.1.24; si vacío se usa ASIST_NOMINA_LINKED_SERVER y luego el default.
     */
    $nominaLsRaw = trim((string) cws_env('ASIST_PROG_NOMINA_LINKED_SERVER', ''));
    if ($nominaLsRaw === '') {
        $nominaLsRaw = trim((string) cws_env('ASIST_NOMINA_LINKED_SERVER', '210.17.1.24'));
    }
    if ($nominaLsRaw === '') {
        $nominaLsRaw = '210.17.1.24';
    }
    if (!preg_match('/^[A-Za-z0-9._\\\\-]+$/', $nominaLsRaw)) {
        fwrite(STDERR, "syn_put_asistencias_prog: ASIST_PROG_NOMINA_LINKED_SERVER / ASIST_NOMINA_LINKED_SERVER inválido.\n");
        exit(1);
    }
    $nominaServer = quote_sql_identifier_prog($nominaLsRaw);

    $nominaDb = env_sql_identifier_prog('ASIST_NOMINA_DATABASE', 'BDDNOMINAMABEL19');

    $tiendasRaw = trim((string) cws_env('ASIST_PROG_CODIGOS_TIENDA', 'RL-PSC,RL-QSS2,RL-SCA'));
    $tiendas = array_values(array_filter(array_map('trim', explode(',', $tiendasRaw)), static function ($t) {
        return $t !== '';
    }));
    if ($tiendas === []) {
        fwrite(STDERR, "syn_put_asistencias_prog: ASIST_PROG_CODIGOS_TIENDA no tiene códigos válidos.\n");
        exit(1);
    }
    $tiendasSql = [];
    foreach ($tiendas as $t) {
        if (!preg_match('/^[A-Za-z0-9._\\-]+$/', $t)) {
            fwrite(STDERR, "syn_put_asistencias_prog: código de tienda inválido en lista: {$t}\n");
            exit(1);
        }
        $tiendasSql[] = "N'" . str_replace("'", "''", $t) . "'";
    }
    $tiendasIn = implode(',', $tiendasSql);

    $query = "
        SET NOCOUNT ON;

        DECLARE @FechaBase DATE = CAST(GETDATE() AS DATE);
        DECLARE @Desde DATE = DATEFROMPARTS(YEAR(@FechaBase), MONTH(@FechaBase), 1);
        DECLARE @Hasta DATE = DATEADD(MONTH, 1, @Desde);

        SELECT
            asesor_nombre = LTRIM(RTRIM(ISNULL(P.strDPe_Apellidos, '') + ' ' + ISNULL(P.strDPe_Nombres, ''))),
            asesor_cedula = P.strDPe_Cedula,
            asesor_cargo = '-',
            asesor_correo = '-',
            fecha = CONVERT(varchar(10), H.Fecha, 23),
            hora = LEFT(CONVERT(varchar(8), H.Hora1, 108), 5),
            local_nombre = '',
            local_codigo = CAST(H.CodigoTienda AS varchar(64))
        FROM {$nominaServer}.{$nominaDb}.[dbo].[Tbl_DatosPersonales] P
        INNER JOIN {$nominaServer}.{$nominaDb}.[dbo].[HorariosDiarios] H
            ON H.IdEmpleado = P.intDPe_Id
        WHERE
            H.Fecha >= @Desde AND H.Fecha < @Hasta
            AND H.DiaLibre = 0
            AND H.CodigoTienda IN ({$tiendasIn})
            AND H.Hora1 IS NOT NULL

        UNION ALL

        SELECT
            asesor_nombre = LTRIM(RTRIM(ISNULL(P.strDPe_Apellidos, '') + ' ' + ISNULL(P.strDPe_Nombres, ''))),
            asesor_cedula = P.strDPe_Cedula,
            asesor_cargo = '-',
            asesor_correo = '-',
            fecha = CONVERT(varchar(10), H.Fecha, 23),
            hora = LEFT(CONVERT(varchar(8), H.Hora4, 108), 5),
            local_nombre = '',
            local_codigo = CAST(H.CodigoTienda AS varchar(64))
        FROM {$nominaServer}.{$nominaDb}.[dbo].[Tbl_DatosPersonales] P
        INNER JOIN {$nominaServer}.{$nominaDb}.[dbo].[HorariosDiarios] H
            ON H.IdEmpleado = P.intDPe_Id
        WHERE
            H.Fecha >= @Desde AND H.Fecha < @Hasta
            AND H.DiaLibre = 0
            AND H.CodigoTienda IN ({$tiendasIn})
            AND H.Hora4 IS NOT NULL

        ORDER BY asesor_cedula, fecha, hora;
    ";

    $asistencias_programadas = resp_simdim($query);
    if (!is_array($asistencias_programadas)) {
        $asistencias_programadas = [];
    }

    if ($asistencias_programadas === []) {
        $resp = 'sin_registros';
    } else {
        $resp = enviarAsistenciasProgramadasKoach360($asistencias_programadas);
    }

    $logLine = date('Y-m-d H:i:s')
        . ' - prog filas=' . count($asistencias_programadas)
        . ' resp=' . (is_string($resp) ? substr(preg_replace('/\s+/', ' ', $resp), 0, 500) : '')
        . "\n";
    file_put_contents(tareas_log_path('syn_put_asistencias_prog.log'), $logLine, FILE_APPEND);

?>
