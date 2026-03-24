<?php
	/**
	 * Carga .env desde la raíz de klienteV1 (sin dependencia extra; opcional vlucas/phpdotenv si existe).
	 */
	if (!function_exists('cws_load_dotenv')) {
		function cws_load_dotenv(string $envDir): void {
			if (class_exists(\Dotenv\Dotenv::class)) {
				\Dotenv\Dotenv::createImmutable($envDir)->safeLoad();
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

	//SNAPP--------------------------------------------------------------------------------------------------------------

		if (!defined('APILOGIN')) { define('APILOGIN', cws_env('APILOGIN', 'https://e-proxy.hitellcorp.com/apilogin/external')); }
		if (!defined('STORES')) { define('STORES', cws_env('STORES', 'https://e-proxy.hitellcorp.com/external/stores')); }
		if (!defined('ARQUEO')) { define('ARQUEO', cws_env('ARQUEO', 'https://e-proxy.hitellcorp.com/external/arqueo-caja')); }

		if (!defined('US_MABEL')) { define('US_MABEL', cws_env('US_MABEL')); }
		if (!defined('PS_MABEL')) { define('PS_MABEL', cws_env('PS_MABEL')); }
		if (!defined('US_COSMEC')) { define('US_COSMEC', cws_env('US_COSMEC')); }
		if (!defined('PS_COSMEC')) { define('PS_COSMEC', cws_env('PS_COSMEC')); }

	//KLIENTE--------------------------------------------------------------------------------------------------------------

		if (!defined('US_VCOUNT')) { define('US_VCOUNT', cws_env('US_VCOUNT')); }
		if (!defined('PS_VCOUNT')) { define('PS_VCOUNT', cws_env('PS_VCOUNT')); }
		if (!defined('URL_VCOUNT')) {
			define('URL_VCOUNT', cws_env('URL_VCOUNT', 'https://boostbi.v-count.com/api/v4/vcountapi'));
		}
		if (!defined('URL_VCOUNT_DAILY')) {
			define('URL_VCOUNT_DAILY', cws_env('URL_VCOUNT_DAILY', 'https://boostbi.v-count.com/api/v4/vcountapi_daily'));
		}

		if (!defined('URL_PVENTAS')) {
			define('URL_PVENTAS', cws_env('URL_PVENTAS', 'https://coach.kliente.tech:443/Kliente/api_ImportarVentasV2/'));
		}
		if (!defined('US_PVENTAS')) { define('US_PVENTAS', cws_env('US_PVENTAS')); }
		if (!defined('PS_PVENTAS')) { define('PS_PVENTAS', cws_env('PS_PVENTAS')); }
		if (!defined('CLI_PVENTAS')) { define('CLI_PVENTAS', cws_env('CLI_PVENTAS', '48')); }

		if (!defined('URL_ASISTENCIAS')) {
			define('URL_ASISTENCIAS', cws_env('URL_ASISTENCIAS', 'https://coach.kliente.tech:443/Kliente/api_ImportarAsistenciasAsesores/index.php'));
		}
		if (!defined('US_ASISTENCIAS')) { define('US_ASISTENCIAS', cws_env('US_ASISTENCIAS')); }
		if (!defined('PS_ASISTENCIAS')) { define('PS_ASISTENCIAS', cws_env('PS_ASISTENCIAS')); }
		if (!defined('CLI_ASISTENCIAS')) { define('CLI_ASISTENCIAS', cws_env('CLI_ASISTENCIAS', '48')); }

		if (!defined('URL_ASISTENCIAS_PROGRAMADAS')) {
			define(
				'URL_ASISTENCIAS_PROGRAMADAS',
				cws_env('URL_ASISTENCIAS_PROGRAMADAS', 'https://coach.kliente.tech/Kliente/api_ImportarAsistenciasProgramadasAsesores/')
			);
		}
		if (!defined('US_ASISTENCIAS_PROGRAMADAS')) {
			define('US_ASISTENCIAS_PROGRAMADAS', cws_env('US_ASISTENCIAS_PROGRAMADAS'));
		}
		if (!defined('PS_ASISTENCIAS_PROGRAMADAS')) {
			define('PS_ASISTENCIAS_PROGRAMADAS', cws_env('PS_ASISTENCIAS_PROGRAMADAS'));
		}
		if (!defined('CLI_ASISTENCIAS_PROGRAMADAS')) {
			define('CLI_ASISTENCIAS_PROGRAMADAS', cws_env('CLI_ASISTENCIAS_PROGRAMADAS', '48'));
		}

		if (!defined('KOACH360_BASE_URL')) {
			define('KOACH360_BASE_URL', cws_env('KOACH360_BASE_URL', 'https://koach360.kliente.tech:5000'));
		}
		if (!defined('KOACH360_PATH_LOGIN')) { define('KOACH360_PATH_LOGIN', '/api/Auth/login'); }
		if (!defined('KOACH360_PATH_VENTAS')) { define('KOACH360_PATH_VENTAS', '/api/Ventas/cargaVentasV1'); }
		if (!defined('KOACH360_PATH_ASIST_REAL')) { define('KOACH360_PATH_ASIST_REAL', '/api/AsistenciaReal/cargaAsistenciaRealV1'); }
		if (!defined('KOACH360_PATH_ASIST_PROG')) {
			define('KOACH360_PATH_ASIST_PROG', '/api/AsistenciasProgramadas/integracionAsistenciasProgramadas');
		}
		if (!defined('KOACH360_USUARIO')) {
			$kU = cws_env('KOACH360_USUARIO');
			define('KOACH360_USUARIO', $kU !== '' ? $kU : US_PVENTAS);
		}
		if (!defined('KOACH360_PASSWORD')) {
			$kP = cws_env('KOACH360_PASSWORD');
			define('KOACH360_PASSWORD', $kP !== '' ? $kP : PS_PVENTAS);
		}
		if (!defined('KOACH360_HORA_PROG_DEFAULT')) {
			define('KOACH360_HORA_PROG_DEFAULT', cws_env('KOACH360_HORA_PROG_DEFAULT', '09:00'));
		}

	//BIMBA Y LOA--------------------------------------------------------------------------------------------------------------

		if (!defined('TENANT_ID')) {
			define('TENANT_ID', cws_env('BYL_TENANT_ID', 'bimbaylola.com'));
		}
		if (!defined('BYL_URL')) {
			define('BYL_URL', cws_env('BYL_URL', 'https://login.microsoftonline.com/' . TENANT_ID . '/oauth2/token?v2.0'));
		}
		if (!defined('BYL_GRANT_TYPE')) {
			define('BYL_GRANT_TYPE', cws_env('BYL_GRANT_TYPE', 'client_credentials'));
		}
		if (!defined('BYL_CLIENT_ID')) { define('BYL_CLIENT_ID', cws_env('BYL_CLIENT_ID')); }
		if (!defined('BYL_CLIENT_SECRET')) { define('BYL_CLIENT_SECRET', cws_env('BYL_CLIENT_SECRET')); }
		if (!defined('BYL_RESOURCE')) { define('BYL_RESOURCE', cws_env('BYL_RESOURCE')); }
		if (!defined('BYL_API_URL')) {
			define('BYL_API_URL', cws_env('BYL_API_URL', 'https://bimbaylola.azure-api.net/pdp-api-v1/StandardFranchisees?api-version=1'));
		}
		if (!defined('BYL_API_SUBSCRIPTION_KEY')) {
			define('BYL_API_SUBSCRIPTION_KEY', cws_env('BYL_API_SUBSCRIPTION_KEY'));
		}

	//--------------------------------------------------------------------------------------------------------------
?>
