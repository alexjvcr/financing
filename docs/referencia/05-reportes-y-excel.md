# Referencia 05 · Reportes y exportación a Excel

Referencia extraída del código real. Cubre el módulo del menú **Reportes**: la clase base `Report`, el registro `ReportCatalog`, el reporte `DebtPositionReport` (posición consolidada de deuda), el escritor de libros Excel `XlsxWriter`, el controlador `ReportController` y las vistas `resources/views/reportes/*.blade.php`. Termina con la guía para agregar un reporte nuevo.

## Visión general

Flujo de una petición (todas las rutas están dentro del grupo `auth` de `routes/web.php`):

| Ruta | Nombre | Acción | Resultado |
|---|---|---|---|
| `GET /reportes` | `reportes.index` | `ReportController@index` | Vista `reportes.index` con el menú completo (listos y anunciados). |
| `GET /reportes/{slug}` | `reportes.show` | `ReportController@show` | Vista del reporte (`$report->view()`) con filtros y datos. |
| `GET /reportes/{slug}/excel` | `reportes.excel` | `ReportController@download` | Descarga `.xlsx` con los mismos datos y filtros. |

`{slug}` está restringido por la ruta a `[a-z0-9-]+`.

```
query string ──> Report::filters() ──> Report::build($filters) ──> array $data
                                                   │                    │
                                       vista reportes.{slug}     Report::workbook($data)
                                       (pantalla)                 └─> XlsxWriter::save($ruta)
```

Principios del diseño:

- La pantalla y el Excel **comparten la misma matriz `$data`** (`build()`), de modo que nunca difieren.
- `build()` no depende de la petición HTTP: recibe filtros ya normalizados y una fecha de corte opcional (`$today`), lo que permite probarlo con fechas fijas.
- Los montos de monedas distintas **nunca se suman**: todo se agrupa por moneda.
- Los saldos y cuotas no se leen de columnas guardadas sino del **plan de pagos reconstruido** (`LoanScheduleService::activeSchedules()`) para los sub préstamos activos.
- No se usa ninguna librería de hojas de cálculo: `XlsxWriter` construye el `.xlsx` con `ZipArchive`.

---

## Report

`App\Reports\Report`. Clase **abstracta** base de todos los reportes del menú. Un reporte construye sus datos a partir de filtros y sabe presentarlos como libro de Excel. No tiene constantes ni propiedades.

| Método | Parámetros | Retorno | Comportamiento |
|---|---|---|---|
| `slug()` (abstracto, público) | ninguno | `string` | Identificador usado en la URL (por ejemplo `posicion-deuda`). También determina el nombre de la vista (`reportes.{slug}`) y el prefijo del archivo descargado. Debe cumplir `[a-z0-9-]+` o la ruta no lo resolverá. |
| `title()` (abstracto, público) | ninguno | `string` | Título mostrado en el menú, en la tarjeta del índice, en el encabezado de la página y en la primera línea de cada hoja de Excel. |
| `description()` (abstracto, público) | ninguno | `string` | Texto descriptivo mostrado en la tarjeta del índice. |
| `icon()` (público) | ninguno | `string` | Nombre del icono del componente `<x-icon>`. Por defecto `'file-text'`. Las subclases pueden sobrescribirlo. |
| `filters()` (público) | `array $query` (query string de la petición) | `array<string, mixed>` | Convierte el query string en filtros **ya validados**. Por defecto devuelve `[]` (el reporte no tiene filtros). Es la única barrera entre la entrada del usuario y `build()`. |
| `build()` (abstracto, público) | `array $filters`, `?Carbon $today = null` | `array<string, mixed>` | Calcula los datos del reporte. Es lo que reciben tanto la vista (`$data`) como `workbook()`. `$today` es la fecha de corte (por defecto, hoy). |
| `workbook()` (abstracto, público) | `array $data` (lo devuelto por `build()`) | `XlsxWriter` | Arma el libro de Excel (hojas, columnas, filas, títulos y notas). No guarda el archivo; eso lo hace el controlador con `->save()`. |
| `fileName()` (público) | `array $data` | `string` | Nombre del archivo descargado. Por defecto `{slug}-{Ymd de hoy}.xlsx` (usa `now()`). Las subclases pueden sobrescribirlo para usar la fecha de corte y los filtros. |
| `view()` (público) | ninguno | `string` | Nombre de la vista Blade: `'reportes.'.$this->slug()`. |

Contrato mínimo para una subclase: implementar `slug()`, `title()`, `description()`, `build()` y `workbook()`; y crear la vista `reportes/{slug}.blade.php`.

---

## ReportCatalog

`App\Reports\ReportCatalog`. Clase `final` con métodos estáticos. Es el **registro** de reportes: los que ya funcionan (clases) y los anunciados en el menú como "Pronto" (arreglos). No tiene estado ni constructor.

### Constantes

| Constante | Visibilidad | Tipo | Contenido |
|---|---|---|---|
| `AVAILABLE` | `private` | `array<int, class-string<Report>>` | Reportes listos. Hoy: `[DebtPositionReport::class]`. |
| `PLANNED` | `private` | `array<int, array{slug, title, description, icon}>` | Reportes anunciados pero sin construir. Hoy: `flujo-efectivo` (Flujo de efectivo proyectado, 13 semanas, icono `trending-up`), `ciclo-conversion-efectivo` (Ciclo de conversión de efectivo, `clock`), `estado-cuenta` (Estado de cuenta, `file-text`) y `alertas-vencimiento` (Alertas de vencimiento, `alert`). |

### Métodos

| Método | Parámetros | Retorno | Comportamiento |
|---|---|---|---|
| `available()` (estático, público) | ninguno | `array<string, Report>` | Instancia cada clase de `AVAILABLE` con `app($class)` (contenedor de Laravel, por lo que se inyectan sus dependencias, p. ej. `LoanScheduleService`) y devuelve un arreglo indexado por `slug()`. Crea instancias nuevas en cada llamada. |
| `find()` (estático, público) | `string $slug` | `?Report` | `available()[$slug] ?? null`. Los reportes anunciados (`PLANNED`) **no** se encuentran: el controlador responde 404 para ellos. |
| `menu()` (estático, público) | ninguno | `array<int, array{slug, title, description, icon, available: bool}>` | Lista para el índice y para el submenú lateral. Primero los reportes listos (con `available => true`, datos tomados de los métodos `slug()`, `title()`, `description()`, `icon()`) y después los de `PLANNED` (con `available => false`). El layout `layouts/dashboard.blade.php` la recorre en cada página para pintar el submenú (los anunciados se muestran con la insignia "Pronto"). |

