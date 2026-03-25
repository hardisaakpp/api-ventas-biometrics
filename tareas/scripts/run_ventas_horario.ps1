Param()

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$phpExe = "php"
$scriptPath = Join-Path $repoRoot "tareas/syn_put_ventas.php"

Set-Location $repoRoot
& $phpExe $scriptPath
