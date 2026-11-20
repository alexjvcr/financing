# 06 · Pruebas y datos

## 1. Resultado de la última ejecución

**235 pruebas · 1,230 aserciones · todas en verde** (2026-11-20). Todas son automatizadas de servidor (PHPUnit 12); no hay pruebas de navegador.

## 2. Cómo ejecutarlas

```bash
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit
```

- `phpunit.xml` usa **SQLite en memoria**, caché y sesión en `array` (no tocan SQL Server). El `php.ini` del servidor no carga `pdo_sqlite`/`sqlite3`, por eso se pasan con `-d`.
- `php artisan test` **no** sirve en este servidor: lanza un proceso hijo que ignora las opciones `-d`.
- Para ver el error real cuando `storage/logs/laravel.log` no es escribible por el usuario actual: anteponer `LOG_CHANNEL=stderr`.
- Si falla con `touch(): Utime failed` al compilar una vista Blade: los archivos de `storage/framework/views` pertenecen a la identidad de IIS y la consola no puede actualizarles la fecha (pasa tras editar una vista). Se evita compilando en una carpeta temporal: `VIEW_COMPILED_PATH=<carpeta temporal> php -d … vendor/bin/phpunit`, o dando permiso de escritura sobre `storage`.
- Una prueba concreta: `… vendor/bin/phpunit --filter nombre_de_la_prueba`.
- Formato de código: `vendor/bin/pint --format agent`.

Las migraciones se ejecutan en cada prueba (`RefreshDatabase`): por eso la migración `create_core_lending_tables` describe las tablas que ya existían en producción, y `tipos_pago` queda sembrada con sus 6 tipos.

## 3. Inventario de pruebas

