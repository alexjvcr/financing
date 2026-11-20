# Controladores, rutas y middleware

Referencia de la capa HTTP: el archivo de rutas, los controladores de `app/Http/Controllers`, el middleware `ConvertDisplayDates`, el `LoginRequest` y el `AppServiceProvider`. Todo lo descrito sale del código; las rutas se verificaron con `php artisan route:list --except-vendor`.

## Convenciones generales

- **Autenticación.** Todas las rutas, salvo `GET /login` y `POST /login`, están dentro del grupo `middleware('auth')`. Un visitante sin sesión es redirigido a la ruta `login`. Las dos rutas de login están en el grupo `guest`: un usuario ya autenticado que entra a `/login` es redirigido por el middleware `guest` de Laravel.
- **Sin autorización por roles.** No hay políticas ni gates: cualquier usuario autenticado puede ejecutar cualquier acción.
- **Mensajes.** Las redirecciones exitosas llevan el mensaje en la sesión flash `status`. Los errores de validación van en el `errorBag` por defecto; los bloqueos de borrado usan la clave de error `delete`.
- **Mensajes de las reglas estándar.** El proyecto no tiene archivos de idioma (`lang/`); las reglas estándar de Laravel (`required`, `numeric`, `exists`…) producen el texto por defecto del locale configurado (`APP_LOCALE`, por defecto `en`). Los mensajes escritos en español en esta referencia son los que define el propio código (`LoginRequest`, closures de validación, servicios y `ValidationException::withMessages`).
- **Errores de validación en JSON.** `bootstrap/app.php` hace que la respuesta sea JSON cuando la petición es `api/*` o `expectsJson()`; en ese caso una validación fallida responde 422.
- **Binding implícito.** `Prestamo`, `Recibo` y `RevisionPrestamo` se resuelven por `id` desde la URL (`{prestamo}`, `{recibo}`, `{revision}`); si no existen responde 404. Los catálogos usan el parámetro `{record}` (un `string`) y lo resuelven con `findOrFail`, también 404.
- **Usuario de auditoría.** Los controladores de recibos y revisiones pasan `$request->user()->email` a los servicios como campo `usuario` / `anulado_por`.
- **Middleware global web.** `ConvertDisplayDates` se agrega al final del grupo `web` en `bootstrap/app.php` (`$middleware->web(append: [...])`); ver la sección propia.

## Tabla de rutas

Son 51 rutas (salida de `route:list --except-vendor`). Todas con middleware `auth`, salvo las dos de login (`guest`). Las rutas de recurso excluyen `show` (`->except('show')`) y renombran el parámetro a `record`.

### Acceso y panel

| Método | URL | Nombre | Controlador@acción | Qué hace |
|---|---|---|---|---|
| GET\|HEAD | `/login` | `login` | `Auth\LoginController@create` | Entra automáticamente con la sesión de Windows (SSO) o muestra el formulario de login. `guest`. |
| POST | `/login` | (sin nombre) | `Auth\LoginController@store` | Valida usuario y contraseña contra Active Directory y abre sesión. `guest` + `throttle:5,1` (5 intentos por minuto). |
| POST | `/logout` | `logout` | `Auth\LoginController@destroy` | Cierra la sesión e impide que el SSO de Windows la reabra de inmediato. |
| GET\|HEAD | `/` | `dashboard` | `DashboardController` (invocable) | Panel: resumen, disponibilidad de crédito y próximas cuotas. |

### Catálogos (CRUD genérico sobre `CatalogController`)

Cada catálogo registra las mismas seis rutas; solo cambian prefijo, controlador y nombre base.

| Método | URL | Nombre | Acción | Qué hace |
|---|---|---|---|---|
| GET\|HEAD | `/empresas` | `empresas.index` | `EmpresaController@index` | Lista paginada con búsqueda `?q=`. |
| GET\|HEAD | `/empresas/create` | `empresas.create` | `EmpresaController@create` | Formulario de alta. |
| POST | `/empresas` | `empresas.store` | `EmpresaController@store` | Crea la empresa. |
| GET\|HEAD | `/empresas/{record}/edit` | `empresas.edit` | `EmpresaController@edit` | Formulario de edición. |
| PUT\|PATCH | `/empresas/{record}` | `empresas.update` | `EmpresaController@update` | Actualiza la empresa. |
| DELETE | `/empresas/{record}` | `empresas.destroy` | `EmpresaController@destroy` | Elimina la empresa si nada la usa. |
| GET\|HEAD, POST, GET\|HEAD, GET\|HEAD, PUT\|PATCH, DELETE | `/bancos`, `/bancos/create`, `/bancos/{record}/edit`, `/bancos/{record}` | `bancos.index`, `.create`, `.store`, `.edit`, `.update`, `.destroy` | `BancoController@…` | Mismo CRUD para bancos (tabla `lineas_bancos`). |
| ídem | `/tipos-tasa`, `/tipos-tasa/create`, `/tipos-tasa/{record}/edit`, `/tipos-tasa/{record}` | `tipos-tasa.index`, `.create`, `.store`, `.edit`, `.update`, `.destroy` | `TipoTasaController@…` | Mismo CRUD para tipos de tasa (tabla `lineas_tipo_tasas`) con historial. |
| ídem | `/tipos-pago`, `/tipos-pago/create`, `/tipos-pago/{record}/edit`, `/tipos-pago/{record}` | `tipos-pago.index`, `.create`, `.store`, `.edit`, `.update`, `.destroy` | `TipoPagoController@…` | Mismo CRUD para tipos de pago (tabla `tipos_pago`). |
| ídem | `/lineas`, `/lineas/create`, `/lineas/{record}/edit`, `/lineas/{record}` | `lineas.index`, `.create`, `.store`, `.edit`, `.update`, `.destroy` | `LineaPrestamoController@…` | Mismo CRUD para líneas de crédito (tabla `lineas_prestamos`). |

Verbos y URL exactos de cada recurso (iguales para los cinco): `GET /x` (index), `GET /x/create` (create), `POST /x` (store), `GET /x/{record}/edit` (edit), `PUT|PATCH /x/{record}` (update), `DELETE /x/{record}` (destroy).

### Sub préstamos, recibos, revisiones y anulaciones

| Método | URL | Nombre | Controlador@acción | Qué hace |
|---|---|---|---|---|
| GET\|HEAD | `/prestamos` | `prestamos.index` | `PrestamoController@index` | Lista paginada con filtro de estado y búsqueda. |
| GET\|HEAD | `/prestamos/create` | `prestamos.create` | `PrestamoController@create` | Formulario de alta. |
| POST | `/prestamos` | `prestamos.store` | `PrestamoController@store` | Crea el sub préstamo y calcula su plan. |
| GET\|HEAD | `/prestamos/{prestamo}` | `prestamos.show` | `PrestamoController@show` | Ficha: plan reconstruido, recibos, revisiones y detalle. |
| GET\|HEAD | `/prestamos/{prestamo}/edit` | `prestamos.edit` | `PrestamoController@edit` | Formulario de edición (parcialmente bloqueado si ya hay movimientos). |
| PUT\|PATCH | `/prestamos/{prestamo}` | `prestamos.update` | `PrestamoController@update` | Actualiza el sub préstamo. |
| DELETE | `/prestamos/{prestamo}` | `prestamos.destroy` | `PrestamoController@destroy` | Elimina el sub préstamo si no tiene dependencias. |
| GET\|HEAD | `/prestamos/{prestamo}/cotizacion` | `prestamos.cotizacion` | `PrestamoController@quote` | JSON con el costo de cancelar el préstamo en una fecha (`?fecha=`). |
| POST | `/prestamos/{prestamo}/recibos` | `prestamos.recibos.store` | `ReciboController@store` | Registra un recibo de pago. |
| GET\|HEAD | `/recibos` | `recibos.index` | `ReciboController@index` | Lista global de recibos con filtros y totales. |
| POST | `/recibos/{recibo}/anular` | `recibos.anular` | `ReciboController@void` | Anula un recibo (el más reciente del préstamo). |
| POST | `/prestamos/{prestamo}/revisiones` | `prestamos.revisiones.store` | `RevisionController@store` | Registra una revisión de tasa, spread o plazo. |
| POST | `/revisiones/{revision}/anular` | `revisiones.anular` | `RevisionController@void` | Anula una revisión (la más reciente del préstamo). |
| GET\|HEAD | `/anulaciones` | `anulaciones.index` | `AnulacionController@index` | Pantalla de anulaciones: lo anulable ahora y el historial de anulados. |

