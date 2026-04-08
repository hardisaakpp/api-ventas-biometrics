<?php
    require_once(__DIR__ . '/../cws/f_consumo_vcount.php'); // consumir_vcount_data()
    // Fuerza credenciales SQL del perfil VCOUNT (SQL_SCS_*_VCOUNT en .env).
    putenv('CWS_SQL_PROFILE=VCOUNT');
    $_ENV['CWS_SQL_PROFILE'] = 'VCOUNT';
    require_once(__DIR__ . '/../cx/bd_scs.php');            // transac_data()
    require_once('f_tareas.php');
    date_default_timezone_set('America/Guayaquil');
    $logFile = tareas_log_path('syn_consumo_vcount.log');
    $startedAt = microtime(true);

    // 1. Calcular fechas de los últimos 7 días (hasta ayer)
    $rango7 = rangoDiasDesdeAyer(7);
    $fecha_fin_consumo = date('Y-m-d', strtotime('yesterday'));
    $fecha_inicio = $rango7['fecha_inicio'];
    $fecha_fin    = $rango7['fecha_fin'];

    try {
        // 2. Borrar registros del rango
        $tsql_delete = "
            DELETE FROM BISTAGING.dbo.STG_ConsumoContador
            WHERE CAST(timeformatted AS DATE) >= '$fecha_inicio' AND CAST(timeformatted AS DATE) < '$fecha_fin';
        ";
        $deleteOk = (int) transac_data($tsql_delete) === 1;

        // 3. Consumir e insertar nuevos datos
        $datos = consumir_vcount_data($fecha_inicio, $fecha_fin_consumo);
        $inserted = 0;

        if ($datos && is_array($datos)) {
            foreach ($datos as $registro) {
                $storeCode     = addslashes($registro['storeCode']);
                $store         = addslashes($registro['store']);
                $timeformatted = date('Y-m-d H:i:s', strtotime($registro['timeformatted']));
                $in            = isset($registro['in']) ? (int)$registro['in'] : 'NULL';
                $out           = isset($registro['out']) ? (int)$registro['out'] : 0;

                $tsql = "
                    INSERT INTO BISTAGING.dbo.STG_ConsumoContador (storeCode, store, timeformatted, [in], [out])
                    VALUES ('$storeCode', '$store', '$timeformatted', $in, $out);
                ";

                $inserted += ((int) transac_data($tsql) === 1) ? 1 : 0;
            }

            $msg = date('Y-m-d H:i:s')." [$fecha_inicio → $fecha_fin_consumo] Inserción completada. filas={$inserted}\n";
            echo $msg;
            file_put_contents($logFile, $msg, FILE_APPEND);
        } else {
            $msg = date('Y-m-d H:i:s')." [$fecha_inicio → $fecha_fin_consumo] No se recibieron datos válidos.\n";
            echo $msg;
            file_put_contents($logFile, $msg, FILE_APPEND);
        }

        $elapsed = round(microtime(true) - $startedAt, 2);
        $meta = date('Y-m-d H:i:s') . " detalle rango={$fecha_inicio}..{$fecha_fin_consumo} delete_ok=" . ($deleteOk ? '1' : '0') . " elapsed_s={$elapsed}\n";
        file_put_contents($logFile, $meta, FILE_APPEND);
    } catch (Throwable $e) {
        $msg = date('Y-m-d H:i:s') . " [$fecha_inicio → $fecha_fin_consumo] ERROR: " . $e->getMessage() . "\n";
        echo $msg;
        file_put_contents($logFile, $msg, FILE_APPEND);
        throw $e;
    }
?>