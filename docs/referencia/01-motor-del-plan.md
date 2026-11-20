# Referencia 01 · Motor del plan de pagos

Referencia extraída del código real de `app/Services/Lending` y `app/Enums`. El plan de pagos **no se guarda**: `LoanScheduleBuilder` lo reconstruye completo cada vez que se pide, a partir de los términos del préstamo, las condiciones de cálculo, los recibos activos y las revisiones activas. No lee ni escribe en la base de datos (solo usa los modelos que recibe como argumentos).

Convenciones usadas en este documento:

- Las tasas anuales se manejan **en porcentaje** (por ejemplo `12.5`), nunca como fracción; se dividen entre 100 al calcular.
- Los importes se redondean a 2 decimales con `round()` en los puntos que se indican.
- `n` = número de períodos, `m` = `monthsPerPeriod` (meses por período), `r` = tasa de un período.

Resumen de clases:

| Clase | Ruta | Papel |
|---|---|---|
| `LoanScheduleBuilder` | `app/Services/Lending/LoanScheduleBuilder.php` | Algoritmo que construye el plan |
| `LoanSchedule` | `app/Services/Lending/LoanSchedule.php` | Resultado: filas + totales y consultas |
| `ScheduleRow` | `app/Services/Lending/ScheduleRow.php` | Una fila del plan (cuota, abono o cancelación) |
| `LoanConditions` | `app/Services/Lending/LoanConditions.php` | Condiciones de cálculo resueltas (préstamo o línea) |
| `ConvencionTasa` | `app/Enums/ConvencionTasa.php` | Nominal o efectiva |
| `DayCount` | `app/Enums/DayCount.php` | Base de días |
| `EstadoCuota` | `app/Enums/EstadoCuota.php` | Estado de una fila del plan |
| `ModalidadCuota` | `app/Enums/ModalidadCuota.php` | Forma de amortizar el principal |
| `TipoRecibo` | `app/Enums/TipoRecibo.php` | Tipo de recibo de pago |

---

## LoanScheduleBuilder

**Ruta:** `app/Services/Lending/LoanScheduleBuilder.php` · `final class`, namespace `App\Services\Lending`.

**Propósito.** Reconstruye desde cero el plan de pagos de un préstamo (sub préstamo) cada vez que se solicita. Combina el monto, plazo, tasa y fecha de formalización del `Prestamo`, las condiciones resueltas por `LoanConditions`, los `Recibo` activos y las `RevisionPrestamo` activas, y devuelve un `LoanSchedule`.

### Reglas generales del motor

- El interés se calcula sobre el **saldo pendiente**. Los eventos dentro de un período (abonos a principal, revisiones de tasa) parten el período en **segmentos**.
- Un recibo numerado (cuota completa o solo interés) **cierra** esa cuota con los importes realmente pagados.
- Un abono a principal baja el saldo desde su fecha. La cuota **no cambia**, por lo que el préstamo termina antes.
- Un recibo de solo interés difiere el principal: la cuota se **recalcula** sobre los períodos restantes (a partir del período siguiente).
- Una revisión de tasa/plazo recalcula la cuota **desde el período siguiente** al de su fecha efectiva.
- Un recibo de cancelación total cierra el préstamo en su fecha.
- Las cuotas sin pagar se **proyectan** como si se pagaran a tiempo.

### Constantes

| Constante | Valor | Uso |
|---|---|---|
| `MAX_PERIODS` | `1200` | Tope de períodos del bucle principal (protección contra bucles infinitos). |
| `EPSILON` | `0.005` | Tolerancia: el bucle sigue mientras `saldo > 0.005`. |

### `build()`

```php
public function build(Prestamo $loan, LineaPrestamo $line, iterable $receipts, iterable $revisions, ?Carbon $today = null): LoanSchedule
```

| Parámetro | Descripción |
|---|---|
| `$loan` | Préstamo. Se usan `formalizacion`, `monto_prestamo`, `tasa_interes`, `tasa_spread`, `plazo_meses`, `cuota_calculada`, más sus campos de condiciones. |
| `$line` | Línea de crédito de la que heredar condiciones. |
| `$receipts` | `iterable<Recibo>`. Se descartan los no activos (`Recibo::isActive()`). |
| `$revisions` | `iterable<RevisionPrestamo>`. Se descartan las anuladas (`isActive()`: `anulado_at === null`). |
| `$today` | Fecha de "hoy" (por defecto `today()`); se normaliza a inicio del día. Solo decide `Vencida` vs `Pendiente` y la tasa vigente. |

