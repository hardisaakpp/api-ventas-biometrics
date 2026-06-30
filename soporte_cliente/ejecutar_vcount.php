<?php
require_once __DIR__ . '/config.php';

$resultado = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tarea = $_POST['tarea'] ?? '';
    if ($tarea === 'fetch') {
        $dias = max(1, min(30, (int)($_POST['dias'] ?? 10)));
        $resultado = run_task('syn_fetch_vcount.php', [$dias]);
    } elseif ($tarea === 'consumo') {
        $resultado = run_task('syn_consumo_vcount.php');
    }
}
$tarea_sel = $_POST['tarea'] ?? 'fetch';
$dias_sel  = (int)($_POST['dias'] ?? 10);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ejecutar — Vcount</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="topbar">
  <a class="btn-back" href="tareas.php">&#8592; Regresar</a>
  <h1>Ejecutar — Vcount</h1>
</div>

<div class="exec-form">
  <form method="post">
    <p class="exec-label">Tarea</p>
    <div class="exec-radios" style="margin-bottom:16px;">
      <label>
        <input type="radio" name="tarea" value="fetch" <?= $tarea_sel !== 'consumo' ? 'checked' : '' ?> onchange="document.getElementById('dias-row').style.display='flex'">
        Fetch desde V-Count <span class="exec-hint">(descarga JSON desde la API)</span>
      </label>
      <label>
        <input type="radio" name="tarea" value="consumo" <?= $tarea_sel === 'consumo' ? 'checked' : '' ?> onchange="document.getElementById('dias-row').style.display='none'">
        Consumo a Staging <span class="exec-hint">(carga JSON a SQL Server)</span>
      </label>
    </div>
    <div class="exec-field" id="dias-row" style="display:<?= $tarea_sel === 'consumo' ? 'none' : 'flex' ?>">
      <label class="exec-label" style="margin:0;white-space:nowrap">Días a descargar</label>
      <input type="number" name="dias" value="<?= $dias_sel ?>" min="1" max="30" style="width:70px;padding:6px 10px;border-radius:6px;border:1px solid #ccc;font-size:13px;">
    </div>
    <button type="submit" class="btn-exec" style="margin-top:16px;">&#9654;&nbsp; Ejecutar</button>
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
