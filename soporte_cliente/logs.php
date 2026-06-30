<?php
require_once __DIR__ . '/config.php';
$logFile = KLIENTEV1_PATH . '/tareas/logs/syn_put_asistencias.log';
$entries = [];

if (file_exists($logFile)) {
    $content = file_get_contents($logFile);

    // Cada entrada empieza con fecha tipo: 2026-04-06 17:00:43
    $pattern = '/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) - asistencias ([^\{]+)resp=(\{.*?\})\s*(?=\d{4}-\d{2}-\d{2}|\z)/s';
    preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

    foreach ($matches as $m) {
        $meta = trim($m[2]);
        $json = json_decode($m[3], true);

        preg_match('/filas=(\d+)/', $meta, $f);
        preg_match('/hoy_ec=([\d-]+)/', $meta, $h);
        preg_match('/lookback=(\d+)/', $meta, $l);

        $entries[] = [
            'timestamp'  => $m[1],
            'filas'      => $f[1] ?? '?',
            'hoy_ec'     => $h[1] ?? null,
            'lookback'   => $l[1] ?? null,
            'mensajes'   => $json['mensajes'] ?? [],
            'stats'      => $json['estadisticas'] ?? [],
        ];
    }

    $entries = array_reverse($entries);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Log Sincronización Asistencias</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="topbar">
  <a class="btn-back" href="tareas.php">&#8592; Regresar</a>
  <h1>Log — Sincronización de Asistencias</h1>
</div>

<?php if (empty($entries)): ?>
  <p class="no-log">No hay entradas en el log.</p>
<?php else: ?>

<?php foreach ($entries as $i => $e):
    $lotesFallidos = $e['stats']['LotesFallidos'] ?? 0;
    $badgeClass    = $lotesFallidos > 0 ? 'badge-err' : 'badge-ok';
    $badgeText     = $lotesFallidos > 0 ? "FALLOS: $lotesFallidos" : 'OK';
?>
<div class="entry">
  <div class="entry-header" onclick="toggle(<?= $i ?>)">
    <span class="ts"><?= htmlspecialchars($e['timestamp']) ?></span>
    <span class="meta-info">
      Filas: <strong><?= $e['filas'] ?></strong>
      <?php if ($e['hoy_ec']): ?>&nbsp;| Fecha: <strong><?= $e['hoy_ec'] ?></strong><?php endif; ?>
      <?php if ($e['lookback']): ?>&nbsp;| Lookback: <strong><?= $e['lookback'] ?> días</strong><?php endif; ?>
      &nbsp;| Lotes: <strong><?= $e['stats']['LotesProcesados'] ?? '?' ?></strong>
    </span>
    <span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span>
    <span class="toggle" id="arrow-<?= $i ?>">&#9660;</span>
  </div>

  <div class="entry-body" id="body-<?= $i ?>">

    <!-- Estadísticas -->
    <div class="stats-grid">
      <?php
      $labels = [
        'AsistenciasCreadas'         => 'Asistencias Creadas',
        'AsistenciasEliminadas'      => 'Asistencias Eliminadas',
        'AsistenciasHoraCreadas'     => 'Asist-Hora Creadas',
        'LotesProcesados'            => 'Lotes Procesados',
        'LotesFallidos'              => 'Lotes Fallidos',
        'PersonasCreadas'            => 'Personas Creadas',
        'PersonaTiendaCargoCreados'  => 'PTC Creados',
        'PersonaTiendaCargoActualizados' => 'PTC Actualizados',
        'UsuariosCreados'            => 'Usuarios Creados',
      ];
      foreach ($labels as $key => $label):
        if (!isset($e['stats'][$key])) continue;
      ?>
      <div class="stat-box">
        <div class="stat-label"><?= $label ?></div>
        <div class="stat-val"><?= number_format($e['stats'][$key]) ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Mensajes -->
    <?php if (!empty($e['mensajes'])): ?>
    <ul class="msgs">
      <?php foreach ($e['mensajes'] as $msg):
        if (str_contains($msg, '✅')) $cls = 'msg-ok';
        elseif (str_contains($msg, '⚠️')) $cls = 'msg-warn';
        elseif (str_contains($msg, '===')) $cls = 'msg-head';
        else $cls = 'msg-info';
      ?>
      <li class="<?= $cls ?>"><?= htmlspecialchars($msg) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

  </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<script src="toggle.js"></script>

</body>
</html>
