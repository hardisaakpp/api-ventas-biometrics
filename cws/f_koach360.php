<?php
    require_once __DIR__ . '/dat_cws.php';

    if (!function_exists('koach360_retry_sleep_ms')) {
        function koach360_retry_sleep_ms(int $attempt, int $baseMs = 400): void {
            $delayMs = $baseMs * (2 ** max(0, $attempt - 1));
            usleep($delayMs * 1000);
        }
    }

    if (!function_exists('koach360_http_should_retry')) {
        function koach360_http_should_retry(int $httpCode): bool {
            return $httpCode === 429 || ($httpCode >= 500 && $httpCode <= 599);
        }
    }

    /**
     * Obtiene JWT para KOACH360. Cache en memoria ~46 min (token ~1 h).
     */
    function koach360_obtener_token(
        ?string $usuario = null,
        ?string $password = null,
        ?string $baseUrl = null,
        bool $forceRefresh = false
    ): ?string {
        static $cacheToken = null;
        static $cacheTime = 0;

        $usuario = $usuario ?? KOACH360_USUARIO;
        $password = $password ?? KOACH360_PASSWORD;
        $baseUrl = rtrim($baseUrl ?? KOACH360_BASE_URL, '/');

        if (!$forceRefresh && $cacheToken !== null && (time() - $cacheTime) < 2760) {
            return $cacheToken;
        }

        $url = $baseUrl . KOACH360_PATH_LOGIN;
        $body = json_encode(['usuario' => $usuario, 'password' => $password], JSON_UNESCAPED_UNICODE);
        $timeout = (int) cws_env('KOACH360_HTTP_TIMEOUT', '3600');
        $connectTimeout = (int) cws_env('KOACH360_CONNECT_TIMEOUT', '20');
        $maxAttempts = max(1, (int) cws_env('KOACH360_RETRY_MAX', '3'));

        $response = '';
        $code = 0;
        $err = '';
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            ]);

            $response = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            unset($ch);

            $transient = ($response === false) || koach360_http_should_retry($code);
            if (!$transient || $attempt === $maxAttempts) {
                break;
            }
            error_log("KOACH360 login retry {$attempt}/{$maxAttempts} HTTP {$code} curl={$err}");
            koach360_retry_sleep_ms($attempt);
        }

        if ($response === false || $code !== 200) {
            error_log('KOACH360 login HTTP ' . $code . ' curl=' . $err . ' body=' . cws_truncate_for_log((string) $response));
            return null;
        }

        $data = json_decode($response, true);
        $token = $data['token'] ?? $data['Token'] ?? null;
        if ($token === null && is_array($data['retorno'] ?? null)) {
            $r = $data['retorno'];
            $token = $r['token'] ?? $r['Token'] ?? null;
        }
        if (!is_string($token) || $token === '') {
            error_log('KOACH360 login sin token en respuesta');
            return null;
        }

        $cacheToken = $token;
        $cacheTime = time();
        return $token;
    }

    /**
     * POST JSON a KOACH360 con Bearer.
     */
    function koach360_post_json(string $path, string $jsonBody, ?string $token = null): array {
        $baseUrl = rtrim(KOACH360_BASE_URL, '/');
        $token = $token ?? koach360_obtener_token();
        if ($token === null) {
            return ['ok' => false, 'http' => 0, 'body' => '', 'error' => 'sin_token'];
        }

        $url = $baseUrl . $path;
        $timeout = (int) cws_env('KOACH360_HTTP_TIMEOUT', '3600');
        $connectTimeout = (int) cws_env('KOACH360_CONNECT_TIMEOUT', '20');
        $maxAttempts = max(1, (int) cws_env('KOACH360_RETRY_MAX', '3'));

        $response = false;
        $http = 0;
        $cerr = '';
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonBody,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $token,
                ],
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            ]);

            $response = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            unset($ch);

            // Token expirado o inválido: refresca una vez y reintenta inmediatamente.
            if ($http === 401 && $attempt === 1) {
                $newToken = koach360_obtener_token(null, null, null, true);
                if (is_string($newToken) && $newToken !== '') {
                    $token = $newToken;
                    continue;
                }
            }

            $transient = ($response === false) || koach360_http_should_retry($http);
            if (!$transient || $attempt === $maxAttempts) {
                break;
            }
            error_log("KOACH360 POST {$path} retry {$attempt}/{$maxAttempts} HTTP {$http} curl={$cerr}");
            koach360_retry_sleep_ms($attempt);
        }

        if ($response === false) {
            return ['ok' => false, 'http' => $http, 'body' => '', 'error' => $cerr];
        }

        $body = (string) $response;
        $ok = $http >= 200 && $http < 300;
        if (!$ok) {
            error_log(
                'KOACH360 POST ' . $path . ' HTTP ' . $http . ' body=' . cws_truncate_for_log($body)
            );
        }

        return ['ok' => $ok, 'http' => $http, 'body' => $body, 'error' => ''];
    }