---

## DebtPositionReport

`App\Reports\DebtPositionReport` (`final`, extiende `Report`). **Posición consolidada de deuda por banco**: cuánto se debe, a qué costo, cuándo vence y dónde queda crédito, para decidir qué línea usar, qué deuda pagar primero y qué línea renegociar.

- slug: `posicion-deuda`; título: "Posición consolidada de deuda"; icono: `layers`; vista: `reportes.posicion-deuda`.
- Dependencia inyectada por constructor: `LoanScheduleService $schedules` (`private readonly`).
- Alcance de los datos: **líneas de crédito activas** (`LineaPrestamo::active()`, `estado = ACTIVO`) y **sub préstamos activos** (por `activeSchedules()`). Las líneas inactivas y los préstamos liquidados quedan fuera.

### Constantes

| Constante | Valor | Uso |
|---|---|---|
| `SERVICE_MONTHS` | `[3, 6, 12]` | Horizontes (en meses desde la fecha de corte) de las ventanas de servicio de la deuda. Genera las claves `service_3`, `service_6`, `service_12` y las columnas "Servicio N meses" del Excel. |
| `UTILIZATION_WARNING` | `80.0` | Utilización (%) de una línea desde la cual se emite una alerta "Importante". |
| `EXPIRY_WARNING_DAYS` | `365` | Días hacia adelante desde la fecha de corte en que un vencimiento (de línea o de sub préstamo) se marca. |
| `CONCENTRATION_WARNING` | `50.0` | Porcentaje de la deuda de una moneda en un solo banco desde el cual se marca concentración. |

### Métodos públicos

| Método | Parámetros | Retorno | Comportamiento |
|---|---|---|---|
| `__construct()` | `LoanScheduleService $schedules` | — | Promociona la propiedad `private readonly $schedules`. |
| `slug()` | ninguno | `string` | `'posicion-deuda'`. |
| `title()` | ninguno | `string` | `'Posición consolidada de deuda'`. |
| `description()` | ninguno | `string` | "Deuda, cupo disponible, costo y vencimientos por banco y línea, con una guía para decidir qué línea usar, qué pagar primero y qué renegociar." |
| `icon()` | ninguno | `string` | `'layers'`. |
| `currencies()` | ninguno | `array<int, string>` | Monedas de las líneas activas: `SELECT DISTINCT moneda` de `LineaPrestamo::active()` ordenado por `moneda`. Alimenta el selector de moneda y valida el filtro. |
| `filters()` | `array $query` | `array{currency: ?string}` | Lee `$query['moneda']`, la recorta (`trim`) y la pasa a mayúsculas. Si la moneda **está en `currencies()`** devuelve `['currency' => $moneda]`; si es vacía o desconocida devuelve `['currency' => null]` (todas las monedas). Nunca falla por una moneda inválida: la ignora. |
| `build()` | `array $filters`, `?Carbon $today = null` | `array` (ver "Estructura de `build()`") | Calcula todo el reporte. Pasos descritos abajo. |
| `workbook()` | `array $data` | `XlsxWriter` | Arma el libro con cuatro hojas. Ver "Libro de Excel". |
| `fileName()` | `array $data` | `string` | `posicion-deuda-{moneda en minúsculas}-{Ymd de as_of}.xlsx`; sin el segmento de moneda si no hay filtro. Ejemplos: `posicion-deuda-20261119.xlsx`, `posicion-deuda-usd-20261119.xlsx`. Usa la **fecha de corte** (`as_of`), no `now()`. |

### Funcionamiento de `build()`

1. **Fecha de corte**: `$today = ($today ?? today())->copy()->startOfDay()`. Moneda: `$filters['currency'] ?? null`.
2. **Planes**: `activeSchedules($today)` devuelve, para cada sub préstamo activo, `['loan' => Prestamo, 'schedule' => LoanSchedule]`. Se agrupan por `id_lineas_prestamos` del préstamo (`$plansByLine`).
3. **Líneas**: `LineaPrestamo::active()->with('banco')`, filtradas por `moneda` si hay filtro, ordenadas por `nombre`.
4. **Sub préstamos** (`$loanRows`): para cada línea y cada plan de esa línea se genera una fila con `loanRow()`. Los préstamos de líneas excluidas por el filtro no aparecen.
5. **Líneas** (`$lineRows`): por cada línea, `summarize($loanRows de esa línea, límite = monto_maximo)` más los campos propios de la línea (ver tabla).
6. **Totales por moneda** (`$totals`): se agrupan las líneas por `currency`; para cada moneda, `summarize($loanRows de esa moneda, suma de límites de las líneas)` más `currency`, `lines_count`, `banks_count` (bancos distintos) y `concentration = 100.0`.
7. **Bancos** (`$banks`): se agrupan las líneas por `banco|moneda` (un mismo banco con líneas en dos monedas genera dos filas). Para cada grupo, `summarize($préstamos de ese banco y moneda, suma de límites de sus líneas)` más `bank`, `currency`, `lines_count` y `concentration`. Se ordenan por moneda ascendente y saldo descendente.
8. **Guía**: `guidance(...)` (ver más abajo).
9. Retorna el arreglo de datos. Los préstamos se ordenan por banco, línea y número.

Nombre del banco (`bankName()`): el de `banco.nombre_banco`; si la línea no tiene banco, se usa el **nombre de la línea** (así la línea aparece igual en el reporte).

### Estructura de `build()`

| Clave | Tipo | Contenido |
|---|---|---|
| `as_of` | `Carbon` | Fecha de corte (inicio del día). |
| `filters` | `array` | Filtros recibidos (`['currency' => ?string]`). |
| `currencies` | `array<int, string>` | Monedas disponibles (para el selector). |
| `banks` | `Collection<array>` | Resumen por banco y moneda. |
| `lines` | `Collection<array>` | Detalle por línea (orden por nombre de línea). |
| `loans` | `Collection<array>` | Detalle por sub préstamo. |
| `totals` | `Collection<array>` | Una fila por moneda (reindexada con `values()`). |
| `service_months` | `array<int, int>` | `SERVICE_MONTHS` (`[3, 6, 12]`). |
| `guidance` | `array{pay_first, use_first, attention}` | Guía de decisión. |

