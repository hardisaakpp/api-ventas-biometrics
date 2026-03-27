/*
  Añade columna Encargado (SI/NO en UI): bit, 0 = no, 1 = sí.
  Ejecutar contra la base donde vive dbo.store_employees (ej. store_control).
*/
IF COL_LENGTH('dbo.store_employees', 'encargado') IS NULL
BEGIN
    ALTER TABLE dbo.store_employees ADD encargado BIT NOT NULL
        CONSTRAINT DF_store_employees_encargado DEFAULT (0);
END
GO

/*
  Regla de negocio: máximo un encargado ACTIVO por tienda.
  Si ya existen duplicados (estado=1 y encargado=1 por misma tienda), este índice fallará al crear.
*/
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
