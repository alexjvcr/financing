# 05 · Frontend, CSS y componentes

## 1. Enfoque

- **Sin compilación:** el servidor no tiene Node.js, así que no se usa Vite/Tailwind (aunque `package.json` los declara). Los estilos son **CSS escrito a mano** en `public/css` y el JavaScript va **dentro de las plantillas Blade** (sin librerías externas, sin dependencias nuevas).
- **Plantillas Blade** con dos layouts: `layouts/app.blade.php` (login) y `layouts/dashboard.blade.php` (resto).
- **Tema empresarial** inspirado en SAP Blue Crystal: menú azul degradado, paneles con barra de título, pestañas, campos planos con borde fino, botones dorados (acción principal) y gris azulado (resto).
- **Tema claro y oscuro:** cada color es una variable CSS con valor para ambos temas.
- **Fuente:** `"Segoe UI", system-ui, sans-serif` (sin descargar fuentes).

## 2. Archivos CSS (orden de carga)

| Archivo | Líneas | Contenido |
|---|---|---|
| `public/css/dashboard.css` | ~390 | Base: variables de color (claro/oscuro), layout con barra lateral, topbar, tarjetas, tablas, badges, botones, alertas, formularios, **tonos** de color de las secciones, fila etiqueta-izquierda/campo-derecha. |
| `public/css/theme.css` | ~230 | **Tema SAP**: sobrescribe lo anterior con las variables `--sap-*` (degradados, bordes, botones dorados), pestañas, cuadro de búsqueda, paginador, diálogo de confirmación, campo de fecha, marcadores "Pronto". |

`theme.css` se carga después de `dashboard.css` y tiene prioridad. El login (`layouts/app.blade.php`) carga ambos y añade un `<style>` mínimo para la tarjeta de acceso.

### Secciones de `dashboard.css`
Layout · Sidebar · Topbar · Content (tarjetas, estadísticas, tablas, badges, pie) · Navigation sections · Alerts · Buttons · Page layout helpers · Forms · Modern forms (formulario en 2 columnas con panel lateral) · Soft colour tones · Hero banner · Coloured boxes · Larger text and field icons · Coloured buttons · Label on the left / control on the right.

### Secciones de `theme.css`
Variables `--sap-*` (claro y oscuro) · Navigation · Top bar and footer · Panels as group boxes · Tables · Buttons · Flat fields · Form (barra de título, pestañas, paneles) · Reports (`.nav-sub`, `.report-grid`, `.report-card`, `.report-description`, `.report-actions`, `.report-subtitle`, `.report-date`, `.guidance-item`) · "Pronto" placeholders · Search box · Row actions and disabled buttons · Pager · Tab panes · Confirmation dialog · Date field.

## 3. Variables de color

### Base (`dashboard.css`)

| Variable | Claro | Oscuro | Uso |
|---|---|---|---|
| `--bg` | `#f1f5f9` | `#0b1120` | Fondo de página (luego lo reemplaza `--sap-bg`) |
| `--surface` | `#ffffff` | `#111827` | Tarjetas y celdas |
| `--surface-muted` | `#f8fafc` | `#1f2937` | Fondos suaves |
| `--border` | `#e2e8f0` | `#1f2937` | Bordes |
| `--text` / `--text-muted` | `#0f172a` / `#64748b` | `#e5e7eb` / `#9ca3af` | Texto |
| `--primary` / `--primary-soft` | `#1d4ed8` / `#dbeafe` | `#60a5fa` / `#1e3a8a` | Acento |
| `--danger` / `--danger-soft` | `#b91c1c` / `#fee2e2` | `#fca5a5` / `#7f1d1d` | Errores, vencidas |
| `--sidebar-width` | `16rem` | | Ancho del menú |

### Tema SAP (`theme.css`)

