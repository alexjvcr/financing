# Referencia 02 · Servicios de operación y comandos artisan

Referencia extraída del código real de los servicios que registran y anulan recibos y revisiones, reconstruyen el plan, mantienen la tabla heredada `planpagos`, alimentan el tablero y autentican contra Active Directory, más los dos comandos artisan de mantenimiento.

Convenciones del documento:

- "Plan" es el `LoanSchedule` reconstruido dinámicamente por `LoanScheduleBuilder` (ver [01-motor-del-plan.md](01-motor-del-plan.md)); "`planpagos`" es la tabla heredada con una fila por cuota y estado `P` (pendiente) o `C` (cancelada/pagada).
- Los recibos y las revisiones "activos" son los que `scopeActive()` deja pasar: recibos con `estado = 'ACTIVO'`, revisiones con `anulado_at IS NULL`, préstamos con `estado = 'ACTIVO'` (`LIQUIDADO` es el otro estado de un préstamo).
- Las relaciones `Prestamo::recibos()` y `Prestamo::revisiones()` vienen ordenadas cronológicamente (`fecha_pago, id` y `fecha_efectiva, id`), por eso `->get()->last()` es "el más reciente".
- Todos los errores de validación son `Illuminate\Validation\ValidationException` con un mensaje por campo; el campo indicado es la clave del arreglo de errores.

## Mapa de dependencias

| Clase | Depende de | Quién la usa |
|---|---|---|
| `LoanScheduleService` | `LoanScheduleBuilder`, `LegacyPlanSync` | `ReceiptRegistrar`, `RevisionRegistrar`, `DashboardMetrics`, controladores, comandos |
| `ReceiptRegistrar` | `LoanScheduleService`, `ReceiptDetailWriter`, `LegacyPlanSync` | `ReciboController`, `AnulacionController` |
| `ReceiptDetailWriter` | modelos `DetallePago`, `TipoPago` | `ReceiptRegistrar`, `ImportPaidInstallments` |
| `RevisionRegistrar` | `LoanScheduleService` | `RevisionController`, `AnulacionController` |
| `LegacyPlanSync` | tabla `planpagos` | `LoanScheduleService`, `ReceiptRegistrar`, `PrestamoController`, `RebuildPlanPagos` |
| `DashboardMetrics` | `LoanScheduleService` | `DashboardController` |
| `ActiveDirectoryAuthenticator` | extensión `ldap`, `config/ldap.php` | `Auth\LoginController` |

---

# Servicios de préstamos (`app/Services/Lending`)

## LoanScheduleService

Ruta: `app/Services/Lending/LoanScheduleService.php`. Namespace `App\Services\Lending`.

Propósito: carga los recibos y las revisiones activos de un préstamo, reconstruye su plan de pagos con `LoanScheduleBuilder` y, con `refreshSnapshot()`, persiste en la fila del préstamo, en la línea y en `planpagos` las cifras derivadas. Es el punto único por el que el resto del sistema obtiene un plan.

Constructor: `__construct(protected LoanScheduleBuilder $builder, protected LegacyPlanSync $legacyPlan)`.

### scheduleFor(Prestamo $loan, ?Carbon $today = null): LoanSchedule

- Hace `$loan->loadMissing('linea')` y llama a `builder->build($loan, $loan->linea, recibos activos, revisiones activos, $today)`.
- Los recibos y revisiones se consultan con `$loan->recibos()->active()->get()` y `$loan->revisiones()->active()->get()` (dos consultas nuevas cada vez, sin usar relaciones ya cargadas).
- `$today` es la fecha de corte para clasificar cuotas como vencidas o pendientes; si es `null` el constructor del plan usa su valor por omisión (hoy).
- Solo lectura: no escribe nada.

### activeSchedules(?Carbon $today = null): Collection

- Retorna `Collection<int, array{loan: Prestamo, schedule: LoanSchedule}>` con el plan reconstruido de **todos** los préstamos activos, ordenados por `numero_prestamo`.
- Carga con eager loading `linea.banco`, `recibos` (solo activos) y `revisiones` (solo activas), de modo que cada plan se arma sin consultas adicionales por préstamo; el constructor del plan usa `$loan->recibos` y `$loan->revisiones` ya filtradas.
- Solo lectura. La usa `DashboardMetrics`.

### payoffQuote(Prestamo $loan, Carbon $date): array{principal: float, interest: float}

- `loadMissing('linea')` y delega en `builder->payoffQuote(...)` con los recibos y revisiones activos y la fecha dada.
- El constructor simula un recibo de cancelación total en `$date` y devuelve el principal y el interés programados de la fila de cancelación (`0.0` si no hubo fila). Es lo que `ReceiptRegistrar::validatePayoff()` compara contra lo que el usuario digitó.
- Solo lectura.

### refreshSnapshot(Prestamo $loan): LoanSchedule

Reconstruye el plan y refleja el resultado en el almacenamiento. Orden de operaciones:

1. `$schedule = scheduleFor($loan)`.
2. Actualiza la fila de `prestamos` con una consulta directa (`$loan->newQuery()->whereKey(...)->update([...])`, para que la fila se actualice aunque el modelo en memoria ya tenga los mismos valores que una copia obsoleta):
   - `saldo_prestamo` = `$schedule->outstandingPrincipal()`.
   - `estado` = `LIQUIDADO` si `$schedule->isSettled()`, si no `ACTIVO` (por eso anular un recibo de cancelación reactiva el préstamo).
   - `proximo_pago` = fecha de la siguiente cuota abierta; si no hay, conserva el valor actual.
   - `vencimiento` = `$schedule->maturityDate()`; si es `null`, conserva el actual.
   - `updated_at` = `now()`.
3. `$loan->refresh()` para recargar el modelo.
4. `$loan->linea->refreshAvailable()`: recalcula `disponible` de la línea = `monto_maximo` menos la suma de `saldo_prestamo` de los préstamos activos de esa línea.
5. `legacyPlan->reflect($loan, $schedule)`: sincroniza `planpagos` (ver más abajo).
6. Retorna el plan.

Notas:

- No abre transacción propia: los llamadores (`ReceiptRegistrar`, `RevisionRegistrar`) la abren; `RebuildPlanPagos` y `ImportPaidInstallments` la invocan sin transacción envolvente (`reflect()` sí abre la suya).
- **No** modifica `cuota_calculada` (la cuota contractual), porque el plan la usa como cuota inicial.
- Idempotente: llamarla dos veces seguidas produce el mismo estado.

---

## ReceiptRegistrar

Ruta: `app/Services/Lending/ReceiptRegistrar.php`.

Propósito: registrar y anular recibos aplicando las reglas que mantienen bien definido el plan reconstruido (orden de cuotas, fechas, montos por tipo de recibo).

Constructor: `__construct(protected LoanScheduleService $schedules, protected ReceiptDetailWriter $details, protected LegacyPlanSync $legacyPlan)`.

### register(Prestamo $loan, array $data, string $user): Recibo

Parámetros:

- `$loan`: sub préstamo que recibe el pago.
- `$data`: `tipo` (`TipoRecibo` o su valor de texto), `fecha_pago`, y opcionales `numero_cuota`, `monto_principal`, `monto_interes`, `monto_otros`, `tipo_pago_otros_id`, `descripcion_otros`, `observaciones`.
- `$user`: se guarda en `recibos.usuario`.
- Lanza `ValidationException`; `TipoRecibo::from()` lanza `ValueError` si el tipo no existe.

Normalización previa:

- `tipo`: `TipoRecibo::from()` si llega como texto.
- `fecha_pago`: `Carbon::parse(...)->startOfDay()`.
- `monto_principal`, `monto_interes`, `monto_otros`: `round((float) ..., 2)`; ausentes cuentan como `0`.
- `numero_cuota`: solo se conserva (como `int`, `0` si falta) cuando el tipo es pago de cuota (`CuotaCompleta` o `ParcialInteres`); para los demás tipos queda `null` aunque se envíe.

Orden de operaciones:

1. `validate()` (ver abajo) devuelve el plan vigente; todas las reglas se evalúan antes de escribir.
2. `resolveOtherType()` determina el tipo de pago de los "otros rubros" (también antes de escribir).
3. `$next = $schedule->nextOpenInstallment()`: la siguiente cuota abierta **antes** del pago; se usa para el detalle.
4. `DB::transaction`:
   1. Crea el recibo con `$loan->recibos()->create([...])` (tabla `recibos`; `estado = ACTIVO`, `usuario = $user`, `observaciones` opcional).
   2. `details->write($receipt, $next?->number, $next?->date, $otherType, descripcion_otros)` escribe `detalle_pago`.
   3. `legacyPlan->markPaid($receipt)` marca la cuota en `planpagos` (solo cuota completa).
   4. `schedules->refreshSnapshot($loan)`: recalcula saldo, estado, próximo pago, vencimiento, `disponible` de la línea y `planpagos`.
5. Retorna el `Recibo`.

Si algo falla dentro de la transacción se revierten recibo, detalle, snapshot y `planpagos`.

Casos borde: el número y la fecha de cuota del detalle provienen de la siguiente cuota abierta del plan, incluso para abonos y cancelaciones (no del `numero_cuota` del recibo, que en esos tipos es `null`); si no hubiera cuota abierta, el detalle queda con `numero_cuota` y `fecha_cuota` nulos.

### void(Recibo $receipt, string $reason, string $user): Recibo

Anula un recibo. Los recibos se anulan del más reciente al más antiguo, para que la secuencia de pagos nunca tenga huecos.

Validaciones (en este orden, campo `recibo`):

1. Recibo ya anulado: `El recibo ya está anulado.`
2. No es el último activo del préstamo: `Los recibos se anulan del más reciente al más antiguo. Anule primero el recibo #{id} del {fecha}.` (la fecha con `DateFormat::format`).

No verifica que el préstamo esté activo: se puede anular el recibo de cancelación de un préstamo liquidado.

Transacción:

1. `$receipt->update(['estado' => ANULADO, 'anulado_at' => now(), 'anulado_por' => $user, 'motivo_anulacion' => $reason])` (auditoría: quién, cuándo y por qué).
2. `legacyPlan->restore($receipt)`: devuelve la cuota a `P` en `planpagos` (solo cuota completa).
3. `schedules->refreshSnapshot($loan)`: restituye saldo, estado del préstamo, disponible de la línea y vuelve a reflejar el plan.