**Devuelve:** `LoanSchedule` con las filas ordenadas, el monto del préstamo redondeado, si hubo cancelación, la tasa vigente en `$today` y las condiciones.

**Preparación**

1. `$conditions = LoanConditions::resolve($loan, $line)`; `$months = monthsPerPeriod`; `$start = formalizacion` (inicio del día).
2. Recibos activos ordenados por `fecha_pago`, luego `id` (ascendente). Se separan en:
   - **recibos de cuota**: `tipo->isInstallmentPayment()` (CuotaCompleta, ParcialInteres), indexados por `numero_cuota`;
   - **abonos**: `tipo->isPrincipalPrepayment()` (SoloPrincipal, AdelantoCuota);
   - **cancelación**: el primero con `TipoRecibo::CancelacionTotal`.
3. Revisiones activas ordenadas por `fecha_efectiva`, luego `id`.
4. Estado inicial:
   - `saldo = round(monto_prestamo, 2)`
   - `globo = round(porcentaje_global / 100 × saldo, 2)` (calculado sobre el monto original)
   - `base = tasa_interes`, `spread = tasa_spread`, `tasa = base + spread`
   - `restantes = max(ceil(plazo_meses / m), 1)` períodos
   - `graciaRestante = min(gracePeriods(), restantes − 1)`
   - `contractualPending = cuota_calculada > 0 && modality->usesContractualInstallment()`

**Bucle** (`for número = 1; saldo > EPSILON && número <= 1200`). En cada iteración:

1. **Vencimiento:** `due = formalizacion + número × m meses` con `addMonthsNoOverflow` (se calcula siempre desde la fecha de formalización, no desde el vencimiento anterior; si el día no existe en el mes se usa el último día).
2. **Revisiones pendientes:** mientras la siguiente revisión tenga `fecha_efectiva <= previousDue` (fecha de vencimiento anterior; en la primera iteración, la formalización) se aplican: `tasa_interes` y `tasa_spread` reemplazan los vigentes solo si no son `null`; si `plazo_restante_meses` no es `null`, `restantes = max(ceil(plazo_restante_meses / m), 1)` y `graciaRestante = min(graciaRestante, restantes − 1)`. Se marca `needsRecalculation = true`. Una revisión cuya fecha cae **dentro** del período afecta el interés de ese período por segmentos (ver `accrue()`), pero la cuota se recalcula en el período siguiente.
3. **Cálculo de la cuota:** solo si `graciaRestante === 0` y (`cuota` aún es `null` o `needsRecalculation`): se llama `amortization(conditions, saldo, tasa, restantes, min(globo, saldo))` y se obtienen `[cuota, principalFijo]`. Si `contractualPending`, la cuota se sustituye **una sola vez** por `round(cuota_calculada, 2)` (la cuota contractual del préstamo para Constante/Creciente). Luego `contractualPending = false`, `needsRecalculation = false`, `growthIndex = 0`. Durante la gracia la cuota permanece `null` (se usa 0.0 al calcular principal).
4. **Cancelación total en el período:** si existe recibo de cancelación y `payoffFallsInPeriod()` es verdadero:
   - se acumula interés desde `previousDue` hasta `payoff->fecha_pago` con `accrue()`;
   - se añaden las filas de abonos intermedios y una fila `KIND_PAYOFF` con estado `Cancelacion`: `principal = monto_principal` y `interest = monto_interes` del recibo, `balance = max(round(saldoALaFecha − monto_principal, 2), 0)`, `scheduledPrincipal = saldoALaFecha`, `scheduledInterest = round(interésAcumulado, 2)`, `number = null`;
   - `saldo = 0`, `isClosed = true` y se sale del bucle. No se genera fila de cuota para ese período.
5. **Interés del período:** `accrue(... previousDue → due ...)`; `interes = round(interés, 2)`; se añaden las filas de abonos intermedios.
6. **Principal programado:** `scheduledPrincipal(...)` con el saldo al vencimiento.
7. **Cuota con recibo** (`installmentReceipts[número]`):
   - `CuotaCompleta` → estado `Pagada`, principal = `monto_principal` del recibo;
   - `ParcialInteres` → estado `ParcialInteres`, principal = 0 y `needsRecalculation = true` (recalcula la cuota en el período siguiente);
   - el interés de la fila es `monto_interes` del recibo (lo realmente pagado); `scheduledPrincipal`/`scheduledInterest` conservan lo que el plan esperaba.
