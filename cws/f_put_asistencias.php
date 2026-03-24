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

    function mapear_asistencias_koach360(
        array $asistencias,
        int $clienteId,
        string $usuarioApi,
        string $claveApi
    ): array {
        $out = [];
        foreach ($asistencias as $asis) {
            $correo = trim((string) ($asis['asesor_correo'] ?? ''));
            if ($correo === '') {
                $correo = '-';
            }
            $out[] = [
                'clienteId'     => $clienteId,
                'asesorNombre'  => trim((string) $asis['asesor_nombre']),
                'asesorCedula'  => trim((string) $asis['asesor_cedula']),
                'asesorCargo'   => trim((string) $asis['asesor_cargo']),
                'asesorCorreo'  => $correo,
                'fecha'         => normalizar_fecha_asistencia_iso((string) $asis['fecha']),
                'hora'          => normalizar_hora_asistencia((string) $asis['hora']),
                'localNombre'   => trim((string) $asis['local_nombre']),
                'localCodigo'   => trim((string) ($asis['local_codigo'] ?? '')),
                'usuarioApi'    => $usuarioApi,
                'claveApi'      => $claveApi,
            ];
        }
        return $out;
    }

    function generarAsistenciasJson(
        array $asistencias,
        $cliente_id = CLI_ASISTENCIAS,
        $usuario_api = US_ASISTENCIAS,
        $clave_api = PS_ASISTENCIAS
    ) {
        $cid = (int) $cliente_id;
        $payload = mapear_asistencias_koach360($asistencias, $cid, (string) $usuario_api, (string) $clave_api);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $nombreArchivo = 'asistencias_' . date('Ymd_His') . '.json';
        $ruta = __DIR__ . '/../tareas/json/' . $nombreArchivo;
        if (!is_dir(dirname($ruta))) {
            @mkdir(dirname($ruta), 0775, true);
        }
        file_put_contents($ruta, $json);
        return $json;
    }

    function enviarAsistenciasKliente(
        array $asistencias,
        $url = null,
        $cliente_id = CLI_ASISTENCIAS,
        $usuario_api = US_ASISTENCIAS,
        $clave_api = PS_ASISTENCIAS
    ) {
        unset($url);

        $cid = (int) $cliente_id;
        $payload = mapear_asistencias_koach360($asistencias, $cid, (string) $usuario_api, (string) $clave_api);
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
