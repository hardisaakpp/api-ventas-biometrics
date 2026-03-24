# klienteV1

Integraciones en PHP entre datos internos (principalmente **SQL Server**), APIs **Kliente** / **KOACH360**, **V-Count**, **Snapp (Hitell)** y **Bimba y Lola**. No es una aplicación web con un único punto de entrada: la mayoría de los flujos son **scripts CLI** pensados para **cron** o **Programador de tareas de Windows**.

## Requisitos

- **PHP 8.x** con extensiones **curl** y **json** (habitualmente ya incluidas).
- **SQL Server** (drivers `sqlsrv` / `pdo_sqlsrv`) solo para los scripts bajo `tareas/` que consultan la base vía `cx/bd_scs.php`.
- **Composer** (en la carpeta `cws`) para dependencias Guzzle y utilidades de desarrollo.

## Estructura del repositorio

| Ruta | Rol |
|------|-----|
| `cws/` | Biblioteca compartida: consumo de APIs (`f_consumo*.php`), envío a KOACH360 (`f_koach360.php`, `f_put_*.php`), carga de configuración (`dat_cws.php`). |
| `cws/vendor/` | Dependencias Composer (generar con `composer install` dentro de `cws`). |
| `cws/scripts/` | Scripts auxiliares (parches IDE, pruebas). |
| `tareas/` | Jobs ejecutables: sincronizaciones y utilidades (`syn_*.php`). |
| `cx/` | Conexión SQL Server (`bd_scs.php`, `dat_scs.php`). |
| `.env` | Variables de entorno en la **raíz del repo** (no versionar secretos). Ver `.env.example`. |

## Configuración

1. Copiar `.env.example` a `.env` en la raíz de `klienteV1` y completar valores reales.
2. **`dat_cws.php`** lee `.env` desde el directorio padre de `cws` (es decir, la raíz del repositorio).
3. **Base de datos**: host, usuario, contraseña y base están definidos en `cx/dat_scs.php`. Conviene no exponer credenciales en el repositorio; valorar mover esos datos a variables de entorno en el futuro.

## Dependencias PHP (carpeta `cws`)

```bash
cd cws
composer install
```

## Ejecución de tareas (CLI)

Desde la raíz del repositorio (ajusta la ruta si es distinta):

```bash
php tareas/syn_put_ventas.php
php tareas/syn_put_asistencias.php
php tareas/syn_put_asistencias_prog.php
php tareas/syn_put_ventas_retro7.php
```

**V-Count** (guarda JSON en `tareas/json/`; no usa SQL Server):

```bash
php tareas/syn_fetch_vcount.php
php tareas/syn_fetch_vcount.php 14
```

El segundo argumento opcional es el número de días hacia atrás (por defecto 7).

### Programación

Los comentarios en cada `syn_*.php` indican recurrencia sugerida (por ejemplo ventas cada hora en horario laboral). En Windows se puede usar el **Programador de tareas** ejecutando `php.exe` con la ruta absoluta al script.

## Prueba rápida: login KOACH360 (JWT)

No requiere SQL Server:

```bash
php cws/scripts/test_koach360_login.php
```

Usa `KOACH360_USUARIO` y `KOACH360_PASSWORD` del `.env`. Si están vacíos, se usan `US_PVENTAS` y `PS_PVENTAS`.

## PowerShell y `php -r`

En PowerShell, las variables `$variable` dentro de comillas dobles se expanden **antes** de llegar a PHP. Para una sola línea, usar **comillas simples** en el argumento de `-r`, o ejecutar un archivo `.php` como en los ejemplos anteriores.

## Variables de entorno (referencia)

Definidas en `cws/dat_cws.php` (valores por defecto entre paréntesis cuando existen):

**Snapp / Hitell**

- `APILOGIN`, `STORES`, `ARQUEO`
- `US_MABEL`, `PS_MABEL`, `US_COSMEC`, `PS_COSMEC`

**Kliente / integraciones legacy**

- `URL_PVENTAS`, `US_PVENTAS`, `PS_PVENTAS`, `CLI_PVENTAS`
- `URL_ASISTENCIAS`, `US_ASISTENCIAS`, `PS_ASISTENCIAS`, `CLI_ASISTENCIAS`
- `URL_ASISTENCIAS_PROGRAMADAS`, `US_ASISTENCIAS_PROGRAMADAS`, `PS_ASISTENCIAS_PROGRAMADAS`, `CLI_ASISTENCIAS_PROGRAMADAS`

**V-Count**

- `US_VCOUNT`, `PS_VCOUNT`, `URL_VCOUNT`, `URL_VCOUNT_DAILY`

**KOACH360**

- `KOACH360_BASE_URL` (por defecto `https://koach360.kliente.tech:5000`)
- `KOACH360_USUARIO`, `KOACH360_PASSWORD` (si faltan, se usan usuario/clave de `US_PVENTAS` / `PS_PVENTAS`)
- `KOACH360_HORA_PROG_DEFAULT` (por defecto `09:00`)

**Bimba y Lola**

- `BYL_TENANT_ID`, `BYL_URL`, `BYL_GRANT_TYPE`, `BYL_CLIENT_ID`, `BYL_CLIENT_SECRET`, `BYL_RESOURCE`, `BYL_API_URL`, `BYL_API_SUBSCRIPTION_KEY`

## Scripts en `tareas/` (resumen)

| Archivo | Descripción breve |
|---------|-------------------|
| `syn_put_ventas.php` | Ventas hacia KOACH360 (JWT); solo días completos; ventana de 8 días. |
| `syn_put_ventas_retro7.php` | Variante con ventana retroactiva de 7 días. |
| `syn_put_asistencias.php` | Asistencias reales hacia KOACH360. |
| `syn_put_asistencias_prog.php` | Asistencias programadas hacia KOACH360. |
| `syn_fetch_vcount.php` | Descarga V-Count y escribe JSON en `tareas/json/`. |
| `f_tareas.php` | Funciones comunes (rangos de fechas, rutas de log). |

## Otros archivos útiles en `cws/`

- `f_koach360.php`: obtención de token JWT y POST autenticados.
- `f_put_ventas.php`, `f_put_asistencias.php`, `f_put_asistencias_prog.php`: mapeo y envío a KOACH360.
- `f_consumo.php`, `f_consumo_vcount.php`, `f_consumo_byl.php`: clientes de APIs.
- `f_procesa.php`: script orientado a pruebas vía servidor web (no es el flujo principal de producción).

## Extensiones SQL Server en Windows (XAMPP)

Si aparecen avisos al arrancar PHP sobre `php_sqlsrv_*.dll`, revisar en `php.ini` que no haya líneas `extension=` duplicadas y que los DLL coincidan con la versión de PHP (**NTS/TS**, **x64**) y estén en `ext\`. Los [Microsoft Drivers for PHP for SQL Server](https://learn.microsoft.com/sql/connect/php/download-drivers-php-sql-server) describen la instalación. Los scripts que solo llaman a APIs HTTP no necesitan `sqlsrv`.