### Campos de cada fila

**Sub préstamo** (`loans`, generada por `loanRow()`):

| Campo | Cálculo |
|---|---|
| `line_id` | `id` de la línea. |
| `bank`, `line`, `currency` | Banco (ver `bankName()`), `nombre` y `moneda` de la línea. |
| `number` | `numero_prestamo`. |
| `original` | `monto_prestamo` (float). |
| `balance` | `schedule->outstandingPrincipal()`: principal original menos el principal pagado (cuotas, abonos y cancelación); `0` si el plan está saldado. |
| `rate` | `schedule->currentAnnualRate` (tasa anual vigente en %, tasa + spread según el plan). |
| `annual_interest` | `round(balance * rate / 100, 2)`. |
| `installment` y `next_amount` | `total()` de la primera cuota abierta (principal + interés), `null` si no hay. |
| `next_due` | Fecha de la primera cuota abierta, `null` si no hay. |
| `overdue_count` | Número de cuotas con estado `Vencida`. |
| `overdue_amount` | Suma de `total()` de esas cuotas (redondeada a 2 decimales). |
| `maturity` | `schedule->maturityDate()`: fecha de la última fila del plan que no sea abono (`KIND_PREPAYMENT`). |
| `years` | Años al vencimiento: `round(max(días entre corte y maturity, 0) / 365, 2)`; `0.0` si no hay vencimiento. Un vencimiento pasado da `0`. |
| `conditions` | `schedule->conditions?->describe()`: texto de las condiciones de cálculo (modalidad, base de días, convención de tasa, periodicidad de pago). |
| `service_3`, `service_6`, `service_12` | Servicio de la deuda a N meses (ver fórmula). |

**Línea** (`lines`): resultado de `summarize()` más:

| Campo | Cálculo |
|---|---|
| `line_id`, `bank`, `line`, `currency` | Datos de la línea. |
| `line_type` | `tipo_linea`. |
| `line_rate` | `round(tasa_interes + tasa_spread, 4)`: la tasa de un **nuevo** desembolso. |
| `expires` | `fecha_vencmiento` de la línea (el nombre de la columna lleva esa ortografía en la base de datos). |

**Banco** (`banks`): `summarize()` más `bank`, `currency`, `lines_count` y `concentration`.

**Total por moneda** (`totals`): `summarize()` más `currency`, `lines_count`, `banks_count` y `concentration = 100.0`.

Los campos que produce `summarize()` están en la sección de ese método.

### Fórmulas

- **Disponible** = `límite − saldo` (`round(…, 2)`). Puede ser negativo si el saldo excede el límite.
- **Utilización (%)** = `saldo / límite × 100`, redondeada a 2 decimales; `0.0` si el límite es `0` o negativo.
- **Tasa ponderada (%)** = `Σ(saldo_i × tasa_i) / Σ saldo_i`, redondeada a 4 decimales, tomando cada sub préstamo `i` del grupo. Es `null` si el saldo del grupo es `0` (la vista lo muestra como "—" y el Excel deja la celda vacía). Es el costo de lo que hoy se debe; no es la `line_rate`.
- **Interés anual estimado** = `Σ (saldo_i × tasa_i / 100)`, es decir, `saldo × tasa` por préstamo, sumado. Es una estimación a saldo actual, no una proyección de amortización.
- **Concentración (%)** de un banco en una moneda = `saldo del banco / saldo total de esa moneda × 100` (2 decimales); `0.0` si el saldo total de la moneda es `0`. La fila de total por moneda siempre tiene `100.0`. Se calcula por moneda: nunca se mezcla USD con CRC.
- **Servicio de la deuda a N meses** (N ∈ {3, 6, 12}), por sub préstamo: se toma `cutoff = today + N meses` (`addMonthsNoOverflow`, es decir, sin desbordar fin de mes: 31 de agosto + 6 meses = 28/29 de febrero) y se suma `total()` (principal + interés) de las **cuotas abiertas** (estado `Vencida` o `Pendiente`) cuya `date <= cutoff`. Incluye las cuotas **vencidas** (su fecha es anterior al corte, por lo que siempre entran). Se redondea a 2 decimales. En líneas, bancos y totales es la suma de los préstamos del grupo.
- **Plazo promedio ponderado (`wam_years`)** = `Σ(saldo_i × años_i) / Σ saldo_i` (2 decimales); `null` si el saldo es `0`. Con `años_i` = `years` del préstamo (días / 365).
- **Próxima cuota de un grupo**: del grupo se toma el sub préstamo con `next_due` más próximo (menor timestamp) entre los que tienen fecha; `next_due` y `next_amount` salen de ese préstamo. Es `null` si ninguno tiene cuota abierta.

### Métodos privados

| Método | Parámetros | Retorno | Comportamiento |
|---|---|---|---|
| `bankName()` | `LineaPrestamo $line` | `string` | `$line->banco?->nombre_banco ?? $line->nombre`. Una línea sin banco se agrupa bajo su propio nombre. |
| `loanRow()` | `array $plan` (`['loan', 'schedule']`), `LineaPrestamo $line`, `string $bank`, `Carbon $today` | `array<string, mixed>` | Construye la fila de un sub préstamo con los campos y fórmulas de la tabla "Sub préstamo". Para cada horizonte de `SERVICE_MONTHS` agrega `service_{N}`. |
| `summarize()` | `Collection $loans` (filas de sub préstamo), `float $limit` | `array<string, mixed>` | Totales y promedios ponderados de un grupo de sub préstamos contra un límite. Devuelve: `loans_count`, `limit`, `balance` (suma de saldos), `available`, `utilization`, `wavg_rate`, `annual_interest` (suma), `overdue_count` (suma), `overdue_amount` (suma), `next_due`, `next_amount`, `wam_years` y `service_{N}` (suma por horizonte). Todas las sumas de dinero se redondean a 2 decimales. Es el único lugar donde se calculan estas métricas, por lo que líneas, bancos y totales son coherentes entre sí. |
| `guidance()` | `Collection $banks`, `Collection $lines`, `Collection $loans`, `Collection $totals` (indexada por moneda), `Carbon $today` | `array{pay_first, use_first, attention}` | Guía de decisión por reglas. Ver sección siguiente. |

