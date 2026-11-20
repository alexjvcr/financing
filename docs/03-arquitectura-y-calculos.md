# 03 · Arquitectura y cálculos

## 1. Estructura del código

```
app/
├── Console/Commands/ImportPaidInstallments.php   lending:import-paid-installments
├── Enums/            TipoRecibo, EstadoCuota, DayCount, ModalidadCuota, ConvencionTasa
├── Http/
│   ├── Controllers/  CatalogController (base) + un controlador por pantalla
│   ├── Middleware/   ConvertDisplayDates (fechas dd/mm/aaaa → aaaa-mm-dd)
│   └── Requests/Auth/LoginRequest.php
├── Models/           Empresa, Banco, TipoTasa, TipoTasaHistorial, LineaPrestamo, Prestamo,
│                     Recibo, DetallePago, TipoPago, RevisionPrestamo, User
├── Providers/AppServiceProvider.php              directivas Blade @fecha y @fechahora
├── Reports/          Report (base), ReportCatalog (reportes listos y anunciados), DebtPositionReport
├── Services/
│   ├── ActiveDirectoryAuthenticator.php          LDAP/AD
│   ├── DashboardMetrics.php                      cifras del dashboard
│   └── Lending/      motor del plan y operaciones de recibos/revisiones
└── Support/          DateFormat (formato de fecha), CalculationOptions (listas de condiciones de cálculo),
                      XlsxWriter (genera archivos .xlsx con ZipArchive, sin dependencias)
config/ldap.php, config/formats.php               configuración propia
resources/views/                                  Blade (ver 04 y 05)
public/css/                                       dashboard.css, theme.css
```

### Controladores

| Controlador | Responsabilidad |
|---|---|
| `CatalogController` (abstracto) | Listado con búsqueda y paginación (15), alta, edición y borrado con validación de llaves foráneas. Lo extienden Empresa, Banco, TipoTasa, LineaPrestamo y TipoPago, que solo declaran columnas, campos, reglas y dependencias. |
| `PrestamoController` | Sub préstamos: listado, alta, edición, detalle, borrado y cotización de cancelación (JSON). |
| `ReciboController` | Listado general de recibos, registro y anulación. |
| `RevisionController` | Registro y anulación de revisiones. |
| `AnulacionController` | Pantalla de anulaciones y su historial. |
| `ReportController` | Centro de reportes, pantalla de cada reporte y su descarga en Excel (404 si el reporte no existe o aún está anunciado). |
| `DashboardController` | Dashboard. |
| `Auth\LoginController` | Login (SSO de Windows o formulario AD) y logout. |

### Servicios del módulo de préstamos (`app/Services/Lending`)

| Clase | Rol |
|---|---|
| `LoanScheduleBuilder` | **Motor puro** (sin base de datos): reconstruye el plan a partir de los términos, los recibos activos y las revisiones activas. |
| `LoanSchedule` / `ScheduleRow` | Resultado: filas del plan y consultas (saldo, próxima cuota, vencidas, vencimiento). |
| `LoanScheduleService` | Carga datos de la base, arma el plan, cotiza cancelaciones y **escribe la instantánea** en `prestamos` y el disponible de la línea (`refreshSnapshot`). |
| `ReceiptRegistrar` | Valida y registra recibos; los anula (del más reciente al más antiguo). |
| `ReceiptDetailWriter` | Crea las líneas de `detalle_pago` de cada recibo. |
| `RevisionRegistrar` | Valida y registra/anula revisiones de tasa y plazo. |
| `LoanConditions` | Resuelve las condiciones de cálculo efectivas de un sub préstamo (las suyas o las de su línea). |
| `LegacyPlanSync` | Escribe el plan calculado en `planpagos` y marca las cuotas `C`/`P` con los recibos y anulaciones. |

## 2. El plan de pagos dinámico

El plan **no se guarda**: `LoanScheduleBuilder` lo reconstruye cada vez que se abre un sub préstamo, se calcula el dashboard o se valida un recibo.

### 2.1 Reglas de cálculo