### Reportes

| Método | URL | Nombre | Controlador@acción | Qué hace |
|---|---|---|---|---|
| GET\|HEAD | `/reportes` | `reportes.index` | `ReportController@index` | Menú de reportes (listos y "Pronto"). |
| GET\|HEAD | `/reportes/{slug}` | `reportes.show` | `ReportController@show` | Muestra un reporte en pantalla. `slug` restringido a `[a-z0-9-]+`. |
| GET\|HEAD | `/reportes/{slug}/excel` | `reportes.excel` | `ReportController@download` | Descarga el reporte como `.xlsx`. `slug` restringido a `[a-z0-9-]+`. |

Nota: el orden de definición en `routes/web.php` es el que se lee arriba; `route:list` los ordena alfabéticamente por URL. `POST /login` es la única ruta sin nombre.

---

## Controller

Clase base abstracta (`App\Http\Controllers\Controller`). Está vacía (solo un comentario `//`): no hereda traits de autorización ni de validación. Todos los controladores la extienden.

---

## CatalogController

`App\Http\Controllers\CatalogController` (abstracta, extiende `Controller`). Implementa las pantallas de listado, alta, edición y borrado compartidas por los catálogos de configuración. Cada catálogo concreto solo declara qué modelo usa, cómo se ve y qué validar, sobrescribiendo métodos "gancho". Vistas usadas: `catalog.index` y `catalog.form`.

Constante: `PER_PAGE = 15` (registros por página).

### Métodos abstractos (obligatorios)

| Método | Retorno | Para qué sirve |
|---|---|---|
| `model()` | `class-string<Model>` | Clase Eloquent que administra la pantalla. |
| `title()` | `string` | Título plural de la pantalla (ej. "Bancos"). |
| `singular()` | `string` | Nombre singular; se usa en los mensajes ("Banco creado correctamente."). |
| `routeBase()` | `string` | Prefijo de nombre de ruta (`empresas`, `tipos-tasa`…). Los redirects usan `route($routeBase.'.index')`. |
| `columns()` | `array<string, callable(Model): mixed>` | Etiqueta de columna => callback que pinta la celda del registro. La primera columna es además el nombre del registro (ver `recordLabel`). |
| `fields()` | `array<string, array<string, mixed>>` | Nombre de campo => definición del campo del formulario (`label`, `type`, `options`, `step`, `hint`, `required`, `group`, `icon`, `span`, `prefix`, `suffix`, `placeholder`, `blank`, `readonly`…). La vista `catalog.form` las interpreta. |
| `rules(?Model $record)` | `array<string, array<int, mixed>>` | Reglas de validación. Recibe `null` en el alta y el modelo en la edición (permite ignorar el propio registro en reglas de unicidad). |

### Ganchos opcionales (con valor por defecto)

| Gancho | Por defecto | Cuándo se usa y qué permite |
|---|---|---|
| `groups(): array` | `[]` | Secciones del formulario: título => `['icon', 'description', 'tone'?]`. Los campos se asignan a una sección con la clave `group`. |
| `searchColumns(): array<int, string>` | `[]` | Columnas sobre las que busca el cuadro `?q=` (`LIKE %término%`). |
| `eagerLoads(): array<int, string>` | `[]` | Relaciones a precargar en el listado (evita N+1). |
| `applySearch(Builder $query, string $term): void` | `WHERE (col1 LIKE … OR col2 LIKE …)` sobre `searchColumns()` | Reemplazable para buscar también en relaciones. |
| `deletionBlockers(Model $record): array<int, string>` | `[]` | Motivos por los que no se puede borrar (otras filas lo referencian). Cada texto se concatena en el mensaje de error; si el arreglo no está vacío, no se borra. También se muestra en el listado para deshabilitar el botón. |
| `deleteRecord(Model $record): void` | `$record->delete()` | Borra el registro y lo que existe solo para describirlo (ej. su historial). Corre dentro de una transacción. |
| `adjustFields(array $fields, ?Model $record): array` | devuelve `$fields` tal cual | Permite alterar los campos para el registro editado (ej. bloquear algunos). Recibe `null` en el alta. |
| `prepare(array $data, ?Model $record): array` | devuelve `$data` | Transforma o completa los datos ya validados antes de guardar (valores derivados). |
| `afterSave(Model $record, ?array $original): void` | no hace nada | Efecto posterior al guardado. `$original` es `null` en el alta y `$model->getOriginal()` (valores antes de actualizar) en la edición. |
| `extraFormData(?Model $record): array<string, mixed>` | `[]` | Variables adicionales para la vista `catalog.form` (se suman con `+` a los datos base). |
| `recordLabel(Model $record): string` | resultado del callback de la primera columna de `columns()` | Nombre del registro en mensajes y diálogos de confirmación. |

### index(Request $request): View

- **Entrada:** query `q` (búsqueda, se aplica `trim`; vacío = sin filtro).
- **Proceso:** consulta `model()::query()->with(eagerLoads())->orderBy('id')`; si hay término llama a `applySearch`; pagina de a 15 con `withQueryString()` (conserva `q` entre páginas).
- **Retorno:** vista `catalog.index` con `title`, `singular`, `routeBase`, `columns`, `records`, `term`, `blockers` (id => lista de bloqueos de `deletionBlockers`) y `labels` (id => `recordLabel`).
- **Efectos:** ninguno (solo lectura). Ejecuta `deletionBlockers` y `recordLabel` por cada fila de la página (los bloqueadores hacen un `count()` por fila).
- **Errores:** ninguno propio.

### create(): View

Devuelve `form(null)`: formulario vacío de alta.

### store(Request $request): RedirectResponse

- **Validación:** `$request->validate($this->rules(null))`. Si falla, redirige atrás con los errores y los datos viejos (o 422 JSON).
- **Proceso:** `prepare($validated, null)` → `model()::create($data)` → `afterSave($record, null)`.
- **Retorno:** redirección a `{routeBase}.index` con flash `status` = `"{singular} creado correctamente."`.
- **Efectos:** inserta una fila en la tabla del modelo (más lo que haga `afterSave`). No usa transacción propia: si `afterSave` falla, el registro ya quedó creado.
- **Errores:** `ValidationException` (reglas); excepciones de base de datos si el modelo rechaza los datos (el atributo `Fillable` del modelo filtra la asignación masiva).

### edit(string $record): View

Busca con `findOrFail($record)` y devuelve `form($model)`. **Error:** 404 si el id no existe.

### update(Request $request, string $record): RedirectResponse

- **Proceso:** `findOrFail`; guarda `$original = $model->getOriginal()`; valida con `rules($model)`; `prepare($data, $model)`; `$model->update($data)`; `afterSave($model, $original)`.
- **Retorno:** redirección a `{routeBase}.index` con `status` = `"{singular} actualizado correctamente."`.
- **Efectos:** actualiza la fila (más lo que haga `afterSave`). Sin transacción propia.
- **Errores:** 404 si no existe; `ValidationException`.

### destroy(string $record): RedirectResponse

