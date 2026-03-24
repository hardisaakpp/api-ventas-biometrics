<?php
    require('dat_cws.php');
    require 'vendor/autoload.php';

    use GuzzleHttp\Client;

    function obtenerAccessToken( $grantType = BYL_GRANT_TYPE, $url = BYL_URL, $clientId = BYL_CLIENT_ID, $clientSecret = BYL_CLIENT_SECRET, $resource = BYL_RESOURCE) {
        $client = new Client();

        try {
            $response = $client->request('POST', $url, [
                'multipart' => [
                    ['name' => 'grant_type', 'contents' => $grantType],
                    ['name' => 'client_id', 'contents' => $clientId],
                    ['name' => 'client_secret', 'contents' => $clientSecret],
                    ['name' => 'resource', 'contents' => $resource],
                ],
            ]);

            $body = $response->getBody();
            $data = json_decode($body, true);

            if (isset($data['access_token'])) {
                return $data['access_token'];
            } else {
                throw new Exception("No se obtuvo token. Respuesta: " . print_r($data, true));
            }
        } catch (Exception $e) {
            throw new Exception("Error al obtener token: " . $e->getMessage());
        }
    }

    function escribirLogError(string $mensaje, string $archivoLog = 'syn_put_ventas_byl.log'): void {
        $fechaHora = date('Y-m-d H:i:s');
        $rutaDir = '/storage/tareas/';
        if (!is_dir($rutaDir)) {
            mkdir($rutaDir, 0755, true);
        }
        $rutaArchivo = $rutaDir . $archivoLog;
        $texto = "[$fechaHora] $mensaje" . PHP_EOL;
        file_put_contents($rutaArchivo, $texto, FILE_APPEND | LOCK_EX);
    }

    function formatearVentas(array $ventas, string $location_code, string $sale_country = 'EC', $vat = 15 ): array {
        $resultado = [];

        foreach ($ventas as $venta) {
            // Crear el nuevo orden de campos
            $nuevaVenta = [
                'location_code' => $location_code,
                'sale_country'  => $sale_country,
            ];

            // Añadir el resto del array (sin 'vat')
            $nuevaVenta += $venta;

            // Agregar 'vat' al final
            $nuevaVenta['vat'] = $vat;

            // Añadir a resultado
            $resultado[] = $nuevaVenta;
        }

        return $resultado;
    }

    function generarJsonDesdeArray(array $registros, $location_code): string {
        // Formatea los registros con la lógica que ya tengas
        $registros_formateados = formatearVentas($registros, $location_code);

        // Envolver en el nodo raíz "sales" como lo requiere la API
        $estructuraFinal = [
            'sales' => $registros_formateados
        ];

        // Codificar a JSON con opciones de formato
        $json = json_encode($estructuraFinal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);        
        
        if ($json === false) {
            $msgError = json_last_error_msg();
            escribirLogError("Error al convertir a JSON: $msgError");
            throw new Exception('Error al convertir a JSON: ' . $msgError);
        }

        // Ruta de guardado del archivo
        $rutaDir = __DIR__ . '/../tareas/json/';
        if (!is_dir($rutaDir)) {
            if (!mkdir($rutaDir, 0755, true)) {
                escribirLogError("No se pudo crear el directorio JSON: $rutaDir");
                throw new Exception("No se pudo crear el directorio: $rutaDir");
            }
        }

        $nombreArchivo = 'ventas_byl_' . date('Ymd_His') . '.json';
        $ruta = $rutaDir . $nombreArchivo;

        if (file_put_contents($ruta, $json) === false) {
            escribirLogError("Error al guardar el archivo JSON en: $ruta");
            throw new Exception('Error al guardar el archivo JSON en: ' . $ruta);
        }

        escribirLogError("JSON guardado correctamente en: $nombreArchivo");
        return $json;
    }

    function consumirApiRemoto(array $array_ventas, $location_code, string $subscriptionKey = BYL_API_SUBSCRIPTION_KEY, string $url = BYL_API_URL ): array {
        try {
            $bearerToken = obtenerAccessToken();
        } catch (Exception $e) {
            escribirLogError("Error al obtener token: " . $e->getMessage());
            throw new Exception("Error al obtener token: " . $e->getMessage());
        }

        $jsonPayload = generarJsonDesdeArray($array_ventas, $location_code);

        if (empty($jsonPayload)) {
            escribirLogError("Payload JSON está vacío");
            throw new Exception("Payload JSON está vacío");
        }

        $headers = [
            "Authorization: Bearer $bearerToken",
            "Ocp-Apim-Subscription-Key: $subscriptionKey",
            "Content-Type: application/json"
        ];

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        echo $response = curl_exec($curl);

        if (curl_errno($curl)) {
            $error = curl_error($curl);
            unset($curl);
            escribirLogError("Error cURL consumirApiRemoto: $error");
            throw new Exception("Error cURL: $error");
        }

        echo $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        unset($curl);

        escribirLogError("API consumida. HTTP code: $httpCode. Response: $response");

        return [
            'http_code' => $httpCode,
            'response_raw' => $response,
            'response_json' => json_decode($response, true)
        ];
    }
?>