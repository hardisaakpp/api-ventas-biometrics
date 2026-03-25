<?php
/**
 * Fragmentos SQL reutilizables para syn_put_ventas / syn_put_ventas_retro7.
 * Requiere dat_cws cargado (p. ej. vía f_put_ventas.php).
 *
 * .env: VENTAS_ASESOR_FROM_NOMINA=1 y/o VENTAS_ASESOR_CORREO_COLUMN — solo entonces se une a
 * BDDNOMINAMABEL19 (debe estar online). Si no, el SQL no referencia esa base (asesorCorreo '-').
 */
declare(strict_types=1);

if (!function_exists('ventas_syn_asesor_correo_fragments')) {
    /**
     * @return array{applySql: string, asesorCorreoExpr: string}
     */
    function ventas_syn_asesor_correo_fragments(): array {
        if (!function_exists('cws_env')) {
            require_once __DIR__ . '/../cws/dat_cws.php';
        }
        $col = trim((string) cws_env('VENTAS_ASESOR_CORREO_COLUMN', ''));
        if (strcasecmp($col, 'none') === 0) {
            return [
                'applySql'         => '',
                'asesorCorreoExpr' => "'-'",
            ];
        }
        if ($col !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col)) {
            return [
                'applySql'         => '',
                'asesorCorreoExpr' => "'-'",
            ];
        }

        $fromNomina = in_array(
            strtolower(trim((string) cws_env('VENTAS_ASESOR_FROM_NOMINA', ''))),
            ['1', 'true', 'yes', 'on'],
            true
        );

        // Cualquier JOIN a BDDNOMINAMABEL19 exige esa base ONLINE (SQL Server falla al parsear si está offline).
        // Por defecto NO se toca nómina: mismo comportamiento que antes (asesorCorreo = '-').
        // Activar correo desde Tbl_DatosPersonales solo si:
        //   - VENTAS_ASESOR_CORREO_COLUMN=nombreColumna  (una columna), o
        //   - VENTAS_ASESOR_FROM_NOMINA=1 y columna vacía → COALESCE(strDPe_Email, EmailEmpresa).
        $useDatosPersonales = ($col !== '') || ($fromNomina && $col === '');
        if (!$useDatosPersonales) {
            return [
                'applySql'         => '',
                'asesorCorreoExpr' => "'-'",
            ];
        }

        $mailSelect = $col === ''
            ? "COALESCE(
                NULLIF(LTRIM(RTRIM(CAST(P.strDPe_Email AS VARCHAR(320)))), ''),
                NULLIF(LTRIM(RTRIM(CAST(P.EmailEmpresa AS VARCHAR(320)))), '')
            )"
            : "P.{$col}";

        return [
            'applySql' => "
        OUTER APPLY (
            SELECT TOP (1) {$mailSelect} AS mail_col
            FROM BDDNOMINAMABEL19.dbo.Tbl_DatosPersonales P
            WHERE P.strDPe_Cedula COLLATE SQL_Latin1_General_CP1_CI_AS = ISNULL(E.Identificacion, LTRIM(RTRIM(V.CodVendedor))) COLLATE SQL_Latin1_General_CP1_CI_AS
        ) Dmail",
            'asesorCorreoExpr' => "CASE V.TipoDocumento
                                    WHEN 'FC' THEN ISNULL(NULLIF(LTRIM(RTRIM(CAST(Dmail.mail_col AS VARCHAR(320)))), ''), '-')
                                    ELSE '-'
                                  END",
        ];
    }
}
