# Referencia 04 · Modelos, soporte, factories, seeder y migraciones

Referencia extraída del código real. Todos los modelos usan atributos PHP 8 (`#[Table]`, `#[Fillable]`, `#[UseFactory]`, `#[Hidden]`) en lugar de las propiedades `$table`, `$fillable`, etc. Ningún modelo declara `$timestamps = false`, por lo que todos mantienen `created_at` / `updated_at`. Los métodos `casts()` son métodos protegidos (estilo Laravel 11+).

Estados usados como cadenas en BD: `ACTIVO`, `INACTIVO`, `LIQUIDADO`, `ANULADO`. Las comparaciones se hacen siempre con las constantes del modelo.

## Mapa de relaciones

| Modelo | Tabla | Relaciones |
|---|---|---|
| `Empresa` | `empresas` | `lineas` (hasMany `LineaPrestamo`) |
| `Banco` | `lineas_bancos` | `lineas` (hasMany `LineaPrestamo`) |
| `TipoTasa` | `lineas_tipo_tasas` | `lineas` (hasMany), `historial` (hasMany) |
| `TipoTasaHistorial` | `lineas_tipo_tasa_historial` | `tipoTasa` (belongsTo) |
| `LineaPrestamo` | `lineas_prestamos` | `empresa`, `banco`, `tipoTasa` (belongsTo), `prestamos` (hasMany) |
| `Prestamo` | `prestamos` | `linea` (belongsTo), `recibos`, `detalles`, `revisiones` (hasMany) |
| `Recibo` | `recibos` | `detalles` (hasMany), `prestamo` (belongsTo) |
| `DetallePago` | `detalle_pago` | `recibo`, `prestamo`, `tipoPago` (belongsTo) |
| `TipoPago` | `tipos_pago` | `detalles` (hasMany) |
| `RevisionPrestamo` | `prestamo_revisiones` | `prestamo` (belongsTo) |
| `User` | `users` | ninguna |

---

# Modelos (`app/Models`)

## Banco

`App\Models\Banco`. Banco o entidad financiera que otorga una línea (catálogo).

- Tabla: `lineas_bancos`. Fillable: `nombre_banco`. Factory: `BancoFactory`. Usa `HasFactory`.
- Sin casts, sin constantes, sin scopes.

| Miembro | Parámetros | Retorno | Comportamiento |
|---|---|---|---|
| `lineas()` | ninguno | `HasMany<LineaPrestamo>` | `hasMany(LineaPrestamo::class, 'banco_id')`. Líneas que pertenecen al banco (`banco_id` es opcional en la línea). |

## DetallePago

`App\Models\DetallePago`. Renglón de detalle de un recibo: cada concepto pagado (principal, interés, otros) con su tipo de pago.

- Tabla: `detalle_pago`. Sin factory (no usa `HasFactory`).
- Fillable: `recibo_id`, `prestamo_id`, `tipo_pago_id`, `numero_cuota`, `fecha_cuota`, `fecha_pago`, `monto`, `descripcion`.
- Casts: `numero_cuota` integer; `fecha_cuota` date; `fecha_pago` date; `monto` float.

| Miembro | Parámetros | Retorno | Comportamiento |
|---|---|---|---|
| `recibo()` | ninguno | `BelongsTo<Recibo>` | FK `recibo_id`. |
| `prestamo()` | ninguno | `BelongsTo<Prestamo>` | FK `prestamo_id`. |
| `tipoPago()` | ninguno | `BelongsTo<TipoPago>` | FK `tipo_pago_id`. |

## Empresa

`App\Models\Empresa`. Empresa deudora a la que pertenecen las líneas de préstamo (catálogo).

- Tabla: `empresas`. Fillable: `nombre_empresa`. Factory: `EmpresaFactory`.
- Sin casts, constantes ni scopes.

| Miembro | Parámetros | Retorno | Comportamiento |
|---|---|---|---|
| `lineas()` | ninguno | `HasMany<LineaPrestamo>` | `hasMany(LineaPrestamo::class, 'empresa_id')`. |

