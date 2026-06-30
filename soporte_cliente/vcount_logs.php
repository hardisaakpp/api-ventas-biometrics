<?php
require_once __DIR__ . '/config.php';
$logFile = KLIENTEV1_PATH . '/tareas/logs/syn_consumo_vcount.log';
$entries = [];

if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    // Agrupar: cada entrada = líneas de "Inserción completada" + su línea "detalle"
    $pending = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (!$line) continue;

        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) detalle rango=(\S+) delete_ok=(\d+) elapsed_s=(\S+)/', $line, $m)) {
            // Línea de cierre: armar la entrada
            [$rFrom, $rTo] = explode('..', $m[2]) + ['', ''];
            $entry = [
                'timestamp'  => $m[1],
                'rango_from' => $rFrom,
                'rango_to'   => $rTo,
                'delete_ok'  => $m[3],
                'elapsed_s'  => $m[4],
                'filas'      => null,
            ];
            // Recuperar filas del último pending con el mismo rango
            foreach (array_reverse($pending) as $p) {
                if (isset($p['filas'])) { $entry['filas'] = $p['filas']; break; }
            }
            $entries[] = $entry;
            $pending = [];
        } elseif (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[.+\] Inserci[oó]n completada\. filas=(\d+)/', $line, $m)) {
            $pending[] = ['ts' => $m[1], 'filas' => $m[2]];
        }
    }

    $entries = array_reverse($entries);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Log Vcount</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="topbar">
  <a class="btn-back" href="tareas.php">&#8592; Regresar</a>
  <h1>Log — Vcount</h1>
</div>

<?php if (empty($entries)): ?>
  <p class="no-log">No hay entradas en el log.</p>
<?php else: ?>

<div class="table-wrap">
<table>
  <thead>
    <tr>
      <th>Timestamp</th>
      <th>Rango desde</th>
      <th>Rango hasta</th>
      <th>Filas</th>
      <th>Elapsed (s)</th>
      <th>Estado</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($entries as $e): ?>
  <tr>
    <td><?= htmlspecialchars($e['timestamp']) ?></td>
    <td><?= htmlspecialchars($e['rango_from']) ?></td>
    <td><?= htmlspecialchars($e['rango_to']) ?></td>
    <td><?= $e['filas'] !== null ? number_format((int)$e['filas']) : '—' ?></td>
    <td><?= htmlspecialchars($e['elapsed_s']) ?></td>
    <?php
      $isErr = ($e['filas'] === '0' || $e['filas'] === null);
      echo '<td class="' . ($isErr ? 'err' : 'ok') . '">' . ($isErr ? 'SIN DATOS' : 'OK') . '</td>';
    ?>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php endif; ?>
</body>
</html>