- **Proceso:** `findOrFail`; calcula `recordLabel` y `deletionBlockers`.
- **Si hay bloqueos:** `back()->withErrors(['delete' => "No se puede eliminar «{etiqueta}»: {bloqueos separados por espacio}"])`. No borra nada.
- **Si no:** `DB::transaction(fn () => deleteRecord($model))`.
- **Retorno:** redirección a `{routeBase}.index` con `status` = `"{singular} «{etiqueta}» eliminado correctamente."`.
- **Efectos:** borra la fila y, según el gancho `deleteRecord`, filas dependientes (solo `TipoTasaController` borra más: su historial).
- **Errores:** 404 si no existe; el bloqueo se informa por el error `delete` (no es una excepción).

### form(?Model $record): View (protegido)

Arma la vista `catalog.form` con `title`, `singular`, `routeBase`, `fields` (= `adjustFields(fields(), $record)`), `groups` (= `groups()`) y `record`; a ese arreglo se le suma `extraFormData($record)`. Lo usan `create` y `edit` (y `PrestamoController` tiene su propio `form` que usa la misma vista).

### Cómo usan los ganchos los cinco catálogos

| Gancho | Empresa | Banco | TipoTasa | LineaPrestamo | TipoPago |
|---|---|---|---|---|---|
| `model/title/singular/routeBase` | `Empresa` / "Empresas" / "Empresa" / `empresas` | `Banco` / "Bancos" / "Banco" / `bancos` | `TipoTasa` / "Tipos de tasa" / "Tipo de tasa" / `tipos-tasa` | `LineaPrestamo` / "Líneas de crédito" / "Línea de crédito" / `lineas` | `TipoPago` / "Tipos de pago" / "Tipo de pago" / `tipos-pago` |
| `columns` | Empresa, Líneas (conteo) | Banco, Líneas (conteo) | Tipo de tasa, Valor base (%) (5 decimales, `—` si es nulo), Líneas | Línea, Banco (`—` si no tiene), Empresa, Moneda, Monto máximo, Disponible (si es nulo usa el monto máximo), Pago (`modalidad_pago · modalidad_cuota`), Estado | Código, Nombre, Descripción (`—`), Reduce el saldo (Sí/No), Origen (Sistema/Propio) |
| `fields` | `nombre_empresa` (texto) | `nombre_banco` (texto) | `nombre_tipo_tasa` (texto), `base` (número, paso 0.00001, opcional, hint de historial) | 19 campos en 4 grupos (ver abajo) | `codigo`, `nombre`, `descripcion` (opcional), `afecta_saldo` (select Sí=1/No=0) |
| `rules` | `nombre_empresa`: required, string, max:255 | `nombre_banco`: required, string, max:255 | `nombre_tipo_tasa`: required, string, max:255; `base`: nullable, numeric, min:0, max:999 | Ver sección de la clase | Ver sección de la clase (unicidad del código sin distinguir mayúsculas) |
| `groups` | no | no | no | sí (4 secciones) | sí (1 sección "Tipo de pago") |
| `searchColumns` | `nombre_empresa` | `nombre_banco` | `nombre_tipo_tasa` | `nombre`, `tipo_linea`, `moneda`, `modalidad_pago`, `modalidad_cuota`, `estado` | `codigo`, `nombre`, `descripcion` |
| `eagerLoads` | no | no | no | `banco`, `empresa` | no |
| `applySearch` | por defecto | por defecto | por defecto | sobrescrito: además busca en `banco.nombre_banco` y `empresa.nombre_empresa` | por defecto |
| `deletionBlockers` | líneas asociadas | líneas asociadas | líneas que usan el tipo | sub préstamos asociados | es del sistema; líneas en el detalle de pagos |
| `deleteRecord` | por defecto | por defecto | sobrescrito: borra primero el historial | por defecto | por defecto |
| `adjustFields` | no | no | no | no | sí: bloquea `codigo` y `afecta_saldo` si es del sistema |
| `prepare` | no | no | no | sí: deriva `periodicidad_pago` y normaliza nulos | sí: mayúsculas, protege campos del sistema, fuerza `sistema=false` |
| `afterSave` | no | no | sí: escribe historial de cambios de `base` | sí: recalcula `disponible` de la línea | no |
| `extraFormData` | no | no | sí: `historial` (últimos 15) | no | no |
| `recordLabel` | por defecto (nombre de la empresa) | por defecto (nombre del banco) | por defecto (nombre del tipo) | por defecto (nombre de la línea) | por defecto (el **código**, primera columna) |

---

## EmpresaController

Extiende `CatalogController`. Catálogo de empresas (tabla `empresas`, modelo `Empresa`). Vistas y rutas: ver tabla de recursos (`empresas.*`).

Solo implementa ganchos; todos los métodos públicos (`index`, `create`, `store`, `edit`, `update`, `destroy`) los hereda de `CatalogController`.

- `model()` → `Empresa::class`; `title()` → `'Empresas'`; `singular()` → `'Empresa'`; `routeBase()` → `'empresas'`.
- `columns()` → `Empresa` (`nombre_empresa`) y `Líneas` (`$empresa->lineas()->count()`).
- `fields()` → `nombre_empresa`, etiqueta "Nombre de la empresa", tipo `text`.
- `rules()` → `nombre_empresa`: `required`, `string`, `max:255`. No exige unicidad.
- `searchColumns()` → `['nombre_empresa']`.
- `deletionBlockers()` → si `lineas()->count() > 0`: `"tiene N línea(s) de crédito asociada(s)."`.
- **Efectos:** inserta/actualiza/elimina en `empresas`. **Errores:** ver `CatalogController` (404, validación, bloqueo `delete`).

---

## BancoController

Extiende `CatalogController`. Catálogo de bancos (tabla `lineas_bancos`, modelo `Banco`; rutas `bancos.*`).

- `model()` → `Banco::class`; `title()` → `'Bancos'`; `singular()` → `'Banco'`; `routeBase()` → `'bancos'`.
- `columns()` → `Banco` (`nombre_banco`) y `Líneas` (conteo de `lineas()`).
- `fields()` → `nombre_banco`, etiqueta "Nombre del banco", tipo `text`.
- `rules()` → `nombre_banco`: `required`, `string`, `max:255`.
- `searchColumns()` → `['nombre_banco']`.
- `deletionBlockers()` → si tiene líneas: `"tiene N línea(s) de crédito asociada(s)."`.
- **Efectos:** `lineas_bancos`. **Errores:** los heredados. Nota: la columna `banco_id` de las líneas es opcional, pero un banco con líneas no se puede borrar.

---

## TipoTasaController

Extiende `CatalogController`. Catálogo de tipos de tasa base (tabla `lineas_tipo_tasas`, modelo `TipoTasa`; rutas `tipos-tasa.*`). Cada cambio del valor base queda en el historial (`lineas_tipo_tasa_historial`, modelo `TipoTasaHistorial`).

- `model()` → `TipoTasa::class`; `title()` → `'Tipos de tasa'`; `singular()` → `'Tipo de tasa'`; `routeBase()` → `'tipos-tasa'`.
- `columns()` → `Tipo de tasa` (`nombre_tipo_tasa`), `Valor base (%)` (`—` si `base` es nulo, si no `number_format(base, 5)`) y `Líneas` (conteo).
- `fields()` → `nombre_tipo_tasa` (texto) y `base` (número, `step` 0.00001, `required => false`, hint "Cada cambio queda registrado en el historial.").
- `rules()` → `nombre_tipo_tasa`: `required|string|max:255`; `base`: `nullable|numeric|min:0|max:999`.
- `searchColumns()` → `['nombre_tipo_tasa']`.
- `deletionBlockers()` → si hay líneas: `"N línea(s) de crédito usan este tipo de tasa."`.
- `deleteRecord(Model $record)`: dentro de la transacción de `destroy` borra `$record->historial()` y luego el registro. **Efecto:** elimina filas de `lineas_tipo_tasa_historial` y de `lineas_tipo_tasas`.
- `extraFormData(?Model $record)` → `['historial' => $record?->historial()->limit(15)->get()]`: los 15 cambios más recientes (la relación ordena por `id` descendente); `null` en el alta.
- `afterSave(Model $record, ?array $original)`:
  1. `$previous` = `$original['base']` convertido a `float` (o `null` si no había original/valor).
  2. Si es una edición (`$original !== null`) y `$previous === $record->base`, no hace nada (el valor no cambió).
  3. En cualquier otro caso crea una fila en `lineas_tipo_tasa_historial` con `tipotasa_id`, `base_anterior` (`$previous`), `base_nueva` (`$record->base`) y `usuario` = email del usuario autenticado (`auth()->user()->email`).
  - **Consecuencia:** al crear un tipo de tasa siempre se registra una entrada (anterior `null`), incluso si `base` es nulo; al editar solo se registra si cambia `base`. Cambiar solo el nombre no genera historial.