## LineaPrestamo

`App\Models\LineaPrestamo`. Línea de crédito (límite, condiciones de cálculo por defecto y saldo disponible). Los sub préstamos (`Prestamo`) heredan sus condiciones salvo que las sobrescriban.

- Tabla: `lineas_prestamos`. Factory: `LineaPrestamoFactory`.
- Fillable: `empresa_id`, `banco_id`, `nombre`, `tipo_linea`, `monto_maximo`, `plazo_maximo_meses`, `modalidad_pago`, `estado`, `fecha_vencmiento` (la columna se llama así, con la errata), `capital_trabajo`, `moneda`, `forma_pago`, `calendario`, `tipo_cuota`, `periodicidad_pago`, `tipo_interes`, `modalidad_cuota`, `variacion_porcentual`, `tipotasa_id`, `tasa_interes`, `tasa_spread`, `disponible`, `meses_gracia`, `porcentaje_global`.
- Las columnas legacy `tasa_interes_fija` y `tasa_interes_variable` existen en la tabla pero no están en `$fillable`.

### Constantes

| Constante | Valor | Uso |
|---|---|---|
| `STATUS_ACTIVE` | `'ACTIVO'` | Estado de línea activa. |
| `STATUS_INACTIVE` | `'INACTIVO'` | Estado de línea inactiva. |
| `PAYMENTS_PER_YEAR` | `['MENSUAL' => 12, 'TRIMESTRAL' => 4, 'SEMESTRAL' => 2, 'ANUAL' => 1, 'UNICO' => 1]` | Cuotas por año de cada modalidad de pago. También lo usa `CalculationOptions::paymentModeCodes()`. |

### Casts

`monto_maximo` float; `disponible` float; `meses_gracia` integer; `porcentaje_global` float; `capital_trabajo` float; `variacion_porcentual` float; `tasa_interes` float; `tasa_spread` float; `fecha_vencmiento` date.

### Métodos

| Miembro | Parámetros | Retorno | Comportamiento |
|---|---|---|---|
| `scopeActive()` | `Builder $query` | `void` | Uso: `LineaPrestamo::active()`. Filtra `estado = 'ACTIVO'`. |
| `refreshAvailable()` | ninguno | `float` | Recalcula y guarda el crédito disponible: `monto_maximo` menos la suma de `saldo_prestamo` de los préstamos **ACTIVO** de la línea (`Prestamo::active()->where('id_lineas_prestamos', $this->id)`), redondeado a 2 decimales. Persiste con un `UPDATE` directo por llave (`disponible` y `updated_at = now()`, sin eventos de modelo) y luego llama `$this->refresh()`. Devuelve el valor calculado. Puede ser negativo si los saldos exceden el límite (no hay tope). |
| `monthsPerPeriod()` | ninguno | `int` | Meses entre dos cuotas: `intdiv(12, PAYMENTS_PER_YEAR[modalidad_pago] ?? 12)`. MENSUAL=1, TRIMESTRAL=3, SEMESTRAL=6, ANUAL=12, UNICO=12; una modalidad desconocida o nula equivale a 1. |
| `empresa()` | ninguno | `BelongsTo<Empresa>` | FK `empresa_id`. |
| `banco()` | ninguno | `BelongsTo<Banco>` | FK `banco_id` (puede ser nulo). |
| `tipoTasa()` | ninguno | `BelongsTo<TipoTasa>` | FK `tipotasa_id`. |
| `prestamos()` | ninguno | `HasMany<Prestamo>` | FK `id_lineas_prestamos`. Sin orden por defecto. |

## Prestamo

`App\Models\Prestamo`. Sub préstamo (desembolso) dentro de una línea, con su saldo, cuota y condiciones opcionales de cálculo.