### Guía de decisión (`guidance()`)

Devuelve tres listas.

**`pay_first` — pagar primero (mayor costo).** Sub préstamos con `balance > 0`, ordenados por `rate` descendente y luego `balance` descendente; los primeros **5**. Cada elemento es la fila completa del sub préstamo (en el Excel y la vista se usan `bank`, `line`, `number`, `currency`, `rate`, `balance`, `annual_interest`).

**`use_first` — usar primero (cupo más barato).** Líneas con `available > 0`, ordenadas por `line_rate` ascendente y luego `available` descendente; las primeras **5**. Se usan `bank`, `line`, `currency`, `line_rate`, `available`.

> Ambos rankings **no separan por moneda**: si hay varias monedas, las tasas de distintas monedas compiten en la misma lista. La moneda se muestra en cada elemento y el filtro por moneda permite acotar la comparación.

**`attention` — alertas.** Se acumulan en este orden y luego se ordenan por severidad (ordenamiento estable: dentro de una severidad se conserva el orden de generación):

| Regla | Severidad (etiqueta) | Condición | Alcance (`scope`) | Texto |
|---|---|---|---|---|
| Cuotas vencidas | `danger` (Urgente) | Sub préstamo con `overdue_count > 0`. | `Sub préstamo {número} · {banco}` | "N cuota(s) vencida(s) por {monto} {moneda}. Regularícelas para evitar mora y renegociaciones forzadas." |
| Línea vencida | `danger` (Urgente) | `expires` no nulo y `expires < today`. | `Línea {línea} · {banco}` | "La línea venció el {fecha}. Renuévela o cancélela." |
| Línea por vencer | `warning` (Importante) | `expires` no nulo, no vencida y `expires <= today + 365 días`. | `Línea … · …` | "La línea vence el {fecha} (en menos de 12 meses): candidata a renegociar o renovar." |
| Utilización alta | `warning` (Importante) | `utilization >= 80.0` (`UTILIZATION_WARNING`). | `Línea … · …` | "Utilización de X.X %: queda poco cupo ({disponible} {moneda})." |
| Sub préstamo por vencer | `info` (Informativo) | `balance > 0`, `maturity` no nulo y `maturity <= today + 365 días`. | `Sub préstamo … · …` | "Vence el {fecha} con saldo de {saldo} {moneda}: prevea su refinanciamiento." |
| Concentración | `warning` (Importante) | La moneda del banco tiene **2 o más bancos** (`totals[moneda].banks_count >= 2`) y `concentration >= 50.0` (`CONCENTRATION_WARNING`). | `Banco {banco}` | "Concentra el X.X % de la deuda en {moneda}: riesgo de concentración." |

Detalles:

- Las reglas de línea (vencida/por vencer y utilización) son independientes: una misma línea puede generar dos alertas. "Vencida" y "por vencer" son excluyentes (`if/elseif`).
- La regla de concentración se omite cuando hay un solo banco en la moneda (sería siempre 100 %).
- Orden por severidad: `danger` (0), `warning` (1), `info` (2). Cada alerta recibe `severity_label`: `Urgente`, `Importante` o `Informativo`.
- Los importes se formatean con `number_format($valor, 2).' '.$moneda`; las fechas con `DateFormat::format()` (formato `DATE_FORMAT`).
- Cada alerta tiene `severity`, `scope`, `currency`, `text`, `severity_label`.

### Libro de Excel (`workbook()`)

Todas las hojas llevan tres líneas de título (título del reporte; `Fecha de corte: …`; `Moneda: {moneda|Todas} · Los montos de monedas distintas no se suman`). Las hojas 1 a 3 llevan tres notas al pie con las definiciones (Saldo, Disponible, Servicio, Tasa ponderada, Interés anual estimado). La hoja 4 lleva su propia nota con las reglas de la guía.

Las columnas se definen con cinco ayudantes locales (`money`, `percent`, `text`, `integer`, `date`) que devuelven `['key', 'label', 'type', 'width']`. Anchos por defecto: money 18, percent 12, text 24, integer 10, date 14. Las columnas "Servicio N meses" se generan a partir de `service_months` y se repiten en las hojas 1 a 3.

| Hoja (nombre) | Filas | Columnas (clave → etiqueta) |
|---|---|---|
| `Resumen por banco` | `banks` más una fila de total por moneda | `bank` Banco · `currency` Moneda · `lines_count` Líneas · `limit` Límite aprobado · `balance` Saldo · `available` Disponible · `utilization` % utilización (percent) · `wavg_rate` Tasa ponderada (percent) · `concentration` % de la deuda (percent) · `annual_interest` Interés anual estimado · `service_3/6/12` Servicio N meses · `overdue_count` Cuotas vencidas · `overdue_amount` Monto vencido · `next_due` Próxima cuota (date) · `next_amount` Monto próxima cuota · `wam_years` Plazo promedio (años) (decimal) |
| `Detalle por línea` | `lines` | `bank` · `line` Línea · `currency` · `limit` · `balance` · `available` · `utilization` · `line_rate` Tasa de la línea (percent) · `wavg_rate` Tasa ponderada deuda · `loans_count` Sub préstamos · `annual_interest` · `service_3/6/12` · `overdue_count` · `overdue_amount` · `next_due` · `expires` Vence la línea (date) |
| `Sub préstamos` | `loans` | `bank` · `line` · `number` Sub préstamo · `currency` · `original` Monto original · `balance` · `rate` Tasa anual (percent) · `annual_interest` · `installment` Cuota actual · `service_3/6/12` · `next_due` · `overdue_count` · `overdue_amount` · `maturity` Vencimiento final (date) · `years` Años al vencimiento (decimal) · `conditions` Condiciones de cálculo (text, ancho 60) |
| `Guía de decisión` | Una fila por elemento de `pay_first`, `use_first` y `attention` | `kind` Recomendación · `item` Detalle · `currency` · `rate` Tasa (percent) · `amount` Monto · `note` Nota |

Detalles de la hoja `Resumen por banco`: las filas de total se construyen como `['bank' => 'TOTAL '.$moneda, '_total' => true] + $total`, de modo que `XlsxWriter` las dibuja con el estilo de total (negrita sobre fondo azul claro).

Filas de la hoja `Guía de decisión` (`kind` → contenido):

