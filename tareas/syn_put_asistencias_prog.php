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
     * Obtiene local_nombre desde SAP (dbo.almacenes) por código de local.
     * Retorna mapa [cod_almacen => nombre].
     */
    function asist_prog_obtener_locales_sap_por_codigo(array $codigos): array
    {
        $codes = [];
        foreach ($codigos as $codigo) {
            $c = trim((string) $codigo);
            if ($c !== '') {
                $codes[$c] = true;
            }
        }
        $codes = array_keys($codes);
        if ($codes === []) {
            return [];
        }

        $host = trim((string) cws_env('SQL_SERVER_SAP_SERVER'));
        $db = trim((string) cws_env('SQL_SERVER_SAP_DATABASE'));
        $uid = trim((string) cws_env('SQL_SERVER_SAP_UID'));
        $pwd = (string) cws_env('SQL_SERVER_SAP_PWD');
        if ($host === '' || $db === '' || $uid === '') {
            return [];
        }

        $opts = [
            'Database' => $db,
            'UID' => $uid,
            'PWD' => $pwd,
            'MultipleActiveResultSets' => true,
            'TrustServerCertificate' => true,
            'CharacterSet' => 'UTF-8',
        ];
        if (trim((string) cws_env('SQL_SERVER_SAP_ENCRYPT', '1')) !== '0') {
            $opts['Encrypt'] = 'yes';
        }

        $cxSap = sqlsrv_connect($host, $opts);
        if ($cxSap === false) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $sql = "
            SELECT
                cod_almacen,
                nombre
            FROM dbo.almacenes
            WHERE inactivo = 0
              AND cod_almacen IN ($placeholders)
            ORDER BY cod_almacen;
        ";
        $stmt = sqlsrv_query($cxSap, $sql, $codes);
        if ($stmt === false) {
            sqlsrv_close($cxSap);
            return [];
        }

        $mapa = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $codigo = trim((string) ($row['cod_almacen'] ?? ''));
            if ($codigo === '' || isset($mapa[$codigo])) {
                continue;
            }
            $mapa[$codigo] = trim((string) ($row['nombre'] ?? ''));
        }

        sqlsrv_free_stmt($stmt);
        sqlsrv_close($cxSap);
        return $mapa;
    }

    /**
     * Linked server hacia SGP (HorariosDiarios en BDDNOMINAMABEL19).
     *
     * Si PHP se conecta al DWH pero los datos solo existen vía Analítica → 210.17.1.24 (como en SSMS),
     * defina ASIST_PROG_EXEC_AT_LINKED_SERVER con el nombre del linked server al que apunta Analítica
     * (p. ej. 10.10.100.12 en sys.servers del DWH). El batch se ejecuta allí con EXEC (...) AT.
     */
    $execAtRaw = trim((string) cws_env('ASIST_PROG_EXEC_AT_LINKED_SERVER', ''));
    $useExecAt = $execAtRaw !== '';
    if ($useExecAt) {
        if (!preg_match('/^[A-Za-z0-9._\\\\-]+$/', $execAtRaw)) {
            fwrite(STDERR, "syn_put_asistencias_prog: ASIST_PROG_EXEC_AT_LINKED_SERVER inválido.\n");
            exit(1);
        }
        $execAtServer = quote_sql_identifier_prog($execAtRaw);
        $nominaLsRaw = trim((string) cws_env('ASIST_PROG_NOMINA_LINKED_SERVER', ''));
        if ($nominaLsRaw === '') {
            $nominaLsRaw = '210.17.1.24';
        }
    } else {
        $execAtServer = null;
        $nominaLsRaw = trim((string) cws_env('ASIST_PROG_NOMINA_LINKED_SERVER', ''));
        if ($nominaLsRaw === '') {
            $nominaLsRaw = trim((string) cws_env('ASIST_NOMINA_LINKED_SERVER', '210.17.1.24'));
        }
        if ($nominaLsRaw === '') {
            $nominaLsRaw = '210.17.1.24';
        }
    }
    if (!preg_match('/^[A-Za-z0-9._\\\\-]+$/', $nominaLsRaw)) {
        fwrite(STDERR, "syn_put_asistencias_prog: ASIST_PROG_NOMINA_LINKED_SERVER / ASIST_NOMINA_LINKED_SERVER inválido.\n");
        exit(1);
    }
    $nominaServer = quote_sql_identifier_prog($nominaLsRaw);

    $nominaDb = env_sql_identifier_prog('ASIST_NOMINA_DATABASE', 'BDDNOMINAMABEL19');

    $tiendasRaw = trim((string) cws_env('ASIST_PROG_CODIGOS_TIENDA', 'RL-CCI,RL-CON,RL-PSC,RL-QSN,RL-QSS2,RL-REC2,RL-SNL,RL-SCA,RL-MSO3,RL-BMB'));
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
        DECLARE @Desde DATE = @FechaBase;
        DECLARE @Hasta DATE = EOMONTH(@FechaBase);

        -- Una fila por marca (entrada Hora1, salida Hora4); mismo payload que UNION ALL pero un solo scan P/H.
        SELECT
            asesor_nombre = LTRIM(RTRIM(ISNULL(P.strDPe_Apellidos, '') + ' ' + ISNULL(P.strDPe_Nombres, ''))),
            asesor_cedula = P.strDPe_Cedula,
            asesor_cargo = '-',
            asesor_correo = '-',
            fecha = CONVERT(varchar(10), H.Fecha, 23),
            hora = LEFT(CONVERT(varchar(8), T.hora_plan, 108), 5),
            local_nombre = '',
            local_codigo = CAST(H.CodigoTienda AS varchar(64))
        FROM {$nominaServer}.{$nominaDb}.[dbo].[Tbl_DatosPersonales] P
        INNER JOIN {$nominaServer}.{$nominaDb}.[dbo].[HorariosDiarios] H
            ON H.IdEmpleado = P.intDPe_Id
        CROSS APPLY (
            SELECT v.hora_plan
            FROM (VALUES (H.Hora1), (H.Hora4)) AS v(hora_plan)
            WHERE v.hora_plan IS NOT NULL
        ) AS T
        WHERE
            H.Fecha >= @Desde AND H.Fecha <= @Hasta
            AND H.DiaLibre = 0
            AND H.CodigoTienda IN ({$tiendasIn})

        ORDER BY asesor_cedula, fecha, hora;
    ";

    $innerSql = trim($query);
    if ($useExecAt) {
        $escaped = str_replace("'", "''", $innerSql);
        $query = 'EXEC (N\'' . $escaped . '\') AT ' . $execAtServer;
    }

    $asistencias_programadas = resp_simdim($query);
    if (!is_array($asistencias_programadas)) {
        $asistencias_programadas = [];
    }
    $localesNoEncontrados = [];
    $localesResueltos = 0;
    if ($asistencias_programadas !== []) {
        $codigosLocal = array_values(array_unique(array_map(static function ($row) {
            return trim((string) ($row['local_codigo'] ?? ''));
        }, $asistencias_programadas)));
        $localesSap = asist_prog_obtener_locales_sap_por_codigo($codigosLocal);
        $localesNoEncontrados = array_values(array_filter($codigosLocal, static function ($codigo) use ($localesSap) {
            return !isset($localesSap[$codigo]) || trim((string) ($localesSap[$codigo] ?? '')) === '';
        }));
        foreach ($asistencias_programadas as &$row) {
            $codigo = trim((string) ($row['local_codigo'] ?? ''));
            $nombreSap = $localesSap[$codigo] ?? '';
            if ($nombreSap !== '') {
                $row['local_nombre'] = $nombreSap;
                $localesResueltos++;
            }
        }
        unset($row);
    }

    if ($asistencias_programadas === []) {
        $resp = 'sin_registros';
    } else {
        $resp = enviarAsistenciasProgramadasKoach360($asistencias_programadas);
    }

    $logLine = date('Y-m-d H:i:s')
        . ' - prog filas=' . count($asistencias_programadas)
        . ' locales_resueltos=' . $localesResueltos
        . ' locales_no_encontrados=' . count($localesNoEncontrados)
        . ' codigos_no_encontrados=' . ($localesNoEncontrados === [] ? '0' : implode(',', $localesNoEncontrados))
        . ' resp=' . (is_string($resp) ? substr(preg_replace('/\s+/', ' ', $resp), 0, 500) : '')
        . "\n";
    file_put_contents(tareas_log_path('syn_put_asistencias_prog.log'), $logLine, FILE_APPEND);

?>
