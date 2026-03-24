<?php
    require_once __DIR__ . '/dat_cws.php';

    /**
     * Obtiene JWT para KOACH360. Cache en memoria ~46 min (token ~1 h).
     */
    function koach360_obtener_token(
        ?string $usuario = null,
        ?string $password = null,
        ?string $baseUrl = null
    ): ?string {
        static $cacheToken = null;
        static $cacheTime = 0;

        $usuario = $usuario ?? KOACH360_USUARIO;
        $password = $password ?? KOACH360_PASSWORD;
        $baseUrl = rtrim($baseUrl ?? KOACH360_BASE_URL, '/');

        if ($cacheToken !== null && (time() - $cacheTime) < 2760) {
            return $cacheToken;
        }

        $url = $baseUrl . KOACH360_PATH_LOGIN;
        $body = json_encode(['usuario' => $usuario, 'password' => $password], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        unset($ch);

        if ($response === false || $code !== 200) {
            error_log('KOACH360 login fallo HTTP ' . $code . ' ' . $err . ' ' . (string) $response);
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
            CURLOPT_TIMEOUT => 0,
        ]);

        $response = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        unset($ch);

        if ($response === false) {
            return ['ok' => false, 'http' => $http, 'body' => '', 'error' => $cerr];
        }

        return ['ok' => $http >= 200 && $http < 300, 'http' => $http, 'body' => (string) $response, 'error' => ''];
    }