- **Errores:** los heredados. `afterSave` asume usuario autenticado (las rutas lo garantizan).

---

## TipoPagoController

Extiende `CatalogController`. Catálogo de tipos de pago (tabla `tipos_pago`, modelo `TipoPago`; rutas `tipos-pago.*`). Distingue tipos **del sistema** (`sistema = true`: PP, PI, CT, PA, PC, OT, que el registro de recibos necesita) y tipos **propios** (usados como "otros rubros" que no modifican el saldo).

- `model()` → `TipoPago::class`; `title()` → `'Tipos de pago'`; `singular()` → `'Tipo de pago'`; `routeBase()` → `'tipos-pago'`.
- `columns()` → `Código`, `Nombre`, `Descripción` (`—` si es nula), `Reduce el saldo` (Sí/No según `afecta_saldo`), `Origen` (`Sistema` si `isSystem()`, si no `Propio`). La primera columna es el código, por lo que `recordLabel` devuelve el código.
- `fields()` → todos en el grupo "Tipo de pago": `codigo` (texto, ej. "PP", hint "Hasta 5 caracteres, en mayúsculas."), `nombre`, `descripcion` (opcional) y `afecta_saldo` (select `1 => Sí`, `0 => No`; hint sobre "otros rubros").
- `groups()` → `'Tipo de pago'` (icono `banknote`, tono `green`, "Conceptos con los que se detalla cada recibo de pago.").
- `adjustFields(array $fields, ?Model $record)`: si `$record?->isSystem()`, marca `codigo` y `afecta_saldo` como `readonly` y cambia el hint de `codigo` a "Tipo del sistema: el código y el efecto sobre el saldo no se pueden cambiar.".
- `rules(?Model $record)`:
  - `codigo`: `required`, `string`, `max:5`, `regex:/^[A-Za-z0-9]+$/` y una closure que rechaza con **"Ya existe un tipo de pago con ese código."** si existe otro registro con el mismo código comparando `UPPER(codigo)` (ignora el propio registro en la edición con `whereKeyNot`).
  - `nombre`: `required|string|max:100`.
  - `descripcion`: `nullable|string|max:255`.
  - `afecta_saldo`: `required|boolean`.
- `prepare(array $data, ?Model $record)`: pone `codigo` en mayúsculas; si el registro editado es del sistema, restituye `codigo` y `afecta_saldo` originales (aunque el formulario enviara otros valores); si no es del sistema (alta o edición de uno propio), fija `sistema = false`. Así un tipo propio nunca puede volverse "del sistema" por el formulario.
- `searchColumns()` → `codigo`, `nombre`, `descripcion`.
- `deletionBlockers()` → acumula: si es del sistema, `"es un tipo del sistema y el cálculo de los recibos lo necesita."`; si tiene `detalles()`, `"tiene N línea(s) en el detalle de pagos."` (tabla `detalle_pago`).
- **Efectos:** `tipos_pago`. **Errores:** los heredados más el mensaje de unicidad.

---

## LineaPrestamoController

Extiende `CatalogController`. Catálogo de líneas de crédito (tabla `lineas_prestamos`, modelo `LineaPrestamo`; rutas `lineas.*`). Es el catálogo más complejo: define las condiciones de pago y tasa de las que parten los sub préstamos.

- `model()` → `LineaPrestamo::class`; `title()` → `'Líneas de crédito'`; `singular()` → `'Línea de crédito'`; `routeBase()` → `'lineas'`.
- `columns()` → `Línea` (`nombre`), `Banco` (`—` si no tiene), `Empresa`, `Moneda`, `Monto máximo` y `Disponible` (ambos con 2 decimales; `Disponible` usa `monto_maximo` si `disponible` es nulo), `Pago` (`modalidad_pago · modalidad_cuota`) y `Estado`.
- `eagerLoads()` → `['banco', 'empresa']`.
- `searchColumns()` → `nombre`, `tipo_linea`, `moneda`, `modalidad_pago`, `modalidad_cuota`, `estado`.
- `applySearch(Builder $query, string $term)`: sobrescrito; dentro de un `WHERE (...)` agrupado hace `OR LIKE` sobre esas columnas y además `orWhereHas('banco')` (`nombre_banco`) y `orWhereHas('empresa')` (`nombre_empresa`).
- `groups()` → cuatro secciones: "Datos generales" (azul), "Montos y plazos" (verde), "Condiciones de pago" (ámbar) y "Tasas" (violeta), cada una con icono y descripción.
- `fields()` → cada campo lleva `group` e `icon` (asignado desde un mapa interno de iconos):
  - **Datos generales:** `nombre`, `empresa_id` (select de `Empresa` ordenadas por nombre), `banco_id` (select de `Banco`, opcional, opción en blanco "Sin banco"), `tipo_linea` (CAPITAL, REVOLVENTE, SOBREGIRO), `moneda` (DOLARES, COLONES, EUROS), `estado` (`LineaPrestamo::STATUS_ACTIVE` / `STATUS_INACTIVE`).
  - **Montos y plazos:** `monto_maximo`, `capital_trabajo`, `plazo_maximo_meses`, `fecha_vencmiento` (tipo `date`; la columna se llama así, con esa ortografía).
  - **Condiciones de pago:** `modalidad_pago`, `modalidad_cuota`, `calendario`, `tipo_cuota`, `forma_pago`, `meses_gracia`, `porcentaje_global`, `variacion_porcentual`; las opciones salen de `CalculationOptions` (`paymentModes()`, `modalities()`, `calendars()`, `rateConventions()`, `paymentForms()`).
  - **Tasas:** `tipo_interes` (BASE_SPREAD, FIJO, VARIABLE, MIXTO, NOMINAL, EFECTIVO, SUBVENCIONADO, PENAL), `tipotasa_id` (select de `TipoTasa`), `tasa_interes`, `tasa_spread`.
- `rules(?Model $record)` (el helper `$in` agrega `required` + `Rule::in`):

  | Campo | Reglas |
  |---|---|
  | `nombre` | required, string, max:255 |
  | `empresa_id` | required, exists:empresas,id |
  | `banco_id` | nullable, exists:lineas_bancos,id |
  | `tipo_linea` | required, in (CAPITAL, REVOLVENTE, SOBREGIRO) |
  | `moneda` | required, in (DOLARES, COLONES, EUROS) |
  | `monto_maximo`, `capital_trabajo` | required, numeric, min:0 |
  | `plazo_maximo_meses` | required, integer, min:1 |
  | `fecha_vencmiento` | required, date |
  | `modalidad_pago` | required, in `CalculationOptions::paymentModeCodes()` |
  | `modalidad_cuota` | required, in claves de `modalities()` |
  | `forma_pago` | required, in claves de `paymentForms()` |
  | `calendario` | required, in claves de `calendars()` |
  | `tipo_cuota` | required, in claves de `rateConventions()` |
  | `meses_gracia` | nullable, integer, min:0, max:600 |
  | `porcentaje_global` | nullable, numeric, min:0, max:100 |
  | `tipo_interes` | required, in (la lista de ocho tipos) |
  | `tipotasa_id` | required, exists:lineas_tipo_tasas,id |
  | `tasa_interes`, `tasa_spread`, `variacion_porcentual` | required, numeric, min:0, max:999 |
  | `estado` | required, in (ACTIVO, INACTIVO) |

  Mensajes: los estándar de Laravel (no hay mensajes personalizados).
