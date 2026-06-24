<?php
	/**
	 * Carga .env desde la raíz de klienteV1 (sin dependencia extra; opcional vlucas/phpdotenv si existe).
	 */
	if (!function_exists('cws_load_dotenv')) {
		function cws_load_dotenv(string $envDir): void {
			if (class_exists('Dotenv\\Dotenv')) {
				call_user_func(['Dotenv\\Dotenv', 'createImmutable'], $envDir)->safeLoad();
				return;
			}
			$path = $envDir . DIRECTORY_SEPARATOR . '.env';
			if (!is_readable($path)) {
				return;
			}
			$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			if ($lines === false) {
				return;
			}
			foreach ($lines as $line) {
				$line = trim($line);
				if ($line === '' || str_starts_with($line, '#')) {
					continue;
				}
				if (!str_contains($line, '=')) {
					continue;
				}
				[$name, $value] = explode('=', $line, 2);
				$name = trim($name);
				$value = trim($value);
				if ($name === '') {
					continue;
				}
				if (str_starts_with($value, '"') && str_ends_with($value, '"') && strlen($value) >= 2) {
					$value = stripcslashes(substr($value, 1, -1));
				} elseif (str_starts_with($value, "'") && str_ends_with($value, "'") && strlen($value) >= 2) {
					$value = substr($value, 1, -1);
				}
				$_ENV[$name] = $value;
				putenv($name . '=' . $value);
			}
		}
	}

	if (is_readable(__DIR__ . '/vendor/autoload.php')) {
		require_once __DIR__ . '/vendor/autoload.php';
	}
	cws_load_dotenv(__DIR__ . '/..');

	if (!function_exists('cws_env')) {
		/**
		 * Lee variable de entorno (.env o sistema). Si la clave existe en .env vacía, devuelve '' (no aplica $default).
		 */
		function cws_env(string $key, string $default = ''): string {
			if (array_key_exists($key, $_ENV)) {
				return (string) $_ENV[$key];
			}
			if (array_key_exists($key, $_SERVER)) {
				return (string) $_SERVER[$key];
			}
			$v = getenv($key);
			if ($v !== false) {
				return (string) $v;
			}
			return $default;
		}
	}

	if (!function_exists('cws_truncate_for_log')) {
		/** Recorta texto para logs (respuestas HTTP largas). */
		function cws_truncate_for_log(string $s, int $maxBytes = 4096): string {
			if ($maxBytes < 64) {
				$maxBytes = 64;
			}
			$len = strlen($s);
			if ($len <= $maxBytes) {
				return $s;
			}
			return substr($s, 0, $maxBytes) . '… [truncado, ' . $len . ' bytes]';
		}
	}

	if (!function_exists('koach360_runtime_environment')) {
		function koach360_runtime_environment(): string {
			$env = trim(cws_env('KOACH360_ENVIRONMENT', cws_env('APP_ENV', 'PRUEBAS')));
			if ($env === '') {
				$env = 'PRUEBAS';
			}
			return strtoupper($env);
		}
	}

	if (!function_exists('koach360_config_connection')) {
		function koach360_config_connection() {
			$host = cws_env('STORE_EMPLOYEES_SQL_HOST', '210.17.1.54');
			$db = cws_env('STORE_EMPLOYEES_SQL_DATABASE', 'sc_kliente');
			$user = cws_env('STORE_EMPLOYEES_SQL_USER');
			$pass = cws_env('STORE_EMPLOYEES_SQL_PASSWORD');
			if ($host === '' || $db === '' || $user === '' || $pass === '') {
				return null;
			}
			$conn = @sqlsrv_connect($host, [
				'Database' => $db,
				'UID' => $user,
				'PWD' => $pass,
				'MultipleActiveResultSets' => true,
				'TrustServerCertificate' => true,
				'CharacterSet' => 'UTF-8',
			]);
			return $conn === false ? null : $conn;
		}
	}

	if (!function_exists('koach360_resolver_config')) {
		/**
		 * Resuelve config de integración desde BD por ambiente y tienda opcional.
		 * Fallback fuera de esta función se hace con variables de entorno.
		 */
		function koach360_resolver_config(string $integration, ?string $codigoTienda = null): array {
			static $cache = [];
			$integration = strtoupper(trim($integration));
			$codigoTienda = trim((string) $codigoTienda);
			$codigoTienda = $codigoTienda === '' ? null : strtoupper($codigoTienda);
			$environment = koach360_runtime_environment();
			$key = $environment . '|' . $integration . '|' . ($codigoTienda ?? '-');
			if (array_key_exists($key, $cache)) {
				return $cache[$key];
			}

			$out = ['cliente_id' => null, 'api_user' => null];
			$conn = koach360_config_connection();
			if ($conn === null) {
				$cache[$key] = $out;
				return $out;
			}

			$sql = "
				SELECT TOP 1 cliente_id, api_user
				FROM dbo.integration_client_config
				WHERE activo = 1
				  AND UPPER(environment) = ?
				  AND UPPER(integration) = ?
				  AND (
				       (? IS NULL AND codigo_tienda IS NULL)
				       OR UPPER(codigo_tienda) = ?
				       OR codigo_tienda IS NULL
				  )
				ORDER BY CASE WHEN UPPER(codigo_tienda) = ? THEN 0 ELSE 1 END, id DESC;
			";
			$params = [$environment, $integration, $codigoTienda, $codigoTienda, $codigoTienda];
			$stmt = @sqlsrv_query($conn, $sql, $params);
			if ($stmt !== false) {
				$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
				if (is_array($row)) {
					$cid = isset($row['cliente_id']) ? (int) $row['cliente_id'] : 0;
					$out['cliente_id'] = $cid > 0 ? $cid : null;
					$user = trim((string) ($row['api_user'] ?? ''));
					$out['api_user'] = $user !== '' ? $user : null;
				}
				sqlsrv_free_stmt($stmt);
			}
			sqlsrv_close($conn);
			$cache[$key] = $out;
			return $out;
		}
	}

	// V-Count BoostBI (manual API)

		if (!defined('US_VCOUNT')) { define('US_VCOUNT', cws_env('US_VCOUNT')); }
		if (!defined('PS_VCOUNT')) { define('PS_VCOUNT', cws_env('PS_VCOUNT')); }
		if (!defined('URL_VCOUNT')) {
			define('URL_VCOUNT', cws_env('URL_VCOUNT', 'https://boostbi.v-count.com/api/v4/vcountapi'));
		}
		if (!defined('URL_VCOUNT_DAILY')) {
			define('URL_VCOUNT_DAILY', cws_env('URL_VCOUNT_DAILY', 'https://boostbi.v-count.com/api/v4/vcountapi_daily'));
		}

	// KOACH360 (manual integración API)

		if (!defined('KOACH360_BASE_URL')) {
			define('KOACH360_BASE_URL', cws_env('KOACH360_BASE_URL', 'https://koach360.kliente.tech:5000'));
		}
		if (!defined('KOACH360_PATH_LOGIN')) { define('KOACH360_PATH_LOGIN', '/api/Auth/login'); }
		if (!defined('KOACH360_PATH_VENTAS')) { define('KOACH360_PATH_VENTAS', '/api/Ventas/cargaVentasV1'); }
		if (!defined('KOACH360_PATH_ASIST_REAL')) { define('KOACH360_PATH_ASIST_REAL', '/api/AsistenciaReal/cargaAsistenciaRealV1'); }
		if (!defined('KOACH360_PATH_ASIST_PROG')) {
			define('KOACH360_PATH_ASIST_PROG', '/api/AsistenciasProgramadas/integracionAsistenciasProgramadas');
		}

		if (!defined('KOACH360_CLIENT_ID')) {
			$cfgVentas = koach360_resolver_config('KOACH360_VENTAS');
			$kid = isset($cfgVentas['cliente_id']) ? (string) $cfgVentas['cliente_id'] : '';
			if ($kid === '' || $kid === '0') {
				$kid = cws_env('KOACH360_CLIENT_ID');
			}
			if ($kid === '') {
				$kid = cws_env('CLI_PVENTAS', '17');
			}
			define('KOACH360_CLIENT_ID', $kid);
		}
		if (!defined('CLI_PVENTAS')) {
			$cfgVentas = koach360_resolver_config('KOACH360_VENTAS');
			$cid = isset($cfgVentas['cliente_id']) ? (string) $cfgVentas['cliente_id'] : '';
			define('CLI_PVENTAS', $cid !== '' && $cid !== '0' ? $cid : KOACH360_CLIENT_ID);
		}
		if (!defined('CLI_ASISTENCIAS')) {
			$cfgAsis = koach360_resolver_config('KOACH360_ASISTENCIAS');
			$cid = isset($cfgAsis['cliente_id']) ? (string) $cfgAsis['cliente_id'] : '';
			define('CLI_ASISTENCIAS', $cid !== '' && $cid !== '0' ? $cid : KOACH360_CLIENT_ID);
		}
		if (!defined('CLI_ASISTENCIAS_PROGRAMADAS')) {
			$cfgAsisProg = koach360_resolver_config('KOACH360_ASISTENCIAS_PROGRAMADAS');
			$cid = isset($cfgAsisProg['cliente_id']) ? (string) $cfgAsisProg['cliente_id'] : '';
			define('CLI_ASISTENCIAS_PROGRAMADAS', $cid !== '' && $cid !== '0' ? $cid : KOACH360_CLIENT_ID);
		}

		if (!defined('KOACH360_USUARIO')) {
			$cfgAuth = koach360_resolver_config('KOACH360_AUTH');
			$cfgVentas = koach360_resolver_config('KOACH360_VENTAS');
			$kU = (string) ($cfgAuth['api_user'] ?? '');
			if ($kU === '') {
				$kU = (string) ($cfgVentas['api_user'] ?? '');
			}
			if ($kU === '') {
				$kU = cws_env('KOACH360_USUARIO');
			}
			define('KOACH360_USUARIO', $kU !== '' ? $kU : cws_env('US_PVENTAS'));
		}
		if (!defined('KOACH360_PASSWORD')) {
			$envName = koach360_runtime_environment();
			$kP = cws_env('KOACH360_PASSWORD_' . $envName);
			if ($kP === '') {
				$kP = cws_env('KOACH360_PASSWORD');
			}
			define('KOACH360_PASSWORD', $kP !== '' ? $kP : cws_env('PS_PVENTAS'));
		}
		if (!defined('KOACH360_HORA_PROG_DEFAULT')) {
			define('KOACH360_HORA_PROG_DEFAULT', cws_env('KOACH360_HORA_PROG_DEFAULT', '09:00'));
		}

	//--------------------------------------------------------------------------------------------------------------
?>