Los renglones de `detalle_pago` del recibo **no** se borran ni se modifican: el recibo queda como registro anulado con su detalle. Retorna el mismo recibo.

### latestActive(Prestamo $loan): ?Recibo

El recibo activo más reciente del préstamo (`recibos()->active()->get()->last()`); es el único anulable. `null` si no hay recibos activos.

### resolveOtherType(float $amount, int|string|null $typeId): ?TipoPago (protegido)

Determina el tipo de pago del monto "otros rubros".

- Si `$amount <= 0` retorna `null`.
- Si `$typeId` es verdadero (no vacío ni `0`), `TipoPago::find($typeId)`; si no, el tipo con código `OT` (`TipoPago::OTHER`).
- Errores (campo `tipo_pago_otros_id`):
  - No existe: `El tipo de pago de los otros rubros no existe.`
  - Es tipo de sistema distinto de `OT`: `El tipo {codigo} se calcula solo; elija OT u otro tipo propio para los otros rubros.` (los tipos `PP`, `PI`, `CT`, `PA`, `PC` los genera el sistema).

### validate(Prestamo $loan, TipoRecibo $type, Carbon $date, ?int $number, float $principal, float $interest): LoanSchedule (protegido)

Reglas comunes, en este orden (la primera que falla corta):

| # | Condición de error | Campo | Mensaje |
|---|---|---|---|
| 1 | El préstamo no está activo | `tipo` | `El sub préstamo está liquidado y no admite más recibos.` |
| 2 | `principal < 0` o `interest < 0` | `monto_principal` | `Los montos no pueden ser negativos.` |
| 3 | `fecha <= formalizacion` | `fecha_pago` | `La fecha del recibo debe ser posterior a la formalización del sub préstamo ({fecha}).` |
| 4 | Hay recibo activo previo y `fecha < su fecha_pago` | `fecha_pago` | `La fecha no puede ser anterior al último recibo registrado ({fecha}).` |

Luego calcula `scheduleFor($loan)` y `outstandingPrincipal()` y despacha con `match` por tipo:

- `CuotaCompleta` → `validateInstallment(..., requirePrincipal: true)`
- `ParcialInteres` → `validateInstallment(..., requirePrincipal: false)`
- `SoloPrincipal` y `AdelantoCuota` → `validatePrepayment()`
- `CancelacionTotal` → `validatePayoff()`

Retorna el plan calculado (el mismo que usa `register()` para `$next`). Observación: el monto de "otros" no se valida contra negativos aquí.

### validateInstallment(LoanSchedule $schedule, ?int $number, float $principal, float $interest, bool $requirePrincipal): void (protegido)

Para cuota completa y parcial de solo interés. Errores:

1. Sin cuota abierta: `numero_cuota` · `El sub préstamo no tiene cuotas pendientes.`
2. `$number` distinto de la siguiente cuota abierta: `numero_cuota` · `La siguiente cuota por pagar es la número {n}.` (se obliga a pagar en orden).
3. `$requirePrincipal` y `principal <= 0` y la cuota tiene principal programado `> 0`: `monto_principal` · `Una cuota completa debe incluir pago a principal.` (si la cuota programada no tiene principal, por ejemplo en gracia, se acepta principal 0).
4. No `$requirePrincipal` y `principal > 0`: `monto_principal` · `Un pago parcial de solo interés no puede incluir principal.`
5. `interest <= 0`: `monto_interes` · `Indique el interés pagado.`

El código no compara los montos contra los programados de la cuota; solo exige las condiciones anteriores.

### validatePrepayment(float $outstanding, float $principal, float $interest): void (protegido)

Para `SoloPrincipal` y `AdelantoCuota`:

1. `principal <= 0`: `monto_principal` · `Indique el monto a principal.`
2. `interest > 0`: `monto_interes` · `Este tipo de recibo solo aplica a principal.`
3. `principal >= outstanding - 0.005`: `monto_principal` · `El monto cubre todo el saldo ({saldo con 2 decimales}). Registre un pago de cancelación total.`

### validatePayoff(Prestamo $loan, Carbon $date, float $principal, LoanSchedule $schedule): void (protegido)

Para `CancelacionTotal`:

1. Si existe una cuota abierta con fecha anterior a `$date`: `fecha_pago` · `Antes de cancelar debe pagar las cuotas con vencimiento anterior a esa fecha (la cuota {n} del {fecha}).`
2. Obtiene `schedules->payoffQuote($loan, $date)`; si `abs($principal - cotizacion.principal) > 0.01`: `monto_principal` · `La cancelación total debe pagar el saldo a esa fecha ({principal cotizado}).`

El interés digitado no se valida contra la cotización (queda a criterio del usuario y se registra tal cual).

### fail(string $field, string $message): never (protegido)

Lanza `ValidationException::withMessages([$field => $message])`. Declarado `never`, por lo que el análisis estático entiende que corta el flujo.

---

## ReceiptDetailWriter

Ruta: `app/Services/Lending/ReceiptDetailWriter.php`.

Propósito: escribe los renglones de `detalle_pago` de un recibo. Cada tipo de recibo se descompone en tipos de pago del catálogo `tipos_pago`:

| Tipo de recibo | Renglones |
|---|---|
| Cuota completa | `PP` (monto_principal) + `PI` (monto_interes) |
| Parcial de solo interés | `PC` (monto_interes) |
| Solo principal | `PP` (monto_principal) |
| Adelanto de cuota | `PA` (monto_principal) |
| Cancelación total | `CT` (monto_principal) + `PI` (monto_interes) |

Si `monto_otros > 0`, se agrega un renglón adicional (tipo `OT` por omisión, o el tipo indicado).

### write(Recibo $receipt, ?int $installmentNumber, ?Carbon $installmentDate, ?TipoPago $otherType = null, ?string $otherDescription = null): array

- `$installmentNumber`, `$installmentDate`: cuota del plan a la que se aplica el pago y su vencimiento; se copian a **todos** los renglones (`numero_cuota`, `fecha_cuota`).
- `$otherType`: tipo del renglón de otros rubros; si es `null` se usa `OT`.
- `$otherDescription`: se guarda en `descripcion` solo del renglón de otros; los renglones de principal e interés llevan `descripcion = null`.
- Retorna `array<int, DetallePago>` con los renglones creados.

Funcionamiento:

1. `codesFor($receipt)` entrega el mapa código → monto.
2. Carga en una sola consulta los `TipoPago` de esos códigos más `OT`, indexados por `codigo`.
3. Arma las líneas, **omitiendo los montos `<= 0`** (por ejemplo, una cuota completa sin interés no genera renglón `PI`).
4. Crea cada `DetallePago` (`recibo_id`, `prestamo_id`, `tipo_pago_id`, `numero_cuota`, `fecha_cuota`, `fecha_pago` = fecha del recibo, `monto`, `descripcion`).

Casos borde: no abre transacción (la abre el llamador); si falta algún código en el catálogo `tipos_pago` (los siembra el seeder) el acceso `$types[$code]` falla con una clave indefinida; no verifica duplicados, así que llamarlo dos veces para el mismo recibo duplica los renglones.

### codesFor(Recibo $receipt): array<string, float> (protegido)

`match` sobre `$receipt->tipo` que devuelve el mapa de la tabla anterior (usa las constantes `TipoPago::PRINCIPAL`, `INTEREST`, `PARTIAL`, `ADVANCE`, `PAYOFF`). No incluye los otros rubros.

---

## RevisionRegistrar

Ruta: `app/Services/Lending/RevisionRegistrar.php`.

Propósito: registrar y anular revisiones de tasa y plazo. Una revisión cambia la tasa base, el spread o el plazo restante desde una fecha efectiva; el plan se reconstruye aplicándolas.

Constructor: `__construct(protected LoanScheduleService $schedules)`.

### register(Prestamo $loan, array $data, string $user): RevisionPrestamo

- `$data`: `fecha_efectiva` (obligatoria), y opcionales `tasa_interes`, `tasa_spread`, `plazo_restante_meses`, `observacion`.
- Normalización: `fecha_efectiva` con `startOfDay()`; tasas con `nullableFloat()` (4 decimales; vacío o `null` = no cambia); plazo como `int`, o `null` si falta o es cadena vacía.
- `validate()` y luego, en transacción:
  1. Crea la revisión en `prestamo_revisiones` (`fecha_efectiva`, `tasa_interes`, `tasa_spread`, `plazo_restante_meses`, `observacion`, `usuario`).
  2. `schedules->refreshSnapshot($loan)` (saldo, estado, próximo pago, vencimiento, disponible de la línea y `planpagos`).
- Retorna la `RevisionPrestamo`. Lanza `ValidationException`.

### void(RevisionPrestamo $revision, string $reason, string $user): RevisionPrestamo

Validaciones (campo `revision`), en este orden:

1. Ya anulada: `La revisión ya está anulada.`
2. No es la última activa: `Las revisiones se anulan de la más reciente a la más antigua. Anule primero la revisión #{id} del {fecha}.`

Transacción: `update(['anulado_at' => now(), 'anulado_por' => $user, 'motivo_anulacion' => $reason])` y `refreshSnapshot($loan)`. La revisión no se borra (las revisiones no tienen columna `estado`; se distinguen por `anulado_at`). Retorna la revisión. No exige que el préstamo esté activo.

### latestActive(Prestamo $loan): ?RevisionPrestamo

Última revisión activa (`revisiones()->active()->get()->last()`), la única anulable. `null` si no hay.

### validate(Prestamo $loan, Carbon $date, ?float $base, ?float $spread, ?int $term): void (protegido)

Errores en orden:

| # | Condición | Campo | Mensaje |
|---|---|---|---|
| 1 | Préstamo no activo | `fecha_efectiva` | `El sub préstamo está liquidado y no admite revisiones.` |
| 2 | Base, spread y plazo todos `null` | `tasa_interes` | `Indique la nueva tasa, el nuevo spread o el nuevo plazo restante.` |
| 3 | Base o spread `< 0` | `tasa_interes` | `Las tasas no pueden ser negativas.` |
| 4 | Plazo `< 1` | `plazo_restante_meses` | `El plazo restante debe ser de al menos 1 mes.` |
| 5 | `fecha < formalizacion` | `fecha_efectiva` | `La fecha no puede ser anterior a la formalización ({fecha}).` |
| 6 | `fecha <` fecha de la última revisión activa | `fecha_efectiva` | `La fecha no puede ser anterior a la última revisión ({fecha}).` |
| 7 | `fecha <` fecha de la última cuota ya cerrada del plan | `fecha_efectiva` | `La revisión no puede ser retroactiva a una cuota ya pagada ({fecha}).` |