- `prepare(array $data, ?Model $record)`: `periodicidad_pago` = `LineaPrestamo::PAYMENTS_PER_YEAR[$data['modalidad_pago']]` (pagos por año derivados de la periodicidad); `meses_gracia ??= 0`; `porcentaje_global ??= 0`.
- `afterSave(Model $record, ?array $original)`: llama `$record->refreshAvailable()`, que recalcula `disponible = monto_maximo − SUM(saldo_prestamo de sus sub préstamos ACTIVOS)` y lo guarda en `lineas_prestamos`. Así el disponible queda coherente cuando se cambia el monto máximo.
- `deletionBlockers()` → si `prestamos()->count() > 0`: `"tiene N sub préstamo(s) asociado(s)."`.
- **Efectos:** escribe `lineas_prestamos` (incluye `periodicidad_pago` y `disponible`). **Errores:** los heredados más las reglas anteriores.

---

## DashboardController

`App\Http\Controllers\DashboardController`, controlador invocable (ruta `dashboard`, `GET /`).

### __invoke(DashboardMetrics $metrics): View

- **Parámetros:** el servicio `DashboardMetrics` (inyectado).
- **Retorno:** vista `dashboard` con `summary` (`summary()`), `availability` (`creditAvailability()`), `nextInstallment` (`nextInstallment()`) e `installments` (`pendingInstallments()`).
- **Efectos:** ninguno (solo lectura; las cifras salen del plan reconstruido de cada préstamo activo). **Errores:** ninguno propio.

---

## Auth\LoginController

`App\Http\Controllers\Auth\LoginController`. Autenticación contra Active Directory con soporte opcional de inicio de sesión integrado de Windows (SSO). Constructor: `__construct(protected ActiveDirectoryAuthenticator $directory)`. No hay contraseñas locales: los usuarios se crean/actualizan en la tabla `users` al iniciar sesión.

### create(Request $request): View|RedirectResponse (`GET /login`)

1. Lee y **elimina** de la sesión la marca `windows_sso_disabled` (`pull`, valor por defecto `false`). Si estaba puesta (el usuario acaba de cerrar sesión), no se intenta el SSO esta vez.
2. Si no estaba, pide a `ActiveDirectoryAuthenticator::windowsAccountName($request)` la cuenta de Windows (solo si `config('ldap.windows_sso')` está activo y IIS entrega `REMOTE_USER`/`AUTH_USER`; si el dominio NetBIOS no coincide devuelve `null`).
3. Si hay cuenta: `signIn($request, ['name' => cuenta, 'email' => emailFor(cuenta)], updateName: false)` y redirige.
4. Si no: devuelve la vista `auth.login`.

- **Efectos:** puede crear el usuario y abrir sesión (ver `signIn`).
- **Errores:** ninguno visible; sin SSO simplemente muestra el formulario.

### store(LoginRequest $request): RedirectResponse (`POST /login`)

- **Validación:** `LoginRequest` (ver sección propia). Además la ruta aplica `throttle:5,1`: al sexto intento en un minuto responde 429.
- **Proceso:** llama a `ActiveDirectoryAuthenticator::authenticate(username, password)`:
  - `RuntimeException` (no se pudo contactar al controlador de dominio o falló el enlace por un motivo distinto a credenciales): `Log::error(mensaje)` y `back()` con el usuario tecleado (`withInput(only('username'))`, nunca la contraseña) y error `username` = **"No fue posible contactar al directorio activo. Intente más tarde."**.
  - Retorna `null` (usuario o contraseña inválidos, usuario o contraseña vacíos tras normalizar): `back()` con error `username` = **"Usuario o contraseña incorrectos."**.
  - Retorna el perfil `{username, name, email}`: `signIn($request, $profile, updateName: true)`.
- **Retorno:** redirección (al destino previsto o al dashboard) o `back()` con errores.
- **Efectos:** ver `signIn`.

### destroy(Request $request): RedirectResponse (`POST /logout`)

`Auth::logout()`, invalida la sesión, regenera el token CSRF y, **después de invalidar**, guarda `windows_sso_disabled = true` en la nueva sesión; redirige a `login`. La marca evita que la siguiente visita a `/login` vuelva a iniciar sesión sola con la cuenta de Windows. **Efectos:** solo sesión. **Errores:** ninguno (la ruta está protegida por `auth`).

### signIn(Request $request, array $profile, bool $updateName): RedirectResponse (protegido)

- **Parámetros:** `$profile` = `array{name: string, email: string}`; `$updateName` indica si se sincroniza el nombre.
- **Proceso:** `User::firstOrCreate(['email' => $profile['email']], ['name' => ..., 'password' => Str::random(40)])` (contraseña aleatoria que nadie usa); si `$updateName` y el nombre guardado es distinto, lo actualiza; `Auth::login($user)`; `session()->regenerate()`.
- **Retorno:** `redirect()->intended(route('dashboard'))`.
- **Efectos:** inserta o actualiza una fila de `users`. Con el SSO (`create`) el nombre del usuario no se actualiza (solo se usa la cuenta de Windows); con el login manual sí (nombre completo desde el directorio).

---

## ConvertDisplayDates (middleware)

`App\Http\Middleware\ConvertDisplayDates`. Permite que todos los formularios muestren y envíen fechas en el formato configurado (`DATE_FORMAT`, p. ej. `d/m/Y`) mientras controladores y modelos siguen recibiendo ISO `yyyy-mm-dd`. Se registra en `bootstrap/app.php` como middleware del grupo `web` (`append`), por lo que se ejecuta en todas las rutas web, también antes de la validación de los controladores.

### handle(Request $request, Closure $next): Response

- **Entrada:** el componente `<x-date-input>` agrega un campo oculto `_dates[]` por cada campo de fecha con el nombre del campo.
- **Proceso:** si `_dates` es un arreglo, recorre los nombres; para cada nombre string toma `$request->input($name)`; si es un string que cumple `DateFormat::matches()` (patrón del formato configurado), guarda `DateFormat::toIso($value)`. Si la fecha es imposible (p. ej. 31/02/2026) `toIso` devuelve el marcador `invalid-date`, que luego rechaza la regla `date`. Los valores vacíos o que ya estén en otro formato (por ejemplo ISO) no se tocan. Luego `merge`a los convertidos en la petición y **elimina** `_dates` del cuerpo y de la query.
- **Retorno:** `$next($request)`.
- **Efectos:** modifica solo la petición en memoria. **Errores:** no lanza; deja que la validación de cada controlador reporte la fecha inválida.
- Como también limpia `query->_dates`, sirve para formularios GET (filtros `desde`/`hasta` de recibos).

---

## LoginRequest

`App\Http\Requests\Auth\LoginRequest` (`FormRequest`), usado por `LoginController@store`.

- `authorize(): bool` → siempre `true` (la ruta es pública para invitados).
- `rules(): array` →
  - `username`: `required`, `string`, `max:256`.
  - `password`: `required`, `string`.
- `messages(): array` → `username.required` = **"Ingrese su usuario."**; `password.required` = **"Ingrese su contraseña."**. Las demás reglas (`string`, `max`) usan el mensaje estándar.
- **Errores:** validación fallida redirige a `/login` con los errores y la entrada anterior (el campo `password` no se reenvía por defecto).

---

## AnulacionController

`App\Http\Controllers\AnulacionController`. Pantalla de anulaciones: qué se puede anular ahora y el historial de lo anulado. Constructor: `ReceiptRegistrar $receipts`, `RevisionRegistrar $revisions`. Las acciones de anulación en sí están en `ReciboController@void` y `RevisionController@void`.

### index(Request $request): View (`GET /anulaciones`)

