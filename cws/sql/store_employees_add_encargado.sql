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
