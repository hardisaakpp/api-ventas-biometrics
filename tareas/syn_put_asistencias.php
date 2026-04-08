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

    function syn_put_asistencias_wrap_exec(string $innerSql, bool $useExecAt, string $execAtServer): string
    {
        $innerSql = trim($innerSql);
        if ($useExecAt) {
            return 'EXEC (N\'' . str_replace("'", "''", $innerSql) . '\') AT ' . $execAtServer;
        }
        return $innerSql;
    }

    /**
     * Obtiene local_nombre desde SAP (dbo.almacenes) por código de local.
     * Retorna mapa [cod_almacen => nombre].
     */
    function asist_reales_obtener_locales_sap_por_codigo(array $codigos): array
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
     * Fecha "hoy" para la ventana: America/Guayaquil (requisito Ecuador), no GETDATE() del SQL Server.
     * Si Analítica/DWH está en UTC u otra zona, GETDATE() desplazaba el rango de 7 días.
     */
    $hoyGuayaquil = (new DateTimeImmutable('now', new DateTimeZone('America/Guayaquil')))->format('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hoyGuayaquil)) {
        fwrite(STDERR, "syn_put_asistencias: no se pudo calcular la fecha calendario Ecuador.\n");
        exit(1);
    }
    $hoySqlDate = $hoyGuayaquil;

    /**
     * Días hacia atrás con fecha fin n-1 (ayer), excluyendo el día actual.
     * Ej.: 7 → desde DATEADD(DAY,-7,@hoy) hasta < @hoy (exactamente 7 fechas: hoy-7 ... hoy-1).
     */
    $lookbackDays = (int) cws_env('ASIST_REALES_LOOKBACK_DAYS', '7');
    if ($lookbackDays < 1) {
        $lookbackDays = 1;
    }
    if ($lookbackDays > 120) {
        $lookbackDays = 120;
    }

    /**
     * Igual que syn_put_asistencias_prog: con ASIST_PROG_EXEC_AT_LINKED_SERVER el batch corre en Analítica
     * (p. ej. 10.10.100.12), donde están Record/Device locales y el linked server hacia 210.17.1.24.
     * En el DWH (MBLUIOBI\DWH) suele no existir [210.17.1.24] en sys.servers → error 7202 si no se usa EXEC AT.
     */
    $execAtRaw = trim((string) cws_env('ASIST_PROG_EXEC_AT_LINKED_SERVER', ''));
    $useExecAt = $execAtRaw !== '';
    if ($useExecAt) {
        if (!preg_match('/^[A-Za-z0-9._\\\\-]+$/', $execAtRaw)) {
            fwrite(STDERR, "syn_put_asistencias: ASIST_PROG_EXEC_AT_LINKED_SERVER inválido.\n");
            exit(1);
        }
        $execAtServer = quote_sql_identifier($execAtRaw);
    } else {
        $execAtServer = '';
    }

    $biometricDb = env_sql_identifier('ASIST_BIOMETRICO_DATABASE', 'ElrayoBiometricos');
    $biometricServer = $useExecAt ? '' : env_sql_identifier('ASIST_BIOMETRICO_LINKED_SERVER', '10.10.100.12');

    $nominaLsRaw = trim((string) cws_env('ASIST_REALES_NOMINA_LINKED_SERVER', ''));
    if ($nominaLsRaw === '') {
        if ($useExecAt) {
            $nominaLsRaw = trim((string) cws_env('ASIST_PROG_NOMINA_LINKED_SERVER', ''));
            if ($nominaLsRaw === '') {
                $nominaLsRaw = '210.17.1.24';
            }
        } else {
            $nominaLsRaw = trim((string) cws_env('ASIST_NOMINA_LINKED_SERVER', ''));
            if ($nominaLsRaw === '') {
                $nominaLsRaw = '210.17.1.24';
            }
        }
    }
    if (!preg_match('/^[A-Za-z0-9._\\\\-]+$/', $nominaLsRaw)) {
        fwrite(STDERR, "syn_put_asistencias: servidor nómina (reales) inválido tras resolver ASIST_*.\n");
        exit(1);
    }
    $nominaServer = quote_sql_identifier($nominaLsRaw);
    $nominaDb = env_sql_identifier('ASIST_NOMINA_DATABASE', 'BDDNOMINAMABEL19');

    if ($useExecAt) {
        $fromRecord = "{$biometricDb}.[dbo].[Record] R";
        $joinDevice = "INNER JOIN {$biometricDb}.[dbo].[Device] D\n                ON R.MachineNumber = D.MachineNumber";
    } else {
        $fromRecord = "{$biometricServer}.{$biometricDb}.[dbo].[Record] R";
        $joinDevice = "INNER JOIN {$biometricServer}.{$biometricDb}.[dbo].[Device] D\n                ON R.MachineNumber = D.MachineNumber";
    }

    // Misma idea que la query SSMS original (evitar TRY_CONVERT(BIGINT) que puede afectar planes o equivalencias raras).
    $joinNomina = "INNER JOIN {$nominaServer}.{$nominaDb}.[dbo].[Tbl_DatosPersonales] P
                ON (
                    P.strDPe_numtarjeta = CONVERT(NVARCHAR(64), R.IdUser)
                    OR LTRIM(RTRIM(CONVERT(NVARCHAR(64), P.strDPe_numtarjeta)))
                        = LTRIM(RTRIM(CONVERT(NVARCHAR(64), R.IdUser)))
                )";

    $filtroTiendas = "
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
                )";

    $ventanaFechas = "
            WHERE
                CONVERT(DATE, R.RecordTime) >= DATEADD(DAY, -{$lookbackDays}, @hoy)
                AND CONVERT(DATE, R.RecordTime) < @hoy
                {$filtroTiendas}";

    $declareHoy = "DECLARE @hoy DATE = CAST('{$hoySqlDate}' AS DATE);";

    $asistDebug = trim((string) cws_env('ASIST_DEBUG', '0'));

    if ($asistDebug === '2') {
        $baseWhere = "
            WHERE
                CONVERT(DATE, R.RecordTime) >= DATEADD(DAY, -{$lookbackDays}, @hoy)
                AND CONVERT(DATE, R.RecordTime) < @hoy
                {$filtroTiendas}";
        $qSin = trim("
            SET NOCOUNT ON;
            {$declareHoy}
            SELECT
                modo = N'sin_nomina',
                cnt_total = COUNT(1),
                min_fecha = MIN(CONVERT(DATE, R.RecordTime)),
                max_fecha = MAX(CONVERT(DATE, R.RecordTime))
            FROM {$fromRecord}
            {$joinDevice}
            {$baseWhere};
        ");
        $qCon = trim("
            SET NOCOUNT ON;
            {$declareHoy}
            SELECT
                modo = N'con_nomina',
                cnt_total = COUNT(1),
                min_fecha = MIN(CONVERT(DATE, R.RecordTime)),
                max_fecha = MAX(CONVERT(DATE, R.RecordTime))
            FROM {$fromRecord}
            {$joinDevice}
            {$joinNomina}
            {$baseWhere};
        ");
        $logLine = date('Y-m-d H:i:s')
            . ' - asistDebug2 hoy_ec=' . $hoyGuayaquil
            . ' lookback=' . $lookbackDays
            . ' sin=' . json_encode(resp_simdim(syn_put_asistencias_wrap_exec($qSin, $useExecAt, $execAtServer)), JSON_UNESCAPED_UNICODE)
            . ' con=' . json_encode(resp_simdim(syn_put_asistencias_wrap_exec($qCon, $useExecAt, $execAtServer)), JSON_UNESCAPED_UNICODE)
            . "\n";
        file_put_contents(tareas_log_path('syn_put_asistencias.log'), $logLine, FILE_APPEND);
        exit;
    }

    if ($asistDebug === '1') {
        $queryDebugInner = "
            SET NOCOUNT ON;
            {$declareHoy}
            SELECT
                cnt_total = COUNT(1),
                min_fecha = MIN(CONVERT(DATE, R.RecordTime)),
                max_fecha = MAX(CONVERT(DATE, R.RecordTime))
            FROM {$fromRecord}
            {$joinDevice}
            {$joinNomina}
            {$ventanaFechas};
        ";
        $queryDebug = syn_put_asistencias_wrap_exec(trim($queryDebugInner), $useExecAt, $execAtServer);

        $debugRows = resp_simdim($queryDebug);
        $row0 = is_array($debugRows) && isset($debugRows[0]) ? $debugRows[0] : [];
        $logLine = date('Y-m-d H:i:s')
            . ' - asistDebug1 hoy_ec=' . $hoyGuayaquil
            . ' lookback=' . $lookbackDays
            . ' cnt_rows=' . count($debugRows)
            . ' row0=' . json_encode($row0, JSON_UNESCAPED_UNICODE)
            . "\n";
        file_put_contents(tareas_log_path('syn_put_asistencias.log'), $logLine, FILE_APPEND);
        exit;
    }

    $queryInner = "
        SET NOCOUNT ON;
        {$declareHoy}

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
        FROM {$fromRecord}
        {$joinDevice}
        {$joinNomina}
        {$ventanaFechas}
        ORDER BY
            fecha, hora;
    ";

    $query = syn_put_asistencias_wrap_exec(trim($queryInner), $useExecAt, $execAtServer);

    $asistencias = resp_simdim($query);
    if (!is_array($asistencias)) {
        $asistencias = [];
    }
    if ($asistencias !== []) {
        $codigosLocal = array_values(array_unique(array_map(static function ($row) {
            return trim((string) ($row['local_codigo'] ?? ''));
        }, $asistencias)));
        $localesSap = asist_reales_obtener_locales_sap_por_codigo($codigosLocal);
        foreach ($asistencias as &$row) {
            $codigo = trim((string) ($row['local_codigo'] ?? ''));
            $nombreSap = $localesSap[$codigo] ?? '';
            if ($nombreSap !== '') {
                $row['local_nombre'] = $nombreSap;
            }
        }
        unset($row);
    }

    // Enviar las asistencias a Kliente (evitar POST con array vacío)
    if ($asistencias === []) {
        $resp = 'sin_registros';
    } else {
        $resp = enviarAsistenciasRealesKoach360($asistencias);
    }
    $logLine = date('Y-m-d H:i:s')
        . ' - asistencias filas=' . count($asistencias)
        . ' hoy_ec=' . $hoyGuayaquil
        . ' lookback=' . $lookbackDays
        . ' resp=' . (is_string($resp) ? substr(preg_replace('/\s+/', ' ', $resp), 0, 1500) : '')
        . "\n";
    file_put_contents(tareas_log_path('syn_put_asistencias.log'), $logLine, FILE_APPEND);
?>