8. **Cuota sin recibo:** estado `Vencida` si `due < today`, si no `Pendiente`; principal = principal programado; interés = interés calculado.
9. **Fila de cuota** (`KIND_INSTALLMENT`): `saldoDespués = max(round(saldoAlVencimiento − principal, 2), 0)`.
10. **Abonos en la fecha de vencimiento:** cada abono con `fecha_pago == due` genera, después de la cuota, una fila `KIND_PREPAYMENT` (estado `Abono`) por `min(monto_principal, saldoDespués)`; se omiten si el monto resulta ≤ 0. Los abonos con fecha estrictamente dentro del período no pasan por aquí sino por `accrue()`.
11. **Avance:** `saldo = saldoDespués`; `restantes = max(restantes − 1, 1)`; `previousDue = due`; si `graciaRestante > 0` se decrementa, si no `growthIndex++`.

Al terminar devuelve `new LoanSchedule(sortRows(filas), round(monto_prestamo, 2), isClosed, rateInForce(loan, revisions, today), conditions)`.

**Casos borde**

- Un abono ya aplicado reduce el saldo pero **no** la cuota: la cuota nivelada solo se recalcula por revisión o por recibo de solo interés; por eso el plan termina antes.
- En el último período (`restantes <= 1`) el principal programado es todo el saldo (incluye el globo).
- Un recibo de cuota completa se respeta aunque su importe difiera del plan: el saldo se calcula con el principal realmente pagado, y `ScheduleRow::hasVariance()` lo detecta.
- Una revisión con fecha ≤ la formalización se aplica antes de la primera cuota.
- La cancelación en la fecha de vencimiento de una cuota **ya pagada** no cae en ese período (cae en el siguiente si su fecha es posterior); si la cuota no está pagada, cancela en ese período sin generar la cuota.
- Fechas de cancelación anteriores a la formalización nunca cumplen `payoffFallsInPeriod()`.
- Si se superan 1200 períodos el bucle termina sin completar el plan.

### `payoffQuote()`

```php
public function payoffQuote(Prestamo $loan, LineaPrestamo $line, iterable $receipts, iterable $revisions, Carbon $date): array
```

**Devuelve:** `array{principal: float, interest: float}`: lo necesario para cancelar el préstamo en `$date`.

**Cómo funciona:** crea un `Recibo` **no guardado** (`CancelacionTotal`, `fecha_pago = $date`, montos 0, estado activo), lo agrega a los recibos recibidos y llama a `build()` usando `$date` como "hoy". Busca la fila `KIND_PAYOFF` y devuelve su `scheduledPrincipal` (saldo adeudado a esa fecha) y `scheduledInterest` (interés acumulado a esa fecha, redondeado a 2 decimales). Si el plan no genera fila de cancelación (por ejemplo, la fecha no cae en ningún período abierto o el préstamo ya está saldado), devuelve `0.0` en ambos. Si ya existe un recibo de cancelación real que se ordene antes, es ese el que se usa en lugar de la sonda.

### `rateInForce()` (privado)

```php
private function rateInForce(Prestamo $loan, Collection $revisions, Carbon $date): float
```

Tasa anual en porcentaje vigente en `$date`: parte de `tasa_interes + tasa_spread` del préstamo y aplica en orden todas las revisiones con `fecha_efectiva <= $date`, sustituyendo base y/o spread cuando no son `null`. Devuelve `base + spread`.

### `periodRate()` (privado)

```php
private function periodRate(LoanConditions $conditions, float $annualRate): float
```

Tasa de **un período medio**, usada solo para dimensionar la cuota. Con `fracciónAño = m / 12`:

| Convención | Fórmula |
|---|---|
| `Efectiva` | `r = (1 + tasa/100) ^ (m/12) − 1` |
| `Nominal` | `r = tasa/100 × (m/12) × f`, con `f = 365/360` si la base es Actual/360 (`usesActualDays()` y `basis() == 360`), `f = 1` en cualquier otro caso (Actual/365 y 30/360) |

El factor 365/360 refleja cómo el banco dimensiona la cuota nivelada con base Actual/360.

### `amortization()` (privado)

```php
private function amortization(LoanConditions $conditions, float $balance, float $annualRate, int $periods, float $balloon): array
```

**Devuelve:** `array{0: float, 1: float}` = `[cuota, principalFijo]`, ambos con `round(…, 2)`.

