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
			$kid = cws_env('KOACH360_CLIENT_ID');
			if ($kid === '') {
				$kid = cws_env('CLI_PVENTAS', '17');
			}
			define('KOACH360_CLIENT_ID', $kid);
		}
		if (!defined('CLI_PVENTAS')) { define('CLI_PVENTAS', KOACH360_CLIENT_ID); }
		if (!defined('CLI_ASISTENCIAS')) { define('CLI_ASISTENCIAS', KOACH360_CLIENT_ID); }
		if (!defined('CLI_ASISTENCIAS_PROGRAMADAS')) { define('CLI_ASISTENCIAS_PROGRAMADAS', KOACH360_CLIENT_ID); }

		if (!defined('KOACH360_USUARIO')) {
			$kU = cws_env('KOACH360_USUARIO');
			define('KOACH360_USUARIO', $kU !== '' ? $kU : cws_env('US_PVENTAS'));
		}
		if (!defined('KOACH360_PASSWORD')) {
			$kP = cws_env('KOACH360_PASSWORD');
			define('KOACH360_PASSWORD', $kP !== '' ? $kP : cws_env('PS_PVENTAS'));
		}
		if (!defined('KOACH360_HORA_PROG_DEFAULT')) {
			define('KOACH360_HORA_PROG_DEFAULT', cws_env('KOACH360_HORA_PROG_DEFAULT', '09:00'));
		}

	//--------------------------------------------------------------------------------------------------------------
?>