- **Entrada:** `q` (búsqueda).
- **Proceso:**
  1. Carga todos los sub préstamos (`with('linea.banco')`, ordenados por `numero_prestamo`, sin paginar) filtrados por `q` sobre `numero_prestamo`, nombre de la línea o nombre del banco.
  2. `voidableReceipts`: por cada préstamo, `ReceiptRegistrar::latestActive()`: solo el recibo activo más reciente es anulable (los recibos se anulan del último al primero); se descartan los nulos.
  3. `voidableRevisions`: igual con `RevisionRegistrar::latestActive()`.
  4. `voidedReceipts`: recibos con `estado = ANULADO` de esos préstamos, con `prestamo.linea.banco`, ordenados por `anulado_at` y `id` descendentes, paginados de a 15 con el nombre de página `recibos_page`.
  5. `voidedRevisions`: revisiones con `anulado_at` no nulo, ordenadas igual, paginadas de a 15 con `revisiones_page`.
- **Retorno:** vista `anulaciones.index` con `term`, `voidableReceipts`, `voidableRevisions`, `voidedReceipts`, `voidedRevisions`.
- **Efectos:** ninguno (lectura). **Errores:** ninguno propio. Nota: `latestActive` hace una consulta por préstamo.

---

## PrestamoController

`App\Http\Controllers\PrestamoController`. CRUD de sub préstamos (tabla `prestamos`, modelo `Prestamo`) más la cotización de cancelación. No usa `CatalogController`, pero reutiliza la vista `catalog.form`. Constructor: `LoanScheduleService $schedules`, `LoanScheduleBuilder $builder`, `LegacyPlanSync $legacyPlan`. Los estados posibles del préstamo son `ACTIVO` y `LIQUIDADO`.

### index(Request $request): View (`GET /prestamos`)

- **Entrada:** `estado` (solo se aplica si es `ACTIVO` o `LIQUIDADO`; otro valor se ignora) y `q`.
- **Proceso:** préstamos con `linea.banco` y `linea.empresa`; `q` busca (`LIKE`) en `numero_prestamo`, `observacion`, nombre de la línea y nombre del banco de la línea; orden `numero_prestamo`; 15 por página con `withQueryString()`.
- **Retorno:** vista `prestamos.index` con `status`, `term`, `loans` y `blockers` (id => resultado de `deletionBlockers`).
- **Efectos:** ninguno. **Errores:** ninguno.

### create(): View (`GET /prestamos/create`)

Devuelve `form(null)`.

### store(Request $request): RedirectResponse (`POST /prestamos`)

1. Valida `rules(null)` (ver `rules`).
2. `LineaPrestamo::findOrFail($data['id_lineas_prestamos'])` (la regla `exists` ya garantiza que existe y está activa).
3. `ensureCreditAvailable($line, monto, null)`.
4. Crea `new Prestamo($data + ['estado' => ACTIVO])`, `applyDerivedFigures($loan, $line)` y `save()`.
5. `LoanScheduleService::refreshSnapshot($loan)`.
- **Retorno:** redirección a `prestamos.show` con `status` = "Sub Préstamo creado. Ya puede registrar recibos y revisiones.".
- **Efectos (tablas):** inserta en `prestamos`; `refreshSnapshot` actualiza en `prestamos` (`saldo_prestamo`, `estado`, `proximo_pago`, `vencimiento`), recalcula `disponible` en `lineas_prestamos` y refleja las cuotas en la tabla histórica `planpagos`. Los pasos no van en una transacción única.
- **Errores:** `ValidationException` por reglas o por exceder el disponible (error en `monto_prestamo`).

### show(Prestamo $prestamo): View (`GET /prestamos/{prestamo}`)

- **Proceso:** carga `linea.banco` y `linea.empresa`; reconstruye el plan con `LoanScheduleService::scheduleFor()`.
- **Retorno:** vista `prestamos.show` con: `loan`; `schedule` (plan); `next` (`nextOpenInstallment()`); `receipts` (recibos ordenados por fecha de pago descendente y luego por id descendente); `revisions` (revisiones en orden inverso); `receiptTypes` (`TipoRecibo::cases()`); `details` (detalle de pagos con `tipoPago` y `recibo`); `otherTypes` (tipos de pago propios, `sistema = false`, más el código `OT`, ordenados por código; alimentan el select de "otros rubros"); `blockers` (motivos de no borrado).
- **Efectos:** ninguno. **Errores:** 404 si el préstamo no existe.

### edit(Prestamo $prestamo): View (`GET /prestamos/{prestamo}/edit`)

Devuelve `form($prestamo)`. 404 si no existe.

### update(Request $request, Prestamo $prestamo): RedirectResponse (`PUT|PATCH /prestamos/{prestamo}`)

1. Valida `rules($prestamo)` (la unicidad de `numero_prestamo` ignora el propio registro). Se validan todos los campos aunque estén bloqueados.
2. **Si está bloqueado** (`isLocked`: tiene recibos activos o revisiones activas): solo actualiza `numero_prestamo` y `observacion`; no recalcula nada.
3. **Si no está bloqueado:** `findOrFail` de la línea; `ensureCreditAvailable($line, monto, $prestamo)` (excluye este préstamo del uso); `fill($data)`; `applyDerivedFigures`; guarda el `id_lineas_prestamos` original, `save()`, `refreshSnapshot`, y si la línea cambió llama `refreshAvailable()` en la línea anterior.
- **Retorno:** redirección a `prestamos.show` con `status` = "Sub Préstamo actualizado.".
- **Efectos:** `prestamos`; en el caso no bloqueado también `lineas_prestamos.disponible` (línea nueva y, si cambió, la anterior) y `planpagos`.
- **Errores:** 404; `ValidationException` (reglas o disponible insuficiente).

### destroy(Prestamo $prestamo): RedirectResponse (`DELETE /prestamos/{prestamo}`)

- **Si `deletionBlockers` no está vacío:** `back()` con error `delete` = `"No se puede eliminar el sub préstamo {numero}: {motivos}"`.
- **Si no:** en una transacción, `LegacyPlanSync::purge($prestamo)` (borra sus filas de `planpagos`) y `$prestamo->delete()`; después `linea->refreshAvailable()` (fuera de la transacción).
- **Retorno:** redirección a `prestamos.index` con `status` = `"Sub Préstamo {numero} eliminado correctamente."`.
- **Efectos:** elimina de `planpagos` y `prestamos`; actualiza `lineas_prestamos.disponible`.
- **Errores:** 404; bloqueos por el error `delete`.

### quote(Request $request, Prestamo $prestamo): JsonResponse (`GET /prestamos/{prestamo}/cotizacion`)

- **Entrada:** query `fecha` (`required`, `date`).
- **Proceso:** `LoanScheduleService::payoffQuote($prestamo, Carbon::parse(fecha)->startOfDay())`.
- **Retorno:** JSON `{principal, interest}`: lo que costaría cancelar el préstamo en esa fecha (lo usa el formulario de recibos para precargar la cancelación total).
- **Efectos:** ninguno. **Errores:** 404; 422 JSON (o redirección con error) si falta `fecha` o no es una fecha.

### deletionBlockers(Prestamo $loan): array (protegido)

Devuelve la lista de motivos por los que no se puede borrar:
- `"tiene N recibo(s) registrado(s)."` si hay recibos (cuenta todos, también los anulados);
- `"tiene N revisión(es) de tasa o plazo."` si hay revisiones (también anuladas);
- `"tiene N cuota(s) pagada(s) en la tabla histórica planpagos."` si `LegacyPlanSync::paidInstallments()` > 0.

### isLocked(Prestamo $loan): bool (protegido)

`true` si el préstamo tiene algún recibo activo o alguna revisión activa. Los términos (monto, línea, plazo, tasas, condiciones) solo se pueden cambiar mientras nada dependa de ellos; después se usa una revisión.

### form(?Prestamo $loan): View (protegido)