| Archivo | Pruebas | Qué cubre |
|---|---|---|
| `tests/Unit/Lending/LoanScheduleBuilderTest.php` | 23 | **Motor del plan**: cifras idénticas al plan original, 96 cuotas y cierre en cero, cuota calculada, vencidas/pendientes, recibos que cierran cuotas, recibos anulados ignorados, variación ("≠ plan"), abonos en fecha de cuota y a mitad de período (prorrateo 30/360), adelanto, solo interés con recálculo, revisión de tasa (en cuota y a mitad de período) y de plazo, revisiones anuladas, cotización y recibo de cancelación, abono total, cuota decreciente, bullet, pagos trimestrales y formalización a fin de mes. |
| `tests/Unit/Lending/CalculationConditionsTest.php` | 17 | **Condiciones de cálculo**: interés Actual/360, Actual/365 y 30/360 (incluida la cuota de 73,290.81 del banco con Actual/360), calendario desconocido = 30/360, tasa efectiva, amortización lineal, bullet, período de gracia, cuota global, cuota creciente, pagos trimestrales, revisión a mitad de período con días reales, herencia y sobreescritura de condiciones del sub préstamo, descripción de las condiciones y cotización de cancelación con días reales. |
| `tests/Feature/Lending/PlanPagosReflectionTest.php` | 16 | **Reflejo en `planpagos`**: escritura de todas las cuotas, sin reescrituras innecesarias, alta de sub préstamo, pago (`C` con fecha y montos reales), anulación, solo interés, revisión de tasa, abono que acorta el plan y elimina cuotas sobrantes, fila `C` sin recibo intacta, borrado con y sin cuotas pagadas, comando `lending:rebuild-planpagos` (simulación, respaldo, idempotencia, un solo préstamo), cuotas de gracia con principal 0 y cancelación que exige pagar las cuotas anteriores. |
| `tests/Feature/CalculationConditionsScreensTest.php` | 10 | Formularios de condiciones: campos de la línea (tipos de cuota, bases de días, gracia, global, crecimiento), valores guardados y por defecto, pestaña "Condiciones de cálculo" del sub préstamo, herencia (valores nulos), sobreescritura que cambia la cuota, validaciones, descripción en el detalle y bloqueo con actividad. |
| `tests/Feature/Lending/ReceiptRegistrarTest.php` | 10 | Registro de recibos y actualización del sub préstamo, secuencia de cuotas, fechas, montos requeridos, solo interés, abonos, cancelación total, anulación del último recibo y reactivación al anular una cancelación. |
| `tests/Feature/Lending/RevisionRegistrarTest.php` | 6 | Revisiones de tasa y plazo, validaciones, fechas, no retroactividad sobre cuotas pagadas y anulación de la más reciente. |
| `tests/Feature/Lending/TipoPagoAndDetailTest.php` | 20 | Tipos de pago sembrados, **detalle de pago** por cada tipo de recibo, fecha de cuota vs fecha de pago, otros rubros (no afectan el saldo, tipos propios, rechazo de tipos del sistema), disponible de la línea (recibos, anulación, altas/bajas de sub préstamo, cambio del monto máximo), pestaña de detalle, **mantenimiento de tipos de pago** (alta, edición, búsqueda, código único/alfanumérico/en mayúsculas, protección de tipos del sistema, borrado bloqueado con detalle). |
| `tests/Feature/Lending/AnulacionesTest.php` | 13 | **Anulación del más reciente al más antiguo** (mensaje con el recibo a anular primero), fecha anterior rechazada, restauración de saldo, `planpagos` y disponible, auditoría (fecha, usuario, motivo), pagar de nuevo tras anular, pantalla (solo el más reciente por sub préstamo, motivo obligatorio, historial), revisiones, cancelación total anulada, menú habilitado y búsqueda. |
| `tests/Feature/Lending/ImportPaidInstallmentsTest.php` | 3 | Importación de cuotas pagadas (recibos, detalle, orden cronológico, disponible), idempotencia y `--dry-run`. |
| `tests/Feature/PrestamoScreensTest.php` | 24 | Sub préstamos: alta con cuota/fechas calculadas, límite de la línea, número único, línea activa, listado/búsqueda/filtro, detalle con 96 cuotas, flujo de recibos y revisiones por pantalla, bloqueo de edición, cotización JSON, borrado con confirmación y bloqueos (recibos, revisiones, `planpagos`), pestañas, formularios agrupados, panel de la línea, estado vacío, menú y marcadores "Pronto". |
| `tests/Feature/ReportsTest.php` | 22 | **Reportes**: acceso, centro de reportes con los anunciados como "Pronto", submenú, catálogo, 404 de reportes inexistentes o anunciados, posición por banco (saldo, disponible, utilización, tasa ponderada, concentración), monedas sin sumar, filtro de moneda, servicio de deuda a 3/6/12 meses, exclusión de líneas inactivas y sub préstamos liquidados, línea sin banco, guía de decisión (pagar/usar primero y alertas por severidad), pantalla y **descarga de Excel** (tipo, nombre, hojas, contenido, fechas y porcentajes como valores de Excel, filtro de moneda). |
| `tests/Unit/Reports/XlsxWriterTest.php` | 10 | Generador de Excel: estructura del zip, tipos de columna, totales, fechas según `DATE_FORMAT`, porcentajes, nombres de hoja, escape de XML y error sin hojas. |
| `tests/Feature/CatalogMaintenanceTest.php` | 16 | Empresas, bancos, tipos de tasa (con historial) y líneas: CRUD, validaciones, búsqueda, paginación, botones de borrado con confirmación y bloqueo, mensajes con el detalle de dependencias. |
| `tests/Feature/RecibosListTest.php` | 9 | Pantalla Recibos: acceso, menú, orden, activos/anulados/todos, filtros por tipo y fechas (en el formato configurado), búsqueda, totales, paginación y enlaces sin marcadores internos. |
| `tests/Feature/DashboardTest.php` | 8 | Dashboard: acceso, resumen derivado de recibos, vencidas, cuotas pendientes, próxima cuota, disponible por banco con proyección, respaldo por nombre de línea y renderizado del layout (menú, cabecera, dos opciones de cerrar sesión). |
| `tests/Feature/DisplayDateFormatTest.php` | 17 | Fechas: formato `dd/mm/aaaa` en formularios, conversión a ISO, días/meses sin cero, fechas imposibles y `mm/dd` rechazadas, valores devueltos tras un error, otros formatos (`m/d/Y`, `Y-m-d`, `d-m-Y`), valor inválido de configuración y envío de la configuración al navegador. |
| `tests/Feature/Auth/ActiveDirectoryLoginTest.php` | 10 | Login: redirección de invitados, SSO de Windows (y de otro dominio), SSO desactivable, credenciales válidas/inválidas, directorio no disponible, campos obligatorios y logout sin reingreso automático. El directorio se simula; no se contacta al AD real. |
| `tests/Unit/ExampleTest.php` | 1 | Prueba de ejemplo de Laravel (sin lógica propia). |

## 4. Datos de prueba de las pruebas automatizadas

No usan la base real. Se generan con **fábricas** (`database/factories`): `EmpresaFactory`, `BancoFactory`, `TipoTasaFactory`, `LineaPrestamoFactory`, `PrestamoFactory`, `ReciboFactory`, `RevisionPrestamoFactory`, `TipoPagoFactory`, `UserFactory`.

Valores por defecto de referencia (los mismos del sistema real):
- Línea: monto máximo 65,000,000, `MENSUAL`, `CONSTANTE`, `365/360`, `NOMINAL`, `BASE_SPREAD`, `DOLARES`, tasa 2 + spread 2.
- Sub préstamo: 6,000,000, formalizado el 2026-03-01, 96 meses, tasa 2 + 2 (4 %), cuota pactada 73,290.81 en la mayoría de las pruebas.
- Hoy simulado: `Carbon::setTestNow('2026-11-18')`.
- Cifras de verificación del plan: cuota 1 = 53,290.81 + 20,000.00; cuota 2 = 53,468.45 + 19,822.36; cuota 3 = 53,646.67 + 19,644.14; saldo tras 2 cuotas = 5,893,240.74.