Con `r = periodRate()`, `n = max($periods, 1)`:

- `globoPresente = globo / (1 + r)^n`
- `financiado = saldo − globoPresente`

| Modalidad | Cuota | Principal fijo |
|---|---|---|
| `Bullet` | `0.0` | `0.0` |
| `Decreciente` | `0.0` | `(saldo − globo) / n` |
| `Constante` | si `r > 0`: `financiado × r / (1 − (1 + r)^(−n))`; si `r = 0`: `(saldo − globo) / n` | `0.0` |
| `Creciente` | `graduatedFirstInstallment(financiado, r, crecimiento/100, n)` | `0.0` |

### `graduatedFirstInstallment()` (privado)

```php
private function graduatedFirstInstallment(float $financed, float $periodRate, float $growth, int $periods): float
```

Primera cuota de una serie que crece `g = growth` cada período y amortiza `$financed`:

- si `|r − g| < 1e-12`: `financiado × (1 + r) / n`
- en otro caso: `financiado × (r − g) / (1 − ((1 + g) / (1 + r))^n)`

### `scheduledPrincipal()` (privado)

```php
private function scheduledPrincipal(LoanConditions $conditions, float $installment, float $fixedPrincipal, int $growthIndex, float $interest, float $balance, int $remaining, int $graceLeft): float
```

Principal que el plan espera pagar en la cuota actual. `$balance` es el saldo al vencimiento (ya con los abonos intermedios).

1. Si `graceLeft > 0` → `0.0` (período de gracia: solo interés).
2. Si `remaining <= 1` → todo el `$balance` (último período; incluye el globo).
3. En otro caso, según la modalidad:

| Modalidad | Principal |
|---|---|
| `Bullet` | `0` |
| `Decreciente` | `principalFijo` |
| `Constante` | `max(cuota − interés, 0)` |
| `Creciente` | `max(cuota × (1 + crecimiento/100)^growthIndex − interés, 0)` |

El resultado es `round(min(principal, saldo), 2)`. `growthIndex` cuenta los períodos desde el último recálculo de la cuota y no avanza durante la gracia.

### `payoffFallsInPeriod()` (privado)

```php
private function payoffFallsInPeriod(Recibo $payoff, Carbon $previousDue, Carbon $due, bool $installmentIsPaid): bool
```

Indica si el recibo de cancelación cierra el préstamo dentro del período que termina en `$due`:

- `false` si `fecha_pago < previousDue`;
- `true` si `fecha_pago < due`;
- `true` si `fecha_pago == due` **y** la cuota de ese período no está pagada (`! $installmentIsPaid`);
- `false` en los demás casos.

### `accrue()` (privado)

```php
private function accrue(LoanConditions $conditions, Carbon $from, Carbon $to, Carbon $periodStart, Carbon $periodEnd, float $balance, float $base, float $spread, Collection $prepayments, Collection $revisions): array
```

Interés acumulado de `$from` a `$to`, partiendo el tramo en cada abono y revisión cuya fecha esté **estrictamente dentro** (`> from` y `< to`).

**Devuelve:** `array{0: float, 1: float, 2: float, 3: list<ScheduleRow>}` = `[interés (sin redondear), saldo en $to, tasa (base + spread) en $to, filas de abonos]`.

Funcionamiento:

1. Se reúnen las fechas de abonos y de revisiones estrictamente dentro, sin duplicados por día (`toDateString`), ordenadas.
2. `$periodStart`/`$periodEnd` son el período completo (se usan solo para la base 30/360; en una cancelación, `$to` es la fecha del recibo pero el período sigue siendo el completo).
3. Para cada punto: se acumula `saldo × interestFactor(tasa vigente, cursor → punto)`; luego, **primero** se aplican las revisiones de esa fecha (cambia base/spread) y **después** los abonos: `monto = min(monto_principal, saldo)`, se omite si ≤ 0, `saldo = round(saldo − monto, 2)` y se crea una fila `KIND_PREPAYMENT` (estado `Abono`, tasa tras la revisión, `scheduledPrincipal = monto`, interés 0).
4. Al final se acumula el último tramo hasta `$to`.

No tiene efectos secundarios fuera de sus valores de retorno (la tasa y el saldo modificados son locales).

### `interestFactor()` (privado)

```php
private function interestFactor(LoanConditions $conditions, float $annualRate, Carbon $from, Carbon $to, Carbon $periodStart, Carbon $periodEnd): float
```

Interés por unidad de saldo entre dos fechas, según la base de días y la convención de tasa.