| Regla | Detalle |
|---|---|
| **Período** | Según `modalidad_pago` de la línea: mensual = 1 mes, trimestral = 3, semestral = 6, anual/único = 12. |
| **Fechas de cuota** | `formalización + k × período`, calculado siempre desde la fecha original (si se formalizó el 31 de enero, las cuotas caen el 28 de febrero, 31 de marzo, 30 de abril…). |
| **Tasa anual** | `tasa_interes + tasa_spread` del sub préstamo (ej. 2 + 2 = 4 %), sustituida por la última revisión vigente. |
| **Interés del período** | Según la **base de días** y el **tipo de cálculo de interés** de las condiciones (ver 2.4). Con eventos a mitad de período (abonos, revisiones) el período se divide en tramos. |
| **Cuota de partida** | `prestamos.cuota_calculada` (la del banco) para cuota nivelada y creciente. Si se crea sin cuota, se calcula con la fórmula del tipo de cuota. |
| **Modalidad `CONSTANTE`** | Cuota nivelada (sistema francés): principal = cuota − interés. |
| **Modalidad `DECRECIENTE`** | Amortización lineal (sistema alemán): principal fijo = (saldo − global) / períodos restantes. |
| **Modalidad `BULLET`** | Solo intereses; todo el principal en la última cuota. |
| **Modalidad `CRECIENTE`** | Cuota graduada: la cuota crece `variacion_porcentual` % por período. |
| **Última cuota** | Cuando quedan ≤ 1 períodos, la cuota paga **todo el saldo**. |
| **Redondeo** | Principal e interés se redondean a 2 decimales por cuota. |
| **Cuotas sin pagar** | Se proyectan como si se pagaran a tiempo. Estado `VENCIDA` si su fecha < hoy, `PENDIENTE` si no. |
| **Cancelar con cuotas sin pagar** | No se permite: antes de registrar la cancelación total hay que pagar las cuotas con vencimiento anterior a esa fecha. |

### 2.2 Cómo reaccionan los recibos y revisiones

| Evento | Efecto en el plan |
|---|---|
| **Cuota completa** (tipo 1) | Cierra esa cuota con los montos realmente pagados (si difieren del plan se marca "≠ plan"). El saldo baja por el principal pagado. |
| **Solo interés** (tipo 2) | Cierra la cuota pagando solo interés; el principal se **difiere**: el saldo no baja y la cuota se **recalcula** con los períodos restantes. |
| **Solo principal** (tipo 3) / **Adelanto** (tipo 4) | Baja el saldo desde su fecha. La **cuota se mantiene** y el préstamo termina antes (se acorta el plazo). Un abono a mitad de período prorratea el interés. |
| **Cancelación total** (tipo 5) | Cierra el préstamo en esa fecha. Cotiza: saldo + interés devengado desde la última cuota. |
| **Revisión de tasa/spread** | Desde el período siguiente a su fecha efectiva usa la nueva tasa y **recalcula la cuota** sobre el saldo y los períodos restantes. Una revisión a mitad de período divide el interés en tramos. |
| **Revisión de plazo** | Fija el plazo restante (en meses, contado desde la siguiente cuota) y recalcula la cuota. |
| **Recibos/revisiones anulados** | Se ignoran por completo. |

### 2.3 Ejemplos con los datos del proyecto

Sub préstamo de 6,000,000, tasa 4 % (2 + 2), plazo 96 meses, cuota pactada 73,290.81, formalizado el 01/03/2026:

| Cuota | Fecha | Interés | Principal | Saldo |
|---|---|---|---|---|
| 1 | 01/04/2026 | 20,000.00 | 53,290.81 | 5,946,709.19 |
| 2 | 01/05/2026 | 19,822.36 | 53,468.45 | 5,893,240.74 |
| 3 | 01/06/2026 | 19,644.14 | 53,646.67 | 5,839,594.07 |

(Los mismos montos que tenía la tabla `planpagos` original.)

- Sin cuota pactada, la anualidad a 96 meses da **73,135.65**.
- Si la cuota 1 se paga solo con interés, la cuota 2 se recalcula en **73,789.12** (95 períodos restantes).
- Cancelación el 16/05/2026 con las dos primeras cuotas pagadas: **5,893,240.74** de principal + **9,822.07** de interés (medio período).
- Un abono de 500,000 el 01/05/2026 deja el saldo en 5,393,240.74; la cuota 3 tiene interés 17,977.47 y el préstamo termina antes de la cuota 96.

> **Observación sobre los datos:** la cuota pactada 73,290.81 se reproduce exactamente con **Actual/360**: es la anualidad a 96 meses con la tasa 4 % × 365/360 (4.0556 %), que es como se dimensiona la cuota nivelada con esa base. Los intereses del plan original (20,000.00 fijos) correspondían a 30/360; con `365/360` el interés de cada cuota pasa a depender de los días reales del mes (por ejemplo 20,666.67 para un mes de 31 días).

