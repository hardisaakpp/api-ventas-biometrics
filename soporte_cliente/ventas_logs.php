<?php
require_once __DIR__ . '/config.php';
$logFile = KLIENTEV1_PATH . '/tareas/logs/syn_put_ventas.log';
$entries = [];

if (file_exists($logFile)) {
    $content = file_get_contents($logFile);

    // Separar por cada timestamp de inicio de entrada
    $parts = preg_split('/(?=\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} - )/', $content, -1, PREG_SPLIT_NO_EMPTY);

    foreach ($parts as $part) {
        $part = trim($part);
        if (!$part) continue;

        // Extraer timestamp
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) - /', $part, $m)) continue;
        $timestamp = $m[1];
        $rest = substr($part, strlen($m[0]));

        // Determinar tipo
        $tipo = '';
        if (str_starts_with($rest, 'intradia'))   $tipo = 'intradia';
        elseif (str_starts_with($rest, 'retroactivo')) $tipo = 'retroactivo';

        // Extraer campos clave
        preg_match('/filas=(\d+)/', $rest, $f);
        preg_match('/fecha=([\d-]+)/', $rest, $fecha);
        preg_match('/desde=([\d-]+)/', $rest, $desde);
        preg_match('/hasta=([\d-]+)/', $rest, $hasta);
        preg_match('/hora_tope=(\d+)/', $rest, $hora);
        preg_match('/fuente=([^\s]+)/', $rest, $fuente);

        // Extraer resp
        $resp = '';
        $json = null;
        if (preg_match('/resp=(\{.+\})\s*$/s', $rest, $r)) {
            $resp = 'json';
            $json = json_decode($r[1], true);
        } elseif (preg_match('/resp=(.+)$/', $rest, $r)) {
            $resp = trim($r[1]);
        }

        $entries[] = [
            'timestamp' => $timestamp,
            'tipo'      => $tipo,
            'filas'     => $f[1] ?? '0',
            'fecha'     => $fecha[1] ?? null,
            'desde'     => $desde[1] ?? null,
            'hasta'     => $hasta[1] ?? null,
            'hora_tope' => $hora[1] ?? null,
            'fuente'    => $fuente[1] ?? null,
            'resp'      => $resp,
            'json'      => $json,
        ];
    }

    $entries = array_reverse($entries);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Log Ventas</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="topbar">
  <a class="btn-back" href="tareas.php">&#8592; Regresar</a>
  <h1>Log — Ventas</h1>
</div>

<?php if (empty($entries)): ?>
  <p class="no-log">No hay entradas en el log.</p>
<?php else: ?>

<?php foreach ($entries as $i => $e):
    if ($e['resp'] === 'json') {
        $exitosas = $e['json']['ventasExitosas'] ?? 0;
        $fallidas = $e['json']['ventasFallidas'] ?? 0;
        $badgeClass = $fallidas > 0 ? 'badge-err' : 'badge-ok';
        $badgeText  = $fallidas > 0 ? "FALLIDAS: $fallidas" : 'OK';
    } elseif (str_contains(strtolower($e['resp']), 'skip')) {
        $badgeClass = 'badge-skip'; $badgeText = 'SKIP';
    } else {
        $badgeClass = 'badge-err'; $badgeText = 'ERROR';
    }
?>
<div class="entry">
  <div class="entry-header" onclick="toggle(<?= $i ?>)">
    <span class="ts"><?= htmlspecialchars($e['timestamp']) ?></span>
    <span class="tipo-badge tipo-<?= $e['tipo'] ?>"><?= $e['tipo'] ?: '?' ?></span>
    <span class="meta-info">
      <?php if ($e['tipo'] === 'intradia' && $e['fecha']): ?>
        Fecha: <strong><?= $e['fecha'] ?></strong> | Hora tope: <strong><?= $e['hora_tope'] ?>h</strong>
      <?php elseif ($e['tipo'] === 'retroactivo' && $e['desde']): ?>
        Desde: <strong><?= $e['desde'] ?></strong> hasta: <strong><?= $e['hasta'] ?></strong>
      <?php endif; ?>
      &nbsp;| Filas: <strong><?= number_format((int)$e['filas']) ?></strong>
    </span>
    <span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span>
    <span class="toggle" id="arrow-<?= $i ?>">&#9660;</span>
  </div>

  <div class="entry-body" id="body-<?= $i ?>">
    <?php if ($e['resp'] === 'json' && $e['json']): ?>
      <div class="stats-grid">
        <div class="stat-box">
          <div class="stat-label">Ventas Exitosas</div>
          <div class="stat-val"><?= number_format($e['json']['ventasExitosas'] ?? 0) ?></div>
        </div>
        <div class="stat-box">
          <div class="stat-label">Ventas Fallidas</div>
          <div class="stat-val"><?= number_format($e['json']['ventasFallidas'] ?? 0) ?></div>
        </div>
        <?php if (!empty($e['json']['tiendasOmitidas'])): ?>
        <div class="stat-box">
          <div class="stat-label">Tiendas Omitidas</div>
          <div class="stat-val"><?= count($e['json']['tiendasOmitidas']) ?></div>
        </div>
        <?php endif; ?>
      </div>
      <?php if (!empty($e['json']['errores'])): ?>
      <ul class="errores">
        <?php foreach ($e['json']['errores'] as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php if ($e['fuente']): ?>
      <p style="font-size:11px;color:#999;margin-top:8px;">Fuente: <?= htmlspecialchars($e['fuente']) ?></p>
      <?php endif; ?>
    <?php else: ?>
      <div class="resp-plain"><?= htmlspecialchars($e['resp']) ?></div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<script src="toggle.js"></script>

<?php endif; ?>
</body>
</html>
