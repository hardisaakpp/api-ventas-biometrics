<?php
    /**
     * Ruta de log portable (evita depender de /storage en Windows).
     */
    function tareas_log_path(string $filename): string {
        $dir = __DIR__ . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/' . $filename;
    }

    function rangoDiasDesdeAyer(int $dias = 0, bool $usarMesActual = false): array {
        date_default_timezone_set('America/Guayaquil');

        if ($usarMesActual) {
            return [
                'fecha_inicio' => date('Y-m-01'),
                'fecha_fin'    => date('Y-m-t')
            ];
        }

        $fecha_fin = date('Y-m-d');
        $fecha_inicio = date('Y-m-d', strtotime("-$dias days", strtotime($fecha_fin)));

        return [
            'fecha_inicio' => $fecha_inicio,
            'fecha_fin'    => $fecha_fin
        ];
    }

    function rangoFechasCondicional(): array|string {
        date_default_timezone_set('America/Guayaquil');
        //$fechaPrueba = '2025-07-14'; // Cambia esta fecha para probar distintos escenarios
        //$hoy = new DateTime($fechaPrueba);
        $hoy = new DateTime();
        $dia = (int)$hoy->format('j');  // Día del mes
        $mes = (int)$hoy->format('m');
        $anio = (int)$hoy->format('Y');
        $diaSemana = (int)$hoy->format('N'); // 1 = lunes, 7 = domingo

        if ($dia >= 1 && $dia <= 6) {
            return [
                'fecha_inicio' => $hoy->format('Y-m-d'),
                'fecha_fin'    => (new DateTime("last day of this month"))->format('Y-m-d')
            ];
        }

        if ($dia > 6 && $diaSemana === 1) { // Es lunes después del 6
            return [
                'fecha_inicio' => $hoy->format('Y-m-d'),
                'fecha_fin'    => (new DateTime("last day of this month"))->format('Y-m-d')
            ];
        }

        // No es una fecha válida para el cálculo
        return FALSE;
    }

?>