- Tabla: `prestamos`. Factory: `PrestamoFactory`.
- Fillable: `id_lineas_prestamos`, `numero_prestamo`, `monto_prestamo`, `formalizacion`, `plazo_meses`, `tasa_interes`, `tasa_spread`, `cuota_calculada`, `vencimiento`, `proximo_pago`, `saldo_prestamo`, `estado`, `observacion`, `modalidad_pago`, `modalidad_cuota`, `calendario`, `tipo_cuota`, `forma_pago`, `meses_gracia`, `porcentaje_global`, `variacion_porcentual`.
- Las columnas `modalidad_pago`, `modalidad_cuota`, `calendario`, `tipo_cuota`, `forma_pago`, `meses_gracia`, `porcentaje_global`, `variacion_porcentual` son sobrescrituras opcionales: nulo significa "heredar de la línea" (la resolución la hace `LoanConditions`, no el modelo).

### Constantes

| Constante | Valor |
|---|---|
| `STATUS_ACTIVE` | `'ACTIVO'` |
| `STATUS_SETTLED` | `'LIQUIDADO'` |

### Casts

`monto_prestamo`, `tasa_interes`, `tasa_spread`, `cuota_calculada`, `saldo_prestamo`, `porcentaje_global`, `variacion_porcentual`: float; `meses_gracia`: integer; `formalizacion`, `vencimiento`, `proximo_pago`: date.

### Métodos

| Miembro | Parámetros | Retorno | Comportamiento |
|---|---|---|---|
| `scopeActive()` | `Builder $query` | `void` | Filtra `estado = 'ACTIVO'`. |
| `isActive()` | ninguno | `bool` | `estado === STATUS_ACTIVE`. |
| `initialAnnualRate()` | ninguno | `float` | Tasa anual en porcentaje: `tasa_interes + tasa_spread` (valores del préstamo, sin considerar revisiones). |
| `linea()` | ninguno | `BelongsTo<LineaPrestamo>` | FK `id_lineas_prestamos`. |
| `recibos()` | ninguno | `HasMany<Recibo>` | FK `prestamo_id`, ordenado por `fecha_pago` ascendente y luego `id` ascendente. Incluye recibos anulados. |
| `detalles()` | ninguno | `HasMany<DetallePago>` | FK `prestamo_id`, ordenado por `fecha_pago` descendente y luego `id` descendente. |
| `revisiones()` | ninguno | `HasMany<RevisionPrestamo>` | FK `prestamo_id`, ordenado por `fecha_efectiva` ascendente y luego `id` ascendente. Incluye revisiones anuladas. |

## Recibo

`App\Models\Recibo`. Recibo de pago de un préstamo. Se anula (no se borra): `estado = 'ANULADO'` con datos de auditoría.

- Tabla: `recibos`. Factory: `ReciboFactory`.
- Fillable: `prestamo_id`, `tipo`, `numero_cuota`, `fecha_pago`, `monto_principal`, `monto_interes`, `monto_otros`, `observaciones`, `usuario`, `estado`, `anulado_at`, `anulado_por`, `motivo_anulacion`.

### Constantes

| Constante | Valor |
|---|---|
| `STATUS_ACTIVE` | `'ACTIVO'` |
| `STATUS_VOIDED` | `'ANULADO'` |

### Casts

`tipo` => enum `App\Enums\TipoRecibo` (`CUOTA_COMPLETA`, `PARCIAL_INTERES`, `SOLO_PRINCIPAL`, `ADELANTO_CUOTA`, `CANCELACION_TOTAL`); `numero_cuota` integer; `fecha_pago` date; `monto_principal`, `monto_interes`, `monto_otros` float; `anulado_at` datetime.

### Métodos

| Miembro | Parámetros | Retorno | Comportamiento |
|---|---|---|---|
| `scopeActive()` | `Builder $query` | `void` | Filtra `estado = 'ACTIVO'`. |
| `isActive()` | ninguno | `bool` | `estado === STATUS_ACTIVE`. |
| `totalAmount()` | ninguno | `float` | `round(monto_principal + monto_interes + monto_otros, 2)`. |
| `detalles()` | ninguno | `HasMany<DetallePago>` | FK `recibo_id`, ordenado por `id` ascendente. |
| `prestamo()` | ninguno | `BelongsTo<Prestamo>` | FK `prestamo_id`. |