### 2.4 Condiciones de cálculo

Todas se definen en la **línea de crédito** y cada **sub préstamo** puede cambiarlas; un valor vacío en el sub préstamo se hereda de la línea (`LoanConditions::resolve`).

| Condición | Campo | Valores | Efecto |
|---|---|---|---|
| **Tipo de cálculo de cuota** | `modalidad_cuota` | `CONSTANTE`, `DECRECIENTE`, `BULLET`, `CRECIENTE` | Cómo se reparte el principal (ver 2.1) |
| **Base de días** | `calendario` | `365/360` Actual/360 · `365/365` Actual/365 · `360/360` 30/360 | Fracción de año de un período: días reales/360, días reales/365 o mes comercial de 30 días (un período completo = `meses/12`) |
| **Tipo de cálculo de interés** | `tipo_cuota` | `NOMINAL`, `EFECTIVA` | Nominal: `saldo × tasa × fracción`. Efectiva: `saldo × ((1 + tasa)^fracción − 1)`, con fracción = días/365 (o meses/12 en 30/360) |
| **Periodicidad** | `modalidad_pago` | `MENSUAL`, `TRIMESTRAL`, `SEMESTRAL`, `ANUAL`, `UNICO` | Meses por período: 1, 3, 6, 12, 12 |
| **Período de gracia** | `meses_gracia` | meses (0 = sin gracia) | Los primeros `meses_gracia / meses por período` períodos solo pagan interés; luego se amortiza sobre los períodos restantes |
| **Cuota global (balloon)** | `porcentaje_global` | 0–100 % del monto | Esa parte del principal se paga con la última cuota; la cuota se dimensiona sobre el resto |
| **Crecimiento de la cuota** | `variacion_porcentual` | % por período | Solo `CRECIENTE`: `cuota₁ × (1 + g)^(k−1)` |
| **Forma de pago del interés** | `forma_pago` | `VENCIMIENTO`, `ADELANTADO` | Se guarda; hoy ambos se calculan como interés vencido |

**Tamaño de la cuota.** Para la cuota nivelada y la creciente se usa la tasa media del período: nominal = `tasa × meses/12` (en Actual/360, × 365/360); efectiva = `(1 + tasa)^(meses/12) − 1`. Con global `B`: nivelada = `(P − B/(1+r)^n) × r / (1 − (1+r)^−n)`; la creciente resuelve la primera cuota de la serie geométrica que amortiza `P − B/(1+r)^n`.

**Prioridad de datos.** La cuota pactada (`cuota_calculada`) solo se usa como cuota de partida para nivelada y creciente; las revisiones, los pagos de solo interés y el fin de la gracia recalculan la cuota.

**Cuotas de solo interés.** Durante la gracia (y en las cuotas intermedias de un bullet) la cuota no lleva principal; se paga como **cuota completa con principal 0**.

## 3. Recibos

### 3.1 Tipos de recibo y su desglose en `detalle_pago`

| Tipo de recibo (código) | Líneas de `detalle_pago` |
|---|---|
| 1. Cuota completa (`CUOTA_COMPLETA`) | **PP** (principal) + **PI** (interés) |
| 2. Cuota parcial solo interés (`PARCIAL_INTERES`) | **PC** (pago parcial) por el interés |
| 3. Solo pago a principal (`SOLO_PRINCIPAL`) | **PP** |
| 4. Adelanto de cuota (`ADELANTO_CUOTA`) | **PA** (pago anticipado) |
| 5. Cancelación total (`CANCELACION_TOTAL`) | **CT** (saldo) + **PI** (interés devengado, si > 0) |
| Cualquiera, con "otros rubros" | + una línea **OT** (u otro tipo propio) por `monto_otros`, **sin afectar el saldo** |

Cada línea guarda tipo, monto, fecha de pago, número de cuota y **fecha de cuota tomada del plan**. En adelantos, abonos y cancelación el número/fecha de cuota es el de la **siguiente cuota abierta** (informativo).

### 3.2 Validaciones al registrar (`ReceiptRegistrar`)

