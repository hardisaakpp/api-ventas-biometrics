<?php
    require_once(__DIR__ . '/../cws/f_consumo_vcount.php'); // consumir_vcount_data()
    require_once(__DIR__ . '/../cx/bd_scs.php');            // transac_data()
    require_once('f_tareas.php');
    date_default_timezone_set('America/Guayaquil');

    // 1. Calcular fechas de los últimos 7 días (hasta ayer)
    $rango7 = rangoDiasDesdeAyer(7);
    $fecha_fin_consumo = date('Y-m-d', strtotime('yesterday'));
    $fecha_inicio = $rango7['fecha_inicio'];
    $fecha_fin    = $rango7['fecha_fin'];

    // 2. Borrar registros del rango
    $tsql_delete = "
        DELETE FROM BISTAGING.dbo.STG_ConsumoContador
        WHERE CAST(timeformatted AS DATE) >= '$fecha_inicio' AND CAST(timeformatted AS DATE) < '$fecha_fin';
    ";
    transac_data($tsql_delete);

    // 3. Consumir e insertar nuevos datos
    $datos = consumir_vcount_data($fecha_inicio, $fecha_fin_consumo);

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

            transac_data($tsql);
        }
        echo date('Y-m-d H:i:s')." [$fecha_inicio → $fecha_fin_consumo] Inserción completada.\n";
    } else {
        echo date('Y-m-d H:i:s')." [$fecha_inicio → $fecha_fin_consumo] No se recibieron datos válidos.\n";
    }
?>