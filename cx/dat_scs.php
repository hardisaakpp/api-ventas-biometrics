<?php
	require_once __DIR__ . '/../cws/dat_cws.php';

	if (!defined('HOST')) {
		define('HOST', cws_env('SQL_SCS_HOST'));
	}
	if (!defined('USUARIO')) {
		define('USUARIO', cws_env('SQL_SCS_USER'));
	}
	if (!defined('PSW')) {
		define('PSW', cws_env('SQL_SCS_PASSWORD'));
	}
	if (!defined('BD')) {
		define('BD', cws_env('SQL_SCS_DATABASE'));
	}
?>