1. El sub préstamo debe estar `ACTIVO`.
2. Montos no negativos.
3. La fecha debe ser **posterior a la formalización**.
4. La fecha **no puede ser anterior al último recibo activo** del sub préstamo (no se paga enero si hay una cuota pagada de febrero).
5. Cuota completa / solo interés: el número debe ser **el de la siguiente cuota por pagar** (las cuotas se pagan en orden). Cuota completa exige interés > 0 y principal > 0, **salvo** que la cuota no lleve principal (período de gracia o cuota intermedia de un bullet), en cuyo caso se paga con principal 0; solo interés exige principal = 0 e interés > 0.
6. Solo principal / adelanto: principal > 0, interés = 0 y menor que el saldo (si cubre todo el saldo se pide cancelación total).
7. Cancelación total: **no deben quedar cuotas sin pagar con vencimiento anterior a la fecha** (el mensaje indica cuál), y el principal debe igualar el saldo cotizado a esa fecha (±0.01).
8. Otros rubros: si se indica un tipo, no puede ser un tipo del sistema distinto de OT.

Todo ocurre en **una transacción**: recibo → detalle de pago → `planpagos` (si es cuota completa) → instantánea del sub préstamo → disponible de la línea.

### 3.3 Anulación (del más reciente al más antiguo)

- Un recibo **solo se anula si es el más reciente** (por fecha de pago y luego id) del sub préstamo. Si no lo es, el error indica cuál anular primero (por ejemplo "Anule primero el recibo #7 del 01/06/2026").
- La anulación exige un **motivo** y registra `anulado_at`, `anulado_por` (correo de AD) y `motivo_anulacion`. El recibo y su detalle **no se borran**.
- Restaura: saldo, próximo pago, vencimiento y estado del sub préstamo (anular una cancelación total lo reactiva); la cuota en `planpagos` vuelve a `P` sin fecha real; el disponible de la línea se recalcula.
- Las revisiones siguen la misma regla (más reciente primero) y guardan la misma auditoría.

### 3.4 Revisiones (`RevisionRegistrar`)

Requieren al menos un cambio (tasa, spread o plazo). La fecha no puede ser anterior a la formalización, a la última revisión ni a **una cuota ya pagada** (no hay revisiones retroactivas sobre lo pagado).

## 4. Importación de cuotas pagadas

`php artisan lending:import-paid-installments` toma las filas de `planpagos` con estado `C` y crea un recibo `CUOTA_COMPLETA` por cada una (usuario `IMPORTACION`), con su detalle (PP + PI) y la fecha de cuota de `planpagos`. Reglas:

- Es **idempotente** (no duplica una cuota ya importada).
- **Respeta el orden cronológico**: si una cuota figura pagada en una fecha anterior a la de la cuota previa, se importa con la fecha de la previa y la fecha real queda en `observaciones`.
- `--dry-run` muestra lo que haría sin escribir.

### 4.1 Reflejo del plan en `planpagos`

El plan sigue siendo dinámico, pero cada vez que cambia se **escribe también en `planpagos`** (`LegacyPlanSync::reflect`), para que el sistema anterior vea las mismas cuotas:

- Cuándo: al crear o editar un sub préstamo, registrar o anular un recibo o una revisión, importar cuotas y al ejecutar el comando de reconstrucción.
- Qué escribe por cuota: fecha, principal, interés, tasa anual, saldos pendientes (principal/interés de la cuota y saldo del préstamo), estado (`C` si está pagada con recibo, `P` si no), fecha real de pago y la observación de un pago parcial.
- Qué no hace: no modifica una fila `C` cuya cuota todavía no tiene recibo (historial sin importar) y no borra filas pagadas. Las filas `P` que ya no existen en el plan (porque un abono lo acortó) sí se eliminan.
- No reescribe las filas que no cambian (compara los valores antes de escribir).

**Comando de reconstrucción:** `php artisan lending:rebuild-planpagos`

| Opción | Efecto |
|---|---|
| `--dry-run` | Muestra, por sub préstamo activo, cuántas cuotas se crearían, actualizarían o eliminarían, sin escribir nada |
| `--loan=9090` | Procesa solo ese número de sub préstamo |
| (sin opciones) | Guarda primero un **respaldo JSON** de las filas actuales en `storage/app/backups/planpagos-AAAAMMDD-HHMMSS.json` y luego reconstruye |

El comando es idempotente: una segunda ejecución no cambia nada.

## 5. Dashboard (`DashboardMetrics`)

Calcula todo a partir de los planes reconstruidos de los sub préstamos activos.