## RevisionPrestamo

`App\Models\RevisionPrestamo`. Revisión de tasa (y opcionalmente del plazo restante) de un préstamo a partir de una fecha efectiva. A diferencia de `Recibo`, su estado se deriva de `anulado_at` (no tiene columna `estado`).

- Tabla: `prestamo_revisiones`. Factory: `RevisionPrestamoFactory`.
- Fillable: `prestamo_id`, `fecha_efectiva`, `tasa_interes`, `tasa_spread`, `plazo_restante_meses`, `observacion`, `usuario`, `anulado_at`, `anulado_por`, `motivo_anulacion`.
- Casts: `fecha_efectiva` date; `tasa_interes` float; `tasa_spread` float; `plazo_restante_meses` integer; `anulado_at` datetime.

| Miembro | Parámetros | Retorno | Comportamiento |
|---|---|---|---|
| `scopeActive()` | `Builder $query` | `void` | `whereNull('anulado_at')`. |
| `isActive()` | ninguno | `bool` | `anulado_at === null`. |
| `prestamo()` | ninguno | `BelongsTo<Prestamo>` | FK `prestamo_id`. |

## TipoPago

`App\Models\TipoPago`. Catálogo de tipos de pago usados en el detalle de recibos. Los de `sistema = true` son los códigos que el registrador de recibos necesita para armar el detalle.

- Tabla: `tipos_pago`. Fillable: `codigo`, `nombre`, `descripcion`, `afecta_saldo`, `sistema`. Factory: `TipoPagoFactory`.
- Casts: `afecta_saldo` boolean; `sistema` boolean.

### Constantes (códigos de tipo de pago)

| Constante | Valor | Significado (según la migración que los siembra) | `afecta_saldo` |
|---|---|---|---|
| `PRINCIPAL` | `'PP'` | Pago principal | sí |
| `INTEREST` | `'PI'` | Pago interés | no |
| `PAYOFF` | `'CT'` | Cancelación total | sí |
| `ADVANCE` | `'PA'` | Pago anticipado | sí |
| `PARTIAL` | `'PC'` | Pago parcial (solo interés) | no |
| `OTHER` | `'OT'` | Otros rubros | no |

| Miembro | Parámetros | Retorno | Comportamiento |
|---|---|---|---|
| `isSystem()` | ninguno | `bool` | Devuelve `$this->sistema`. |
| `detalles()` | ninguno | `HasMany<DetallePago>` | FK `tipo_pago_id`. |

## TipoTasa

`App\Models\TipoTasa`. Tipo de tasa de referencia (por ejemplo SOFR, Prime) con su valor base vigente.

- Tabla: `lineas_tipo_tasas`. Fillable: `nombre_tipo_tasa`, `base`. Factory: `TipoTasaFactory`.
- Casts: `base` float.

| Miembro | Parámetros | Retorno | Comportamiento |
|---|---|---|---|
| `lineas()` | ninguno | `HasMany<LineaPrestamo>` | FK `tipotasa_id`. |
| `historial()` | ninguno | `HasMany<TipoTasaHistorial>` | FK `tipotasa_id`, ordenado con `latest('id')` (más reciente primero). |

## TipoTasaHistorial

`App\Models\TipoTasaHistorial`. Bitácora de cambios del valor `base` de un `TipoTasa`.

- Tabla: `lineas_tipo_tasa_historial`. Fillable: `tipotasa_id`, `base_anterior`, `base_nueva`, `usuario`. Sin factory.
- Casts: `base_anterior` float; `base_nueva` float.

| Miembro | Parámetros | Retorno | Comportamiento |
|---|---|---|---|
| `tipoTasa()` | ninguno | `BelongsTo<TipoTasa>` | FK `tipotasa_id`. |

## User

`App\Models\User`. Usuario de autenticación estándar de Laravel (extiende `Authenticatable`, usa `HasFactory` y `Notifiable`). `MustVerifyEmail` está comentado.

