<?php
	require ('dat_cws.php');
	function formatear_fecha($date){
		if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){ return $date; }
		$timestamp = strtotime($date);
		if($timestamp===false){ throw new InvalidArgumentException("Fecha inválida: '$date'"); }
		return date('Y-m-d', $timestamp);
	}

	function consumir_token($us,$ps,$url=APILOGIN) {
		$curl = curl_init();		
		$payload = json_encode([
			"username" => $us,
			"password" => $ps
		]);
		curl_setopt_array($curl, array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => $payload,
			CURLOPT_HTTPHEADER => array(
				'User-Agent: Apidog/1.0.0 (https://apidog.com)',
				'Content-Type: application/json',
				'Accept: */*',
				'Connection: keep-alive'
			),
		));
		try{
			$response = curl_exec($curl);
			if($response===false){ throw new Exception('Error de cURL: ' . curl_error($curl)); }
			$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			if($httpCode!==200){ throw new Exception("Error HTTP $httpCode. Respuesta: $response"); }
			$a_res=json_decode($response, true);
			if(json_last_error()!==JSON_ERROR_NONE){ throw new Exception('Error al decodificar JSON: ' . json_last_error_msg()); }
			if(!isset($a_res['retorno']['token'])){ throw new Exception('Token no encontrado en la respuesta.'); }
			return $a_res['retorno']['token'];
		}catch(Exception $e){
			error_log("Error al obtener el token: " . $e->getMessage());
			return null;
		} finally { unset($curl); }
	}

	function consumo_tiendas($token,$cod_negocio,$link=STORES){
		$url=$link.'/'.$cod_negocio;
		$curl=curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'GET',
			CURLOPT_HTTPHEADER => array(
				"Authorization: Bearer $token",
				'User-Agent: Apidog/1.0.0 (https://apidog.com)',
				'Content-Type: application/json',
				'Accept: */*',
				'Connection: keep-alive'
			),
		));
		try{
			$response=curl_exec($curl);
			if($response===false){ throw new Exception('cURL Error: ' . curl_error($curl)); }
			$httpCode=curl_getinfo($curl, CURLINFO_HTTP_CODE);
			if($httpCode!==200){ throw new Exception("HTTP Error: $httpCode\nResponse: $response"); }
			$a_res=json_decode($response,true);
			$data=$a_res['retorno'];
			return $data;
		}
		catch(Exception $e){ echo "Error al realizar la solicitud: " . $e->getMessage(); }
		finally{ unset($curl); }
	}

	function consumo_arqueo_caja_date($token,$cod_negocio,$cod_tienda,$num_terminal,$date,$link=ARQUEO){
		$date=formatear_fecha($date);
		echo $url=$link.'/'.$cod_negocio.'/'.$cod_tienda.'/'.$num_terminal.'?date='.$date;
		$curl = curl_init();	
		curl_setopt_array($curl, array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'GET',
			CURLOPT_HTTPHEADER => array(
				"Authorization: Bearer $token",
				'User-Agent: Apidog/1.0.0 (https://apidog.com)',
				'Content-Type: application/json',
				'Accept: */*',
				'Connection: keep-alive'
			),
		));	
		try{
			$response=curl_exec($curl);	
			if($response===false){ throw new Exception('Error cURL: ' . curl_error($curl)); }	
			$httpCode=curl_getinfo($curl,CURLINFO_HTTP_CODE);
			if($httpCode!==200){ throw new Exception("Error HTTP $httpCode: $response"); }
			$data=json_decode($response, true);
			if(json_last_error()!==JSON_ERROR_NONE){ throw new Exception('Error al decodificar JSON: ' . json_last_error_msg()); }
			return $data['retorno'];	
		}catch(Exception $e){
			error_log("Error al consultar arqueo de caja: " . $e->getMessage());
			return null;
		}finally{ unset($curl); }
	}

	function get_token($tiendaCica, $db, $userName) {  //codtiendaCICA - servicio=Hitell
		$token = null;	

			$sentencia2 = $db->query("select * from almacen where id=".$tiendaCica."  "  );
			$TEMP1 = $sentencia2->fetchObject();
				$h_cod_neg = $TEMP1->hit_cod_neg;
				$h_cod_local = $TEMP1->hit_cod_local;
				$emp=$TEMP1->fk_emp;


			if ($emp=='MT') {
				// 1. Consultar si hay un token válido
				$sentencia3 = $db->query("SELECT token, fecha_expiracion FROM api_tokens WHERE servicio = 'HitellMT'  AND fecha_expiracion > GETDATE()"  );
				$TEMP2 = $sentencia3->fetchObject();
				if ($TEMP2) {
					$token = $TEMP2->token; //Si existe un token válido, usarlo
				} else {
					$token=consumir_token(US_MABEL,PS_MABEL); // 2. Si no hay un token válido, solicitar uno nuevo	
					$sentencia1 = $db->prepare("exec sp_guardar_token_api  'HitellMT', ?, 710000000, ?;" );
					$resultado1 = $sentencia1->execute([ $token, $userName]);
				}
			} else {
				// 1. Consultar si hay un token válido
				$sentencia3 = $db->query("SELECT token, fecha_expiracion FROM api_tokens WHERE servicio = 'HitellCE'  AND fecha_expiracion > GETDATE()"  );
				$TEMP2 = $sentencia3->fetchObject();
				if ($TEMP2) {
					$token = $TEMP2->token; //Si existe un token válido, usarlo
				} else {
					$token=consumir_token(US_COSMEC,PS_COSMEC); // 2. Si no hay un token válido, solicitar uno nuevo	
					$sentencia1 = $db->prepare("exec sp_guardar_token_api  'HitellCE', ?, 710000000, ?;" );
					$resultado1 = $sentencia1->execute([ $token, $userName]);
				}

			}
		
			return $token;
			
	}

	// Arqueo formas de pago agrupado por payment_method
	function consumo_arqueo_group_metodo($token, $cod_negocio, $cod_tienda, $num_terminal, $date, $link = ARQUEO) {
		$date = formatear_fecha($date);
		$url = $link . '/' . $cod_negocio . '/' . $cod_tienda . '/' . $num_terminal . '?date=' . $date;
		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'GET',
			CURLOPT_HTTPHEADER => array(
				"Authorization: Bearer $token",
				'User-Agent: Apidog/1.0.0 (https://apidog.com)',
				'Content-Type: application/json',
				'Accept: */*',
				'Connection: keep-alive'
			),
		));
		try {
			$response = curl_exec($curl);
			if ($response === false) {
				throw new Exception('Error cURL: ' . curl_error($curl));
			}
			$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			if ($httpCode !== 200) {
				throw new Exception("Error HTTP $httpCode: $response");
			}
			$data = json_decode($response, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				throw new Exception('Error al decodificar JSON: ' . json_last_error_msg());
			}
			$pagosAgrupados = [];
			if (isset($data['retorno'][0]['doc']) && is_array($data['retorno'][0]['doc'])) {
				foreach ($data['retorno'][0]['doc'] as $doc) {
					if (isset($doc['tender']) && is_array($doc['tender'])) {
						foreach ($doc['tender'] as $pago) {
							$metodo = $pago['payment_method'];
							$monto = floatval($pago['amount']);
							if (!isset($pagosAgrupados[$metodo])) {
								$pagosAgrupados[$metodo] = 0;
							}
							$pagosAgrupados[$metodo] += $monto;
						}
					}
				}
				return $pagosAgrupados;
			} else {
				return $pagosAgrupados;
			}			
		} catch (Exception $e) {
			error_log("Error al consultar arqueo de caja: " . $e->getMessage());
			return null;
		} finally {
			unset($curl);
		}
	}
	
	// Arqueo abonos a credito
	function consumo_arqueo_abonos($token, $cod_negocio, $cod_tienda, $num_terminal, $date, $link = ARQUEO) {
		$date = formatear_fecha($date);
		$url = $link . '/' . $cod_negocio . '/' . $cod_tienda . '/' . $num_terminal . '?date=' . $date;
		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'GET',
			CURLOPT_HTTPHEADER => array(
				"Authorization: Bearer $token",
				'User-Agent: Apidog/1.0.0 (https://apidog.com)',
				'Content-Type: application/json',
				'Accept: */*',
				'Connection: keep-alive'
			),
		));
		try {
			$response = curl_exec($curl);
			if ($response === false) {
				throw new Exception('Error cURL: ' . curl_error($curl));
			}
			$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			if ($httpCode !== 200) {
				throw new Exception("Error HTTP $httpCode: $response");
			}
			$data = json_decode($response, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				throw new Exception('Error al decodificar JSON: ' . json_last_error_msg());
			}

			// Inicializar las claves del array
	
			
			$ttvalor = 0;
			$ttcount = 0;

			// Procesar los datos para agrupar por payment_method
			foreach ($data['retorno'] as $item) {
				foreach ($item['abono_syscard'] as $abono_syscard) {
					$amount = $abono_syscard['amount'];
					$ttvalor += $amount;
					$ttcount += 1;
				}
			}

				$payment_summary = ['total_amount' => $ttvalor, 'count' => $ttcount];
			return $payment_summary;
		} catch (Exception $e) {
			error_log("Error al consultar arqueo de caja: " . $e->getMessage());
			return null;
		} finally {
			unset($curl);
		}
	}
?>