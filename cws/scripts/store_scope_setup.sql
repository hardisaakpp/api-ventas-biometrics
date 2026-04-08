/* Catálogo escalable de tiendas por integración/fase.
   Ejecutar en la BD configurada por STORE_EMPLOYEES_SQL_DATABASE (ej. store_control). */

IF OBJECT_ID('dbo.integration_store_scope', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.integration_store_scope (
        id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        codigo_tienda VARCHAR(64) NOT NULL,
        nombre_tienda NVARCHAR(255) NULL,
        integration VARCHAR(64) NOT NULL CONSTRAINT DF_integration_store_scope_integration DEFAULT ('ALL'),
        es_fase1 BIT NOT NULL CONSTRAINT DF_integration_store_scope_es_fase1 DEFAULT (0),
        activo BIT NOT NULL CONSTRAINT DF_integration_store_scope_activo DEFAULT (1),
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_integration_store_scope_updated_at DEFAULT (SYSUTCDATETIME()),
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_integration_store_scope_created_at DEFAULT (SYSUTCDATETIME())
    );

    CREATE UNIQUE INDEX UX_integration_store_scope
        ON dbo.integration_store_scope(codigo_tienda, integration);
END
GO

/* Seed mínimo Fase 1 (ajustar si cambia alcance).
   Inserta/actualiza para ALL y KOACH360_* para facilitar filtros por API. */
MERGE dbo.integration_store_scope AS tgt
USING (
    SELECT v.codigo_tienda, v.integration, CAST(1 AS bit) AS es_fase1, CAST(1 AS bit) AS activo
    FROM (VALUES
        ('RL-CCI', 'ALL'), ('RL-CON', 'ALL'), ('RL-PSC', 'ALL'), ('RL-QSN', 'ALL'), ('RL-QSS2', 'ALL'),
        ('RL-REC2', 'ALL'), ('RL-SCA', 'ALL'), ('RL-SNL', 'ALL'), ('RL-MSO3', 'ALL'), ('RL-BMB', 'ALL'),
        ('RL-CCI', 'KOACH360_VENTAS'), ('RL-CON', 'KOACH360_VENTAS'), ('RL-PSC', 'KOACH360_VENTAS'),
        ('RL-QSN', 'KOACH360_VENTAS'), ('RL-QSS2', 'KOACH360_VENTAS'), ('RL-REC2', 'KOACH360_VENTAS'),
        ('RL-SCA', 'KOACH360_VENTAS'), ('RL-SNL', 'KOACH360_VENTAS'), ('RL-MSO3', 'KOACH360_VENTAS'),
        ('RL-BMB', 'KOACH360_VENTAS'),
        ('RL-CCI', 'KOACH360_ASISTENCIAS'), ('RL-CON', 'KOACH360_ASISTENCIAS'), ('RL-PSC', 'KOACH360_ASISTENCIAS'),
        ('RL-QSN', 'KOACH360_ASISTENCIAS'), ('RL-QSS2', 'KOACH360_ASISTENCIAS'), ('RL-REC2', 'KOACH360_ASISTENCIAS'),
        ('RL-SCA', 'KOACH360_ASISTENCIAS'), ('RL-SNL', 'KOACH360_ASISTENCIAS'), ('RL-MSO3', 'KOACH360_ASISTENCIAS'),
        ('RL-BMB', 'KOACH360_ASISTENCIAS'),
        ('RL-CCI', 'KOACH360_ASISTENCIAS_PROGRAMADAS'), ('RL-CON', 'KOACH360_ASISTENCIAS_PROGRAMADAS'),
        ('RL-PSC', 'KOACH360_ASISTENCIAS_PROGRAMADAS'), ('RL-QSN', 'KOACH360_ASISTENCIAS_PROGRAMADAS'),
        ('RL-QSS2', 'KOACH360_ASISTENCIAS_PROGRAMADAS'), ('RL-REC2', 'KOACH360_ASISTENCIAS_PROGRAMADAS'),
        ('RL-SCA', 'KOACH360_ASISTENCIAS_PROGRAMADAS'), ('RL-SNL', 'KOACH360_ASISTENCIAS_PROGRAMADAS'),
        ('RL-MSO3', 'KOACH360_ASISTENCIAS_PROGRAMADAS'), ('RL-BMB', 'KOACH360_ASISTENCIAS_PROGRAMADAS')
    ) AS v(codigo_tienda, integration)
) AS src
ON UPPER(tgt.codigo_tienda) = UPPER(src.codigo_tienda)
   AND UPPER(tgt.integration) = UPPER(src.integration)
WHEN MATCHED THEN
    UPDATE SET es_fase1 = src.es_fase1, activo = src.activo, updated_at = SYSUTCDATETIME()
WHEN NOT MATCHED THEN
    INSERT (codigo_tienda, integration, es_fase1, activo)
    VALUES (src.codigo_tienda, src.integration, src.es_fase1, src.activo);
GO