La regla 7 toma las filas del plan que son cuotas (`isInstallment()`) y no están abiertas (pagadas o parcial de interés) y compara con la última. A diferencia de los recibos, la fecha igual a la formalización sí se admite (`lt`, no `lte`).

### nullableFloat(float|int|string|null $value): ?float (protegido)

Retorna `null` si el valor es `null` o cadena vacía; si no, `round((float) $value, 4)`.

### fail(string $field, string $message): never (protegido)

Lanza `ValidationException` con un mensaje para el campo (igual que en `ReceiptRegistrar`).

---

## LegacyPlanSync

Ruta: `app/Services/Lending/LegacyPlanSync.php`.

Propósito: mantener la tabla heredada `planpagos` alineada con el plan dinámico. La tabla conserva una fila por cuota con estado pendiente (`P`) o pagada (`C`). Esta clase escribe en ella las cuotas calculadas y las marca como pagadas o pendientes al registrar o anular recibos.

Constantes: `PAID = 'C'`, `PENDING = 'P'`.

Todos los métodos son tolerantes a que la tabla no exista (`Schema::hasTable('planpagos')`): en ese caso no hacen nada.

### markPaid(Recibo $receipt): void

Si `appliesTo($receipt)`, actualiza la fila de `planpagos` con `prestamo_id` y `numero_cuota` del recibo: `estado = 'C'`, `fecha_pago_real` = fecha del recibo (`Y-m-d`), `updated_at = now()`. Si la fila no existe no hace nada (el `update` afecta 0 filas); luego `reflect()` la crea.

### restore(Recibo $receipt): void

Inverso de `markPaid` para recibos anulados: `estado = 'P'`, `fecha_pago_real = null`, `updated_at = now()`. Aplica solo si `appliesTo()`.

### reflect(Prestamo $loan, LoanSchedule $schedule, bool $dryRun = false): array{inserted: int, updated: int, deleted: int}

Escribe en `planpagos` las cuotas calculadas del préstamo. Solo se consideran las filas del plan que son cuotas (los abonos y la cancelación no tienen fila en `planpagos`).

Reglas por cada cuota calculada (se compara con la fila existente de la misma `numero_cuota`):

1. Si la fila existente está en `C` y la cuota del plan **no** está `PAGADA` (aún sin recibo), se **omite** y no se toca.
2. Si no existe, se inserta (`prestamo_id`, `numero_cuota`, los valores de `valuesFor()`, `created_at`, `updated_at`) y cuenta en `inserted`.
3. Si existe y `differs()` es verdadero, se actualiza con `valuesFor()` más `updated_at` y cuenta en `updated`.
4. Las cuotas iguales no se escriben (no cuentan).

Después se eliminan las filas **pendientes** (`estado !== 'C'`) cuyo número ya no figura entre las cuotas del plan (por ejemplo, tras abonos que acortan el plan); cuenta en `deleted`. Las filas `C` sobrantes nunca se eliminan.

Modo de ejecución:

- Normal: todo dentro de `DB::transaction`.
- `$dryRun = true`: no escribe ni abre transacción, pero calcula y devuelve los mismos contadores (los insert/update se evitan con `$dryRun || DB::table(...)`).
- Si la tabla no existe devuelve `['inserted' => 0, 'updated' => 0, 'deleted' => 0]`.

Es idempotente: una segunda llamada con el mismo plan devuelve todo en cero.

### purge(Prestamo $loan): void

Borra todas las filas de `planpagos` del préstamo. Se usa al eliminar el préstamo (`PrestamoController`).

### paidInstallments(Prestamo $loan): int

Cuenta las filas del préstamo con `estado = 'C'`; `0` si la tabla no existe. La usa `PrestamoController` para saber cuántas cuotas están saldadas en la tabla heredada.

### valuesFor(ScheduleRow $row): array (protegido)

Columnas de `planpagos` para una cuota del plan:

| Columna | Valor |
|---|---|
| `fecha_pago` | fecha de la cuota (`Y-m-d`) |
| `monto_principal` | `$row->principal` |
| `monto_interes` | `$row->interest` |
| `tasa_interes` | tasa anual redondeada a 2 decimales |
| `saldo_principal` | principal si la cuota está abierta (vencida o pendiente); `0` si no |
| `saldo_interes` | interés si está abierta; `0` si no |
| `saldo_prestamo` | saldo del préstamo tras la cuota (`$row->balance`) |
| `estado` | `C` si la cuota es `PAGADA`; `P` en cualquier otro caso (incluida la parcial de solo interés) |
| `fecha_pago_real` | fecha del recibo si está pagada; `null` si no |
| `observaciones` | `Pago parcial: solo interés` si el estado es parcial de interés; `null` si no |

### differs(object $current, array $values): bool (protegido)

Compara una fila guardada con los valores calculados. Es `true` si algún campo entre `monto_principal`, `monto_interes`, `tasa_interes`, `saldo_principal`, `saldo_interes`, `saldo_prestamo` difiere en más de `0.004`, o si difieren `fecha_pago` (10 primeros caracteres), `estado`, `fecha_pago_real` (10 primeros caracteres) u `observaciones`.

