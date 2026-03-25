# klienteV1

Integraciones **PHP CLI** alineadas con la documentación de **KOACH360** (ventas, asistencias reales y programadas) y **V-Count BoostBI** (conteo horario y diario). Los datos de negocio salen de **SQL Server** (ventas vía DWH, asistencias vía biométricos/nómina); no hay interfaz web propia: se ejecuta con **cron** o **Programador de tareas de Windows**.

**Despliegue:** carpeta dedicada solo para este proyecto. En **Windows** suele ser `D:\Apps\klienteV1` + Programador de tareas. En **Linux** ejemplo documentado: `/opt/backend/kliente-v1` + `cron`. El `.env` **no** viaja en Git: se crea en el servidor con valores de ese entorno (pruebas vs producción).

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

- **PHP 8.x** con **curl** y **json** (en Ubuntu: `php-cli`, `php-curl`; `json` suele ir incluido).
- **SQL Server** + extensiones PHP **`sqlsrv`** (y red hasta el servidor SQL) para `syn_put_*` y `syn_consumo_vcount`.
- **Composer** no es obligatorio: no hay paquetes `require`. `dat_cws.php` funciona sin `vendor`.

## Configuración

1. Copiar `.env.example` → `.env` en la raíz de `klienteV1`.
2. Rellenar `SQL_SCS_*`, `KOACH360_USUARIO`, `KOACH360_PASSWORD`, `KOACH360_CLIENT_ID` (o `CLI_PVENTAS`), `US_VCOUNT` / `PS_VCOUNT` cuando aplique.
3. Si `KOACH360_USUARIO` / `KOACH360_PASSWORD` están vacíos, aún se pueden usar `US_PVENTAS` / `PS_PVENTAS` como respaldo (compatibilidad).

### Comprobar `.env` (sin ejecutar las tareas)

Valida que existan `SQL_SCS_*` y credenciales KOACH360 (no llama a la API):

```bash
php cws/scripts/check_env.php
php cws/scripts/check_env.php --no-sql   # si el servidor aún no tiene SQL_SCS_* (solo valida KOACH360)
```

O equivalente por variable: `CWS_CHECK_ENV_NO_SQL=1` en `.env`.

## Despliegue en Linux sin Git (artefacto `.tar`)

Útil cuando el servidor **no** es un clon de Git (error `fatal: not a git repository`).

### 1) En la máquina con Git (PC de desarrollo)

Desde la raíz del repo, en la rama deseada (ej. `test` o `main`):

```powershell
git archive --format=tar --output klienteV1-test.tar --prefix=klienteV1/ test
```

Cambiar `test` por `main` u otra rama si aplica. El prefijo `klienteV1/` crea al extraer la carpeta `klienteV1` (camelCase).

### 2) Subir al servidor

Si el **hostname no resuelve** desde tu PC, usar la **IP** del servidor:

```powershell
scp klienteV1-test.tar pruebas@IP_DEL_SERVIDOR:/tmp/
```

### 3) En el servidor (Ubuntu): backup, extraer, nombre de carpeta

Ejemplo de ruta de instalación usada en pruebas: `/opt/backend/kliente-v1` (con guión). Tras `git archive`, la carpeta será `klienteV1`; renómbrala si vuestra convención es `kliente-v1`:

```bash
APP_DIR="/opt/backend/kliente-v1"
TS="$(date +%Y%m%d_%H%M%S)"
sudo mv "$APP_DIR" "${APP_DIR}.bak.${TS}"
sudo tar -xf /tmp/klienteV1-test.tar -C /opt/backend
sudo mv /opt/backend/klienteV1 "$APP_DIR"
```

### 4) Restaurar secretos y datos generados

```bash
BAK="${APP_DIR}.bak.TU_TIMESTAMP"
sudo cp -a "$BAK/.env" "$APP_DIR/.env" 2>/dev/null || true
sudo cp -a "$BAK/tareas/json" "$APP_DIR/tareas/" 2>/dev/null || true
sudo cp -a "$BAK/tareas/logs" "$APP_DIR/tareas/" 2>/dev/null || true
sudo chown -R pruebas:pruebas "$APP_DIR"
chmod 600 "$APP_DIR/.env" 2>/dev/null || true
```

Si no hay `.env` previo, copiar `.env.example` a `.env` en el servidor y editar valores **de ese entorno**.

### 5) Rollback rápido

```bash
sudo rm -rf /opt/backend/kliente-v1
sudo mv /opt/backend/kliente-v1.bak.TU_TIMESTAMP /opt/backend/kliente-v1
```

### 6) Limpiar artefacto en `/tmp` (opcional)

```bash
rm -f /tmp/klienteV1-test.tar
```

## Instalación de PHP en Ubuntu (CLI)

Si `php` no existe en el servidor:

```bash
sudo apt update
sudo apt install -y php-cli php-curl php-mbstring
php -v
php -m | grep -i curl
```

## SQL Server desde PHP en Linux (`sqlsrv`)

