# Referencia de clases y métodos

Documenta, clase por clase, **qué hace cada función, método y procedimiento** del código (parámetros, retorno, reglas, efectos en la base de datos y casos de error). Complementa a los documentos generales de [`docs/`](../README.md): aquellos explican el sistema; este explica el código.

| Documento | Cubre |
|---|---|
| [01-motor-del-plan.md](01-motor-del-plan.md) | `LoanScheduleBuilder`, `LoanSchedule`, `ScheduleRow`, `LoanConditions` y los enums (`DayCount`, `ModalidadCuota`, `ConvencionTasa`, `EstadoCuota`, `TipoRecibo`) |
| [02-servicios-de-operacion.md](02-servicios-de-operacion.md) | `LoanScheduleService`, `ReceiptRegistrar`, `ReceiptDetailWriter`, `RevisionRegistrar`, `LegacyPlanSync`, `DashboardMetrics`, `ActiveDirectoryAuthenticator` y los comandos `lending:import-paid-installments` y `lending:rebuild-planpagos` |
| [03-controladores-y-rutas.md](03-controladores-y-rutas.md) | Tabla de rutas, controladores (incluida la base `CatalogController`), middleware, `LoginRequest` y `AppServiceProvider` |
| [04-modelos-y-soporte.md](04-modelos-y-soporte.md) | Modelos y relaciones, `DateFormat`, `CalculationOptions`, fábricas, seeder y migraciones |
| [05-reportes-y-excel.md](05-reportes-y-excel.md) | `Report`, `ReportCatalog`, `DebtPositionReport`, `XlsxWriter`, vistas de reportes y cómo agregar uno nuevo |

## Cómo leerla

- Los métodos están agrupados por clase y siguen el orden del archivo.
- "Efectos" indica qué tablas modifica el método; "Errores" qué validaciones o excepciones puede producir.
- Si cambia el código, actualice el documento de su clase en el mismo commit.