- Tabla: `users` (convención). Fillable: `name`, `email`, `password`. Hidden: `password`, `remember_token`. Factory: `UserFactory`.
- Casts: `email_verified_at` datetime; `password` hashed.
- Sin relaciones, constantes ni scopes. Los campos de auditoría de negocio (`usuario`, `anulado_por`) son cadenas de texto, no llaves foráneas a `users`.

---

# Soporte (`app/Support`)

## CalculationOptions

`App\Support\CalculationOptions` (clase `final`, solo métodos estáticos). Listas `valor => etiqueta` de las condiciones de cálculo, compartidas por los formularios de línea y de sub préstamo.

| Método | Parámetros | Retorno | Comportamiento |
|---|---|---|---|
| `modalities()` | ninguno | `array<string, string>` | Recorre `ModalidadCuota::cases()` y devuelve `valor => label()`: `CONSTANTE` "Cuota nivelada (constante)", `DECRECIENTE` "Amortización lineal (decreciente)", `BULLET` "Solo intereses (bullet)", `CRECIENTE` "Cuota creciente (graduada)". |
| `calendars()` | ninguno | `array<string, string>` | Ídem con `DayCount::cases()`: `365/360`, `365/365`, `360/360` con su etiqueta. |
| `rateConventions()` | ninguno | `array<string, string>` | Ídem con `ConvencionTasa::cases()`: `NOMINAL` "Tasa nominal (interés simple)", `EFECTIVA` "Tasa efectiva anual (capitalizable)". |
| `paymentModes()` | ninguno | `array<string, string>` | Lista fija: `MENSUAL` "Mensual (12 cuotas al año)", `TRIMESTRAL` "Trimestral (4 cuotas al año)", `SEMESTRAL` "Semestral (2 cuotas al año)", `ANUAL` "Anual (1 cuota al año)", `UNICO` "Único (un pago al vencimiento)". |
| `paymentForms()` | ninguno | `array<string, string>` | Lista fija: `VENCIMIENTO` "Interés vencido (se paga al vencimiento de cada cuota)", `ADELANTADO` "Interés adelantado (hoy se calcula como vencido)". |
| `paymentModeCodes()` | ninguno | `array<int, string>` | `array_keys(LineaPrestamo::PAYMENTS_PER_YEAR)`, es decir `MENSUAL, TRIMESTRAL, SEMESTRAL, ANUAL, UNICO`. Sirve para validar la modalidad de pago. |

Nota: el docblock de `paymentModeCodes()` dice "Payments per year of each payment mode", pero el método devuelve solo los códigos (llaves).

## DateFormat

`App\Support\DateFormat` (clase `final`, solo métodos estáticos). Fuente única de cómo se muestran y escriben las fechas. Las fechas siempre se almacenan y validan como `yyyy-mm-dd`; esta clase solo convierte entre ISO y el formato configurado (`DATE_FORMAT` en `.env`, leído de `config('formats.date')`, por defecto `d/m/Y`).

### Constantes

| Constante | Valor | Uso |
|---|---|---|
| `SUPPORTED` | `['d/m/Y', 'm/d/Y', 'Y/m/d', 'd-m-Y', 'm-d-Y', 'Y-m-d']` | Formatos permitidos. |
| `INVALID` | `'invalid-date'` | Valor que ninguna regla de fecha acepta; se usa para entradas imposibles, de modo que se rechacen en vez de leerse en otro orden. |
| `DEFAULT` (privada) | `'d/m/Y'` | Formato de respaldo. |

### Métodos públicos