| `kind` | `item` | `rate` | `amount` | `note` |
|---|---|---|---|---|
| `Pagar primero (mayor costo)` | `{banco} · {línea} · sub préstamo {número}` | `rate` | `balance` | `Interés anual estimado: {n}` (`number_format(…, 2)`) |
| `Usar primero (cupo más barato)` | `{banco} · {línea}` | `line_rate` | `available` | `Disponible para nuevos desembolsos` |
| `Atención ({Urgente\|Importante\|Informativo})` | `scope` | vacío | vacío | `text` |

---

## XlsxWriter

`App\Support\XlsxWriter` (`final`). Escritor mínimo de libros `.xlsx` (Office Open XML) sobre la extensión `zip` (`ZipArchive`), para descargar reportes sin instalar una librería de hojas de cálculo. Cada hoja tiene líneas de título opcionales, una fila de encabezados, las filas de datos, encabezado congelado y autofiltro. El escritor es de **solo escritura**: no lee ni modifica libros existentes. Se usa en cadena: `(new XlsxWriter)->addSheet(...)->addSheet(...)->save($ruta)`.

### Constantes

| Constante | Valor | Significado |
|---|---|---|
| `STYLE_TITLE` | `1` | Índice de estilo de celda del título principal. |
| `STYLE_HEADER` | `2` | Índice del encabezado de columnas. |
| `STYLE_NOTE` | `12` | Índice de las notas al pie. |
| `STYLE_BOLD` | `14` | Índice de las líneas de título secundarias (negrita). |
| `TYPE_STYLES` | ver tabla | Índice de estilo por tipo de columna: `[normal, total]`. |

`TYPE_STYLES`:

| Tipo de columna | Normal | Total | Formato numérico |
|---|---|---|---|
| `text` | 3 | 8 | General (id 0) |
| `money` | 4 | 9 | `#,##0.00` (id integrado 4) |
| `percent` | 5 | 10 | `0.00%` (id integrado 10) |
| `date` | 6 | 13 | Formato propio id 164 (ver `excelDateFormat()`), centrado |
| `integer` | 7 | 11 | `#,##0` (id integrado 3) |
| `decimal` | 4 | 9 | `#,##0.00` (el mismo estilo que `money`) |

### Propiedad

`private array $sheets = []`: hojas acumuladas por `addSheet()`; cada elemento es `{name, columns, rows, titles, notes}`.

### Tipos de columna y valores de celda

| Tipo | Valor aceptado | Cómo se escribe |
|---|---|---|
| `text` (o ausente/desconocido) | Cualquier valor | Cadena en línea (`t="inlineStr"`). |
| `money`, `decimal`, `integer` | Numérico (`is_numeric`) | Número tal cual, con el estilo correspondiente. |
| `percent` | Numérico **en unidades de porcentaje** (por ejemplo `41.5`) | Se divide entre 100 al escribir (`0.415`); Excel lo muestra como `41.50%`. |
| `date` | `DateTimeInterface` o texto que empiece por `yyyy-mm-dd` | Número de serie de Excel (ver `dateSerial()`) con formato de fecha. Si no se reconoce como fecha, se escribe como texto con el estilo de fecha. |
| Cualquiera | `null` o `''` | Celda vacía con estilo (conserva borde). El valor `0` **sí** se escribe como `0`. |
| Numérico/decimal con valor no numérico | Texto | Se escribe como cadena en línea. |

Una fila con la clave `_total => true` se dibuja con la columna "Total" de `TYPE_STYLES` (negrita, fondo azul claro `D9E4F2`).

### Estructura del archivo `.xlsx`

El `.xlsx` es un ZIP con estas partes (todas escritas con `addFromString`; **no** hay `docProps`, `sharedStrings.xml` ni tema):

| Parte | Generada por | Contenido |
|---|---|---|
| `[Content_Types].xml` | `contentTypes()` | Tipos por defecto para `rels` y `xml`; `Override` para `/xl/workbook.xml`, cada `/xl/worksheets/sheetN.xml` y `/xl/styles.xml`. |
| `_rels/.rels` | `rootRelationships()` | Relación `rId1` de tipo `officeDocument` hacia `xl/workbook.xml`. |
| `xl/workbook.xml` | `workbook()` | Lista `<sheets>`: `<sheet name sheetId=N r:id="rIdN"/>`. |
| `xl/_rels/workbook.xml.rels` | `workbookRelationships()` | `rId1…rIdN` hacia `worksheets/sheet{N}.xml` y `rId{N+1}` hacia `styles.xml`. |
| `xl/styles.xml` | `styles()` | Formato de fecha, fuentes, rellenos, bordes y 15 formatos de celda. |
| `xl/worksheets/sheet1.xml … sheetN.xml` | `worksheet()` | Una parte por hoja, en el orden de `addSheet()`. |

Los textos van como **cadenas en línea** (`<c t="inlineStr"><is><t xml:space="preserve">…</t></is></c>`), por lo que no hay tabla de cadenas compartidas; `xml:space="preserve"` conserva los espacios.

### Estilos (`xl/styles.xml`)

Fuentes (5): 0 Calibri 11; 1 Calibri 11 negrita; 2 Calibri 14 negrita azul `1C3F73`; 3 Calibri 11 negrita blanca; 4 Calibri 10 cursiva gris `64748B`.
Rellenos (4): 0 ninguno; 1 `gray125` (obligatorio de Excel); 2 sólido azul oscuro `1C3F73`; 3 sólido azul claro `D9E4F2`.
Bordes (2): 0 sin borde; 1 fino en los cuatro lados, color `8EA8C8`.

Formatos de celda (`cellXfs`, 15):

| Índice | Uso | numFmt | Fuente | Relleno | Borde | Alineación |
|---|---|---|---|---|---|---|
| 0 | Predeterminado | 0 | 0 | 0 | 0 | — |
| 1 | Título principal | 0 | 2 | 0 | 0 | — |
| 2 | Encabezado de columna | 0 | 3 | 2 | 1 | centrado, vertical centrado, ajuste de texto |
| 3 | Texto | 0 | 0 | 0 | 1 | — |
| 4 | Dinero / decimal | 4 | 0 | 0 | 1 | — |
| 5 | Porcentaje | 10 | 0 | 0 | 1 | — |
| 6 | Fecha | 164 | 0 | 0 | 1 | centrado |
| 7 | Entero | 3 | 0 | 0 | 1 | — |
| 8 | Total texto | 0 | 1 | 3 | 1 | — |
| 9 | Total dinero / decimal | 4 | 1 | 3 | 1 | — |
| 10 | Total porcentaje | 10 | 1 | 3 | 1 | — |
| 11 | Total entero | 3 | 1 | 3 | 1 | — |
| 12 | Nota | 0 | 4 | 0 | 0 | — |
| 13 | Total fecha | 164 | 1 | 3 | 1 | centrado |
| 14 | Negrita (títulos secundarios) | 0 | 1 | 0 | 0 | — |

