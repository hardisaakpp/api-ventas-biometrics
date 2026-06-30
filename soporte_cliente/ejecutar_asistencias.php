<?php
require_once __DIR__ . '/config.php';

$resultado = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resultado = run_task('syn_put_asistencias.php');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ejecutar — Asistencias</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="topbar">
  <a class="btn-back" href="tareas.php">&#8592; Regresar</a>
  <h1>Ejecutar — Sincronización de Asistencias</h1>
</div>

<div class="exec-form">
  <form method="post">
    <p class="exec-label">Sincroniza asistencias reales desde biométricos hacia KOACH360.</p>
    <p class="exec-hint" style="margin-bottom:16px;">Rango: últimos 7 días desde la fecha actual.</p>
    <button type="submit" class="btn-exec">&#9654;&nbsp; Ejecutar</button>
  </form>
</div>

<?php if ($resultado): ?>
<div class="exec-result">
  <div class="exec-meta">
    <span class="badge <?= $resultado['exit'] === 0 ? 'badge-ok' : 'badge-err' ?>">
      <?= $resultado['exit'] === 0 ? 'OK' : 'ERROR (código ' . $resultado['exit'] . ')' ?>
    </span>
    <span class="exec-elapsed">Tiempo: <?= $resultado['elapsed'] ?>s</span>
  </div>
  <pre class="output-box"><?= htmlspecialchars($resultado['output']) ?></pre>
</div>
<?php endif; ?>

</body>
</html>
