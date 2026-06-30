<?php
require_once __DIR__ . '/config.php';

$REGEX_SERVER = '/^[A-Za-z0-9._\\\\-]+$/';
$DEFAULT_NOMINA_SERVER = '210.17.1.24';
$DEFAULT_NOMINA_DB     = 'BDDNOMINAMABEL19';

$errors    = [];
$resultado = null;

$nominaServer = trim($_POST['nomina_server'] ?? $DEFAULT_NOMINA_SERVER);
$nominaDb     = trim($_POST['nomina_db']     ?? $DEFAULT_NOMINA_DB);
$execAt       = trim($_POST['exec_at']       ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($nominaServer === '') {
        $nominaServer = $DEFAULT_NOMINA_SERVER;
    }
    if (!preg_match($REGEX_SERVER, $nominaServer)) {
        $errors[] = 'Nomina Linked Server contiene caracteres no válidos.';
    }

    if ($nominaDb === '') {
        $nominaDb = $DEFAULT_NOMINA_DB;
    }
    if (!preg_match($REGEX_SERVER, $nominaDb)) {
        $errors[] = 'Nomina Database contiene caracteres no válidos.';
    }

    if ($execAt !== '' && !preg_match($REGEX_SERVER, $execAt)) {
        $errors[] = 'EXEC AT Linked Server contiene caracteres no válidos.';
    }

    if ($errors === []) {
        $env = [
            'ASIST_PROG_NOMINA_LINKED_SERVER' => $nominaServer,
            'ASIST_NOMINA_DATABASE'           => $nominaDb,
        ];
        if ($execAt !== '') {
            $env['ASIST_PROG_EXEC_AT_LINKED_SERVER'] = $execAt;
        }
        $resultado = run_task('syn_put_asistencias_prog.php', [], $env);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ejecutar — Asistencias Programadas</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="topbar">
  <a class="btn-back" href="tareas.php">&#8592; Regresar</a>
  <h1>Ejecutar — Sincronización de Asistencias Programadas</h1>
</div>

<?php if ($errors): ?>
<div class="error">
  <?php foreach ($errors as $e): ?>
    <div><?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="exec-form">
  <form method="post">
    <p class="exec-label">Sincroniza horarios programados desde SGP hacia KOACH360.</p>
    <p class="exec-hint" style="margin-bottom:16px;">Solo procesa fechas desde hoy hasta fin de mes.</p>

    <details class="param-details" <?= ($_SERVER['REQUEST_METHOD'] === 'POST' && ($nominaServer !== $DEFAULT_NOMINA_SERVER || $nominaDb !== $DEFAULT_NOMINA_DB || $execAt !== '')) ? 'open' : '' ?>>
      <summary class="param-summary">Parámetros avanzados</summary>
      <div class="param-grid">

        <div class="param-group">
          <label class="param-label" for="nomina_server">Nomina Linked Server</label>
          <input type="text" id="nomina_server" name="nomina_server" class="param-input"
                 value="<?= htmlspecialchars($nominaServer) ?>" placeholder="<?= $DEFAULT_NOMINA_SERVER ?>">
          <span class="param-hint">IP o nombre del linked server hacia la base de nómina (SGP / HorariosDiarios)</span>
        </div>

        <div class="param-group">
          <label class="param-label" for="nomina_db">Nomina Database</label>
          <input type="text" id="nomina_db" name="nomina_db" class="param-input"
                 value="<?= htmlspecialchars($nominaDb) ?>" placeholder="<?= $DEFAULT_NOMINA_DB ?>">
          <span class="param-hint">Base de datos en el linked server de nómina</span>
        </div>

        <div class="param-group">
          <label class="param-label" for="exec_at">
            EXEC AT Linked Server
            <span class="param-optional">(opcional)</span>
          </label>
          <input type="text" id="exec_at" name="exec_at" class="param-input"
                 value="<?= htmlspecialchars($execAt) ?>" placeholder="vacío = desactivado">
          <span class="param-hint">Ejecuta la consulta remotamente via EXEC (…) AT. Dejar vacío si la conexión es directa.</span>
        </div>

      </div>
    </details>

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
    <span class="exec-hint">
      Servidor: <strong><?= htmlspecialchars($nominaServer) ?></strong>
      &nbsp;|&nbsp; DB: <strong><?= htmlspecialchars($nominaDb) ?></strong>
      <?= $execAt !== '' ? '&nbsp;|&nbsp; EXEC AT: <strong>' . htmlspecialchars($execAt) . '</strong>' : '' ?>
    </span>
  </div>
  <pre class="output-box"><?= htmlspecialchars($resultado['output']) ?></pre>
</div>
<?php endif; ?>

</body>
</html>