### appliesTo(Recibo $receipt): bool (protegido)

`true` solo si el recibo es de tipo `CuotaCompleta`, tiene `numero_cuota` y existe la tabla `planpagos`. Los parciales de interés, abonos y cancelaciones no cierran filas del plan heredado.

---

# Servicios generales (`app/Services`)

## DashboardMetrics

Ruta: `app/Services/DashboardMetrics.php`.

Propósito: cifras del tablero, derivadas del plan reconstruido de cada préstamo activo (no de columnas guardadas). Todo es solo lectura.

Constructor: `__construct(protected LoanScheduleService $schedules)`.
Constante protegida: `AVAILABILITY_HORIZONS = [3, 6, 9, 12, 18, 24]` (meses de proyección por omisión).
Propiedad: `protected array $scheduleCache`, un caché por instancia indexado por fecha (`Y-m-d`), de modo que `summary()`, `creditAvailability()` y las listas de cuotas de una misma petición reconstruyen los planes una sola vez.

### summary(?Carbon $today = null): array

Retorna:

| Clave | Cálculo |
|---|---|
| `outstanding_balance` | suma de `outstandingPrincipal()` de los planes activos (2 decimales) |
| `active_loans` | cantidad de préstamos activos |
| `active_credit_lines` | cantidad de líneas activas |
| `credit_lines_limit` | suma de `monto_maximo` de las líneas activas |
| `overdue_installments` | cuotas vencidas (`overdueInstallments()`) de todos los planes |
| `overdue_amount` | suma de `total()` (principal + interés) de esas cuotas, 2 decimales |

### creditAvailability(?Carbon $today = null, array $horizons = self::AVAILABILITY_HORIZONS): array

Crédito disponible por banco y moneda, hoy y proyectado.

Funcionamiento:

1. `$today` se normaliza a `startOfDay()`; se obtienen los planes y se agrupan por `id_lineas_prestamos`.
2. Toma las líneas activas con su banco y las agrupa por `nombre_banco|moneda` (si la línea no tiene banco usa el nombre de la línea).
3. Por grupo: `limit` = suma de `monto_maximo`; `balance` = suma del saldo vigente de los planes de esas líneas; `available` = `limit - balance`.
4. Proyección por cada horizonte `m` (meses): `cutoff = today + m meses` (`addMonthsNoOverflow`); `amortizado` = suma del principal de las cuotas abiertas con fecha `<= cutoff`; `projected[m] = limit - max(balance - amortizado, 0)`. Es decir, cada proyección suma de vuelta el principal de las cuotas que vencen dentro del horizonte, sin pasar del límite.
5. Las filas se ordenan por `name`.
6. `totals`: una fila por moneda con la suma de `limit`, `balance`, `available` y de cada proyección.
7. `plan_ends_on`: la fecha de vencimiento más lejana (`maturityDate()`) entre todos los planes activos, o `null`.

Retorna `['horizons' => $horizons, 'rows' => Collection<object{name, currency, limit, balance, available, projected}>, 'totals' => Collection<object{currency, limit, balance, available, projected}>, 'plan_ends_on' => ?Carbon]`.

Observaciones: el disponible se calcula aquí (límite menos saldo de los planes) y no lee la columna `lineas_prestamos.disponible`. Los préstamos activos de líneas inactivas cuentan en `summary()` y en `plan_ends_on`, pero no aparecen en las filas de disponibilidad.

### pendingInstallments(int $limit = 8, ?Carbon $today = null): Collection

Las primeras `$limit` cuotas abiertas de todos los préstamos activos (vencidas y pendientes), ordenadas por fecha de vencimiento; al ser las vencidas anteriores a hoy, quedan primero. Cada elemento es un objeto `{loan_number, credit_line, installment_number, due_date (Y-m-d), amount, is_overdue}`.

### nextInstallment(?Carbon $today = null): ?object

La primera cuota abierta que **no** está vencida (próxima por vencer), con la misma forma de objeto; `null` si no hay.

### openInstallments(?Carbon $today): Collection (protegido)

Une las cuotas abiertas de todos los planes y las convierte a objetos con: `loan_number` (`numero_prestamo`), `credit_line` (nombre de la línea), `installment_number`, `due_date`, `amount` (`total()` = principal + interés), `is_overdue` (estado `VENCIDA`). Ordena por la cadena `due_date` + número de cuota con relleno a 4 dígitos + número de préstamo.

### plans(?Carbon $today): Collection (protegido)

Normaliza `$today` a `startOfDay()` y devuelve `schedules->activeSchedules($today)` memorizado en `$scheduleCache` por fecha.

---

## ActiveDirectoryAuthenticator

Ruta: `app/Services/ActiveDirectoryAuthenticator.php`. Usa la extensión `ldap` de PHP y `config/ldap.php`.

Propósito: autenticar usuarios contra Active Directory haciendo un bind como el propio usuario (`usuario@dominio`), por lo que no requiere cuenta de servicio; también resuelve el usuario de Windows que IIS entrega para el inicio de sesión único.