| Variable | Claro | Oscuro | Uso |
|---|---|---|---|
| `--sap-bg` | `#e3eaf4` | `#0b1120` | Fondo general |
| `--sap-panel` | `#f4f7fb` | `#111a2e` | Panel/tarjeta |
| `--sap-panel-head` | degradado `#f2f6fb → #d3e0f0` | `#1c2a47 → #15203a` | Barra de título de paneles |
| `--sap-border` / `--sap-field-border` | `#8ea8c8` / `#7f9db9` | `#2b3f66` / `#3b5280` | Bordes |
| `--sap-title` | `#1c3f73` | `#bcd2f5` | Títulos y acentos |
| `--sap-btn`, `--sap-btn-hover`, `--sap-btn-border`, `--sap-btn-text` | gris azulado | gris oscuro | Botón normal |
| `--sap-gold`, `--sap-gold-hover`, `--sap-gold-border`, `--sap-gold-text` | crema → dorado (`#fff5c9 → #efd16a`) | dorado oscuro | Botón principal (Guardar, Nuevo…) |
| `--sap-nav`, `--sap-nav-hover`, `--sap-nav-active` | azul `#2f5e9f → #173b72` | azul marino | Menú lateral |
| `--sap-topbar`, `--sap-tab`, `--sap-tab-active`, `--sap-th` | degradados claros | degradados oscuros | Cabecera, pestañas, encabezados de tabla |

### Tonos (`.tone-blue/green/amber/violet/rose`)
Definen `--tone` y, con `color-mix`, `--tone-bg`, `--tone-soft` y `--tone-border`. Se usan en las secciones de formulario (en el tema SAP todas toman `--sap-title`, así que hoy el color vive en el tema y no en el tono).

## 4. Clases principales

| Grupo | Clases |
|---|---|
| Layout | `.app-shell`, `.sidebar`, `.main`, `.topbar`, `.content`, `.page-footer`, `.sidebar-backdrop` · estados en `body`: `.sidebar-collapsed` (escritorio) y `.sidebar-open` (móvil) |
| Menú | `.nav-link` (`.is-active`, `.is-disabled`), `.nav-section`, `.nav-badge` ("Pronto"), `.logout-link` |
| Tarjetas | `.card`, `.card-header`, `.card-title`, `.stat-grid`, `.stat` (`.stat-danger`), `.stat-icon`, `.two-columns` |
| Tablas | `.table-wrapper`, `.table-scroll` (cabecera fija), `.numeric`, `td.actions`, `.row-overdue`, `.row-voided` |
| Badges | `.badge-neutral`, `.badge-info`, `.badge-success`, `.badge-danger`, `.count-badge` |
| Botones | `.btn`, `.btn-primary` / `.btn-success` (dorado), `.btn-soft-primary`, `.btn-soft-danger`, `.btn-danger`, `.btn-small`, `.btn-lg`, `.btn-block` |
| Formularios | `.form-layout` (principal + `aside`), `.form-section`, `.section-header`, `.form-field` (`.span-2`, `.has-error`), `.input-wrap`, `.affix` (prefijo/sufijo `$`, `%`, `meses`), `.hint`, `.field-error`, `.form-grid` |
| Pestañas | `.tab-bar`, `.tab` (`.is-active`), `.tab-dot`, `.tab-pane`, `.pane-note` |
| Búsqueda | `.search-box`, `.search-form`, `.search-field`, `.search-actions` |
| Paginador | `.pager`, `.pager-links`, `.pager-page` |
| Diálogo | `.modal`, `.modal-title`, `.modal-message`, `.modal-actions` |
| Fecha | `.date-input`, `.date-picker-button`, `.date-native` |
| Alertas | `.alert-success`, `.alert-error`, `.alert-info` |

### Puntos de corte responsivos
- `≤ 1023px`: el menú se vuelve un panel deslizable con fondo oscurecido.
- `≤ 1100px`: el formulario pasa a una columna y el panel lateral deja de ser fijo.
- `≤ 700px`: las filas etiqueta/campo se apilan.
- `≤ 639px`: se oculta el nombre en el botón de usuario.