| Método | Parámetros | Retorno | Comportamiento |
|---|---|---|---|
| `pattern()` | ninguno | `string` | Formato PHP configurado; si `config('formats.date')` no está en `SUPPORTED`, devuelve `d/m/Y`. |
| `separator()` | ninguno | `string` | `/` si el patrón contiene `/`, si no `-`. |
| `order()` | ninguno | `array<int, string>` | Partes del patrón en orden, p. ej. `['d','m','Y']` (separa el patrón por el separador). |
| `placeholder()` | ninguno | `string` | Texto de ayuda de los campos: `d`=>`dd`, `m`=>`mm`, `Y`=>`aaaa`, unidos con el separador (p. ej. `dd/mm/aaaa`). |
| `regex()` | ninguno | `string` | Cuerpo de expresión regular (sin delimitadores): `\d{4}` para el año y `\d{1,2}` para día y mes, unidos con el separador escapado con `preg_quote(..., '#')`. |
| `matches()` | `string $text` | `bool` | `true` si `trim($text)` coincide completamente con `regex()`. Solo valida la forma, no que la fecha exista. |
| `toIso()` | `string $text` | `string` | Parte el texto por `/` o `-`, asigna cada pieza (como entero) según `order()` y valida con `checkdate`. Devuelve `Y-m-d` (`%04d-%02d-%02d`) o `INVALID` si la fecha es imposible. Supone que el texto ya pasó `matches()` (el número de piezas debe coincidir con el patrón). |
| `format()` | `mixed $value` | `string` | Formato de tablas y mensajes. Si es `DateTimeInterface`, `->format(pattern())`. Si es cadena que empieza con `yyyy-mm-dd`, la recompone en el formato configurado. En cualquier otro caso (incluido nulo) devuelve `''`. |
| `formatDateTime()` | `mixed $value` | `string` | Solo para `DateTimeInterface`: `pattern() . ' H:i'` (p. ej. `18/11/2026 14:30`); si no, `''`. |
| `toDisplay()` | `mixed $value` | `string` | Valor para un campo de fecha: usa `format()` si produce algo; si no, y el valor es una cadena distinta de `INVALID` que cumple `matches()`, devuelve el texto escrito por el usuario recortado; si no, `''`. Así se conservan los datos reescritos tras un error de validación. |
| `forJavaScript()` | ninguno | `array{order: array<int, string>, separator: string, placeholder: string}` | Configuración que el navegador necesita para enmascarar y leer los campos de fecha. |

Método privado: `compose(int $year, int $month, int $day): string` arma la fecha con ceros a la izquierda (`%02d`/`%04d`) en el orden y separador configurados.

---

# Factories (`database/factories`)

Todas extienden `Factory`, usan `fake()` y declaran `definition(): array`.

