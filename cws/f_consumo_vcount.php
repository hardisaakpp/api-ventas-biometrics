<?php
    require_once __DIR__ . '/dat_cws.php';

    function consumir_vcount_data($start_date, $finish_date, $store = 'all', $us = US_VCOUNT, $ps = PS_VCOUNT, $url = URL_VCOUNT) {
        $curl = curl_init();

        $postfields = http_build_query([
            'username'    => $us,
            'password'    => $ps,
            'format'      => 'json',
            'start_date'  => $start_date,
            'finish_date' => $finish_date,
            'store'       => $store
        ]);

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 120,
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

        try {
            $response = curl_exec($curl);
            if ($response === false) {
                throw new Exception('cURL: ' . curl_error($curl));
            }

            $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
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
        } finally {
            unset($curl);
        }
    }

    /** Conteo diario V-Count (mismo contrato POST, otro endpoint). */
    function consumir_vcount_data_diario($start_date, $finish_date, $store = 'all', $us = US_VCOUNT, $ps = PS_VCOUNT) {
        return consumir_vcount_data($start_date, $finish_date, $store, $us, $ps, URL_VCOUNT_DAILY);
    }

?>