<?php

/**
 * Declaraciones para Intelephense: extensión Microsoft sqlsrv (cargada en runtime por PHP).
 * No incluir este archivo en la aplicación; solo indexación del IDE.
 *
 * @see https://www.php.net/manual/en/ref.sqlsrv.php
 */

declare(strict_types=1);

const SQLSRV_FETCH_ASSOC = 2;
const SQLSRV_FETCH_NUMERIC = 1;

/** @param array<string, mixed> $connectionInfo */
function sqlsrv_connect(string $serverName, array $connectionInfo): mixed
{
    return false;
}

/** @return array<int, array<string, mixed>>|false|null */
function sqlsrv_errors(?int $errorsAndOrWarnings = null): array|false|null
{
    return false;
}

/**
 * @param resource $conn
 * @param array<int, mixed> $params
 * @param array<string, mixed> $options
 */
function sqlsrv_query($conn, string $sql, array $params = [], array $options = []): mixed
{
    return false;
}

/**
 * @param resource $stmt
 */
function sqlsrv_fetch_array($stmt, ?int $fetchType = null, ?int $row = null, ?int $offset = null): array|false|null
{
    return false;
}

/**
 * @param resource $stmt
 */
function sqlsrv_next_result($stmt): ?bool
{
    return false;
}

/** @param resource $stmt */
function sqlsrv_free_stmt($stmt): bool
{
    return true;
}

/** @param resource $conn */
function sqlsrv_close($conn): bool
{
    return true;
}

/** @param resource $conn */
function sqlsrv_begin_transaction($conn): bool
{
    return true;
}

/** @param resource $conn */
function sqlsrv_commit($conn): bool
{
    return true;
}

/** @param resource $conn */
function sqlsrv_rollback($conn): bool
{
    return true;
}