Los scripts usan `sqlsrv_*` vía `cx/bd_scs.php`. En Ubuntu hay que instalar **Microsoft ODBC Driver for SQL Server** y las extensiones PHP (vía **PECL** o paquetes que distribuya vuestro equipo). Guía oficial (versiones y pasos actualizados):

[Instalar los controladores de Microsoft para PHP para SQL Server en Linux o macOS](https://learn.microsoft.com/sql/connect/php/installation-tutorial-linux-mac)

Comprobación típica:

```bash
php -m | grep -i sqlsrv
```

El servidor Linux debe poder **alcanzar por red** el `SQL_SCS_HOST` (firewall/VPN). Las credenciales van en `.env` (`SQL_SCS_*`).

## Pruebas recomendadas (orden)

Ejecutar **desde la raíz del proyecto** (`cd` a la carpeta que contiene `cws/`, `tareas/`, `.env`).

| Paso | Comando | Qué valida |
|------|---------|------------|
| 1 | `php cws/scripts/check_env.php` | `.env`: SQL + KOACH360 (y avisos V-Count) |
| 2 | `php cws/scripts/test_koach360_login.php` | Login JWT KOACH360 (red) |
| 3 | `php tareas/syn_fetch_vcount.php 1` | V-Count horario + diario → `tareas/json/` y `tareas/logs/syn_fetch_vcount.log` |
| 4 | `php -m` (salida debe incluir `sqlsrv`) | Extensiones SQL cargadas |
| 5 | `php tareas/syn_put_ventas.php` | SQL + envío ventas a KOACH360 (impacto real; usar solo cuando toque en ese entorno) |
| 6 | `php tareas/syn_put_asistencias.php` | SQL + asistencias reales |
| 7 | `php tareas/syn_put_asistencias_prog.php` | SQL + asistencias programadas |
| 8 | `php tareas/syn_consumo_vcount.php` | SQL + carga V-Count a staging (si lo usáis) |

**Interpretación rápida de fallos:** `check_env` con SQL vacío → completar `SQL_SCS_*`. KOACH360 **401** → usuario/contraseña de ese entorno. V-Count **400** → suele ser `US_VCOUNT` / `PS_VCOUNT` vacíos o parámetros; **401** → credenciales incorrectas.

## Ejecución manual (Windows, desde la raíz del repo)

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

## Ejecución manual y `cron` (Linux, desde la raíz del repo)

```bash
cd /opt/backend/kliente-v1
php cws/scripts/check_env.php
php cws/scripts/test_koach360_login.php
php tareas/syn_fetch_vcount.php 1
php tareas/syn_put_ventas.php
```

Ejemplo de líneas en `crontab` (ajustar rutas y horarios; el usuario debe tener `php` en PATH o usar ruta absoluta a `php`):

```cron
# Ventas cada hora (ej. minuto 5)
5 * * * * cd /opt/backend/kliente-v1 && /usr/bin/php tareas/syn_put_ventas.php >> /opt/backend/kliente-v1/tareas/logs/cron_ventas.log 2>&1

# Retro 7 días una vez al día (ej. 02:15)
15 2 * * * cd /opt/backend/kliente-v1 && /usr/bin/php tareas/syn_put_ventas_retro7.php >> /opt/backend/kliente-v1/tareas/logs/cron_ventas_retro7.log 2>&1

# Asistencias (ej. cada 30 min)
*/30 * * * * cd /opt/backend/kliente-v1 && /usr/bin/php tareas/syn_put_asistencias.php >> /opt/backend/kliente-v1/tareas/logs/cron_asistencias.log 2>&1

# Asistencias programadas (ej. diario 06:10)
10 6 * * * cd /opt/backend/kliente-v1 && /usr/bin/php tareas/syn_put_asistencias_prog.php >> /opt/backend/kliente-v1/tareas/logs/cron_asist_prog.log 2>&1

# V-Count (ej. diario 07:00, últimos 7 días)
0 7 * * * cd /opt/backend/kliente-v1 && /usr/bin/php tareas/syn_fetch_vcount.php >> /opt/backend/kliente-v1/tareas/logs/cron_vcount.log 2>&1
```

Scripts que deben seguir existiendo en el repo para cron: los bajo `tareas/syn_put_*.php`, `syn_fetch_vcount.php`, `syn_consumo_vcount.php` (opcional). Tras un despliegue, revisar que **ningún** job apunte a scripts eliminados en versiones anteriores.

## Recurrencia recomendada (manual KOACH360)

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
- **HTTP (opcional):** `KOACH360_HTTP_TIMEOUT`, `KOACH360_CONNECT_TIMEOUT`, `KOACH360_RETRY_MAX`, `VCOUNT_HTTP_TIMEOUT`, `VCOUNT_CONNECT_TIMEOUT`, `VCOUNT_RETRY_MAX`
- **check_env:** `CWS_CHECK_ENV_NO_SQL=1` (equivalente a `php cws/scripts/check_env.php --no-sql`)

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