Construye la vista `catalog.form` con:
- `locked`: si es edición y `isLocked`; en ese caso todos los campos de términos reciben `readonly => true` (`numero_prestamo` y `observacion` no) y se muestra el aviso `notice`: "Este sub préstamo ya tiene recibos o revisiones: solo puede cambiar el número y la observación. Para cambiar la tasa o el plazo use una revisión.".
- Solo líneas **activas** (`LineaPrestamo::active()`, ordenadas por nombre) en el select `id_lineas_prestamos`.
- `lineInfo` por línea para el panel informativo de la vista: banco (o "Sin banco"), empresa, moneda, `limit`, `available` (monto máximo menos la suma de `saldo_prestamo` de los demás sub préstamos activos de esa línea; excluye el préstamo editado), `payment` (`modalidad_pago · cuota modalidad`), `conditions` (`LoanConditions::resolve(new Prestamo, $line)->describe()`), `rate` y `spread`.
- `emptyState` si no hay líneas activas (invita a crear una línea con enlace a `lineas.create`).
- `groups`: Identificación, Condiciones financieras, Tasa y cuota, Condiciones de cálculo, Notas.
- `fields`: `numero_prestamo`, `id_lineas_prestamos`, `monto_prestamo`, `formalizacion` (date), `plazo_meses`, `tasa_interes`, `tasa_spread`, `cuota_calculada` (opcional: vacía = se calcula con plazo y tasa), `modalidad_cuota`, `calendario`, `tipo_cuota`, `modalidad_pago`, `forma_pago`, `meses_gracia`, `porcentaje_global`, `variacion_porcentual` (estos ocho últimos con opción en blanco "Heredar de la línea" y `required => false`; las opciones salen de `CalculationOptions`) y `observacion`.

### rules(?Prestamo $loan): array (protegido)

| Campo | Reglas |
|---|---|
| `numero_prestamo` | required, string, max:100, unique en `prestamos.numero_prestamo` (ignora el propio) |
| `id_lineas_prestamos` | required, existe en `lineas_prestamos.id` con `estado = ACTIVO` |
| `monto_prestamo` | required, numeric, gt:0 |
| `formalizacion` | required, date |
| `plazo_meses` | required, integer, min:1, max:1200 |
| `tasa_interes`, `tasa_spread` | required, numeric, min:0, max:999 |
| `cuota_calculada` | nullable, numeric, min:0 |
| `modalidad_cuota` | nullable, in claves de `CalculationOptions::modalities()` |
| `calendario` | nullable, in claves de `calendars()` |
| `tipo_cuota` | nullable, in claves de `rateConventions()` |
| `modalidad_pago` | nullable, in `paymentModeCodes()` |
| `forma_pago` | nullable, in claves de `paymentForms()` |
| `meses_gracia` | nullable, integer, min:0, max:600 |
| `porcentaje_global` | nullable, numeric, min:0, max:100 |
| `variacion_porcentual` | nullable, numeric, min:0, max:999 |
| `observacion` | nullable, string, max:255 |

Los mensajes son los estándar de Laravel.

### ensureCreditAvailable(LineaPrestamo $line, float $amount, ?Prestamo $current): void (protegido)

Calcula `disponible = monto_maximo − SUM(saldo_prestamo)` de los préstamos activos de la línea (excluyendo `$current` al editar). Si `$amount > disponible + 0.005` lanza `ValidationException` con la clave `monto_prestamo` y el mensaje **"El monto supera el disponible de la línea (X)."** (X con 2 decimales).

### applyDerivedFigures(Prestamo $loan, LineaPrestamo $line): void (protegido)

Completa las cifras que salen del plan: asigna la relación `linea`; toma `cuota_calculada` (0 si es nula); construye un plan "de prueba" con cuota 0 para obtener el total de la primera cuota y, si la cuota indicada es ≤ 0, usa esa cuota; construye el plan definitivo (sin recibos ni revisiones, a `today()`) y fija `saldo_prestamo = monto_prestamo`, `proximo_pago` = fecha de la primera fila (o `formalizacion`), `vencimiento` = `maturityDate()` (o `formalizacion`) y `estado = ACTIVO`. No guarda: solo modifica el modelo en memoria.

---

## ReciboController

`App\Http\Controllers\ReciboController`. Lista, registra y anula recibos de pago (tabla `recibos`, modelo `Recibo`). La lógica de negocio está en `ReceiptRegistrar` (constructor: `protected ReceiptRegistrar $registrar`). Estados del recibo: `ACTIVO` y `ANULADO`.

### index(Request $request): View (`GET /recibos`)

- **Entrada (query):** `q` (usuario, observaciones, número de préstamo, nombre de la línea o del banco); `tipo` (un valor de `TipoRecibo`, si no es válido se ignora); `estado` (`ACTIVO` por defecto, `ANULADO` o `TODOS`; cualquier otro valor vuelve a `ACTIVO`); `desde` y `hasta` (fechas ISO `yyyy-mm-dd`; si no son fechas reales se ignoran, ver `isoDate`).
- **Proceso:** filtra por fecha de pago (`>= desde`, `<= hasta`); calcula `totals` sobre el mismo filtro pero **solo recibos activos** (cantidad, suma de `monto_principal`, `monto_interes` y `monto_otros`); lista con `prestamo.linea.banco`, orden `fecha_pago` y `id` descendentes, 15 por página con `withQueryString()`.
- **Retorno:** vista `recibos.index` con `receipts`, `totals`, `term`, `type`, `status`, `from`, `to`, `types`.
- **Efectos:** ninguno. **Errores:** ninguno (los filtros inválidos se descartan en silencio).

### store(Request $request, Prestamo $prestamo): RedirectResponse (`POST /prestamos/{prestamo}/recibos`)

- **Validación:**

  | Campo | Reglas |
  |---|---|
  | `tipo` | required, `Rule::enum(TipoRecibo::class)` (CUOTA_COMPLETA, PARCIAL_INTERES, SOLO_PRINCIPAL, ADELANTO_CUOTA, CANCELACION_TOTAL) |
  | `fecha_pago` | required, date |
  | `numero_cuota` | nullable, integer, min:1 |
  | `monto_principal`, `monto_interes`, `monto_otros` | nullable, numeric, min:0 |
  | `tipo_pago_otros_id` | nullable, exists:tipos_pago,id |
  | `descripcion_otros` | nullable, string, max:255 |
  | `observaciones` | nullable, string, max:255 |

- **Proceso:** `ReceiptRegistrar::register($prestamo, $data, email_del_usuario)`. Redondea los montos a 2 decimales, valida las reglas de negocio (abajo) y, en una transacción, crea el recibo (`estado = ACTIVO`, `usuario`), escribe sus líneas de detalle, marca la cuota como pagada en `planpagos` (solo en cuota completa) y refresca el préstamo y la línea.
- **Retorno:** redirección a `prestamos.show` con `status` = `"Recibo registrado: {etiqueta del tipo}."`.
- **Efectos (tablas):** inserta en `recibos` y `detalle_pago`; actualiza `planpagos`; `refreshSnapshot` actualiza `prestamos` (saldo, estado —puede pasar a `LIQUIDADO`—, próximo pago, vencimiento) y `lineas_prestamos.disponible`.
- **Errores (`ValidationException`, clave del campo => mensaje):**
  - `tipo`: "El sub préstamo está liquidado y no admite más recibos."
  - `monto_principal`: "Los montos no pueden ser negativos."
  - `fecha_pago`: "La fecha del recibo debe ser posterior a la formalización del sub préstamo (fecha)."; "La fecha no puede ser anterior al último recibo registrado (fecha)."
  - Cuota completa o parcial de interés: `numero_cuota` "El sub préstamo no tiene cuotas pendientes." / "La siguiente cuota por pagar es la número N."; `monto_principal` "Una cuota completa debe incluir pago a principal." / "Un pago parcial de solo interés no puede incluir principal."; `monto_interes` "Indique el interés pagado.".
  - Solo principal o adelanto de cuota: `monto_principal` "Indique el monto a principal." / "El monto cubre todo el saldo (X). Registre un pago de cancelación total."; `monto_interes` "Este tipo de recibo solo aplica a principal.".
  - Cancelación total: `fecha_pago` "Antes de cancelar debe pagar las cuotas con vencimiento anterior a esa fecha (la cuota N del fecha)."; `monto_principal` "La cancelación total debe pagar el saldo a esa fecha (X).".
  - Otros rubros (`monto_otros` > 0): `tipo_pago_otros_id` "El tipo de pago de los otros rubros no existe." / "El tipo {codigo} se calcula solo; elija OT u otro tipo propio para los otros rubros.".
  - 404 si el préstamo no existe.

