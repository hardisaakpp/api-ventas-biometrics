<?php
	require_once __DIR__ . '/../cws/dat_cws.php';

	if (!function_exists('dat_scs_env_value')) {
		function dat_scs_env_value(string $keyBase): string {
			$profile = strtoupper(trim(cws_env('CWS_SQL_PROFILE')));
			if ($profile !== '') {
				$profileKey = $keyBase . '_' . $profile;
				$v = cws_env($profileKey);
				if ($v !== '') {
					return $v;
				}
			}
			return cws_env($keyBase);
		}
	}

	if (!defined('HOST')) {
		define('HOST', dat_scs_env_value('SQL_SCS_HOST'));
	}
	if (!defined('USUARIO')) {
		define('USUARIO', dat_scs_env_value('SQL_SCS_USER'));
	}
	if (!defined('PSW')) {
		define('PSW', dat_scs_env_value('SQL_SCS_PASSWORD'));
	}
	if (!defined('BD')) {
		define('BD', dat_scs_env_value('SQL_SCS_DATABASE'));
	}
?>