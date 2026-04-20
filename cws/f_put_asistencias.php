<?php
    require_once __DIR__ . '/dat_cws.php';
    require_once __DIR__ . '/f_koach360.php';

    function normalizar_fecha_asistencia_iso(string $fecha): string {
        $fecha = trim($fecha);
        if (preg_match('/^\d{8}$/', $fecha)) {
            return substr($fecha, 0, 4) . '-' . substr($fecha, 4, 2) . '-' . substr($fecha, 6, 2);
        }
        return $fecha;
    }

    function normalizar_hora_asistencia(string $hora): string {
        $hora = trim($hora);
        if (preg_match('/^(\d{1,2}:\d{2})/', $hora, $m)) {
            $parts = explode(':', $m[1]);
            return str_pad((string) (int) $parts[0], 2, '0', STR_PAD_LEFT) . ':' . str_pad($parts[1], 2, '0', STR_PAD_LEFT);
        }
        return $hora;
    }

    /**
     * Trae cargo y correo por cédula desde SCNexus (store_control.store_employees).
     * Retorna mapa [cedula_identidad => ['cargo' => ..., 'correo' => ...]].
     *
     * Se lee la tabla completa (estado = 1) y el cruce se hace en PHP contra las
     * cédulas recibidas. Evita el IN (?, ?, ...) con parámetros, que en algunos
     * entornos falla silenciosamente por conversión implícita de tipos y deja
     * vacío el enriquecimiento de cargo/correo.
     */
    function obtener_datos_scnexus_por_cedula(array $cedulas): array {
        $cedulasBuscadas = [];
        foreach ($cedulas as $cedula) {
            $c = trim((string) $cedula);
            if ($c !== '') {
                $cedulasBuscadas[$c] = true;
            }
        }
        if ($cedulasBuscadas === []) {
            return [];
        }

        $host = cws_env('STORE_EMPLOYEES_SQL_HOST', '210.17.1.54');
        $db   = cws_env('STORE_EMPLOYEES_SQL_DATABASE', 'store_control');
        $user = cws_env('STORE_EMPLOYEES_SQL_USER');
        $pass = cws_env('STORE_EMPLOYEES_SQL_PASSWORD');
        if ($host === '' || $db === '' || $user === '' || $pass === '') {
            return [];
        }

        $cxScnexus = sqlsrv_connect($host, [
            'Database' => $db,
            'UID' => $user,
            'PWD' => $pass,
            'MultipleActiveResultSets' => true,
            'TrustServerCertificate' => true,
            'CharacterSet' => 'UTF-8',
        ]);
        if ($cxScnexus === false) {
            return [];
        }

        $sql = "
            SELECT
                cedula_identidad,
                cargo,
                correo,
                id
            FROM dbo.store_employees
            WHERE estado = 1
            ORDER BY cedula_identidad, id DESC;
        ";

        $stmt = sqlsrv_query($cxScnexus, $sql);
        if ($stmt === false) {
            $errs = sqlsrv_errors();
            if (is_array($errs) && $errs !== []) {
                error_log('obtener_datos_scnexus_por_cedula sqlsrv_errors=' . json_encode($errs, JSON_UNESCAPED_UNICODE));
            }
            sqlsrv_close($cxScnexus);
            return [];
        }

        $mapa = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $cedula = trim((string) ($row['cedula_identidad'] ?? ''));
            if ($cedula === '' || isset($mapa[$cedula])) {
                continue;
            }
            if (!isset($cedulasBuscadas[$cedula])) {
                continue;
            }
            $mapa[$cedula] = [
                'cargo' => trim((string) ($row['cargo'] ?? '')),
                'correo' => trim((string) ($row['correo'] ?? '')),
            ];
        }

        sqlsrv_free_stmt($stmt);
        sqlsrv_close($cxScnexus);
        return $mapa;
    }

    /**
     * Mapea registros de asistencias al formato KOACH360 (manual).
     * Nota: el token JWT va por header (no en el body), por eso aquí NO se envían
     * credenciales adicionales.
     */
    function mapear_asistencias_koach360(
        array $asistencias,
        int $clienteId
    ): array {
        $out = [];
        $cedulas = array_values(array_unique(array_map(static function ($asis) {
            return trim((string) ($asis['asesor_cedula'] ?? ''));
        }, $asistencias)));
        $datosScnexusPorCedula = obtener_datos_scnexus_por_cedula($cedulas);
        foreach ($asistencias as $asis) {
            $correo = trim((string) ($asis['asesor_correo'] ?? ''));
            $cedula = trim((string) ($asis['asesor_cedula'] ?? ''));
            $cargo = trim((string) ($asis['asesor_cargo'] ?? ''));
            $datosScnexus = $datosScnexusPorCedula[$cedula] ?? null;
            $cargoScnexus = is_array($datosScnexus) ? trim((string) ($datosScnexus['cargo'] ?? '')) : '';
            $correoScnexus = is_array($datosScnexus) ? trim((string) ($datosScnexus['correo'] ?? '')) : '';
            if ($cargoScnexus !== '') {
                $cargo = $cargoScnexus;
            }
            if ($correoScnexus !== '') {
                $correo = $correoScnexus;
            }
            if ($cargo === '' || $cargo === '-') {
                $cargo = 'Asesor de Ventas';
            }
            if ($correo === '') {
                // Fallback seguro para KOACH360: no enviar string vacío
                $correo = '-';
            }
            $out[] = [
                'clienteId'     => $clienteId,
                'asesorNombre'  => trim((string) $asis['asesor_nombre']),
                'asesorCedula'  => $cedula,
                'asesorCargo'   => $cargo,
                'asesorCorreo'  => $correo,
                'fecha'         => normalizar_fecha_asistencia_iso((string) $asis['fecha']),
                'hora'          => normalizar_hora_asistencia((string) $asis['hora']),
                'localNombre'   => trim((string) $asis['local_nombre']),
                'localCodigo'   => trim((string) ($asis['local_codigo'] ?? '')),
            ];
        }
        return $out;
    }

    function generarAsistenciasRealesKoach360Json(
        array $asistencias,
        $cliente_id = CLI_ASISTENCIAS
    ) {
        $cid = (int) $cliente_id;
        $payload = mapear_asistencias_koach360($asistencias, $cid);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $nombreArchivo = 'asistencias_' . date('Ymd_His') . '.json';
        $ruta = __DIR__ . '/../tareas/json/' . $nombreArchivo;
        if (!is_dir(dirname($ruta))) {
            @mkdir(dirname($ruta), 0775, true);
        }
        file_put_contents($ruta, $json);
        return $json;
    }

    /**
     * @deprecated Usar generarAsistenciasRealesKoach360Json
     */
    function generarAsistenciasJson(array $asistencias, $cliente_id = CLI_ASISTENCIAS) {
        return generarAsistenciasRealesKoach360Json($asistencias, $cliente_id);
    }

    function enviarAsistenciasRealesKoach360(
        array $asistencias,
        $url = null,
        $cliente_id = CLI_ASISTENCIAS
    ) {
        unset($url);

        $cid = (int) $cliente_id;
        $payload = mapear_asistencias_koach360($asistencias, $cid);
        $jsonData = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $nombreArchivo = 'asistencias_' . date('Ymd_His') . '.json';
        $ruta = __DIR__ . '/../tareas/json/' . $nombreArchivo;
        if (!is_dir(dirname($ruta))) {
            @mkdir(dirname($ruta), 0775, true);
        }
        file_put_contents($ruta, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $res = koach360_post_json(KOACH360_PATH_ASIST_REAL, $jsonData);
        if (!$res['ok']) {
            return 'KOACH360 asistencias HTTP ' . $res['http'] . ' ' . ($res['error'] ?: $res['body']);
        }
        return $res['body'];
    }

    /**
     * @deprecated Usar enviarAsistenciasRealesKoach360
     */
    function enviarAsistenciasKliente(array $asistencias, $url = null, $cliente_id = CLI_ASISTENCIAS) {
        return enviarAsistenciasRealesKoach360($asistencias, $url, $cliente_id);
    }