| Factory | Modelo | Valores por defecto relevantes | Estados personalizados |
|---|---|---|---|
| `BancoFactory` | `Banco` | `nombre_banco` = empresa aleatoria única + `' Bank'`. | ninguno |
| `EmpresaFactory` | `Empresa` | `nombre_empresa` = empresa aleatoria única. | ninguno |
| `TipoTasaFactory` | `TipoTasa` | `nombre_tipo_tasa` = palabra única en mayúsculas; `base` = decimal aleatorio de 5 decimales entre 1 y 8. | ninguno |
| `LineaPrestamoFactory` | `LineaPrestamo` | `empresa_id` = `Empresa::factory()`; `banco_id` = `null`; `nombre` = empresa única + `' DOLARES'`; `tipo_linea` `CAPITAL`; `monto_maximo` 65000000; `plazo_maximo_meses` 96; `modalidad_pago` `MENSUAL`; `estado` `STATUS_ACTIVE`; `fecha_vencmiento` `2032-03-07`; `capital_trabajo` 0; `moneda` `DOLARES`; `forma_pago` `VENCIMIENTO`; `calendario` `360/360`; `tipo_cuota` `NOMINAL`; `periodicidad_pago` 12; `tipo_interes` `BASE_SPREAD`; `modalidad_cuota` `CONSTANTE`; `variacion_porcentual` 0; `tipotasa_id` = `TipoTasa::factory()`; `tasa_interes` 2; `tasa_spread` 2. No define `disponible` (queda nulo), `meses_gracia` ni `porcentaje_global` (toman el default de BD, 0). | `inactive()`: pone `estado` en `LineaPrestamo::STATUS_INACTIVE`. |
| `PrestamoFactory` | `Prestamo` | `id_lineas_prestamos` = `LineaPrestamo::factory()`; `numero_prestamo` = 4 dígitos únicos (cadena); `monto_prestamo` 6000000; `formalizacion` `2026-03-01`; `plazo_meses` 96; `tasa_interes` 2; `tasa_spread` 2; `cuota_calculada` 73135.65; `vencimiento` `2034-03-01`; `proximo_pago` `2026-04-01`; `saldo_prestamo` 6000000; `estado` `STATUS_ACTIVE`; `observacion` `null`. Las sobrescrituras de condiciones quedan nulas. | `settled()`: `estado` = `STATUS_SETTLED` (`LIQUIDADO`) y `saldo_prestamo` = 0. |
| `ReciboFactory` | `Recibo` | `prestamo_id` = `Prestamo::factory()`; `tipo` `TipoRecibo::CuotaCompleta`; `numero_cuota` 1; `fecha_pago` `2026-04-01`; `monto_principal` 53135.65; `monto_interes` 20000; `observaciones` `null`; `usuario` `tester`; `estado` `STATUS_ACTIVE`. `monto_otros` toma el default de BD (0). No crea `DetallePago`. | `voided()`: `estado` = `STATUS_VOIDED`, `anulado_at` = `now()`, `motivo_anulacion` = `'Prueba'` (no llena `anulado_por`). |
| `RevisionPrestamoFactory` | `RevisionPrestamo` | `prestamo_id` = `Prestamo::factory()`; `fecha_efectiva` `2026-07-01`; `tasa_interes` 1.5; `tasa_spread` 2; `plazo_restante_meses` `null`; `observacion` `null`; `usuario` `tester`. | ninguno (para una revisión anulada hay que pasar `anulado_at` a mano) |
| `TipoPagoFactory` | `TipoPago` | Tipo personalizado (no de sistema), útil como concepto de "otros rubros": `codigo` = 2 letras aleatorias únicas en mayúsculas; `nombre` = 2 palabras únicas; `descripcion` `null`; `afecta_saldo` `false`; `sistema` `false`. | ninguno |
| `UserFactory` | `User` | `name`, `email` único seguro, `email_verified_at` = `now()`, `password` = hash de `'password'` (cacheado en la propiedad estática `$password`), `remember_token` aleatorio de 10 caracteres. | `unverified()`: `email_verified_at` = `null`. |

`DetallePago` y `TipoTasaHistorial` no tienen factory.

---

# Seeder (`database/seeders/DatabaseSeeder.php`)

`Database\Seeders\DatabaseSeeder` (usa `WithoutModelEvents`). Su `run(): void` crea un único usuario con `User::factory()->create(['name' => 'Test User', 'email' => 'test@example.com'])` (contraseña `password` por el factory). La línea `User::factory(10)->create()` está comentada. No siembra empresas, bancos, tasas ni préstamos; los tipos de pago base se siembran en la propia migración `create_tipos_pago_table`.

---

# Migraciones (`database/migrations`)

Motor de producción: SQL Server (base `Lineas`), donde varias tablas ya existían antes de las migraciones. Solo la migración `create_core_lending_tables` está protegida con `Schema::hasTable()`. Las demás no tienen guardas y fallarían si la tabla o columna ya existiera.

