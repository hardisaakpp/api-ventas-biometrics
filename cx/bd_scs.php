<?php
    function cx(){
        include_once('dat_scs.php');
        $cx= sqlsrv_connect(HOST,array('Database'=>BD,'UID'=>USUARIO,'PWD'=>PSW,'MultipleActiveResultSets'=>true,'TrustServerCertificate'=>'TRUE','CharacterSet'=>'UTF-8')); 
        if($cx==false){ die(FormatErrors(sqlsrv_errors())); }
        return $cx;
    }

    function FormatErrors($errors) {
        $output = "";
        foreach ($errors as $error) {
            $output .= "SQLSTATE: " . $error['SQLSTATE'] . "<br />";
            $output .= "Code: " . $error['code'] . "<br />";
            $output .= "Message: " . $error['message'] . "<br /><br />";
        }
        return $output;
    }

    //lectura
    function resp_oneval($query){ //retorna un valor numérico 0 vacio 1 encontró coincidencia
        try{
             $cx=cx();
             $get=sqlsrv_query($cx, $query);
             if($get==FALSE){ die(FormatErrors(sqlsrv_errors())); }
             $resp=sqlsrv_fetch_array($get, SQLSRV_FETCH_NUMERIC);
             sqlsrv_free_stmt($get);
             sqlsrv_close($cx);
             return $resp[0];
         }catch(Exception $e) { echo("Error!"); }
    }

    function resp_onedim($query){ //retorna un array unidimensional
        try{
             $cx=cx();
             $get=sqlsrv_query($cx, $query);
             if($get==FALSE){ die(FormatErrors(sqlsrv_errors())); }
             while($row=sqlsrv_fetch_array($get, SQLSRV_FETCH_ASSOC)){ foreach($row as $key => $value){ if($value instanceof DateTime){ $row[$key] = $value->format('Y-m-d H:i:s'); } } $resp=[$row]; }
             sqlsrv_free_stmt($get);
	     sqlsrv_close($cx);
	     $resp=$resp[0];
             return $resp;
         }catch(Exception $e) { echo("Error!"); }
    }

    function resp_simdim($query){ //retorna un array bidimensional
        try{
             $cx=cx();
             $get=sqlsrv_query($cx, $query);
             if($get==FALSE){ die(FormatErrors(sqlsrv_errors())); }
             $resp = [];
             while($row=sqlsrv_fetch_array($get, SQLSRV_FETCH_ASSOC)){ foreach($row as $key => $value){ if($value instanceof DateTime){ $row[$key] = $value->format('Y-m-d H:i:s'); } } $resp[]=$row; }
             sqlsrv_free_stmt($get);
             sqlsrv_close($cx);
             return $resp;
         }catch(Exception $e) { echo("Error!"); }
    }

    function resp_muldim($query){ //retorna un array multidimensional
        try{
             $cx=cx();
             $get=sqlsrv_query($cx, $query);
             if($get==FALSE){ die(FormatErrors(sqlsrv_errors())); }
             while($row=sqlsrv_fetch_array($get, SQLSRV_FETCH_ASSOC)){ foreach($row as $key => $value){ if($value instanceof DateTime){ $row[$key] = $value->format('Y-m-d H:i:s'); } } $resp[]=array($row); }
             sqlsrv_free_stmt($get);
             sqlsrv_close($cx);
             return $resp;
         }catch(Exception $e) { echo("Error!"); }
    }

    //escritura 
    function insert_data($tsql){ //inserción simple
        //session_start();
        //if(!isset($_SESSION['id_tienda']) || !isset($_SESSION['iden'])){ session_unset(); session_destroy(); header("Location: index.php"); exit(); }
        try{
            $cx=cx();
            $i=sqlsrv_query($cx, $tsql);
            if($i==FALSE){ die(FormatErrors(sqlsrv_errors())); }                
            sqlsrv_free_stmt($i);
            sqlsrv_close($cx);
        }catch(Exception $e){ echo("Error!"); }
    }

    function transac_data($tsql){ //inserción por transaccion 
        //session_start();
        //if(!isset($_SESSION['id_tienda']) || !isset($_SESSION['iden'])){ session_unset(); session_destroy(); header("Location: index.php"); exit(); }
        try{
            $cx = cx();
            if(sqlsrv_begin_transaction($cx)==FALSE){ die(FormatErrors(sqlsrv_errors())); }
            $stmt = sqlsrv_query($cx, $tsql);
            if($stmt){ sqlsrv_commit($cx); return 1; }
            else{ sqlsrv_rollback($cx); return 0; }
            sqlsrv_free_stmt( $stmt);
        }catch(Exception $e){ echo("Error!"); }
    }
?>
