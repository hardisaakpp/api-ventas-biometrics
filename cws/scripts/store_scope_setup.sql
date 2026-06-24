/* Catálogo de tiendas por integración.
   Ejecutar en sc_kliente (desarrollo) o SCOdcsap (producción hasta migración). */

-- Migración: eliminar es_fase1 si existe en una BD ya instalada
IF EXISTS (
    SELECT 1 FROM sys.default_constraints
    WHERE name = 'DF_integration_store_scope_es_fase1'
)
    ALTER TABLE dbo.integration_store_scope
        DROP CONSTRAINT DF_integration_store_scope_es_fase1;

IF EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.integration_store_scope')
      AND name = 'es_fase1'
)
    ALTER TABLE dbo.integration_store_scope DROP COLUMN es_fase1;
GO

IF OBJECT_ID('dbo.integration_store_scope', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.integration_store_scope (
        id            INT IDENTITY(1,1)  NOT NULL PRIMARY KEY,
        codigo_tienda VARCHAR(64)        NOT NULL,
        nombre_tienda NVARCHAR(255)      NULL,
        integration   VARCHAR(64)        NOT NULL CONSTRAINT DF_integration_store_scope_integration DEFAULT ('ALL'),
        activo        BIT                NOT NULL CONSTRAINT DF_integration_store_scope_activo      DEFAULT (1),
        updated_at    DATETIME2(0)       NOT NULL CONSTRAINT DF_integration_store_scope_updated_at  DEFAULT (SYSUTCDATETIME()),
        created_at    DATETIME2(0)       NOT NULL CONSTRAINT DF_integration_store_scope_created_at  DEFAULT (SYSUTCDATETIME())
    );

    CREATE UNIQUE INDEX UX_integration_store_scope
        ON dbo.integration_store_scope(codigo_tienda, integration);
END
GO

/* Seed inicial — 10 tiendas activas para todas las integraciones.
   Para agregar tiendas: INSERT con activo=1.
   Para pausar una tienda: UPDATE SET activo=0. */
MERGE dbo.integration_store_scope AS tgt
USING (
    SELECT v.codigo_tienda, v.nombre_tienda, v.integration, CAST(1 AS bit) AS activo
    FROM (VALUES
        ('RL-CCI',  'ROLAND-QUITO-CCI',              'ALL'),
        ('RL-CON',  'ROLAND-QUITO-CONDADO SHOPPING',  'ALL'),
        ('RL-PSC',  'ROLAND-QUITO-PORTAL SHOPPING',   'ALL'),
        ('RL-QSN',  'ROLAND-QUITO-QUICENTRO NORTE',   'ALL'),
        ('RL-QSS2', 'ROLAND-QUITO-QUICENTRO SUR',     'ALL'),
        ('RL-REC2', 'ROLAND-QUITO-RECREO',             'ALL'),
        ('RL-SCA',  'ROLAND-QUITO-SCALA SHOPPING',    'ALL'),
        ('RL-SNL',  'ROLAND-QUITO-SAN LUIS',          'ALL'),
        ('RL-MSO3', 'ROLAND-GUAYAQUIL-MALL DEL SOL3', 'ALL'),
        ('RL-BMB',  'ROLAND-STO DOMINGO-BOMBOLI',     'ALL')
    ) AS v(codigo_tienda, nombre_tienda, integration)
) AS src
ON UPPER(tgt.codigo_tienda) = UPPER(src.codigo_tienda)
   AND UPPER(tgt.integration) = UPPER(src.integration)
WHEN MATCHED THEN
    UPDATE SET
        nombre_tienda = src.nombre_tienda,
        activo        = src.activo,
        updated_at    = SYSUTCDATETIME()
WHEN NOT MATCHED THEN
    INSERT (codigo_tienda, nombre_tienda, integration, activo)
    VALUES (src.codigo_tienda, src.nombre_tienda, src.integration, src.activo);
GO
