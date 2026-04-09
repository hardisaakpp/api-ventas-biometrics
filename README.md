# klienteV1

Integraciones **PHP CLI** alineadas con la documentación de **KOACH360** (ventas, asistencias reales y programadas) y **V-Count BoostBI** (conteo horario y diario). Los datos de negocio salen de **SQL Server** (ventas vía DWH, asistencias vía biométricos/nómina); no hay interfaz web propia: se ejecuta con **cron** o **Programador de tareas de Windows**.

**Despliegue:** carpeta dedicada solo para este proyecto. En **Windows** suele ser `D:\Apps\klienteV1` + Programador de tareas. En **Linux** ejemplo documentado: `/opt/backend/kliente-v1` + `cron`. El `.env` **no** viaja en Git: se crea en el servidor con valores de ese entorno (pruebas vs producción).

## Alcance (qué incluye este repo)


| Área                   | Scripts / módulos                                                                                               |
| ---------------------- | --------------------------------------------------------------------------------------------------------------- |
| KOACH360 — ventas      | `tareas/syn_put_ventas.php` (modos `retroactivo` / `intradia`) → `cws/f_put_ventas.php`, `cws/f_koach360.php`   |
| KOACH360 — asistencias | `tareas/syn_put_asistencias.php`, `tareas/syn_put_asistencias_prog.php` → `cws/f_put_asistencias*.php`          |
| V-Count                | `tareas/syn_fetch_vcount.php` (JSON en `tareas/json/`), `tareas/syn_consumo_vcount.php` (opcional: staging SQL) |
| Configuración          | `.env` en la raíz, `cws/dat_cws.php`                                                                            |
| SQL Server             | `cx/bd_scs.php`, `cx/dat_scs.php` (credenciales `SQL_SCS_*` en `.env`)                                          |


Integraciones retiradas del árbol (vivían en el mismo repo antes): Snapp/Hitell, APIs legacy `coach.kliente.tech`, Bimba y Lola, repositorio `bd_scs_211`, correo aleatorio, etc.

## Requisitos

- **PHP 8.x** con **curl** y **json** (en Ubuntu: `php-cli`, `php-curl`; `json` suele ir incluido).
- **SQL Server** + extensiones PHP `**sqlsrv`** (y red hasta el servidor SQL) para `syn_put_*` y `syn_consumo_vcount`.
- **Composer** no es obligatorio: no hay paquetes `require`. `dat_cws.php` funciona sin `vendor`.

## Configuración

1. Copiar `.env.example` → `.env` en la raíz de `klienteV1`.
2. Rellenar `SQL_SCS_`*, `KOACH360_USUARIO`, `KOACH360_PASSWORD`, `KOACH360_CLIENT_ID` (o `CLI_PVENTAS`), `US_VCOUNT` / `PS_VCOUNT` cuando aplique.
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

El servidor Linux debe poder **alcanzar por red** el `SQL_SCS_HOST` (firewall/VPN). Las credenciales van en `.env` (`SQL_SCS_`*).

### Integrar ventas y asistencias en Ubuntu (ODBC + `sqlsrv`)

Tras `php-cli`, instalar dependencias de compilación y el driver ODBC de Microsoft (Ubuntu 24.04; ajustar si usáis otra versión):

```bash
sudo apt update
sudo apt install -y php-dev php-pear php-xml build-essential unixodbc-dev

curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | sudo gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg
curl -fsSL https://packages.microsoft.com/config/ubuntu/24.04/prod.list | sudo tee /etc/apt/sources.list.d/mssql-release.list
sudo apt update
sudo ACCEPT_EULA=Y apt install -y msodbcsql18

sudo pecl install sqlsrv
sudo pecl install pdo_sqlsrv
```