| Base | `fracciónAño` (nominal) | `fracciónEfectiva` |
|---|---|---|
| Actual/360 | `díasReales / 360` | `díasReales / 365` |
| Actual/365 | `díasReales / 365` | `díasReales / 365` |
| 30/360 | `days360(from, to) / max(days360(periodStart, periodEnd), 1) × (m / 12)` | igual a la nominal |

`díasReales = DayCount::actualDays(from, to)`. En 30/360 un período completo vale exactamente `m/12` de año y los segmentos internos se reparten proporcionalmente.

Resultado:

- `Efectiva`: `(1 + tasa/100) ^ fracciónEfectiva − 1`
- `Nominal`: `tasa/100 × fracciónAño`

### `days360()` (privado)

```php
private function days360(Carbon $from, Carbon $to): int
```

Días entre dos fechas en base 30/360: `360 × (Δaños) + 30 × (Δmeses) + (díaHasta − díaDesde)`, con `díaDesde = min(día de from, 30)` y `díaHasta = 30` cuando el día de `to` es 31 y `díaDesde` es 30 (si no, el día real de `to`). No ajusta fin de febrero.

### `sortRows()` (privado)

```php
private function sortRows(Collection $rows): Collection
```

Ordena las filas por la clave de texto `fecha(Ymd) + orden del tipo + número de cuota con 4 dígitos`. Orden del tipo en el mismo día: cuota (0), abono (1), cancelación (2). Las filas sin número usan `0`. Devuelve la colección reindexada (`values()`).

---

## LoanSchedule

**Ruta:** `app/Services/Lending/LoanSchedule.php` · `final class`.

**Propósito.** Objeto de solo lectura con el plan reconstruido: las filas cronológicas y métodos de consulta (totales pagados, saldo, cuotas abiertas, vencimiento).

### Constructor

```php
public function __construct(
    public readonly Collection $rows,            // Collection<int, ScheduleRow>, cronológicas
    public readonly float $loanAmount,
    public readonly bool $hasPayoff,
    public readonly float $currentAnnualRate,    // porcentaje vigente hoy
    public readonly ?LoanConditions $conditions = null,
)
```

| Propiedad | Significado |
|---|---|
| `rows` | Filas ordenadas (cuotas, abonos, cancelación). |
| `loanAmount` | Monto original del préstamo, redondeado a 2 decimales. |
| `hasPayoff` | `true` si el plan se cerró por un recibo de cancelación total. |
| `currentAnnualRate` | Tasa anual (base + spread) vigente en la fecha de "hoy" del cálculo. |
| `conditions` | Condiciones usadas; opcional. |

### Métodos

| Método | Devuelve | Funcionamiento |
|---|---|---|
| `paidPrincipal(): float` | Principal pagado | Suma `principal` de las filas con estado `Pagada`, `ParcialInteres`, `Abono` o `Cancelacion`, redondeada a 2 decimales. (En `ParcialInteres` el principal es 0.) |
| `paidInterest(): float` | Interés pagado | Suma `interest` de las filas cuyo estado **no** está abierto (`!isOpen()`) y **no** es `Abono`; redondeada a 2. Incluye por tanto el interés de la fila de cancelación. |
| `isSettled(): bool` | Saldado | `hasPayoff` o `round(loanAmount − paidPrincipal(), 2) <= 0.005`. |
| `outstandingPrincipal(): float` | Principal pendiente hoy | `0.0` si `isSettled()`; si no, `round(loanAmount − paidPrincipal(), 2)`. |
| `openInstallments(): Collection<ScheduleRow>` | Cuotas por pagar | Filas con `ScheduleRow::isOpen()` (cuota con estado `Vencida` o `Pendiente`), reindexadas; las vencidas quedan primero por el orden cronológico. |
| `overdueInstallments(): Collection<ScheduleRow>` | Cuotas vencidas | Cuotas (`isInstallment()`) con estado `Vencida`. |
| `nextOpenInstallment(): ?ScheduleRow` | Primera cuota abierta | `openInstallments()->first()`; `null` si no hay. |
| `nextUpcomingInstallment(): ?ScheduleRow` | Primera cuota aún no vencida | Primera cuota con estado `Pendiente`; `null` si no hay. |
| `maturityDate(): ?Carbon` | Fecha de vencimiento final | Fecha de la última fila que no sea abono (la última cuota o la cancelación); `null` si no hay filas. |
| `currentInstallmentAmount(): float` | Cuota vigente | `total()` de `nextOpenInstallment()`, o `0.0` si no hay cuota abierta. |