| Archivo | Qué crea o modifica | Guardas / notas |
|---|---|---|
| `0001_01_01_000000_create_users_table.php` | Crea `users`, `password_reset_tokens`, `sessions`. | Estándar de Laravel; sin guardas. `down` borra las tres. |
| `0001_01_01_000001_create_cache_table.php` | Crea `cache`, `cache_locks`. | Estándar; sin guardas. |
| `0001_01_01_000002_create_jobs_table.php` | Crea `jobs`, `job_batches`, `failed_jobs`. | Estándar; sin guardas. |
| `2026_11_19_040654_create_core_lending_tables.php` | Crea `empresas`, `lineas_tipo_tasas`, `lineas_prestamos`, `prestamos` con el esquema de producción (incluye las columnas legacy `tasa_interes_fija` y `tasa_interes_variable`). | Cada tabla se envuelve en `if (! Schema::hasTable(...))`: contra la BD de producción no cambia nada; en entornos nuevos y tests crea el esquema completo. `down()` está vacío (nunca borra). |
| `2026_11_19_040655_create_lineas_bancos_table.php` | Crea `lineas_bancos` (`nombre_banco`). | Sin guarda. `down` hace `dropIfExists`. |
| `2026_11_19_040656_add_banco_id_to_lineas_prestamos_table.php` | Agrega `lineas_prestamos.banco_id` (nullable, FK a `lineas_bancos`). | Sin guarda. `down` usa `dropConstrainedForeignId`. |
| `2026_11_19_040657_create_recibos_table.php` | Crea `recibos` (`prestamo_id` FK, `tipo`, `numero_cuota`, `fecha_pago`, `monto_principal`, `monto_interes`, `observaciones`, `usuario`, `estado` default `ACTIVO`, `anulado_at`, `motivo_anulacion`) con índice `(prestamo_id, estado, fecha_pago)`. | Sin guarda. |
| `2026_11_19_040658_create_prestamo_revisiones_table.php` | Crea `prestamo_revisiones` (`prestamo_id` FK, `fecha_efectiva`, `tasa_interes`, `tasa_spread`, `plazo_restante_meses`, `observacion`, `usuario`, `anulado_at`) con índice `(prestamo_id, fecha_efectiva)`. | Sin guarda. |
| `2026_11_19_040659_create_lineas_tipo_tasa_historial_table.php` | Crea `lineas_tipo_tasa_historial` (`tipotasa_id` FK, `base_anterior`, `base_nueva`, `usuario`). | Sin guarda. |
| `2026_11_19_191118_create_tipos_pago_table.php` | Crea `tipos_pago` (`codigo` único de 5, `nombre`, `descripcion`, `afecta_saldo`, `sistema`) y siembra los 6 tipos de sistema `PP`, `PI`, `CT`, `PA`, `PC`, `OT`, luego les fija `created_at`/`updated_at`. | Sin guarda. |
| `2026_11_19_191119_create_detalle_pago_table.php` | Crea `detalle_pago` (FK a `recibos`, `prestamos`, `tipos_pago`; `numero_cuota`, `fecha_cuota`, `fecha_pago`, `monto`, `descripcion`) con índice `(prestamo_id, fecha_pago)`. | Sin guarda. |
| `2026_11_19_191120_add_monto_otros_to_recibos_table.php` | Agrega `recibos.monto_otros` decimal(18,2) default 0. | Sin guarda. |
| `2026_11_19_191121_add_disponible_to_lineas_prestamos_table.php` | Agrega `lineas_prestamos.disponible` decimal(15,2) nullable y la inicializa con `monto_maximo` menos la suma de `saldo_prestamo` de los préstamos `ACTIVO` de cada línea. | Sin guarda. Backfill con consultas `DB::table`. |
| `2026_11_19_232725_add_anulado_por_to_recibos_and_revisiones.php` | Agrega `recibos.anulado_por`; y `prestamo_revisiones.anulado_por` y `prestamo_revisiones.motivo_anulacion` (todas string(255) nullable). | Sin guarda. |
| `2026_11_19_235955_add_calculation_conditions_to_lineas_and_prestamos.php` | En `lineas_prestamos`: `meses_gracia` (unsignedInteger, default 0) y `porcentaje_global` (decimal(8,4), default 0). En `prestamos` (todas nullable, nulo = heredar de la línea): `modalidad_pago`, `modalidad_cuota`, `calendario`, `tipo_cuota`, `forma_pago`, `meses_gracia`, `porcentaje_global`, `variacion_porcentual`. | Sin guarda. |

La tabla legacy `planpagos` (referenciada en `docs/02-base-de-datos.md`) no es creada por ninguna migración ni tiene modelo en `app/Models`.
