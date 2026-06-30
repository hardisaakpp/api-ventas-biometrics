<?php
require_once __DIR__ . '/config.php';
$logFile = KLIENTEV1_PATH . '/tareas/logs/syn_put_asistencias_prog.log';
$entries = [];

if (file_exists($logFile)) {
    $content = file_get_contents($logFile);

    $parts = preg_split('/(?=\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} - )/', $content, -1, PREG_SPLIT_NO_EMPTY);

    foreach ($parts as $part) {
        $part = trim($part);
        if (!$part) continue;

        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) - prog (.+)$/', $part, $m)) continue;
        $timestamp = $m[1];
        $rest = $m[2];

        preg_match('/filas=(\d+)/', $rest, $f);
        preg_match('/locales_resueltos=(\d+)/', $rest, $lr);
        preg_match('/locales_no_encontrados=(\d+)/', $rest, $lnf);

        $json = null;
        $respRaw = '';
        if (preg_match('/resp=(\{.+\})\s*$/s', $rest, $r)) {
            $json = json_decode($r[1], true);
            $respRaw = 'json';
        } elseif (preg_match('/resp=(.+)$/', $rest, $r)) {
            $respRaw = trim($r[1]);
        }

        // Determinar si hay errores en los mensajes
        $tieneErrores = false;
        $tieneHttp5xx = false;
        if ($json && !empty($json['mensajes'])) {
            foreach ($json['mensajes'] as $msg) {
                if (str_contains($msg, 'ERROR:')) { $tieneErrores = true; break; }
            }
        }
        if (str_contains($respRaw, 'HTTP 5') || str_contains($respRaw, 'HTTP 4')) $tieneHttp5xx = true;

        $entries[] = [
            'timestamp'           => $timestamp,
            'filas'               => $f[1] ?? '0',
            'locales_resueltos'   => $lr[1] ?? null,
            'locales_no_enc'      => $lnf[1] ?? null,
            'json'                => $json,
            'respRaw'             => $respRaw,
            'tieneErrores'        => $tieneErrores,
            'tieneHttp5xx'        => $tieneHttp5xx,
        ];
    }

    $entries = array_reverse($entries);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Log Asistencias Programadas</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="topbar">
  <a class="btn-back" href="tareas.php">&#8592; Regresar</a>
  <h1>Log — Asistencias Programadas</h1>
</div>

<?php if (empty($entries)): ?>
  <p class="no-log">No hay entradas en el log.</p>
<?php else: ?>

<?php foreach ($entries as $i => $e):
    if ($e['tieneHttp5xx'] || ($e['json'] && ($e['json']['estadisticas']['RegistrosFallidos'] ?? 0) > 0)) {
        $badgeClass = 'badge-err'; $badgeText = 'ERROR';
    } elseif ($e['tieneErrores']) {
        $badgeClass = 'badge-warn'; $badgeText = 'ADVERTENCIAS';
    } elseif ($e['respRaw'] === 'sin_registros') {
        $badgeClass = 'badge-warn'; $badgeText = 'SIN REGISTROS';
    } else {
        $badgeClass = 'badge-ok'; $badgeText = 'OK';
    }
    $stats = $e['json']['estadisticas'] ?? [];
?>
<div class="entry">
  <div class="entry-header" onclick="toggle(<?= $i ?>)">
    <span class="ts"><?= htmlspecialchars($e['timestamp']) ?></span>
    <span class="meta-info">
      Filas: <strong><?= number_format((int)$e['filas']) ?></strong>
      <?php if ($e['locales_resueltos'] !== null): ?>
        &nbsp;| Locales: <strong><?= $e['locales_resueltos'] ?></strong>
      <?php endif; ?>
      <?php if (!empty($stats)): ?>
        &nbsp;| Procesados: <strong><?= number_format($stats['RegistrosProcesados'] ?? 0) ?></strong>
      <?php endif; ?>
    </span>
    <span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span>
    <span class="toggle" id="arrow-<?= $i ?>">&#9660;</span>
  </div>

  <div class="entry-body" id="body-<?= $i ?>">
    <?php if (!empty($stats)): ?>
    <div class="stats-grid">
      <?php $labels = [
        'AsistenciasCreadas'        => 'Asistencias Creadas',
        'AsistenciasEliminadas'     => 'Asistencias Eliminadas',
        'RegistrosProcesados'       => 'Registros Procesados',
        'RegistrosFallidos'         => 'Registros Fallidos',
        'PersonasCreadas'           => 'Personas Creadas',
        'PersonaTiendaCargoCreados' => 'PTC Creados',
      ];
      foreach ($labels as $k => $l): if (!isset($stats[$k])) continue; ?>
      <div class="stat-box">
        <div class="stat-label"><?= $l ?></div>
        <div class="stat-val"><?= number_format($stats[$k]) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($e['json']['mensajes'])): ?>
    <ul class="msgs">
      <?php foreach ($e['json']['mensajes'] as $msg):
        if (str_contains($msg, 'ERROR:')) $cls = 'msg-err';
        elseif (str_contains($msg, '===')) $cls = 'msg-head';
        elseif (str_contains($msg, 'éxito')) $cls = 'msg-ok';
        else $cls = 'msg-info';
      ?>
      <li class="<?= $cls ?>"><?= htmlspecialchars($msg) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php elseif ($e['respRaw'] !== 'json'): ?>
    <div class="resp-plain"><?= htmlspecialchars($e['respRaw']) ?></div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<script src="toggle.js"></script>

<?php endif; ?>
</body>
</html>
