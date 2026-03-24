<?php
    require_once __DIR__ . '/dat_cws.php';
    require_once __DIR__ . '/f_koach360.php';

    /**
     * Convierte fecha desde SQL (yyyy-mm-dd o yyyymmdd) a yyyy-mm-dd.
     */
    function normalizar_fecha_venta_iso(string $fecha): string {
        $fecha = trim($fecha);
        if (preg_match('/^\d{8}$/', $fecha)) {
            return substr($fecha, 0, 4) . '-' . substr($fecha, 4, 2) . '-' . substr($fecha, 6, 2);
        }
        return $fecha;
    }

    /**
     * Normaliza hora a HH:mm (entrada HH:mm:ss u otros).
     */
    function normalizar_hora_venta(string $hora): string {
        $hora = trim($hora);
        if (preg_match('/^(\d{1,2}:\d{2})/', $hora, $m)) {
            $parts = explode(':', $m[1]);
            return str_pad((string) (int) $parts[0], 2, '0', STR_PAD_LEFT) . ':' . str_pad($parts[1], 2, '0', STR_PAD_LEFT);
        }
        return $hora;
    }

    /**
     * Arma el cuerpo KOACH360 (camelCase según manual). Notas de crédito: importes y unidades en negativo.
     */
    function mapear_ventas_koach360(
        array $facturasBase,
        int $clienteId,
        string $usuarioApi,
        string $claveApi
    ): array {
        $out = [];
        foreach ($facturasBase as $factura) {
            $origen = strtoupper(trim((string) ($factura['factura_origen'] ?? 'FAC')));
            $valor = (float) $factura['valor_transaccion'];
            $unidades = (int) round((float) $factura['cantidad_unidades']);
            if ($origen === 'NC') {
                $valor = -abs($valor);
                $unidades = -abs($unidades);
            }

            $out[] = [
                'asesorCedula'      => trim((string) $factura['asesor_cedula']),
                'asesorCorreo'      => trim((string) ($factura['asesor_correo'] ?: '-')),
                'asesorNombre'      => trim((string) $factura['asesor_nombre']),
                'claveApi'          => $claveApi,
                'clienteId'         => $clienteId,
                'facturaFecha'      => normalizar_fecha_venta_iso((string) $factura['factura_fecha']),
                'facturaHora'       => normalizar_hora_venta((string) $factura['factura_hora']),
                'facturaNumero'     => trim((string) $factura['factura_numero']),
                'facturaOrigen'     => $origen,
                'liderCedula'       => trim((string) $factura['lider_cedula']),
                'liderCorreo'       => trim((string) $factura['lider_correo']),
                'liderNombre'       => trim((string) $factura['lider_nombre']),
                'localNombre'       => trim((string) $factura['local_nombre']),
                'localCodigo'       => trim((string) $factura['local_codigo']),
                'usuarioApi'        => $usuarioApi,
                'valorTransaccion'  => $valor,
                'cantidadUnidades'  => $unidades,
            ];
        }
        return $out;
    }

    function generarFacturasJson(
        array $facturasBase,
        $cliente_id = CLI_PVENTAS,
        $usuario_api = US_PVENTAS,
        $clave_api = PS_PVENTAS
    ) {
        $cid = (int) $cliente_id;
        $payload = mapear_ventas_koach360($facturasBase, $cid, (string) $usuario_api, (string) $clave_api);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $nombreArchivo = 'ventas_' . date('Ymd_His') . '.json';
        $ruta = __DIR__ . '/../tareas/json/' . $nombreArchivo;
        if (!is_dir(dirname($ruta))) {
            @mkdir(dirname($ruta), 0775, true);
        }
        file_put_contents($ruta, $json);
        return $json;
    }

    /**
     * Envía ventas a KOACH360 (POST + JWT). Solo días completos deben venir en $facturas (filtrar en SQL).
     */
    function enviarFacturasKliente(
        array $facturas,
        $url = null,
        $cliente_id = CLI_PVENTAS,
        $usuario_api = US_PVENTAS,
        $clave_api = PS_PVENTAS
    ) {
        unset($url);

        $cid = (int) $cliente_id;
        $payload = mapear_ventas_koach360($facturas, $cid, (string) $usuario_api, (string) $clave_api);
        $jsonData = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $nombreArchivo = 'ventas_' . date('Ymd_His') . '.json';
        $ruta = __DIR__ . '/../tareas/json/' . $nombreArchivo;
        if (!is_dir(dirname($ruta))) {
            @mkdir(dirname($ruta), 0775, true);
        }
        file_put_contents($ruta, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $res = koach360_post_json(KOACH360_PATH_VENTAS, $jsonData);
        if (!$res['ok']) {
            return 'KOACH360 ventas HTTP ' . $res['http'] . ' ' . ($res['error'] ?: $res['body']);
        }
        return $res['body'];
    }
