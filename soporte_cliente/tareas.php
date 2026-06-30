<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Tareas — Soporte Técnico</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #f0f2f5; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; }

        h2 { color: #1a1a2e; margin-bottom: 40px; font-size: 22px; }

        .buttons { display: flex; gap: 16px; flex-wrap: wrap; justify-content: center; }

        .dropdown { position: relative; display: inline-block; }

        .btn {
            display: inline-block;
            padding: 15px 32px;
            font-size: 15px;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            cursor: default;
            user-select: none;
            transition: filter .15s;
        }
        .btn:hover { filter: brightness(1.12); }

        .btn-ventas  { background-color: #2c7be5; }
        .btn-asis    { background-color: #28a745; }
        .btn-aprog   { background-color: #6f42c1; }
        .btn-vcount  { background-color: #e67e22; }

        .dropdown-menu {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            left: 50%;
            transform: translateX(-50%);
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 16px rgba(0,0,0,.15);
            min-width: 160px;
            z-index: 10;
            overflow: hidden;
        }

        /* puente invisible que tapa el gap entre botón y menú */
        .dropdown::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            height: 8px;
            display: none;
        }

        .dropdown:hover::after    { display: block; }
        .dropdown:hover .dropdown-menu { display: block; }

        .dropdown-menu a {
            display: block;
            padding: 11px 18px;
            font-size: 13px;
            color: #1a1a2e;
            text-decoration: none;
            white-space: nowrap;
        }
        .dropdown-menu a:hover { background: #f0f2f5; }
        .dropdown-menu a + a { border-top: 1px solid #eee; }
        .dropdown-menu a.exec { color: #e63946; font-weight: bold; }
    </style>
</head>
<body>

<h2>Tareas — Soporte Técnico</h2>

<div class="buttons">

    <div class="dropdown">
        <span class="btn btn-ventas">Ventas &#9660;</span>
        <div class="dropdown-menu">
            <a href="ventas_logs.php">Ver Logs</a>
            <a href="ventas_json.php">Verificar JSON</a>
            <a href="ejecutar_ventas.php" class="exec">&#9654; Ejecutar</a>
        </div>
    </div>

    <div class="dropdown">
        <span class="btn btn-asis">Asistencias &#9660;</span>
        <div class="dropdown-menu">
            <a href="logs.php">Ver Logs</a>
            <a href="verificar_json.php">Verificar JSON</a>
            <a href="ejecutar_asistencias.php" class="exec">&#9654; Ejecutar</a>
        </div>
    </div>

    <div class="dropdown">
        <span class="btn btn-aprog">Asistencias Programadas &#9660;</span>
        <div class="dropdown-menu">
            <a href="asis_prog_logs.php">Ver Logs</a>
            <a href="asis_prog_json.php">Verificar JSON</a>
            <a href="ejecutar_asis_prog.php" class="exec">&#9654; Ejecutar</a>
        </div>
    </div>

    <div class="dropdown">
        <span class="btn btn-vcount">Vcount &#9660;</span>
        <div class="dropdown-menu">
            <a href="vcount_logs.php">Ver Logs</a>
            <a href="ejecutar_vcount.php" class="exec">&#9654; Ejecutar</a>
        </div>
    </div>

</div>

</body>
</html>