## 5. Componentes Blade (`resources/views/components`)

| Componente | Uso | Parámetros |
|---|---|---|
| `<x-icon name="…">` | Íconos SVG en línea (trazo, heredan el color) | `name`: `menu sun moon dashboard building layers banknote calendar activity logout alert chevron-down percent file-text info check x dollar clock tag trending-up arrow-left search edit trash plus lock eye` |
| `<x-date-input>` | Campo de fecha con máscara y calendario | `name`, `value`, `id`, `required`, `readonly` |
| `<x-search-box>` | Caja "Buscar" de los listados (acepta filtros extra en el *slot*) | `action`, `term`, `title`, `placeholder` |
| `<x-row-actions>` | Celda de acciones (Ver / Editar / Eliminar con confirmación y bloqueo "En uso") | `show`, `edit`, `destroy`, `label`, `blockers` |
| `<x-pager>` | Paginador "Anterior / Página X de Y / Siguiente" | `paginator` |

**Directivas** (`AppServiceProvider`): `@fecha($valor)` y `@fechahora($valor)` imprimen fechas en el formato de `DATE_FORMAT`.

**Vistas genéricas** reutilizadas por los catálogos: `catalog/index.blade.php` y `catalog/form.blade.php`. Cada campo se declara en el controlador (`label`, `type`: text/number/date/select, `group`, `icon`, `prefix`/`suffix`, `step`, `hint`, `required`, `readonly`, `options`, `span`) y los grupos con ícono, tono y descripción. El formulario las agrupa en **pestañas** y marca los campos obligatorios.

## 6. JavaScript (en `layouts/dashboard.blade.php`)

| Comportamiento | Cómo funciona |
|---|---|
| **Tema claro/oscuro** | Un script en `<head>` aplica `data-theme` antes de pintar (`localStorage` o preferencia del sistema); el botón de la cabecera lo alterna y lo guarda. |
| **Menú lateral** | El botón alterna `.sidebar-collapsed` (escritorio) o `.sidebar-open` (móvil); `Esc` o el fondo lo cierran. |
| **Menú de usuario** | Desplegable con cierre al hacer clic fuera o con `Esc`. |
| **Confirmación** | Cualquier `<form data-confirm="mensaje">` abre el diálogo nativo `<dialog>`; solo se envía si se pulsa **Aceptar**. |
| **Pestañas** | Todo contenedor `[data-tabs]` conmuta sus paneles `[data-panel]`; si un campo obligatorio queda en un panel oculto, abre esa pestaña antes de enviar. |
| **Fechas** | `window.dateFormat` (orden y separador de `DATE_FORMAT`): máscara que inserta el separador, validación (`setCustomValidity`), conversión a ISO y calendario nativo (`showPicker`). |

Scripts propios de pantalla:
- **Detalle del sub préstamo:** rellena los montos del recibo según el tipo y consulta `GET /prestamos/{id}/cotizacion?fecha=aaaa-mm-dd` para la cancelación total.
- **Alta/edición de sub préstamo:** muestra los datos de la línea elegida y rellena tasa y spread.

## 7. Cómo extender

- **Nueva pantalla de catálogo:** crear un controlador que extienda `CatalogController`, declarar `model`, `title`, `singular`, `routeBase`, `columns`, `fields`, `rules` (y opcionalmente `groups`, `searchColumns`, `deletionBlockers`) y registrar `Route::resource(...)->parameters([... => 'record'])`.
- **Fechas:** usar siempre `<x-date-input>` en formularios y `@fecha` al mostrar.
- **Íconos:** agregar la ruta SVG (24×24, trazo) a la lista de `components/icon.blade.php`.
- **Colores:** cambiar las variables `--sap-*` en `theme.css` (valores para claro y oscuro).
- **Trampas de Blade:** no escribir etiquetas tipo `<x-componente>` dentro de comentarios de JavaScript (Blade las interpreta) y evitar `@php(...)` con paréntesis anidados (usar el bloque `@php … @endphp`).