Si **PECL falla al compilar** con errores de GCC, probad (según [FAQ Microsoft msphpsql](https://github.com/microsoft/msphpsql/wiki/FAQ)):

```bash
sudo CFLAGS="-Wno-error=implicit-function-declaration -Wno-error=incompatible-pointer-types" pecl install sqlsrv
sudo CFLAGS="-Wno-error=implicit-function-declaration -Wno-error=incompatible-pointer-types" pecl install pdo_sqlsrv
```

Habilitar extensiones (sustituir `8.3` por vuestra versión: `php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;"`):

```bash
PHPV=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
printf "extension=sqlsrv.so\n" | sudo tee "/etc/php/${PHPV}/mods-available/sqlsrv.ini"
printf "extension=pdo_sqlsrv.so\n" | sudo tee "/etc/php/${PHPV}/mods-available/pdo_sqlsrv.ini"
sudo phpenmod sqlsrv pdo_sqlsrv
```

Comprobar módulos y conexión real (requiere `.env` con `SQL_SCS_*`):

```bash
php -m | grep -i sqlsrv
cd /opt/backend/kliente-v1
php cws/scripts/check_sqlsrv.php
```

Si `check_sqlsrv.php` responde **OK**, podéis ejecutar:

```bash
php tareas/syn_put_ventas.php
php tareas/syn_put_asistencias.php
php tareas/syn_put_asistencias_prog.php
```

## Pruebas recomendadas (orden)

Ejecutar **desde la raíz del proyecto** (`cd` a la carpeta que contiene `cws/`, `tareas/`, `.env`).


| Paso | Comando                                     | Qué valida                                                                         |
| ---- | ------------------------------------------- | ---------------------------------------------------------------------------------- |
| 1    | `php cws/scripts/check_env.php`             | `.env`: SQL + KOACH360 (y avisos V-Count)                                          |
| 2    | `php cws/scripts/test_koach360_login.php`   | Login JWT KOACH360 (red)                                                           |
| 3    | `php tareas/syn_fetch_vcount.php 1`         | V-Count horario + diario → `tareas/json/` y `tareas/logs/syn_fetch_vcount.log`     |
| 4    | `php -m` (salida debe incluir `sqlsrv`)     | Extensiones SQL cargadas                                                           |
| 5    | `php cws/scripts/check_sqlsrv.php`          | Conexión real a `SQL_SCS_*` (`SELECT 1`)                                           |
| 6    | `php tareas/syn_put_ventas.php retroactivo` | SQL + envío ventas KOACH360 (últimos 7 días hasta hoy; impacto real)               |
| 7    | `php tareas/syn_put_ventas.php intradia`    | SQL + envío ventas KOACH360 (solo hoy, acumulado desde 00:00; horario 09:00-22:00) |
| 8    | `php tareas/syn_put_asistencias.php`        | SQL + asistencias reales                                                           |
| 9    | `php tareas/syn_put_asistencias_prog.php`   | SQL + asistencias programadas                                                      |
| 10   | `php tareas/syn_consumo_vcount.php`         | SQL + carga V-Count a staging (si lo usáis)                                        |


**Interpretación rápida de fallos:** `check_env` con SQL vacío → completar `SQL_SCS_*`. KOACH360 **401** → usuario/contraseña de ese entorno. V-Count **400** → suele ser `US_VCOUNT` / `PS_VCOUNT` vacíos o parámetros; **401** → credenciales incorrectas.

## Guía de validación para QA

Objetivo: que QA pueda comprobar cada integración con criterios reproducibles. Todos los comandos suponen **estar en la raíz del proyecto** (carpeta que contiene `cws/`, `tareas/`, `.env`) y tener **PHP accesible** (`php -v`).

### Entorno y alcance

- Acordar con desarrollo/negocio si las pruebas con `**syn_put_*`** y `**syn_consumo_vcount**` se ejecutan contra **preproducción** u otro entorno aislado. Esas tareas **leen SQL** y **envían o escriben datos** (KOACH360 y/o tablas de staging).
- Los scripts `**check_env.php`**, `**test_koach360_login.php**`, `**syn_fetch_vcount.php**` y `**check_sqlsrv.php**` sirven para validar configuración y conectividad sin ser la prueba funcional completa del negocio en KOACH360.

### Niveles sugeridos


| Nivel         | Pasos (ver tabla anterior)      | Notas                                                                     |
| ------------- | ------------------------------- | ------------------------------------------------------------------------- |
| Humo / config | 1 → 2 → 3; si aplica SQL: 4 → 5 | No ejecuta envíos masivos a KOACH360 ni consumo V-Count → SQL.            |
| Integración   | 6 → 9                           | Requiere acuerdo de entorno; revisar logs y JSON según tablas siguientes. |


### Criterios por funcionalidad


| Funcionalidad                      | Comando                                     | Prerrequisitos                                    | Cómo saber que fue bien (automatizable)                                                                                                                                                                                                                              | Comprobar además (manual / otro sistema)                                                                |
| ---------------------------------- | ------------------------------------------- | ------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| Variables `.env`                   | `php cws/scripts/check_env.php`             | `.env` copiado y relleno                          | Salida en consola: línea que empieza por `OK:`; **código de salida 0** (`echo $?` en Linux, `$LASTEXITCODE` en PowerShell). Avisos V-Count en stderr son esperables si credenciales V-Count están vacías.                                                            | Si falla: mensajes en stderr indican qué falta.                                                         |
| Solo KOACH en `.env` (sin SQL aún) | `php cws/scripts/check_env.php --no-sql`    | Credenciales KOACH360                             | Igual: `OK:` y código **0**.                                                                                                                                                                                                                                         | —                                                                                                       |
| Login KOACH360                     | `php cws/scripts/test_koach360_login.php`   | Red hasta `KOACH360_BASE_URL`; usuario/contraseña | Consola: `OK: token obtenido`; código **0**.                                                                                                                                                                                                                         | Si código **1**: revisar credenciales y URL.                                                            |
| V-Count → archivos JSON            | `php tareas/syn_fetch_vcount.php 1`         | `US_VCOUNT`, `PS_VCOUNT`, red                     | En `tareas/json/` aparecen **nuevos** `vcount_horaria_YYYYMMDD_HHMMSS.json` y `vcount_diaria_YYYYMMDD_HHMMSS.json`. En `tareas/logs/syn_fetch_vcount.log`, la última línea debe llevar `horaria=ok` y `diaria=ok` (no `null`).                                       | Abrir JSON y comprobar que la estructura no sea claramente vacía/errónea según criterio de negocio.     |
| Extensión `sqlsrv`                 | `php -m`                                    | PHP instalado                                     | La salida incluye `**sqlsrv`** (y suele aparecer `pdo_sqlsrv`).                                                                                                                                                                                                      | —                                                                                                       |
| SQL Server (conexión)              | `php cws/scripts/check_sqlsrv.php`          | `SQL_SCS_*` correctas; red                        | Consola: línea `OK: sqlsrv conectado...`; código **0**.                                                                                                                                                                                                              | —                                                                                                       |
| Ventas → KOACH360 (retroactivo)    | `php tareas/syn_put_ventas.php retroactivo` | SQL + KOACH360                                    | Nuevo `tareas/json/ventas_YYYYMMDD_HHMMSS.json`. En `tareas/logs/syn_put_ventas.log`, última línea con `modo=retroactivo ... filas=N resp=...`. **Éxito HTTP:** `resp=` no debe empezar por `KOACH360 ventas HTTP`.                                                  | Validar en **KOACH360** (manual de integración / pantallas acordadas) que los datos esperados llegaron. |
| Ventas → KOACH360 (intradia)       | `php tareas/syn_put_ventas.php intradia`    | SQL + KOACH360                                    | Log `tareas/logs/syn_put_ventas.log` con `modo=intradia`. Fuera de ventana 09-22 registra `skip fuera_ventana` (sin envío). Misma regla para `resp=`.                                                                                                                | Igual: verificación en KOACH360.                                                                        |
| Asistencias reales → KOACH360      | `php tareas/syn_put_asistencias.php`        | SQL + KOACH360                                    | Si **hay filas** (`filas>0` en log): nuevo `tareas/json/asistencias_YYYYMMDD_HHMMSS.json` y línea en `tareas/logs/syn_put_asistencias.log` con `resp=` sin `KOACH360 asistencias HTTP`. Si **no hay datos**: `resp=sin_registros` (no se llama a la API; es válido). | KOACH360 / negocio.                                                                                     |
| Asistencias programadas            | `php tareas/syn_put_asistencias_prog.php`   | SQL + KOACH360                                    | Nuevo `tareas/json/asistencias__prog_YYYYMMDD_HHMMSS.json` salvo que la expansión quede vacía (`resp=sin_registros_programados_expandidos`). Sin `KOACH360 asistencias programadas HTTP` en `resp=` cuando sí hubo POST.                                             | KOACH360 / negocio.                                                                                     |
| V-Count → staging SQL              | `php tareas/syn_consumo_vcount.php`         | SQL + API V-Count                                 | Consola: `Inserción completada.` (si hubo datos) o `No se recibieron datos válidos.` (cuando la API no devolvió datos útiles). El script usa por defecto los últimos **10 días** (hasta ayer).                                                                       | Tabla `BISTAGING.dbo.STG_ConsumoContador` (coordinar con DBA/datos).                                    |


### Depuración de asistencias (solo diagnóstico)

Con `**ASIST_DEBUG=1`** en `.env`, `syn_put_asistencias.php` **no envía** a KOACH360: escribe contadores de la consulta en `tareas/logs/syn_put_asistencias.log` y termina. Usar solo para analizar datos en SQL, no como prueba de integración KOACH360.

### Sobre códigos de salida de `syn_put_*`

PHP suele devolver **0** al terminar sin error fatal aunque la API devuelva error: el criterio principal para ventas/asistencias es el texto `**resp=`** en el log correspondiente y la ausencia de prefijos `KOACH360 ... HTTP`.

## Ejecución manual (Windows, desde la raíz del repo)

```powershell
Set-Location "C:\ruta\klienteV1"
php cws/scripts/check_env.php
php cws/scripts/test_koach360_login.php
php tareas/syn_put_ventas.php retroactivo
php tareas/syn_put_ventas.php intradia
php tareas/syn_put_asistencias.php
php tareas/syn_put_asistencias_prog.php
php tareas/syn_fetch_vcount.php
php tareas/syn_fetch_vcount.php 14
```

## API `store_employees` (HTTP)

El archivo `cws/api/store_employees.php` expone una API CRUD de empleados (GET/POST/PUT/PATCH/DELETE).  
Para pruebas locales, levantar servidor embebido de PHP desde la raíz del repo:

```powershell
Set-Location "C:\ruta\klienteV1"
php -S localhost:8080 -t cws/api
```

En Linux:

```bash
cd /opt/backend/kliente-v1
php -S 0.0.0.0:8080 -t cws/api
```

Pruebas rápidas:

- Health: `GET /store_employees.php?health=1`
- Cargos permitidos: `GET /store_employees.php?meta=cargos`
- Catálogo de almacenes: `GET /store_employees.php?meta=almacenes`

Ejemplos:

```bash
curl "http://localhost:8080/store_employees.php?health=1"
curl "http://localhost:8080/store_employees.php?meta=cargos"
curl "http://localhost:8080/store_employees.php?meta=almacenes"
```

Alta de empleado (POST):

```bash
curl -X POST "http://localhost:8080/store_employees.php" \
  -H "Content-Type: application/json" \
  -H "X-User: QA_LOCAL" \
  -d '{
    "cedula_identidad":"0123456789",
    "correo":"empleado@empresa.com",
    "nombre":"Empleado Prueba",
    "cargo":"Asesor de ventas",
    "codigo_tienda":"ALM001",
    "estado":1,
    "encargado":0
  }'
```

## Ejecución manual y `cron` (Linux, desde la raíz del repo)

```bash
cd /opt/klienteV1
php cws/scripts/check_env.php
php cws/scripts/test_koach360_login.php
php tareas/syn_fetch_vcount.php 1
php tareas/syn_put_ventas.php retroactivo
php tareas/syn_put_ventas.php intradia
```

Configuración actual de `crontab`:

```cron
# KLIENTE Ejecuta 'syn_consumo_vcount.php' todos los días a las 02:00 a.m., y guarda la salida en el log
0 2 * * * /usr/bin/php $K/tareas/syn_consumo_vcount.php >> /storage/tareas/syn_consumo_vcount.log 2>&1

# KLIENTE Ejecuta 'syn_put_ventas.php' todos los días a las 06:00 a.m., y guarda la salida en el log
0 6 * * * cd /opt/klienteV1 && /usr/bin/php tareas/syn_put_ventas.php retroactivo >> /opt/klienteV1/tareas/logs/cron_put_ventas.log 2>&1

# KLIENTE Ejecuta 'syn_put_ventas.php' intradia (09:00-22:00, cada hora)
0 9-22 * * * cd /opt/klienteV1 && /usr/bin/php tareas/syn_put_ventas.php intradia >> /opt/klienteV1/tareas/logs/cron_put_ventas_intradia.log 2>&1

# KLIENTE Ejecuta 'syn_put_asistencias.php' Asistencias reales (diario)
20 9 * * * cd /opt/klienteV1 && /usr/bin/php tareas/syn_put_asistencias.php >> /opt/klienteV1/tareas/logs/cron_put_asistencias.log 2>&1

# KLIENTE Ejecuta 'syn_put_asistencias_prog.php' Asistencias programadas: diario días 1-8
20 6 1-8 * * cd /opt/klienteV1 && /usr/bin/php tareas/syn_put_asistencias_prog.php >> /opt/klienteV1/tareas/logs/cron_put_asistencias_prog.log 2>&1

# KLIENTE Ejecuta 'syn_put_asistencias_prog.php' Asistencias programadas: lunes cuando día > 8
30 6 * * 1 [ "$(date +\%d)" -gt 8 ] && cd /opt/klienteV1 && /usr/bin/php tareas/syn_put_asistencias_prog.php >> /opt/klienteV1/tareas/logs/cron_put_asistencias_prog.log 2>&1
```

Scripts que deben seguir existiendo en el repo para cron: los bajo `tareas/syn_put_*.php`, `syn_fetch_vcount.php`, `syn_consumo_vcount.php` (opcional). Tras un despliegue, revisar que **ningún** job apunte a scripts eliminados en versiones anteriores.

## Recurrencia recomendada (manual KOACH360)

- Ventas continuas: **1 vez al día** `retroactivo` + **cada hora 09:00-22:00** `intradia` usando `syn_put_ventas.php`.
- Tiendas consideradas: **Fase 1** según `dbo.integration_store_scope` (por `integration` y `es_fase1=1`), con fallback a `TIENDAS_FASE1_CODIGOS` en `.env`.

### Reglas de filtrado en ventas (`syn_put_ventas.php`)

El query de ventas aplica filtros funcionales para evitar ruido o duplicidad en la carga hacia KOACH360:

- `dv.CodigoInterno <> '114'`  
Excluye el vendedor/código interno `114` del envío (regla de negocio específica del cliente).
- `dp.TipoProducto = 'PRODUCTO'`  
Considera solo líneas de producto; excluye conceptos que no son producto (servicios, cargos u otros tipos).
- `fv.VentaDiariaFc = 0`  
Filtra registros no marcados como venta fiscal/ajuste en `Fact_VentasDiaria`, evitando duplicar o mezclar movimientos no deseados en el payload final.

Además de lo anterior, el script siempre filtra por:

- rango de fecha/hora según modo (`retroactivo` o `intradia`),
- tiendas autorizadas por scope (`integration_store_scope` / fallback de `.env`).

Wrappers PowerShell disponibles: `tareas/scripts/run_ventas_horario.ps1`, `tareas/scripts/run_ventas_retro7_madrugada.ps1` (si se mantiene el flujo legado).

Ejemplo `schtasks` (ajustar ruta):

```powershell
schtasks /Create /F /TN "KLIENTEV1_VENTAS_RETROACTIVO" /SC DAILY /ST 06:00 /TR "powershell.exe -NoProfile -ExecutionPolicy Bypass -Command \"Set-Location 'C:\ruta\klienteV1'; php tareas\syn_put_ventas.php retroactivo\""
schtasks /Create /F /TN "KLIENTEV1_VENTAS_INTRADIA" /SC HOURLY /MO 1 /ST 09:00 /ET 22:59 /TR "powershell.exe -NoProfile -ExecutionPolicy Bypass -Command \"Set-Location 'C:\ruta\klienteV1'; php tareas\syn_put_ventas.php intradia\""
```

## Variables de entorno (resumen)

Ver `.env.example`. Principales:

- **SQL:** `SQL_SCS_HOST`, `SQL_SCS_DATABASE`, `SQL_SCS_USER`, `SQL_SCS_PASSWORD`
- **KOACH360:** `KOACH360_BASE_URL`, `KOACH360_USUARIO`, `KOACH360_PASSWORD`, `KOACH360_CLIENT_ID` (o `CLI_PVENTAS`), `KOACH360_HORA_PROG_DEFAULT`
- **Ventas — correo asesor (opcional):** `VENTAS_ASESOR_FROM_NOMINA`, `VENTAS_ASESOR_CORREO_COLUMN`
- **V-Count:** `US_VCOUNT`, `PS_VCOUNT`, `URL_VCOUNT`, `URL_VCOUNT_DAILY`
- **HTTP (opcional):** `KOACH360_HTTP_TIMEOUT`, `KOACH360_CONNECT_TIMEOUT`, `KOACH360_RETRY_MAX`, `VCOUNT_HTTP_TIMEOUT`, `VCOUNT_CONNECT_TIMEOUT`, `VCOUNT_RETRY_MAX`
- **check_env:** `CWS_CHECK_ENV_NO_SQL=1` (equivalente a `php cws/scripts/check_env.php --no-sql`)
- **Asistencias (solo diagnóstico SQL):** `ASIST_DEBUG=1` (ver sección *Guía de validación para QA*)
- **Ventas continuas:** `VENTAS_CONTINUO_DESDE` (go-live, p. ej. `2026-04-01`), `VENTAS_INTRADIA_FORCE=1` (solo pruebas fuera de 09-22)

## Extensiones SQL en Windows (XAMPP)

Si aparecen avisos `Unable to load dynamic library 'php_sqlsrv_...'` o *already loaded*, revisar `php.ini`: una sola línea por extensión y DLL que exista. `test_koach360_login.php` no necesita SQL; `check_env.php` solo lee variables (tampoco abre SQL).

## Observabilidad

- **V-Count:** errores HTTP o JSON inválido se registran con `error_log`, incluyendo URL y cuerpo de respuesta **truncado** (~4 KB) vía `cws_truncate_for_log()` en `dat_cws.php`.
- **KOACH360:** fallos de login y respuestas HTTP no exitosas en `koach360_post_json` también se registran con cuerpo truncado.
- Las tareas `syn_`* siguen escribiendo en `tareas/logs/*.log` y JSON en `tareas/json/`.

## Funciones PHP (envío KOACH360)


| Uso                     | Función principal                                                                   | Alias antiguo (`@deprecated`)                                       |
| ----------------------- | ----------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| Ventas                  | `enviarVentasKoach360`, `generarVentasKoach360Json`                                 | `enviarFacturasKliente`, `generarFacturasJson`                      |
| Asistencias reales      | `enviarAsistenciasRealesKoach360`, `generarAsistenciasRealesKoach360Json`           | `enviarAsistenciasKliente`, `generarAsistenciasJson`                |
| Asistencias programadas | `enviarAsistenciasProgramadasKoach360`, `generarAsistenciasProgramadasKoach360Json` | `enviarAsistenciasProgramadas`, `generarAsistenciasProgramadasJson` |


## Archivos clave en `cws/`

- `dat_cws.php` — `.env` + constantes KOACH360 / V-Count + `cws_truncate_for_log`.
- `f_koach360.php` — JWT y POST autenticados.
- `f_put_ventas.php`, `f_put_asistencias.php`, `f_put_asistencias_prog.php` — mapeo al contrato del manual.
- `f_consumo_vcount.php` — cliente BoostBI.
- `scripts/check_env.php` — validación mínima de `.env`.
- `scripts/check_sqlsrv.php` — prueba de conexión `sqlsrv` + `SELECT 1`.