### Métodos

| Método | Parámetros | Retorno | Comportamiento |
|---|---|---|---|
| `addSheet()` (público) | `string $name`, `array $columns` (`[{key, label, type?, width?}]`), `iterable $rows` (arreglos asociativos; `_total => true` marca una fila de total), `array $titles = []`, `array $notes = []` | `self` | Agrega una hoja. El nombre se normaliza con `sheetName()`; las columnas y filas se reindexan (`array_values`; un iterable se convierte con `iterator_to_array(…, false)`). Devuelve `$this` para encadenar. Cada columna se enlaza con su valor mediante `key`; las claves faltantes en una fila quedan como celda vacía. |
| `save()` (público) | `string $path` | `void` | Escribe el libro en `$path` con `ZipArchive::CREATE \| OVERWRITE`. Lanza `RuntimeException` si no hay hojas (`A workbook needs at least one sheet.`) o si no puede crear el archivo (`Could not create the workbook at {ruta}.`). Escribe las cinco partes fijas y una por hoja, y cierra el ZIP. |
| `sheetName()` (privado) | `string $name` | `string` | Reemplaza por un espacio los caracteres que Excel prohíbe en nombres de hoja (`\ / * ? : [ ]`), recorta espacios, usa `'Hoja'` si queda vacío y limita a **31 caracteres** (`mb_substr`, seguro con acentos). No comprueba nombres duplicados: el llamador debe usar nombres distintos. |
| `xml()` (privado) | `string $value` | `string` | Escape XML: primero elimina caracteres de control no válidos en XML 1.0 (`\x00-\x08`, `\x0B`, `\x0C`, `\x0E-\x1F`; se conservan tabulador, salto de línea y retorno), luego aplica `htmlspecialchars(ENT_XML1 \| ENT_QUOTES, 'UTF-8')` (convierte `& < > " '`). Se usa en textos de celdas, nombres de hoja y el formato de fecha. Un texto con UTF-8 inválido produce cadena vacía (no se usa `ENT_SUBSTITUTE`). |
| `contentTypes()` (privado) | ninguno | `string` | XML de `[Content_Types].xml`. |
| `rootRelationships()` (privado) | ninguno | `string` | XML de `_rels/.rels`. |
| `workbook()` (privado) | ninguno | `string` | XML de `xl/workbook.xml` con las hojas (`sheetId` y `r:id` = posición base 1; el nombre pasa por `xml()`). |
| `workbookRelationships()` (privado) | ninguno | `string` | XML de `xl/_rels/workbook.xml.rels`; el id de los estilos es `rId{número de hojas + 1}`. |
| `styles()` (privado) | ninguno | `string` | XML de `xl/styles.xml`. Define con un ayudante local `$xf(numFmt, font, fill, border, alignment)` los 15 formatos de la tabla de estilos y añade los atributos `applyNumberFormat/applyFont/applyFill/applyBorder/applyAlignment` solo cuando el valor es mayor que 0 (o hay alineación). Declara `numFmt` 164 con `excelDateFormat()` escapado. |
| `excelDateFormat()` (privado) | ninguno | `string` | Traduce `DateFormat::pattern()` (el `DATE_FORMAT` de la aplicación) al código de formato de Excel con `strtr(['d' => 'dd', 'm' => 'mm', 'Y' => 'yyyy'])`. Ejemplos: `d/m/Y` → `dd/mm/yyyy`; `Y-m-d` → `yyyy-mm-dd`. Así Excel muestra las fechas igual que la aplicación. |
| `worksheet()` (privado) | `array $sheet` | `string` | XML de una hoja. Ver "Distribución de una hoja". |
| `cell()` (privado) | `string $reference`, `mixed $value`, `string $type`, `bool $isTotal` | `string` | Genera una celda. Estilo = `TYPE_STYLES[$type]` (tipo desconocido → `text`), columna 0 normal o 1 total. Vacío (`null`/`''`) → `<c r s/>`. `date` → serial o texto. `text` o valor no numérico → `textCell()`. Resto → `<v>` numérico; si el tipo es `percent` se divide entre 100. |
| `textCell()` (privado) | `string $reference`, `string $text`, `int $style` | `string` | Celda de cadena en línea con el texto escapado por `xml()`. |
| `number()` (privado) | `float $value` | `string` | Número sin ceros sobrantes: `sprintf('%.10F')` (10 decimales, punto decimal sin importar la configuración regional), `rtrim` de ceros y del punto final; devuelve `'0'` si queda vacío. |
| `dateSerial()` (privado) | `mixed $value` | `?int` | Número de serie de Excel (días desde 1899-12-30). Acepta `DateTimeInterface` (usa solo año, mes y día; ignora hora y zona) o una cadena que empiece por `aaaa-mm-dd`; otro valor da `null`. Cálculo: `intdiv(gmmktime(0, 0, 0, mes, día, año), 86400) + 25569`, donde 25569 es el serial de 1970-01-01. Ejemplo: 1970-01-01 → 25569. |
| `reference()` (privado) | `int $columnIndex` (base 0), `int $row` (base 1) | `string` | Referencia A1, por ejemplo `(0, 3)` → `A3`. |
| `columnLetters()` (privado) | `int $index` (base 0) | `string` | Letras de columna en base 26 bijectiva: `0`→`A`, `25`→`Z`, `26`→`AA`, `701`→`ZZ`, `702`→`AAA`. Soporta más de 26 columnas. |

### Distribución de una hoja (`worksheet()`)

De arriba hacia abajo, en la columna A para títulos y notas (sin combinar celdas):

