/*
  Instalación de dbo.store_employees en la misma BD que SAP/ODC (ej. SCOdcsap).
  Esquema alineado con cws/api/store_employees y migraciones store_employees_add_encargado / store_employees_audit_columns.

  Ejecutar en SSMS conectado a la BD destino (ej. SCOdcsap en 192.168.2.211).
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.store_employees', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.store_employees (
        id BIGINT IDENTITY(1,1) NOT NULL,
        cedula_identidad VARCHAR(32) NOT NULL,
        correo NVARCHAR(320) NULL,
        nombre NVARCHAR(200) NOT NULL,
        cargo NVARCHAR(100) NOT NULL,
        codigo_tienda NVARCHAR(64) NOT NULL,
        estado BIT NOT NULL CONSTRAINT DF_store_employees_estado DEFAULT (1),
        encargado BIT NOT NULL CONSTRAINT DF_store_employees_encargado DEFAULT (0),
        fecha_ingreso DATETIME2 NOT NULL CONSTRAINT DF_store_employees_fecha_ingreso DEFAULT (SYSDATETIME()),
        fecha_ultima_actualizacion DATETIME2 NOT NULL CONSTRAINT DF_store_employees_fecha_ultima_actualizacion DEFAULT (SYSDATETIME()),
        usuario_ultima_actualizacion NVARCHAR(64) NOT NULL CONSTRAINT DF_store_employees_usuario_ultima_actualizacion DEFAULT (N'API_STORE_EMPLOYEES'),
        CONSTRAINT PK_store_employees PRIMARY KEY CLUSTERED (id),
        CONSTRAINT UQ_store_employees_cedula UNIQUE (cedula_identidad)
    );
END
GO

/* Índice filtrado: a lo sumo un encargado activo por tienda */
IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.store_employees')
      AND name = 'UX_store_employees_tienda_encargado_activo'
)
BEGIN
    CREATE UNIQUE INDEX UX_store_employees_tienda_encargado_activo
        ON dbo.store_employees (codigo_tienda)
        WHERE estado = 1 AND encargado = 1;
END
GO