---

## ScheduleRow

**Ruta:** `app/Services/Lending/ScheduleRow.php` · `final class`.

**Propósito.** Una línea del plan reconstruido: una cuota, un abono a principal o la cancelación final. Es inmutable (propiedades `readonly`).

### Constantes

| Constante | Valor | Significado |
|---|---|---|
| `KIND_INSTALLMENT` | `'cuota'` | Cuota del plan. |
| `KIND_PREPAYMENT` | `'abono'` | Abono a principal fuera de cuota. |
| `KIND_PAYOFF` | `'cancelacion'` | Cancelación total. |

### Constructor

```php
public function __construct(string $kind, ?int $number, Carbon $date, EstadoCuota $status, float $annualRate,
    float $principal, float $interest, float $balance, float $scheduledPrincipal, float $scheduledInterest, ?Recibo $receipt = null)
```

| Propiedad | Significado |
|---|---|
| `kind` | Una de las constantes `KIND_*`. |
| `number` | Número de cuota; `null` para abonos y cancelación. |
| `date` | Fecha de la fila (vencimiento, fecha del abono o de la cancelación). |
| `status` | `EstadoCuota` de la fila. |
| `annualRate` | Tasa anual en porcentaje aplicable (base + spread). |
| `principal` | Principal **pagado** si la fila está cerrada; **programado** si está abierta. |
| `interest` | Interés pagado si está cerrada; programado si está abierta. |
| `balance` | Saldo de principal después de esta fila. |
| `scheduledPrincipal` | Lo que el plan esperaba. En la cancelación: el saldo adeudado en esa fecha. |
| `scheduledInterest` | Lo que el plan esperaba. En la cancelación: el interés acumulado hasta esa fecha. |
| `receipt` | `Recibo` que originó la fila, si lo hay. |

### Métodos

| Método | Devuelve | Funcionamiento |
|---|---|---|
| `total(): float` | Total de la fila | `round(principal + interest, 2)`. |
| `isInstallment(): bool` | Es cuota | `kind === KIND_INSTALLMENT`. |
| `isOpen(): bool` | Cuota por pagar | `isInstallment()` y `status->isOpen()` (Vencida o Pendiente). |
| `hasVariance(): bool` | Difiere de lo esperado | `false` si no es cuota o está abierta; en otro caso `true` si `abs(principal − scheduledPrincipal) > 0.01` o `abs(interest − scheduledInterest) > 0.01`. |

---

## LoanConditions

**Ruta:** `app/Services/Lending/LoanConditions.php` · `final class`.

**Propósito.** Agrupa las condiciones de cálculo de un sub préstamo. Cada condición se define en la línea de crédito y el préstamo puede sobrescribirla; un valor `null` en el préstamo significa "heredar de la línea".

### Constructor

```php
public function __construct(
    public readonly ModalidadCuota $modality,
    public readonly DayCount $dayCount,
    public readonly ConvencionTasa $rateConvention,
    public readonly int $monthsPerPeriod,
    public readonly int $graceMonths,
    public readonly float $balloonPercent,
    public readonly float $growthPercent,
    public readonly string $paymentForm,
)
```

| Propiedad | Origen (columna) | Significado |
|---|---|---|
| `modality` | `modalidad_cuota` | Forma de amortizar. |
| `dayCount` | `calendario` | Base de días. |
| `rateConvention` | `tipo_cuota` | Nominal o efectiva. |
| `monthsPerPeriod` | `modalidad_pago` | Meses por período de pago. |
| `graceMonths` | `meses_gracia` | Meses de gracia (solo interés). |
| `balloonPercent` | `porcentaje_global` | % del monto que se paga al final (cuota global/globo). |
| `growthPercent` | `variacion_porcentual` | % de crecimiento por período (modalidad Creciente). |
| `paymentForm` | `forma_pago` | Forma de pago; por defecto `'VENCIMIENTO'`. `LoanScheduleBuilder` no la utiliza en el cálculo. |

### `resolve()`

```php
public static function resolve(Prestamo $loan, LineaPrestamo $line): self
```

**Devuelve:** una instancia con cada condición tomada de `$loan->{columna} ?? $line->{columna}`.

Reglas de conversión:

- `monthsPerPeriod = max(intdiv(12, pagosPorAño), 1)`, donde `pagosPorAño = LineaPrestamo::PAYMENTS_PER_YEAR[modalidad_pago] ?? 12`. Tabla: `MENSUAL` 12 (1 mes), `TRIMESTRAL` 4 (3), `SEMESTRAL` 2 (6), `ANUAL` 1 (12), `UNICO` 1 (12). Un valor desconocido equivale a mensual.
- `modality = ModalidadCuota::fromCode(...)` (por defecto Constante); `dayCount = DayCount::fromCalendar(...)` (por defecto 30/360); `rateConvention = ConvencionTasa::fromCode(...)` (por defecto Nominal).
- `graceMonths`, `balloonPercent` y `growthPercent` se convierten a número y se limitan a `>= 0` (`max(..., 0)`).
- `paymentForm = (string) (valor ?? 'VENCIMIENTO')`.

### `gracePeriods()`

```php
public function gracePeriods(): int
```

Número de períodos iniciales en los que solo se paga interés: `intdiv(graceMonths, monthsPerPeriod)` (división entera; los meses sobrantes no cuentan).

### `describe()`

```php
public function describe(): string
```

Descripción de una línea para pantallas. Une con `' · '`:

1. `modality->label()`, `dayCount->label()`, `rateConvention->label()`;
2. `'pago '` + período: `mensual` (1), `trimestral` (3), `semestral` (6), `anual` (cualquier otro);
3. si `graceMonths > 0`: `gracia de N meses`;
4. si `balloonPercent > 0`: `cuota global X %`;
5. si la modalidad es `Creciente`: `crece X % por período`.

Los porcentajes se formatean con 4 decimales y se les quitan los ceros finales y el punto sobrante. Nota: el comentario del método usa el ejemplo "Cuota nivelada · Actual/360 · mensual", pero el texto real usa las etiquetas completas de los enums.

---

## ConvencionTasa

**Ruta:** `app/Enums/ConvencionTasa.php` · `enum ConvencionTasa: string`.

**Propósito.** Define cómo la tasa anual se convierte en el interés de un período (columna `tipo_cuota`).

| Caso | Valor | Descripción |
|---|---|---|
| `Nominal` | `'NOMINAL'` | Tasa nominal simple: `interés = saldo × tasa × fracción del año`. |
| `Efectiva` | `'EFECTIVA'` | Tasa efectiva anual: `interés = saldo × ((1 + tasa)^fracción − 1)`. |

| Método | Devuelve | Funcionamiento |
|---|---|---|
| `static fromCode(?string $code): self` | Caso | `tryFrom((string) $code)`; si es `null` o desconocido, `Nominal`. |
| `label(): string` | Texto | `Nominal` → "Tasa nominal (interés simple)"; `Efectiva` → "Tasa efectiva anual (capitalizable)". |

---

## DayCount

**Ruta:** `app/Enums/DayCount.php` · `enum DayCount: string`.

**Propósito.** Base de días para convertir los días de un período en fracción de año. Los valores son los códigos de `calendario` guardados en `lineas_prestamos` y `prestamos`.

| Caso | Valor | Descripción |
|---|---|---|
| `Actual360` | `'365/360'` | Días reales entre 360 (típico de préstamos en dólares: SOFR, Prime). |
| `Actual365` | `'365/365'` | Días reales entre 365 (común en colones y bancos locales). |
| `Thirty360` | `'360/360'` | Mes comercial de 30 días sobre año de 360. |

| Método | Devuelve | Funcionamiento |
|---|---|---|
| `static fromCalendar(?string $code): self` | Caso | `tryFrom((string) $code)`; si es `null`, vacío o desconocido, `Thirty360`. |
| `label(): string` | Texto | "365/360 · Actual/360 (días reales sobre 360)", "365/365 · Actual/365 (días reales sobre 365)" o "360/360 · 30/360 (mes comercial de 30 días)". |
| `usesActualDays(): bool` | Bool | `true` salvo en `Thirty360`. |
| `basis(): int` | Divisor anual | `365` para `Actual365`; `360` para `Actual360` y `Thirty360`. |
| `static actualDays(Carbon $from, Carbon $to): int` | Días | Días enteros entre ambas fechas (cada una normalizada a inicio del día), con signo: negativo si `$to < $from`. Redondea con `round()`. |

---

## EstadoCuota

**Ruta:** `app/Enums/EstadoCuota.php` · `enum EstadoCuota: string`.

**Propósito.** Estado de una fila del plan de pagos (cuota, abono o cancelación).

