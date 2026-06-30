<?php
require_once __DIR__ . '/config.php';
$dir = KLIENTEV1_PATH . '/tareas/json';
$archivos = glob($dir . '/ventas_*.json');
rsort($archivos);

$seleccionado = isset($_GET['archivo']) ? basename($_GET['archivo']) : '';
if ($seleccionado && file_exists($dir . '/' . $seleccionado) && str_starts_with($seleccionado, 'ventas_') && str_ends_with($seleccionado, '.json')) {
    $archivoActual = $dir . '/' . $seleccionado;
} elseif (!empty($archivos)) {
    $archivoActual = $archivos[0];
    $seleccionado  = basename($archivos[0]);
} else {
    $archivoActual = null;
}

$registros = [];
$errorMsg  = '';
if ($archivoActual) {
    $data = json_decode(file_get_contents($archivoActual), true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
        $registros = $data;
    } else {
        $errorMsg = 'Error al leer el JSON: ' . json_last_error_msg();
    }
}

$porLocal = [];
foreach ($registros as $r) {
    $key = ($r['localCodigo'] ?? '?') . ' — ' . ($r['localNombre'] ?? '');
    $porLocal[$key][] = $r;
}
ksort($porLocal);

$fechaArchivo = '';
if (preg_match('/ventas_(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})\.json/', $seleccionado, $m)) {
    $fechaArchivo = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
}

$totalValor = array_sum(array_column($registros, 'valorTransaccion'));
$totalUnidades = array_sum(array_column($registros, 'cantidadUnidades'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Verificar JSON Ventas</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="topbar">
  <a class="btn-back" href="tareas.php">&#8592; Regresar</a>
  <h1>Verificar JSON — Ventas</h1>
  <?php if (count($archivos) > 1): ?>
  <form method="get" style="display:inline">
    <select name="archivo" onchange="this.form.submit()">
      <?php foreach ($archivos as $a):
        $nombre = basename($a);
        $sel = ($nombre === $seleccionado) ? 'selected' : '';
      ?>
      <option value="<?= htmlspecialchars($nombre) ?>" <?= $sel ?>><?= htmlspecialchars($nombre) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
  <?php if ($seleccionado): ?>
  <a class="btn-download" href="download.php?archivo=<?= rawurlencode($seleccionado) ?>" download="<?= htmlspecialchars($seleccionado) ?>">&#8595; Descargar JSON</a>
  <?php endif; ?>
</div>

<?php if ($errorMsg): ?>
  <div class="error"><?= htmlspecialchars($errorMsg) ?></div>
<?php elseif (empty($archivos)): ?>
  <p class="no-data">No se encontraron archivos ventas_*.json.</p>
<?php else: ?>

<div class="summary">
  <div class="s-box">
    <div class="s-label">Archivo</div>
    <div class="s-val" style="font-size:12px;color:#555;"><?= htmlspecialchars($seleccionado) ?></div>
  </div>
  <?php if ($fechaArchivo): ?>
  <div class="s-box">
    <div class="s-label">Generado</div>
    <div class="s-val" style="font-size:15px;"><?= $fechaArchivo ?></div>
  </div>
  <?php endif; ?>
  <div class="s-box">
    <div class="s-label">Total Registros</div>
    <div class="s-val"><?= number_format(count($registros)) ?></div>
  </div>
  <div class="s-box">
    <div class="s-label">Locales</div>
    <div class="s-val"><?= count($porLocal) ?></div>
  </div>
  <div class="s-box">
    <div class="s-label">Total Valor</div>
    <div class="s-val" style="font-size:16px;">$<?= number_format($totalValor, 2) ?></div>
  </div>
  <div class="s-box">
    <div class="s-label">Total Unidades</div>
    <div class="s-val"><?= number_format($totalUnidades) ?></div>
  </div>
</div>

<?php if (!empty($registros)): ?>
<div class="search-bar">
  <input type="text" id="buscar" placeholder="Buscar nombre, cédula, local, factura..." oninput="filtrar()">
</div>

<div id="contenido">
<?php foreach ($porLocal as $local => $filas): ?>
<div class="group" data-grupo>
  <div class="group-header">
    <span><?= htmlspecialchars($local) ?></span>
    <span class="group-count"><?= count($filas) ?> registro<?= count($filas) !== 1 ? 's' : '' ?></span>
  </div>
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Asesor</th>
        <th>Cédula</th>
        <th>Líder</th>
        <th>Factura</th>
        <th>Fecha / Hora</th>
        <th>Valor</th>
        <th>Unidades</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($filas as $idx => $r): ?>
    <tr>
      <td><?= $idx + 1 ?></td>
      <td><?= htmlspecialchars($r['asesorNombre'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['asesorCedula'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['liderNombre'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['facturaNumero'] ?? '') ?></td>
      <td style="white-space:nowrap"><?= htmlspecialchars(($r['facturaFecha'] ?? '') . ' ' . ($r['facturaHora'] ?? '')) ?></td>
      <td>$<?= number_format((float)($r['valorTransaccion'] ?? 0), 2) ?></td>
      <td><?= (int)($r['cantidadUnidades'] ?? 0) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>
</div>

<script src="filtrar.js"></script>
<?php endif; ?>

<?php endif; ?>
</body>
</html>