| Indicador | Fórmula |
|---|---|
| Saldo de sub préstamos activos | Σ saldo de principal pendiente |
| Líneas activas / monto máximo | Cantidad y Σ `monto_maximo` de líneas `ACTIVO` |
| Próxima cuota | Primera cuota `PENDIENTE` entre todos los sub préstamos |
| Cuotas vencidas | Cuotas `VENCIDA` (cantidad y Σ principal + interés) |
| **Disponible por banco** | Por banco y moneda (si la línea no tiene banco, por nombre de línea): `Σ monto_maximo − Σ saldo`. |
| Disponible a 3, 6, 9, 12, 18 y 24 meses | `límite − max(saldo − Σ principal de cuotas abiertas con fecha ≤ hoy + N meses, 0)`. Incluye las cuotas vencidas, asumiendo que se pagarán. |

Las líneas `INACTIVO` y los sub préstamos `LIQUIDADO` no cuentan.

### 5.1 Reportes (`app/Reports`)

Cada reporte extiende `Report` (`slug`, `title`, `description`, `icon`, `filters`, `build`, `workbook`) y se registra en `ReportCatalog`, que alimenta el menú, el centro de reportes y las rutas (`reportes.index`, `reportes.show`, `reportes.excel`). `build()` devuelve los datos una sola vez y los usan la vista y el Excel, así pantalla y archivo no pueden diferir.

**Posición consolidada de deuda** (`DebtPositionReport`), sobre los planes reconstruidos de los sub préstamos activos:

| Indicador | Fórmula |
|---|---|
| Utilización | `saldo / límite × 100` |
| Tasa ponderada | `Σ(saldo × tasa) / Σ saldo` (tasa = tasa + spread del sub préstamo) |
| Concentración | Saldo del banco / saldo total **de su misma moneda** |
| Interés anual estimado | `saldo × tasa / 100` |
| Servicio a N meses | Σ (principal + interés) de las cuotas abiertas con fecha ≤ hoy + N meses; incluye las vencidas |
| Plazo promedio | Años al vencimiento ponderados por saldo |

Las monedas nunca se suman. Los umbrales de la guía (80 % de utilización, 50 % de concentración, 365 días al vencimiento, 5 elementos por lista) son constantes de la clase.

**`XlsxWriter`:** arma el `.xlsx` (zip de XML) con textos en línea, 15 estilos, filas de total, panel inmovilizado y filtro automático. Tipos de columna: `text`, `money`, `percent` (se guarda como fracción: 18.46 → 0.1846), `integer`, `decimal` y `date` (número de serie de Excel con el formato de `DATE_FORMAT`).

## 6. Borrado con validación de llaves foráneas

Antes de borrar, el servidor cuenta los registros que dependen del registro y bloquea el borrado con un mensaje con el detalle. En los listados el botón **Eliminar** se muestra deshabilitado (con la etiqueta "En uso") cuando hay dependencias.

| Registro | Se bloquea si… |
|---|---|
| Empresa | tiene líneas de crédito |
| Banco | tiene líneas de crédito |
| Tipo de tasa | hay líneas que lo usan (si no, se borra también su historial de cambios) |
| Línea de crédito | tiene sub préstamos |
| Tipo de pago | es del sistema, o tiene líneas en `detalle_pago` |
| Sub préstamo | tiene recibos (activos o anulados), revisiones, o filas en `planpagos` |

Siempre se pide **confirmación en un diálogo** antes de enviar el borrado.

## 7. Formato de fecha

1. En base y modelos las fechas son siempre `aaaa-mm-dd`.
2. `App\Support\DateFormat` centraliza el formato de pantalla (`DATE_FORMAT`).
3. Las vistas muestran fechas con `@fecha($valor)` / `@fechahora($valor)`.
4. Los formularios usan el componente `<x-date-input>`, que añade un campo oculto `_dates[]` con el nombre del campo.
5. El middleware `ConvertDisplayDates` convierte esos campos a `aaaa-mm-dd` **antes de validar**, así los controladores usan la regla `date` normal. Una fecha imposible (31/02) o en otro orden (12/13/2026 con formato d/m/Y) se reemplaza por `invalid-date` para que la validación la rechace en vez de leerla mal.

## 8. Búsqueda y paginación

Todos los listados filtran en el servidor con el parámetro `q` (texto) y paginan de 15 en 15 conservando los filtros. La búsqueda de recibos y anulaciones agrega estado, tipo y rango de fechas.
