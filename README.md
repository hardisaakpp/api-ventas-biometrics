# klienteV1

Integraciones **PHP CLI** alineadas con la documentación de **KOACH360** (ventas, asistencias reales y programadas) y **V-Count BoostBI** (conteo horario y diario). Los datos de negocio salen de **SQL Server** (ventas vía DWH, asistencias vía biométricos/nómina); no hay interfaz web propia: se ejecuta con **cron** o **Programador de tareas de Windows**.

**Despliegue:** instalar el repositorio en un directorio dedicado (por ejemplo fuera de `/storage/` mezclado con otras apps), por ejemplo `D:\Apps\klienteV1`, y programar las tareas solo contra esa ruta.

## Alcance (qué incluye este repo)

| Área | Scripts / módulos |
|------|-------------------|
| KOACH360 — ventas | `tareas/syn_put_ventas.php`, `tareas/syn_put_ventas_retro7.php` → `cws/f_put_ventas.php`, `cws/f_koach360.php` |
| KOACH360 — asistencias | `tareas/syn_put_asistencias.php`, `tareas/syn_put_asistencias_prog.php` → `cws/f_put_asistencias*.php` |
| V-Count | `tareas/syn_fetch_vcount.php` (JSON en `tareas/json/`), `tareas/syn_consumo_vcount.php` (opcional: staging SQL) |
| Configuración | `.env` en la raíz, `cws/dat_cws.php` |
| SQL Server | `cx/bd_scs.php`, `cx/dat_scs.php` (credenciales `SQL_SCS_*` en `.env`) |

Integraciones retiradas del árbol (vivían en el mismo repo antes): Snapp/Hitell, APIs legacy `coach.kliente.tech`, Bimba y Lola, repositorio `bd_scs_211`, correo aleatorio, etc.

## Requisitos

- **PHP 8.x** con **curl** y **json**.
- **SQL Server** + extensiones **`sqlsrv`** para las tareas que leen/escriben base (`syn_put_*`, `syn_consumo_vcount`).
- **Composer** ya no es obligatorio: no hay paquetes `require`. Opcional `composer install` en `cws` solo genera `vendor/` vacío; `dat_cws.php` funciona sin `vendor` (carga `.env` con parser propio).

## Configuración

1. Copiar `.env.example` → `.env` en la raíz de `klienteV1`.
2. Rellenar `SQL_SCS_*`, `KOACH360_USUARIO`, `KOACH360_PASSWORD`, `KOACH360_CLIENT_ID` (o `CLI_PVENTAS`), `US_VCOUNT` / `PS_VCOUNT` cuando aplique.
3. Si `KOACH360_USUARIO` / `KOACH360_PASSWORD` están vacíos, aún se pueden usar `US_PVENTAS` / `PS_PVENTAS` como respaldo (compatibilidad).

### Comprobar `.env` (sin ejecutar las tareas)

Valida que existan `SQL_SCS_*` y credenciales KOACH360 (no llama a la API):

```bash
php cws/scripts/check_env.php
```

## Ejecución (desde la raíz del repo)

```powershell
Set-Location "C:\ruta\klienteV1"
php cws/scripts/check_env.php
php cws/scripts/test_koach360_login.php
php tareas/syn_put_ventas.php
php tareas/syn_put_ventas_retro7.php
php tareas/syn_put_asistencias.php
php tareas/syn_put_asistencias_prog.php
php tareas/syn_fetch_vcount.php
php tareas/syn_fetch_vcount.php 14
```

### Recurrencia recomendada (manual KOACH360)

- Ventas: **cada hora** en horario laboral → `syn_put_ventas.php`.
- Ventas retroactivo **7 días** en madrugada → `syn_put_ventas_retro7.php`.

Wrappers PowerShell: `tareas/scripts/run_ventas_horario.ps1`, `tareas/scripts/run_ventas_retro7_madrugada.ps1`.

Ejemplo `schtasks` (ajustar ruta):

```powershell
schtasks /Create /F /TN "KLIENTEV1_VENTAS_HORARIO" /SC HOURLY /MO 1 /ST 06:00 /ET 22:59 /TR "powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"C:\ruta\klienteV1\tareas\scripts\run_ventas_horario.ps1\""
schtasks /Create /F /TN "KLIENTEV1_VENTAS_RETRO7" /SC DAILY /ST 02:15 /TR "powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"C:\ruta\klienteV1\tareas\scripts\run_ventas_retro7_madrugada.ps1\""
```

## Variables de entorno (resumen)

Ver `.env.example`. Principales:

- **SQL:** `SQL_SCS_HOST`, `SQL_SCS_DATABASE`, `SQL_SCS_USER`, `SQL_SCS_PASSWORD`
- **KOACH360:** `KOACH360_BASE_URL`, `KOACH360_USUARIO`, `KOACH360_PASSWORD`, `KOACH360_CLIENT_ID` (o `CLI_PVENTAS`), `KOACH360_HORA_PROG_DEFAULT`
- **Ventas — correo asesor (opcional):** `VENTAS_ASESOR_FROM_NOMINA`, `VENTAS_ASESOR_CORREO_COLUMN`
- **V-Count:** `US_VCOUNT`, `PS_VCOUNT`, `URL_VCOUNT`, `URL_VCOUNT_DAILY`

## Extensiones SQL en Windows (XAMPP)

Si aparecen avisos `Unable to load dynamic library 'php_sqlsrv_...'` o *already loaded*, revisar `php.ini`: una sola línea por extensión y DLL que exista. `test_koach360_login.php` no necesita SQL; `check_env.php` solo lee variables (tampoco abre SQL).

## Observabilidad

- **V-Count:** errores HTTP o JSON inválido se registran con `error_log`, incluyendo URL y cuerpo de respuesta **truncado** (~4 KB) vía `cws_truncate_for_log()` en `dat_cws.php`.
- **KOACH360:** fallos de login y respuestas HTTP no exitosas en `koach360_post_json` también se registran con cuerpo truncado.
- Las tareas `syn_*` siguen escribiendo en `tareas/logs/*.log` y JSON en `tareas/json/`.

## Funciones PHP (envío KOACH360)

| Uso | Función principal | Alias antiguo (`@deprecated`) |
|-----|-------------------|-------------------------------|
| Ventas | `enviarVentasKoach360`, `generarVentasKoach360Json` | `enviarFacturasKliente`, `generarFacturasJson` |
| Asistencias reales | `enviarAsistenciasRealesKoach360`, `generarAsistenciasRealesKoach360Json` | `enviarAsistenciasKliente`, `generarAsistenciasJson` |
| Asistencias programadas | `enviarAsistenciasProgramadasKoach360`, `generarAsistenciasProgramadasKoach360Json` | `enviarAsistenciasProgramadas`, `generarAsistenciasProgramadasJson` |

## Archivos clave en `cws/`

- `dat_cws.php` — `.env` + constantes KOACH360 / V-Count + `cws_truncate_for_log`.
- `f_koach360.php` — JWT y POST autenticados.
- `f_put_ventas.php`, `f_put_asistencias.php`, `f_put_asistencias_prog.php` — mapeo al contrato del manual.
- `f_consumo_vcount.php` — cliente BoostBI.
- `scripts/check_env.php` — validación mínima de `.env`.