### void(Request $request, Recibo $recibo): RedirectResponse (`POST /recibos/{recibo}/anular`)

- **Validación:** `motivo`: `required`, `string`, `max:255`.
- **Proceso:** `ReceiptRegistrar::void($recibo, $motivo, email)`: marca el recibo como `ANULADO`, guarda `anulado_at`, `anulado_por` y `motivo_anulacion`, restituye la cuota a pendiente en `planpagos` y recalcula el préstamo y la línea.
- **Retorno:** `backTo($request, $recibo->prestamo_id)` con `status` = "Recibo anulado. Se restauró el saldo del sub préstamo, su cuota y el disponible de la línea.".
- **Efectos:** actualiza `recibos`, `planpagos`, `prestamos` y `lineas_prestamos.disponible`.
- **Errores (`ValidationException`, clave `recibo`):** "El recibo ya está anulado."; "Los recibos se anulan del más reciente al más antiguo. Anule primero el recibo #N del fecha." Además, 404 y la validación de `motivo`.

### backTo(Request $request, int $loanId): RedirectResponse (protegido)

Si la petición trae `volver=anulaciones` redirige a `anulaciones.index`; si no, a `prestamos.show` del préstamo. Permite anular desde la pantalla de anulaciones o desde la ficha.

### isoDate(mixed $value): ?string (protegido)

Devuelve el valor si es un string `yyyy-mm-dd` que además es una fecha real (`checkdate`); si no, `null`.

---

## RevisionController

`App\Http\Controllers\RevisionController`. Registra y anula revisiones de tasa, spread o plazo restante (tabla `prestamo_revisiones`, modelo `RevisionPrestamo`). Lógica en `RevisionRegistrar` (constructor: `protected RevisionRegistrar $registrar`).

### store(Request $request, Prestamo $prestamo): RedirectResponse (`POST /prestamos/{prestamo}/revisiones`)

- **Validación:** `fecha_efectiva` required|date; `tasa_interes` nullable|numeric|min:0; `tasa_spread` nullable|numeric|min:0; `plazo_restante_meses` nullable|integer|min:1; `observacion` nullable|string|max:255.
- **Proceso:** `RevisionRegistrar::register($prestamo, $data, email)`; en una transacción crea la revisión (tasas redondeadas a 4 decimales) y refresca el préstamo y la línea (el plan se reconstruye con la revisión aplicada).
- **Retorno:** redirección a `prestamos.show` con `status` = "Revisión registrada. El plan de pagos se recalculó.".
- **Efectos:** inserta en `prestamo_revisiones`; actualiza `prestamos`, `lineas_prestamos.disponible` y `planpagos`.
- **Errores (`ValidationException`):** `fecha_efectiva` "El sub préstamo está liquidado y no admite revisiones." / "La fecha no puede ser anterior a la formalización (fecha)." / "La fecha no puede ser anterior a la última revisión (fecha)." / "La revisión no puede ser retroactiva a una cuota ya pagada (fecha)."; `tasa_interes` "Indique la nueva tasa, el nuevo spread o el nuevo plazo restante." y "Las tasas no pueden ser negativas."; `plazo_restante_meses` "El plazo restante debe ser de al menos 1 mes."; 404 si no existe el préstamo.

### void(Request $request, RevisionPrestamo $revision): RedirectResponse (`POST /revisiones/{revision}/anular`)

- **Validación:** `motivo`: required|string|max:255.
- **Proceso:** `RevisionRegistrar::void($revision, $motivo, email)`: guarda `anulado_at`, `anulado_por` y `motivo_anulacion`, y refresca el préstamo (el plan vuelve a calcularse sin esa revisión).
- **Retorno:** a `anulaciones.index` si `volver=anulaciones`, si no a `prestamos.show` del préstamo de la revisión; con `status` = "Revisión anulada. El plan de pagos se recalculó.".
- **Efectos:** `prestamo_revisiones`, `prestamos`, `lineas_prestamos.disponible`, `planpagos`.
- **Errores (clave `revision`):** "La revisión ya está anulada."; "Las revisiones se anulan de la más reciente a la más antigua. Anule primero la revisión #N del fecha."; 404; validación de `motivo`.

---

## ReportController

`App\Http\Controllers\ReportController`. Expone los reportes registrados en `ReportCatalog` (hoy solo `posicion-deuda`, "Posición consolidada de deuda"; admite el filtro `moneda`). Constante privada `XLSX_MIME` (`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`). No escribe en la base de datos.

### index(): View (`GET /reportes`)

Vista `reportes.index` con `reports` = `ReportCatalog::menu()`: los reportes listos primero (`available: true`) y después los anunciados como "Pronto" (`flujo-efectivo`, `ciclo-conversion-efectivo`, `estado-cuenta`, `alertas-vencimiento`; `available: false`).

### show(Request $request, string $slug): View (`GET /reportes/{slug}`)

- **Proceso:** `report($slug)`; `filters($request->query())`; `build($filters)`.
- **Retorno:** la vista que indica el reporte (`$report->view()`, por defecto `reportes.{slug}`) con `report`, `filters` y `data`.
- **Errores:** 404 si el `slug` no es de un reporte disponible (los anunciados "Pronto" también dan 404) o no cumple `[a-z0-9-]+`.

### download(Request $request, string $slug): BinaryFileResponse (`GET /reportes/{slug}/excel`)

- **Proceso:** resuelve el reporte, construye los datos con los filtros de la query, crea el directorio `storage/app/tmp` si no existe (se usa `storage` para que el grupo de aplicaciones de IIS pueda escribir), genera un archivo temporal con `tempnam(..., 'rpt')` renombrado a `.xlsx`, lo llena con `$report->workbook($data)->save($path)`.
- **Retorno:** descarga con el nombre de `$report->fileName($data)` (por defecto `{slug}-{Ymd}.xlsx`), `Content-Type` `XLSX_MIME` y `deleteFileAfterSend()` (borra el temporal tras enviarlo).
- **Efectos:** crea y luego elimina un archivo temporal; ninguna tabla. **Errores:** 404 por `slug` desconocido.

### report(string $slug): Report (privado)

`ReportCatalog::find($slug) ?? abort(404)`.

---

## AppServiceProvider

`App\Providers\AppServiceProvider`.

- `register(): void` → vacío.
- `boot(): void` → registra dos directivas Blade que imprimen fechas con el formato configurado (`DATE_FORMAT`, vía `App\Support\DateFormat`), escapadas con `e()`:
  - `@fecha($valor)` → `DateFormat::format($valor)`: fecha en el formato configurado (por ejemplo `18/11/2026`); cadena vacía si el valor no es fecha.
  - `@fechahora($valor)` → `DateFormat::formatDateTime($valor)`: fecha y hora `H:i` (por ejemplo `18/11/2026 14:30`); cadena vacía si el valor no es un `DateTimeInterface`.
- **Efectos:** solo registra directivas de compilación de vistas. **Errores:** ninguno propio.

Las otras piezas del bootstrap HTTP están en `bootstrap/app.php`: rutas web (`routes/web.php`), comandos (`routes/console.php`), health check en `/up`, el middleware `ConvertDisplayDates` en el grupo `web` y el render JSON de excepciones para `api/*` y peticiones que esperan JSON.