| Caso | Valor | `label()` | `badgeClass()` | `isOpen()` |
|---|---|---|---|---|
| `Pagada` | `'PAGADA'` | Pagada | `badge-success` | no |
| `ParcialInteres` | `'PARCIAL'` | Solo interés | `badge-info` | no |
| `Vencida` | `'VENCIDA'` | Vencida | `badge-danger` | **sí** |
| `Pendiente` | `'PENDIENTE'` | Pendiente | `badge-neutral` | **sí** |
| `Abono` | `'ABONO'` | Abono a principal | `badge-info` | no |
| `Cancelacion` | `'CANCELACION'` | Cancelación total | `badge-success` | no |

| Método | Devuelve | Funcionamiento |
|---|---|---|
| `label(): string` | Texto | Etiqueta en español de la tabla. |
| `badgeClass(): string` | Clase CSS | Modificador del componente badge: `badge-success` (Pagada, Cancelacion), `badge-info` (ParcialInteres, Abono), `badge-danger` (Vencida), `badge-neutral` (Pendiente). |
| `isOpen(): bool` | Bool | `true` solo para `Vencida` y `Pendiente` (cuota que aún debe pagarse). |

Quién asigna cada estado en `build()`: `Pagada`/`ParcialInteres` según el tipo de recibo de la cuota; `Vencida`/`Pendiente` según `due < today`; `Abono` y `Cancelacion` en filas de abono y de cancelación.

---

## ModalidadCuota

**Ruta:** `app/Enums/ModalidadCuota.php` · `enum ModalidadCuota: string`.

**Propósito.** Forma en que se amortiza el principal (columna `modalidad_cuota`).

| Caso | Valor | Descripción |
|---|---|---|
| `Constante` | `'CONSTANTE'` | Cuota nivelada (sistema francés): pago constante de principal más interés. |
| `Decreciente` | `'DECRECIENTE'` | Amortización lineal (sistema alemán): principal constante, cuota decreciente. |
| `Bullet` | `'BULLET'` | Solo intereses durante todo el plazo; el principal se paga al vencimiento. |
| `Creciente` | `'CRECIENTE'` | Cuota graduada: crece un porcentaje fijo cada período. |

| Método | Devuelve | Funcionamiento |
|---|---|---|
| `static fromCode(?string $code): self` | Caso | `tryFrom((string) $code)`; si es `null` o desconocido, `Constante`. |
| `label(): string` | Texto | "Cuota nivelada (constante)", "Amortización lineal (decreciente)", "Solo intereses (bullet)", "Cuota creciente (graduada)". |
| `usesContractualInstallment(): bool` | Bool | `true` para `Constante` y `Creciente`: la cuota guardada en `cuota_calculada` se toma como cuota inicial en lugar de la calculada. |

---

## TipoRecibo

**Ruta:** `app/Enums/TipoRecibo.php` · `enum TipoRecibo: string`.

**Propósito.** Tipo de recibo de pago; determina cómo afecta al plan.

| Caso | Valor | `code()` | `label()` | `isInstallmentPayment()` | `isPrincipalPrepayment()` |
|---|---|---|---|---|---|
| `CuotaCompleta` | `'CUOTA_COMPLETA'` | 1 | Cuota completa (principal e interés) | sí | no |
| `ParcialInteres` | `'PARCIAL_INTERES'` | 2 | Cuota parcial (solo interés) | sí | no |
| `SoloPrincipal` | `'SOLO_PRINCIPAL'` | 3 | Solo pago a principal | no | sí |
| `AdelantoCuota` | `'ADELANTO_CUOTA'` | 4 | Adelanto de cuota | no | sí |
| `CancelacionTotal` | `'CANCELACION_TOTAL'` | 5 | Pago de cancelación total | no | no |

| Método | Devuelve | Funcionamiento |
|---|---|---|
| `code(): int` | Código numérico | 1 a 5 según la tabla. |
| `label(): string` | Texto | Etiqueta en español de la tabla. |
| `isInstallmentPayment(): bool` | Bool | `true` para `CuotaCompleta` y `ParcialInteres`: recibos que liquidan una cuota numerada del plan. |
| `isPrincipalPrepayment(): bool` | Bool | `true` para `SoloPrincipal` y `AdelantoCuota`: reducen el saldo fuera de las cuotas regulares. |

`CancelacionTotal` no pertenece a ninguno de los dos grupos: `build()` lo trata aparte (primer recibo con ese tipo, ordenado por fecha e id).
