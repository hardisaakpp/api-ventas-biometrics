/*
  Auditoría en dbo.store_employees:
  - fecha_ingreso: momento de alta del registro (no cambia en updates normales).
  - fecha_ultima_actualizacion / usuario_ultima_actualizacion: último cambio (antes fecha_actual / usuario_actual).

  Ejecutar contra la base donde vive dbo.store_employees (ej. store_control).
  Orden: rellenar fecha_ingreso usando fecha_actual antes de renombrar esa columna.
*/

IF COL_LENGTH('dbo.store_employees', 'fecha_ingreso') IS NULL
BEGIN
    ALTER TABLE dbo.store_employees ADD fecha_ingreso DATETIME2 NULL;
END
GO

/* Dynamic SQL: el motor compila el UPDATE solo si el IF se cumple (columnas pueden no existir en otras fases). */
IF COL_LENGTH('dbo.store_employees', 'fecha_ingreso') IS NOT NULL
   AND COL_LENGTH('dbo.store_employees', 'fecha_actual') IS NOT NULL
BEGIN
    EXEC (N'UPDATE dbo.store_employees SET fecha_ingreso = ISNULL(fecha_actual, SYSDATETIME()) WHERE fecha_ingreso IS NULL');
END
GO

IF COL_LENGTH('dbo.store_employees', 'fecha_ingreso') IS NOT NULL
   AND COL_LENGTH('dbo.store_employees', 'fecha_actual') IS NULL
   AND COL_LENGTH('dbo.store_employees', 'fecha_ultima_actualizacion') IS NOT NULL
BEGIN
    EXEC (N'UPDATE dbo.store_employees SET fecha_ingreso = ISNULL(fecha_ultima_actualizacion, SYSDATETIME()) WHERE fecha_ingreso IS NULL');
END
GO

IF COL_LENGTH('dbo.store_employees', 'fecha_ingreso') IS NOT NULL
   AND COL_LENGTH('dbo.store_employees', 'fecha_actual') IS NULL
   AND COL_LENGTH('dbo.store_employees', 'fecha_ultima_actualizacion') IS NULL
BEGIN
    EXEC (N'UPDATE dbo.store_employees SET fecha_ingreso = SYSDATETIME() WHERE fecha_ingreso IS NULL');
END
GO

IF COL_LENGTH('dbo.store_employees', 'fecha_ingreso') IS NOT NULL
   AND EXISTS (
        SELECT 1
        FROM sys.columns c
        WHERE c.object_id = OBJECT_ID('dbo.store_employees')
          AND c.name = 'fecha_ingreso'
          AND c.is_nullable = 1
   )
BEGIN
    ALTER TABLE dbo.store_employees ALTER COLUMN fecha_ingreso DATETIME2 NOT NULL;
END
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.default_constraints
    WHERE parent_object_id = OBJECT_ID('dbo.store_employees')
      AND name = 'DF_store_employees_fecha_ingreso'
)
BEGIN
    ALTER TABLE dbo.store_employees ADD CONSTRAINT DF_store_employees_fecha_ingreso DEFAULT (SYSDATETIME()) FOR fecha_ingreso;
END
GO

IF COL_LENGTH('dbo.store_employees', 'fecha_ultima_actualizacion') IS NULL
   AND COL_LENGTH('dbo.store_employees', 'fecha_actual') IS NOT NULL
BEGIN
    EXEC sp_rename 'dbo.store_employees.fecha_actual', 'fecha_ultima_actualizacion', 'COLUMN';
END
GO

IF COL_LENGTH('dbo.store_employees', 'usuario_ultima_actualizacion') IS NULL
   AND COL_LENGTH('dbo.store_employees', 'usuario_actual') IS NOT NULL
BEGIN
    EXEC sp_rename 'dbo.store_employees.usuario_actual', 'usuario_ultima_actualizacion', 'COLUMN';
END
GO

/* Esquema nuevo sin columnas de auditoría previas */
IF COL_LENGTH('dbo.store_employees', 'fecha_ultima_actualizacion') IS NULL
BEGIN
    ALTER TABLE dbo.store_employees ADD fecha_ultima_actualizacion DATETIME2 NOT NULL
        CONSTRAINT DF_store_employees_fecha_ultima_actualizacion DEFAULT (SYSDATETIME());
END
GO

IF COL_LENGTH('dbo.store_employees', 'usuario_ultima_actualizacion') IS NULL
BEGIN
    ALTER TABLE dbo.store_employees ADD usuario_ultima_actualizacion NVARCHAR(64) NOT NULL
        CONSTRAINT DF_store_employees_usuario_ultima_actualizacion DEFAULT (N'API_STORE_EMPLOYEES');
END
GO
