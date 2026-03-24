<?php
    /**
     * Descarga V-Count BoostBI (horario + diario) y guarda JSON en tareas/json/.
     * Ajustar $dias si necesitas otro rango. No requiere SQL Server.
     */
    require_once __DIR__ . '/../cws/f_consumo_vcount.php';
    require_once __DIR__ . '/f_tareas.php';
    date_default_timezone_set('America/Guayaquil');

    $dias = isset($argv[1]) ? max(1, (int) $argv[1]) : 7;
    $fin = date('Y-m-d', strtotime('yesterday'));
    $ini = date('Y-m-d', strtotime('-' . ($dias - 1) . ' days', strtotime($fin)));

    $horaria = consumir_vcount_data($ini, $fin);
    $diaria = consumir_vcount_data_diario($ini, $fin);

    $dir = __DIR__ . '/json';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $ts = date('Ymd_His');
    file_put_contents(
        $dir . '/vcount_horaria_' . $ts . '.json',
        json_encode($horaria, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
    file_put_contents(
        $dir . '/vcount_diaria_' . $ts . '.json',
        json_encode($diaria, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );

    $log = date('Y-m-d H:i:s') . " vcount rango=$ini..$fin horaria=" . ($horaria === null ? 'null' : 'ok') . ' diaria=' . ($diaria === null ? 'null' : 'ok') . "\n";
    file_put_contents(tareas_log_path('syn_fetch_vcount.log'), $log, FILE_APPEND);
