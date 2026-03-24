<?php
    require_once __DIR__ . '/dat_cws.php';
    require_once __DIR__ . '/f_put_asistencias.php';
    require_once __DIR__ . '/f_koach360.php';

    /**
     * Expande bloques semanales (fecha_desde / fecha_hasta / dias_semana) a un registro por día (KOACH360).
     * dias_semana: "1;2;5" con 1=lunes … 7=domingo (DATEFIRST 1 en SQL).
     */
    function expandir_asistencias_programadas_koach360(array $filas): array {
        $tz = new DateTimeZone('America/Guayaquil');
        $horaDefault = KOACH360_HORA_PROG_DEFAULT;
        $hoy = new DateTime('now', $tz);
        $hoy->setTime(0, 0, 0);
        $out = [];

        foreach ($filas as $r) {
            $desde = DateTime::createFromFormat('Ymd', preg_replace('/\D/', '', (string) $r['fecha_desde']), $tz);
            $hasta = DateTime::createFromFormat('Ymd', preg_replace('/\D/', '', (string) $r['fecha_hasta']), $tz);
            if (!$desde || !$hasta) {
                continue;
            }
            $desde->setTime(0, 0, 0);
            $hasta->setTime(0, 0, 0);

            $partes = explode(';', (string) ($r['dias_semana'] ?? ''));
            $diasSet = [];
            foreach ($partes as $p) {
                $p = trim($p);
                if ($p === '') {
                    continue;
                }
                $n = (int) $p;
                if ($n >= 1 && $n <= 7) {
                    $diasSet[$n] = true;
                }
            }
            if ($diasSet === []) {
                continue;
            }

            $cursor = clone $desde;
            while ($cursor <= $hasta) {
                $dow = (int) $cursor->format('N');
                if (isset($diasSet[$dow]) && $cursor >= $hoy) {
                    $out[] = [
                        'asesor_nombre'  => trim((string) ($r['asesor_nombre'] ?? '')),
                        'asesor_cedula'  => trim((string) $r['asesor_cedula']),
                        'asesor_cargo'   => trim((string) $r['asesor_cargo']),
                        'asesor_correo'  => trim((string) ($r['asesor_correo'] ?? '')),
                        'fecha'          => $cursor->format('Y-m-d'),
                        'hora'           => $horaDefault,
                        'local_nombre'   => trim((string) $r['local_nombre']),
                        'local_codigo'   => trim((string) ($r['local_codigo'] ?? '')),
                    ];
                }
                $cursor->modify('+1 day');
            }
        }
        return $out;
    }

    function generarAsistenciasProgramadasJson(
        array $registros,
        $cliente_id = CLI_ASISTENCIAS_PROGRAMADAS,
        $usuario_api = US_ASISTENCIAS_PROGRAMADAS,
        $clave_api = PS_ASISTENCIAS_PROGRAMADAS
    ) {
        $expandidos = expandir_asistencias_programadas_koach360($registros);
        $cid = (int) $cliente_id;
        $payload = mapear_asistencias_koach360($expandidos, $cid, (string) $usuario_api, (string) $clave_api);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $nombreArchivo = 'asistencias__prog_' . date('Ymd_His') . '.json';
        $ruta = __DIR__ . '/../tareas/json/' . $nombreArchivo;
        if (!is_dir(dirname($ruta))) {
            @mkdir(dirname($ruta), 0775, true);
        }
        file_put_contents($ruta, $json);
        return $json;
    }

    function enviarAsistenciasProgramadas(
        array $registros,
        $url = null,
        $cliente_id = CLI_ASISTENCIAS_PROGRAMADAS,
        $usuario_api = US_ASISTENCIAS_PROGRAMADAS,
        $clave_api = PS_ASISTENCIAS_PROGRAMADAS
    ) {
        unset($url);

        $expandidos = expandir_asistencias_programadas_koach360($registros);
        if ($expandidos === []) {
            return 'sin_registros_programados_expandidos';
        }

        $cid = (int) $cliente_id;
        $payload = mapear_asistencias_koach360($expandidos, $cid, (string) $usuario_api, (string) $clave_api);
        $jsonData = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $nombreArchivo = 'asistencias__prog_' . date('Ymd_His') . '.json';
        $ruta = __DIR__ . '/../tareas/json/' . $nombreArchivo;
        if (!is_dir(dirname($ruta))) {
            @mkdir(dirname($ruta), 0775, true);
        }
        file_put_contents($ruta, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $res = koach360_post_json(KOACH360_PATH_ASIST_PROG, $jsonData);
        if (!$res['ok']) {
            return 'KOACH360 asistencias programadas HTTP ' . $res['http'] . ' ' . ($res['error'] ?: $res['body']);
        }
        return $res['body'];
    }
