<?php
require_once __DIR__ . '/config.php';

$resultado = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $modo = in_array($_POST['modo'] ?? '', ['retroactivo', 'intradia']) ? $_POST['modo'] : 'intradia';
    $resultado = run_task('syn_put_ventas.php', [$modo]);
}
$modo_sel = $_POST['modo'] ?? 'intradia';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ejecutar — Ventas</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="topbar">
  <a class="btn-back" href="tareas.php">&#8592; Regresar</a>
  <h1>Ejecutar — Sincronización de Ventas</h1>
</div>

<div class="exec-form">
  <form method="post">
    <p class="exec-label">Modo de ejecución</p>
    <div class="exec-radios">
      <label>
        <input type="radio" name="modo" value="intradia" <?= $modo_sel !== 'retroactivo' ? 'checked' : '' ?>>
        Intradia <span class="exec-hint">(ventas del día hasta hora actual)</span>
      </label>
      <label>
        <input type="radio" name="modo" value="retroactivo" <?= $modo_sel === 'retroactivo' ? 'checked' : '' ?>>
        Retroactivo <span class="exec-hint">(últimos 7 días)</span>
      </label>
    </div>
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