1. **Títulos** (si hay): una fila por línea. La primera usa el estilo 1 (título grande azul); las siguientes usan el estilo 14 (negrita). Después se deja **una fila en blanco**.
2. **Encabezado**: fila `n títulos + 2` (o la fila 1 si no hay títulos), de altura 32 (`ht="32" customHeight="1"`), estilo 2.
3. **Datos**: una fila por elemento, columnas en el orden declarado.
4. **Notas** (si hay): una fila en blanco y luego una fila por nota (estilo 12, cursiva gris).

Además:

- `<dimension>`: `A1:{última columna}{última fila escrita}` (incluye las notas).
- `<sheetView showGridLines="0">`: sin cuadrícula; **panel congelado** (`pane ySplit={fila del encabezado}`, `topLeftCell=A{encabezado+1}`, `state="frozen"`), de modo que títulos y encabezado permanecen visibles al desplazarse.
- `<cols>`: un `<col>` por columna con `customWidth="1"`; ancho `width` de la columna o **16** por defecto.
- `<autoFilter ref="A{encabezado}:{última columna}{última fila de datos}"/>` solo si hay filas de datos (una hoja vacía no lleva filtro). El rango del filtro **incluye las filas de total** si existen.
- `sheetFormatPr defaultRowHeight="15"`.

---

## ReportController

`App\Http\Controllers\ReportController` (extiende `Controller`). Capa HTTP delgada: no contiene lógica de reporte; todo lo delega en `ReportCatalog` y en la instancia de `Report`.