## 5. Datos cargados en la base real (SQL Server `Lineas`)

Estado al 2026-11-20 (tras aplicar todas las migraciones e importar las cuotas pagadas del 9090).

| Tabla | Filas | Contenido |
|---|---|---|
| `empresas` | 1 | `ATI` |
| `lineas_bancos` | 0 | (sin bancos todavía; el dashboard agrupa por nombre de línea) |
| `lineas_tipo_tasas` | 2 | `PRIME` (5.50000) y `TRI 96 MES` (3.50000) |
| `lineas_prestamos` | 2 | **BCT DOLARES** (id 12): máximo 65,000,000, capital de trabajo 6,500,000, disponible 47,106,759.26, mensual, constante, 365/360, tasa 2 + 2, vence 2032-03-07. **BNCR DOLARES** (id 14): máximo 35,000,000, capital de trabajo 35,000,000, disponible 35,000,000, tasa 1.5 + 1.5, vence 2032-03-07. Ambas `CAPITAL`, `DOLARES`, `ACTIVO`, plazo máximo 12 meses. |
| `prestamos` | 3 | Sub préstamos **4040** (id 2), **6060** (id 3) y **9090** (id 5), todos de la línea BCT: 6,000,000, formalizados el 2026-03-01, 96 meses, tasa 2 + 2, cuota 73,290.81, vencimiento 2034-03-01, próximo pago 2026-04-01, saldo 6,000,000, `ACTIVO`. **Tras importar sus 2 cuotas pagadas, el 9090 quedó con saldo 5,893,240.74 y próximo pago 2026-06-01** (los otros dos siguen sin pagos). |
| `planpagos` | 288 | **Reconstruida el 2026-11-20 con `lending:rebuild-planpagos`** (276 filas nuevas, 12 actualizadas, 0 eliminadas; segunda simulación sin cambios): 96 cuotas por cada sub préstamo (4040, 6060 y 9090), con la base de días heredada de la línea BCT (`365/360`). En el 9090 las cuotas 1 y 2 siguen en `C`. Antes solo existían 12 filas del 9090 (plan guardado hasta 2027-03-01 cuya última cuota cobraba todo el saldo, 5,403,932.75); su respaldo está en `storage/app/backups/planpagos-20261120-025944.json`. |
| `bitacora_cambio_tasas` | 6 | Cambios de tasa antiguos de los préstamos `1` y `1112` (sistema anterior) |
| `tipos_pago` | 6 | `PP`, `PI`, `CT`, `PA`, `PC`, `OT` (sembrados por migración) |
| `users` | 1 | `Alejo Jimenez` (`c-aljimenez@corp.obgroup.com`), creado en el primer acceso con AD |
| `recibos` | 2 | Cuotas 1 y 2 del 9090, importadas de `planpagos` (usuario `IMPORTACION`, ambas con fecha 01/04/2026; la fecha real de la cuota 2, 09/03/2026, quedó en observaciones) |
| `detalle_pago` | 4 | PP + PI de cada uno de esos dos recibos |
| `prestamo_revisiones`, `lineas_tipo_tasa_historial`, `lineas_bancos` | 0 | Vacías |
| `sessions` | 21 | Sesiones activas (`SESSION_DRIVER=database`) |

### Resultado de la importación de las cuotas pagadas (ya ejecutada en la base real)

`php artisan lending:import-paid-installments` creó 2 recibos para el 9090 (cuota 1: 53,290.81 + 20,000.00; cuota 2: 53,468.45 + 19,822.36, con fecha ajustada al 01/04/2026) y daría:

| Indicador | Valor |
|---|---|
| Saldo total de sub préstamos | 17,893,240.74 |
| Sub préstamo 9090 | saldo 5,893,240.74, próximo pago 01/06/2026 |
| Disponible BCT hoy | 47,106,759.26 |
| Disponible BNCR | 35,000,000.00 |
| Cuotas vencidas | 22 (importe 1,612,397.82) |
| Próxima cuota | 73,290.81 el 01/12/2026 |

> Los sub préstamos 4040 y 6060 no tienen ningún pago registrado, por eso aparecen con todas sus cuotas hasta noviembre de 2026 como vencidas. Hay que registrar sus pagos o confirmar que son datos de prueba.

## 6. Lo que no está probado

- **SQL Server:** las pruebas corren en SQLite; los flujos no se han ejecutado automáticamente contra SQL Server (sí se verificó a mano con la base real: migraciones, importación en simulación y métricas).
- **Navegador:** no hay pruebas visuales ni de JavaScript (pestañas, calendario, diálogos, tema oscuro, móvil); se validaron por inspección del HTML generado.
- **Active Directory real:** solo se comprobó a mano la conexión LDAPS y el rechazo de un usuario inexistente.
- **IIS con Windows Authentication:** no probado (el sitio usa hoy el formulario).
