<?php
    /*
            MABEL
        cod_negocio nombre_negocio
                9   DESCUENTON   
                2   LILI PINK
                1   ROLAND

            COSMEC
        cod_negocio nombre_negocio  
                10  CLINIQUE
                8   MAC
                11  PDPAOLA
                7   PSYCHO BUNNY
                6   SALVATORE FERRAGAMO
    */
    session_start();
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    require ('f_consumo.php');
    require ('dat_cws.php');
    date_default_timezone_set('America/Bogota');
    //$userName = $_SESSION['username'];

    //obtener token
    //if(!isset($_SESSION['token'])){ $_SESSION['token']=consumir_token(US_COSMEC,PS_COSMEC); }
    //echo $_SESSION['token'];

    //consumo de datos tiendas cod_tienda
    $key='Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJtYWJlbCIsImlhdCI6MTc1MzY1OTM0NiwiZXhwIjoxNzU0Mzc5MzQ2LCJhdWQiOiJhcGktcm9sZSIsImlzcyI6IlByb3h5IEV4dGVybmFsIExvZ2luIiwiYXV0aG9yaXRpZXMiOlsiZXh0ZXJuYWwtcHJveHkiXX0._WVS9qhTDONLUlkmshQ_LDWcD2I-JOMvZp-VOTt_EWU';
    $cod_negocio=9;
    $res2=consumo_tiendas($key,$cod_negocio);
    echo '<pre>';
    print_r($res2);
    echo '</pre>';

    //consumo de datos arqueo_caja fechas
    /*$cod_negocio=1;
    $cod_tienda=179;
    $num_terminal=1;
    $date='2025-07-15';
    $resultado=consumo_arqueo_caja_date($key,$cod_negocio,$cod_tienda,$num_terminal,$date);
    //$resultado=consumo_arqueo_group_metodo($key, $cod_negocio, $cod_tienda, $num_terminal, $date);
    echo '<pre>';
    print_r($resultado);
    echo '</pre>';*/
?>