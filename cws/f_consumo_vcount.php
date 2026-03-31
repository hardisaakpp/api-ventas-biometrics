<?php
    require_once __DIR__ . '/dat_cws.php';

    if (!function_exists('vcount_retry_sleep_ms')) {
        function vcount_retry_sleep_ms(int $attempt, int $baseMs = 500): void {
            $delayMs = $baseMs * (2 ** max(0, $attempt - 1));
            usleep($delayMs * 1000);
        }
    }

    if (!function_exists('vcount_http_should_retry')) {
        function vcount_http_should_retry(int $httpCode): bool {
            return $httpCode === 429 || ($httpCode >= 500 && $httpCode <= 599);
        }
    }

    function consumir_vcount_data($start_date, $finish_date, $store = 'all', $us = US_VCOUNT, $ps = PS_VCOUNT, $url = URL_VCOUNT) {
        $postfields = http_build_query([
            'username'    => $us,
            'password'    => $ps,
            'format'      => 'json',
            'start_date'  => $start_date,
            'finish_date' => $finish_date,
            'store'       => $store
        ]);
        $timeout = (int) cws_env('VCOUNT_HTTP_TIMEOUT', '3600');
        $connectTimeout = (int) cws_env('VCOUNT_CONNECT_TIMEOUT', '20');
        $maxAttempts = max(1, (int) cws_env('VCOUNT_RETRY_MAX', '3'));

        try {
            $response = false;
            $httpCode = 0;
            $curlError = '';
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => $postfields,
                    CURLOPT_HTTPHEADER => array(
                        'User-Agent: Apidog/1.0.0 (https://apidog.com)',
                        'Accept: */*',
                        'Connection: keep-alive',
                        'Content-Type: application/x-www-form-urlencoded'
                    ),
                ));

                $response = curl_exec($curl);
                $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
                $curlError = curl_error($curl);
                unset($curl);

                $transient = ($response === false) || vcount_http_should_retry($httpCode);
                if (!$transient || $attempt === $maxAttempts) {
                    break;
                }
                error_log("V-Count retry {$attempt}/{$maxAttempts} HTTP {$httpCode} curl={$curlError} url={$url}");
                vcount_retry_sleep_ms($attempt);
            }

            if ($response === false) {
                throw new Exception('cURL: ' . $curlError);
            }

            if ($httpCode !== 200) {
                $body = cws_truncate_for_log((string) $response);
                throw new Exception("HTTP {$httpCode} url={$url} body={$body}");
            }

            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON: ' . json_last_error_msg() . ' snippet=' . cws_truncate_for_log((string) $response, 512));
            }

            return $data;
        } catch (Exception $e) {
            error_log('V-Count: ' . $e->getMessage());
            return null;
        }
    }

    /** Conteo diario V-Count (mismo contrato POST, otro endpoint). */
    function consumir_vcount_data_diario($start_date, $finish_date, $store = 'all', $us = US_VCOUNT, $ps = PS_VCOUNT) {
        return consumir_vcount_data($start_date, $finish_date, $store, $us, $ps, URL_VCOUNT_DAILY);
    }

?>