| Miembro | Parámetros | Retorno | Comportamiento |
|---|---|---|---|
| `XLSX_MIME` (constante privada) | — | `string` | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`. |
| `index()` | ninguno | `View` | Renderiza `reportes.index` con `reports = ReportCatalog::menu()`. |
| `show()` | `Request $request`, `string $slug` | `View` | Resuelve el reporte (`report($slug)`, 404 si no existe), calcula `$filters = $report->filters($request->query())` y renderiza `$report->view()` con `report`, `filters` y `data = $report->build($filters)`. La fecha de corte es siempre hoy. |
| `download()` | `Request $request`, `string $slug` | `BinaryFileResponse` | Resuelve el reporte (404 si no existe), calcula `build()` con los mismos filtros, crea un archivo temporal en `storage/app/tmp` (`File::ensureDirectoryExists`, `tempnam(..., 'rpt')` y renombrado a `.xlsx`; se usa `storage` para que el grupo de aplicaciones de IIS siempre pueda escribir), guarda el libro con `$report->workbook($data)->save($path)` y responde la descarga con el nombre de `$report->fileName($data)`, el `Content-Type` `XLSX_MIME` y `deleteFileAfterSend()` (el temporal se borra tras enviarlo). |
| `report()` (privado) | `string $slug` | `Report` | `ReportCatalog::find($slug) ?? abort(404)`. Los slugs anunciados (`PLANNED`) o inexistentes devuelven 404. |

---

## Vistas (`resources/views/reportes`)

Ambas extienden `layouts.dashboard` y definen las secciones `title`, `heading` y `content`. Usan el componente `<x-icon>`, la directiva `@fecha()` (formato `DATE_FORMAT`) y clases de `public/css/theme.css` (`report-grid`, `report-card`, `report-subtitle`, `report-date`, `guidance-item`, `stat-grid`, `stat`, `tab-bar`, `tab-pane`, `table-wrapper`, etc.).

### `index.blade.php`

Título "Reportes". Recibe:

| Variable | Origen | Contenido |
|---|---|---|
| `$reports` | `ReportCatalog::menu()` | Arreglo de `{slug, title, description, icon, available}`. |

Muestra una nota y una rejilla (`report-grid`) con una tarjeta por reporte (clase adicional `is-planned` si no está disponible): título con icono, descripción y, según `available`:

- **Disponible**: botón "Abrir" (`route('reportes.show', $slug)`) y botón "Descargar Excel" (`route('reportes.excel', $slug)`).
- **No disponible**: insignia "Pronto" (`badge-neutral`), sin enlaces.

### `posicion-deuda.blade.php`

Título y encabezado: `$report->title()`. Recibe:

| Variable | Origen | Uso |
|---|---|---|
| `$report` | Instancia de `DebtPositionReport` | `title()`, `slug()` para las rutas. |
| `$filters` | `filters()` | Se lee `$filters['currency']` para preseleccionar el selector y para el enlace de Excel. |
| `$data` | `build()` | Toda la información (estructura descrita arriba). |

Variables y ayudantes locales (`@php`): `$currencyFilter`, `$months = $data['service_months']`, `$money` (`number_format(…, 2)`, o "—" si es `null`), `$percent` (`number_format(…, $decimales).' %'`, o "—" si es `null`), `$excelUrl` (`reportes.excel` con `slug` y `moneda`; así la descarga conserva el filtro) y `$tabs` (cuatro pestañas con icono y conteo).

Contenido, de arriba hacia abajo:

1. **Acciones**: "Reportes" (vuelve al índice) y "Descargar Excel".
2. **Filtros**: formulario `GET` a `reportes.show` con el selector de moneda (opción "Todas" más `$data['currencies']`), la fecha de corte (`@fecha($data['as_of'])`, solo lectura) y los botones Aplicar y Limpiar.
3. **Tarjetas de totales por moneda**: por cada elemento de `$data['totals']`, un subtítulo "Total en {moneda} · N línea(s) · N banco(s)" y seis tarjetas: Deuda (saldo) con el número de sub préstamos activos; Límite aprobado con el disponible; Utilización (tarjeta con `stat-danger` si es `>= 80`); Tasa ponderada con el interés anual estimado; Servicio de la deuda a 12 meses con los de 3 y 6 meses (usa `service_12`, `service_3`, `service_6` fijos); Cuotas vencidas (con `stat-danger` si hay alguna) y el monto pendiente. Si no hay totales (`@empty`), se muestra "No hay líneas de crédito activas para esta moneda."
4. **Pestañas** (`data-tabs`; el cambio de pestaña lo resuelve el script del layout):
   - `banks` "Resumen por banco (N)": tabla con las columnas del resumen por banco, columnas de servicio generadas con `$months`, y un `<tfoot>` con una fila TOTAL por moneda (la columna "% de la deuda" muestra `100.0 %`). La utilización `>= 80` y las cuotas vencidas `> 0` se resaltan con `is-negative`.
   - `lines` "Detalle por línea (N)": tabla por línea, con la nota "Tasa de la línea" (nuevo desembolso) frente a "Tasa ponderada" (lo que hoy se debe). Solo muestra el servicio a 12 meses; "Vence la línea" muestra "—" si no hay fecha.
   - `loans` "Sub préstamos (N)": tabla por sub préstamo (sin las columnas de servicio ni de condiciones, que solo están en el Excel); si no hay, "No hay sub préstamos activos.".
   - `guidance` "Guía de decisión (N)": N es la cantidad de alertas de `attention`. Dos columnas "Pagar primero (mayor costo)" (insignia roja con la tasa, saldo e interés anual) y "Usar primero (cupo más barato)" (insignia verde con la tasa de la línea y el disponible), y debajo "Atención" con una insignia por severidad (`danger` → `badge-danger`, `warning` → `badge-info`, `info` → `badge-neutral`) y el texto. Cada lista tiene su mensaje vacío; si no hay alertas: "Sin alertas: no hay cuotas vencidas, vencimientos cercanos, utilización alta ni concentración excesiva."

Observaciones al mantener la vista:

- Los umbrales de resalte (`>= 80` de utilización) están escritos en la vista; no leen `UTILIZATION_WARNING`. Si se cambia la constante, hay que actualizar también la vista (y el texto de la nota de la guía, tanto en la vista como en el Excel).
- Las tarjetas y la pestaña de líneas referencian `service_3`, `service_6` y `service_12` de forma fija; la tabla por banco sí recorre `service_months`. Si se cambia `SERVICE_MONTHS`, hay que revisar esos puntos.
- Los valores se leen con índice directo (`$total['balance']`, `$bank['wam_years']`, etc.): una clave faltante en `build()` produce un error en la vista.

---

## Guía: cómo agregar un reporte nuevo

Ejemplo para un reporte con slug `estado-cuenta`. Ya existe como anunciado en `PLANNED`.

1. **Crear la clase** `app/Reports/EstadoCuentaReport.php` (`final`, `extends Report`, namespace `App\Reports`) e implementar:
   - `slug()` → `'estado-cuenta'` (solo `a-z`, `0-9` y `-`).
   - `title()`, `description()` y, si se desea, `icon()` (uno de los nombres del componente `<x-icon>`: `menu`, `sun`, `moon`, `dashboard`, `building`, `layers`, `banknote`, `calendar`, `activity`, `logout`, `alert`, `chevron-down`, `percent`, `file-text`, `info`, `check`, `x`, `dollar`, `clock`, `tag`, `trending-up`, `arrow-left`, `search`, `edit`, `trash`, `plus`, `lock`, `eye`; si hace falta otro, se agrega en `resources/views/components/icon.blade.php`).
   - `filters(array $query)` si hay filtros: leer del query string, **validar y normalizar** (descartar valores desconocidos en vez de fallar), como hace `DebtPositionReport::filters()` con la moneda.
   - `build(array $filters, ?Carbon $today = null)`: devolver un arreglo con todo lo que necesiten la vista y el Excel. Incluir `as_of` (la fecha de corte) y `filters` si `fileName()` o los títulos los usan. Recibir dependencias por el constructor (se inyectan con el contenedor). No acceder a `request()`: todo entra por `$filters` y `$today`.
   - `workbook(array $data)`: crear `new XlsxWriter` y llamar `addSheet($nombre, $columnas, $filas, $titulos, $notas)` por cada hoja. Elegir el `type` correcto de cada columna (`text`, `money`, `percent` en unidades de porcentaje, `integer`, `decimal`, `date`), usar `'_total' => true` en las filas de total y nombres de hoja distintos (máximo 31 caracteres).
   - Opcional: `fileName(array $data)` si el nombre debe llevar la fecha de corte o los filtros.
2. **Registrar el reporte** en `ReportCatalog::AVAILABLE` (agregar `EstadoCuentaReport::class`). Si estaba en `PLANNED`, **quitar su entrada** de esa constante para que no aparezca duplicado (en el menú los listos salen primero y los anunciados después).
3. **Crear la vista** `resources/views/reportes/estado-cuenta.blade.php` (`'reportes.'.$slug`). Recibe `$report`, `$filters` y `$data`. Extender `layouts.dashboard`, definir `title`, `heading` y `content`; incluir el enlace "Descargar Excel" con `route('reportes.excel', ['slug' => $report->slug(), …filtros])` para conservar los filtros y un formulario `GET` a `route('reportes.show', $report->slug())` para aplicarlos. Usar `@fecha()` para fechas y reutilizar las clases de `theme.css`. Para pestañas, copiar el patrón `data-tabs` / `.tab-bar .tab[data-tab]` / `[data-panel]` de `posicion-deuda.blade.php`.
4. **No hace falta tocar** rutas, controlador, índice de reportes ni el submenú del layout: `ReportController` resuelve el reporte por `slug` con `ReportCatalog::find()`, y tanto el índice como el submenú lateral se generan con `ReportCatalog::menu()`.
5. **Escribir pruebas** (ver `tests/Feature/ReportsTest.php` y `tests/Unit/Reports/XlsxWriterTest.php` como modelo): que `build()` calcule lo esperado con una fecha de corte fija, que los filtros desconocidos se ignoren, que la página (`reportes.show`) y la descarga (`reportes.excel`) respondan 200 con el `Content-Type` del `.xlsx` (y 302 a login si no hay sesión) y que el libro tenga las hojas y los valores esperados (abrirlo con `ZipArchive` y leer `xl/worksheets/sheetN.xml`).
6. **Formatear y ejecutar**: `vendor/bin/pint --dirty --format agent` y `php artisan test --compact tests/Feature/ReportsTest.php` (o el archivo del reporte nuevo).

Buenas prácticas para los reportes:

- Mantener `build()` puro respecto de la petición (filtros y fecha por parámetro) para poder probarlo.
- No sumar montos de monedas distintas; agrupar siempre por moneda.
- Si el reporte necesita saldos o cuotas, obtenerlos de `LoanScheduleService::activeSchedules($today)` (planes reconstruidos) y no de columnas guardadas.
- Mantener pantalla y Excel alimentados por el mismo `$data`; si se agrega una columna, agregarla en ambos.
- Porcentajes: guardar en `$data` en unidades de porcentaje (`41.5`); `XlsxWriter` los divide entre 100 al escribirlos con el tipo `percent`.