Configuración (`config/ldap.php`, variables de entorno entre paréntesis): `host` (`LDAP_HOST`), `port` (`LDAP_PORT`, 636), `use_ssl` (`LDAP_USE_SSL`), `verify_certificate` (`LDAP_VERIFY_CERTIFICATE`), `timeout` (`LDAP_TIMEOUT`, 5 s), `domain` (`LDAP_DOMAIN`), `netbios_domain` (`LDAP_NETBIOS_DOMAIN`, `CORP`), `base_dn` (`LDAP_BASE_DN`), `windows_sso` (`LDAP_WINDOWS_SSO`).

### authenticate(string $username, string $password): ?array

- Retorna `['username', 'name', 'email']` si las credenciales son válidas; `null` si son inválidas; lanza `RuntimeException` si no se puede contactar al controlador de dominio o el bind falla por otra causa.
- Normaliza el usuario con `normalizeUsername()`. Si queda vacío, o la contraseña es vacía, retorna `null` **sin consultar al directorio** (un bind LDAP con contraseña vacía sería un bind anónimo exitoso).
- `connect()` y `ldap_bind($conn, "{usuario}@{domain}", $password)`.
- Si el bind falla: con código LDAP 49 (credenciales inválidas) retorna `null`; con cualquier otro código lanza `RuntimeException("Active Directory bind failed: {mensaje}")`. `ldap_connect` no abre la conexión real, así que un servidor inaccesible aparece aquí como bind fallido distinto de 49.
- Si el bind funciona, retorna `fetchProfile()`.
- La conexión no se cierra explícitamente (termina con la petición). `LoginController::store` captura la excepción, la registra en el log y muestra "No fue posible contactar al directorio activo".

### windowsAccountName(Request $request): ?string

Cuenta de dominio del usuario de Windows autenticado en IIS.

- `null` si `ldap.windows_sso` está desactivado.
- Lee `REMOTE_USER` o, si está vacío, `AUTH_USER`.
- Si contiene `\`, separa dominio y usuario por la primera barra; si el dominio no coincide (sin distinguir mayúsculas) con `ldap.netbios_domain`, retorna `null`.
- Normaliza con `normalizeUsername()`; retorna `null` si queda vacío.
- No consulta al directorio: confía en lo que el servidor web entrega.

### emailFor(string $accountName): string

Correo único con el que se guarda la cuenta localmente: `"{cuenta}@{ldap.domain}"`.

### normalizeUsername(string $username): string

Quita espacios, el prefijo `DOMINIO\` (todo hasta la última `\`) y el sufijo `@dominio` (desde la última `@`), y devuelve el resultado en minúsculas y sin espacios. Ejemplos: `CORP\Jperez` → `jperez`; `JPerez@corp.obgroup.com` → `jperez`.

### connect(): Connection (protegido)

- Si `verify_certificate` es falso, desactiva globalmente la verificación del certificado (`LDAP_OPT_X_TLS_REQUIRE_CERT = NEVER`).
- Construye la URL `ldaps://host:port` (o `ldap://` si `use_ssl` es falso) con `ldap_connect`; si devuelve `false` lanza `RuntimeException('Invalid Active Directory connection settings.')`.
- Fija protocolo versión 3, referrals desactivados y el tiempo de espera de red `ldap.timeout`.

### fetchProfile(Connection $connection, string $accountName): array (protegido)

Arma `['username' => cuenta, 'name' => cuenta, 'email' => emailFor(cuenta)]` y trata de mejorar `name`: busca en `base_dn` con el filtro `(sAMAccountName=…)` (cuenta escapada con `ldap_escape` para filtros), pidiendo solo `displayName` y límite 1 resultado. Si la búsqueda falla o no devuelve nombre, conserva el nombre de cuenta. Nunca lanza por la búsqueda.

---

# Comandos artisan (`app/Console/Commands`)

Ambos usan los atributos `#[Signature]` y `#[Description]` de Laravel y terminan con `self::SUCCESS` salvo que se indique.

## ImportPaidInstallments

Ruta: `app/Console/Commands/ImportPaidInstallments.php`.

Firma: `php artisan lending:import-paid-installments {--dry-run}`

Descripción: importa como recibos de cuota completa las cuotas que `planpagos` ya marca como pagadas (`C`). Sirve para migrar el histórico heredado al modelo basado en recibos.

Opciones:

- `--dry-run`: muestra lo que se importaría sin escribir nada.

### handle(LoanScheduleService $schedules, ReceiptDetailWriter $details): int

1. Lee todas las filas de `planpagos` con `estado = 'C'`, ordenadas por `prestamo_id` y `numero_cuota`.
2. Para cada fila:
   - **Idempotencia**: si ya existe un recibo **activo** del mismo `prestamo_id` y `numero_cuota`, la cuenta como omitida (`skipped`) y sigue. Por eso el comando se puede ejecutar varias veces; un recibo anulado no cuenta como existente.
   - Fecha de pago: `fecha_pago_real`, o `fecha_pago` si aquella es nula (se usan los 10 primeros caracteres).
   - Observación: `Importado de planpagos`.
   - **Orden de fechas**: la fecha nunca puede ser anterior al recibo previo del préstamo. Se toma como referencia la mayor `fecha_pago` de los recibos activos del préstamo (memorizada en memoria y actualizada con cada importación); si la fecha real es menor, se usa la de referencia y la observación pasa a `Importado de planpagos (fecha real de pago: {fecha})`. Esta lógica se aplica también en simulación, así que el resultado del `--dry-run` refleja los ajustes reales.
   - Imprime una línea: `[simulación]` (con `--dry-run`) o `[importa]`, seguida de `préstamo {id} cuota {n}  {fecha}  principal {x}  interés {y}`.
   - Si no es simulación: crea el `Recibo` directamente (`tipo = CUOTA_COMPLETA`, `numero_cuota`, `fecha_pago`, `monto_principal` y `monto_interes` copiados de la fila, `observaciones`, `usuario = 'IMPORTACION'`, `estado = ACTIVO`) y escribe su detalle con `ReceiptDetailWriter::write()` usando la cuota y su `fecha_pago` programada. Anota el préstamo para refrescarlo.
