<?php

/**
 * Declaraciones solo para el IDE (Intelephense). No incluir en runtime: la extensión sqlsrv ya define estos símbolos.
 *
 * @see https://learn.microsoft.com/en-us/sql/connect/php/sqlsrv-driver-api-reference
 */

const SQLSRV_FETCH_NUMERIC = 1;
const SQLSRV_FETCH_ASSOC = 2;
const SQLSRV_FETCH_BOTH = 3;

function sqlsrv_connect($serverName, $connectionInfo, $options = null) {}

function sqlsrv_errors($errorsAndWarnings = null) {}

function sqlsrv_query($conn, $sql, $params = null, $options = null) {}

function sqlsrv_fetch_array($stmt, $fetchType = null, $row = null, $offset = null) {}

function sqlsrv_free_stmt($stmt) {}

function sqlsrv_close($conn) {}

function sqlsrv_begin_transaction($conn) {}

function sqlsrv_commit($conn) {}

function sqlsrv_rollback($conn) {}