3. Una vez procesadas todas las filas, y solo fuera de simulación, llama a `refreshSnapshot(Prestamo::findOrFail($id))` por cada préstamo importado, lo que recalcula saldo, estado, próximo pago, vencimiento, disponible de la línea y reconstruye `planpagos`.
4. Salida final: `Se importarían {n} recibos; {m} ya existían.` (simulación) o `Importados {n} recibos; {m} ya existían.`

Casos borde:

- No pasa por `ReceiptRegistrar`: no ejecuta sus validaciones ni `markPaid` (la fila ya está en `C`). No se abre una transacción global; si se interrumpe, lo ya creado queda y una nueva ejecución continúa donde quedó (omite lo existente).
- Los recibos importados no llevan `monto_otros`.
- Una fila de `planpagos` de un préstamo inexistente haría fallar la creación del recibo (o `findOrFail`).
- La importación considera filas `C` de préstamos en cualquier estado, no solo activos.
- Siempre retorna `SUCCESS`.

## RebuildPlanPagos

Ruta: `app/Console/Commands/RebuildPlanPagos.php`.

Firma: `php artisan lending:rebuild-planpagos {--dry-run} {--loan=}`

Descripción: escribe el plan de pagos calculado de los préstamos activos en la tabla heredada `planpagos`.

Opciones:

- `--dry-run`: simula; no escribe en `planpagos` ni en ninguna otra tabla, ni crea respaldo.
- `--loan=NUMERO`: limita el proceso al préstamo cuyo `numero_prestamo` coincide exactamente (solo entre los activos).

### handle(LoanScheduleService $schedules, LegacyPlanSync $legacyPlan): int

1. Si no existe la tabla `planpagos`: error `La tabla planpagos no existe.` y retorna `FAILURE`.
2. Selecciona los préstamos activos (filtrados por `--loan` si se indicó), ordenados por `numero_prestamo`. Si no hay ninguno: advertencia `No hay sub préstamos activos que procesar.` y retorna `SUCCESS`.
3. Fuera de simulación llama primero a `backupExistingRows()` (respaldo antes de reescribir nada).
4. Por cada préstamo calcula el plan con `scheduleFor()` y:
   - en simulación usa `LegacyPlanSync::reflect($loan, $schedule, dryRun: true)` (contadores reales sin escribir);
   - en ejecución usa `reflectAndRefresh()`.
5. Imprime por préstamo: `[simulación]` o `[reconstruye]` + `sub préstamo {numero}: {n} cuotas calculadas · {x} nuevas · {y} actualizadas · {z} eliminadas`, y acumula los totales.
6. Salida final: `Se harían: {a} nuevas, {b} actualizadas y {c} eliminadas en planpagos.` (simulación) o `Hecho: ...`. Retorna `SUCCESS`.

### reflectAndRefresh(LoanScheduleService $schedules, Prestamo $loan): array (protegido)

Cuenta las filas de `planpagos` del préstamo, ejecuta `refreshSnapshot($loan)` (que ya refleja el plan por sí mismo) y vuelve a contar. Deriva los contadores: `inserted = max(después - antes, 0)`, `deleted = max(antes - después, 0)`, `updated = min(antes, después)`.

Importante: en ejecución real `refreshSnapshot` además actualiza la fila del préstamo (`saldo_prestamo`, `estado`, `proximo_pago`, `vencimiento`) y el `disponible` de su línea, no solo `planpagos`. Y como `updated` se estima con el número de filas (no con los cambios reales), en ejecución real ese contador es una aproximación y no queda en cero al repetir; solo el modo simulación informa cambios exactos.

### backupExistingRows(array $loanIds): void (protegido)

Si los préstamos tienen filas en `planpagos`, las guarda (ordenadas por `prestamo_id` y `numero_cuota`) en `storage/app/backups/planpagos-YYYYMMDD-HHMMSS.json` (JSON con sangría y Unicode sin escapar; crea la carpeta si falta) e informa `Respaldo de {n} filas de planpagos en {ruta}`. Si no hay filas no crea archivo.

Idempotencia y seguridad: reutiliza las reglas de `LegacyPlanSync::reflect()`, es decir, no modifica filas `C` que aún no tienen recibo ni elimina filas `C`. Ejecutarlo de nuevo con el mismo estado del plan no produce cambios reales en la tabla (aunque genere un nuevo respaldo y el contador aproximado `updated`). Para restaurar basta usar el JSON del respaldo.
