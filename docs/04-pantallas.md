# 04 · Pantallas

Todas las pantallas (excepto el login) exigen sesión. Los textos de la interfaz están en español y el formato de fecha lo define `DATE_FORMAT` (por defecto `dd/mm/aaaa`). En esta versión **todo usuario autenticado puede usar todas las funciones** (los roles están pendientes).

## 0. Menú lateral

| Sección | Opción | Ruta | Estado |
|---|---|---|---|
| — | Dashboard | `/` | Activa |
| — | Sub Préstamo | `/prestamos` | Activa |
| — | Recibos | `/recibos` | Activa |
| — | Anulaciones | `/anulaciones` | Activa |
| Reportes | Reportes (centro de reportes) | `/reportes` | Activa |
| Reportes | └ Posición consolidada de deuda | `/reportes/posicion-deuda` | Activa |
| Reportes | └ Flujo de efectivo proyectado (13 semanas) · Ciclo de conversión de efectivo · Estado de cuenta · Alertas de vencimiento | — | **Pronto** |
| Configuración | Empresas · Bancos · Líneas de crédito · Tipos de tasa · Tipos de pago | `/empresas` · `/bancos` · `/lineas` · `/tipos-tasa` · `/tipos-pago` | Activas |
| Administración | Roles y permisos · Auditoría | — | **Pronto** |

Pie del menú: nombre y correo del usuario y **Cerrar sesión**. La cabecera tiene el botón de menú (ocultar/mostrar), el selector de tema claro/oscuro (se recuerda en el navegador) y el menú de usuario (también con **Cerrar sesión**). Las opciones "Pronto" aparecen atenuadas y no se pueden abrir.

## 1. Login (`/login`)

- Si IIS entrega una identidad de Windows `CORP\usuario`, entra directamente al dashboard.
- Si no, muestra **Usuario** y **Contraseña** (de Active Directory). Acepta `usuario`, `CORP\usuario` o `usuario@corp.obgroup.com`.
- Errores: "Usuario o contraseña incorrectos." o "No fue posible contactar al directorio activo." Máximo 5 intentos por minuto.
- Al cerrar sesión no vuelve a entrar solo con Windows.

## 2. Dashboard (`/`)

- **Tarjetas:** saldo de sub préstamos activos (y cuántos), líneas de crédito activas con su monto máximo, próxima cuota (monto, fecha y sub préstamo) y cuotas vencidas (cantidad e importe, en rojo si hay).
- **Disponible por banco:** tabla por banco con monto máximo, saldo, **disponible hoy** y el disponible proyectado a **3, 6, 9, 12, 18 y 24 meses**, una barra de utilización, fila de total por moneda y la nota del último vencimiento. Valores negativos en rojo.
- **Cuotas pendientes:** próximas 8 cuotas abiertas de todos los sub préstamos (vencidas primero) con etiqueta *Vencida* / *Por vencer*.

## 3. Sub Préstamo

### 3.1 Listado (`/prestamos`)
- **Buscar:** número, banco, línea u observación; filtro **Estado** (todos / activos / liquidados). Botones Buscar y Limpiar.
- **Tabla:** sub préstamo, banco/línea, monto, saldo, tasa, próximo pago, vencimiento, estado y acciones **Ver**, **Editar**, **Eliminar** (con diálogo de confirmación; bloqueado con la etiqueta "En uso" si tiene recibos, revisiones o filas en `planpagos`).
- Botón **Nuevo sub préstamo**. Paginación de 15.

### 3.2 Alta y edición (`/prestamos/create`, `/prestamos/{id}/edit`)
Formulario con **5 pestañas** y un panel lateral.

| Pestaña | Campos |
|---|---|
| Identificación | **Número de sub préstamo** (único), **Línea de crédito** (solo líneas activas) |
| Condiciones financieras | **Monto** (> 0), **Fecha de formalización**, **Plazo** en meses (1–1200) |
| Tasa y cuota | **Tasa de interés** y **Spread** (se rellenan con los de la línea y se pueden cambiar), **Cuota pactada** (opcional: si se deja vacía se calcula) |
| Condiciones de cálculo | Opcionales: **Tipo de cálculo de cuota**, **Base de días**, **Tipo de cálculo de interés**, **Periodicidad de pago**, **Forma de pago del interés**, **Período de gracia**, **Cuota global** y **Crecimiento de la cuota**. En blanco = "Heredar de la línea". |
| Notas | **Observación** |

- **Panel "Línea seleccionada":** banco, empresa, moneda, forma de pago, monto máximo, **disponible**, tasa y **condiciones de cálculo de la línea**, con una barra de utilización.
- **Reglas:** el monto no puede superar el **disponible de la línea** (monto máximo − saldos de sus sub préstamos activos). Al guardar se calculan la cuota (si no se indicó), el vencimiento y el próximo pago; se actualiza el disponible de la línea.
- **Edición bloqueada:** si el sub préstamo ya tiene recibos o revisiones, solo se pueden cambiar el número y la observación; la tasa o el plazo se cambian con una **revisión**.
- **Sin líneas activas:** en vez del formulario aparece "Primero necesita una línea de crédito" con un botón para crearla.

### 3.3 Detalle (`/prestamos/{id}`)
Cabecera con **Sub Préstamos** (volver), **Editar** y **Eliminar** (con confirmación; bloqueado si tiene actividad). Cuatro tarjetas: **saldo de principal**, **tasa vigente**, **próxima cuota** y **cuotas vencidas** (con la fecha de vencimiento).

Pestañas:

| Pestaña | Contenido |
|---|---|
| **Plan de pagos** | Una nota con las **condiciones de cálculo** vigentes (por ejemplo "Cuota nivelada · 365/360 · pago mensual") y todas las cuotas reconstruidas: número, fecha, movimiento (cuota / abono a principal / cancelación), tasa, principal, interés, total, saldo y estado (*Pagada*, *Solo interés*, *Vencida*, *Pendiente*, *Abono a principal*, *Cancelación total*). Las vencidas se resaltan; "≠ plan" indica que lo pagado difiere de lo esperado. |
| **Registrar recibo** (solo si está activo) | Tipo de recibo (5 tipos), fecha de pago, número de cuota, principal, interés, **otros rubros** (monto, tipo y detalle) y observaciones. Al elegir el tipo se **rellenan los montos** desde el plan; en la cancelación total se cotiza saldo + interés a la fecha elegida. |
| **Revisión de tasa o plazo** (solo si está activo) | Fecha efectiva, nueva tasa, nuevo spread, nuevo plazo restante y observación (se deja vacío lo que no cambia). |
| **Recibos (n)** | Fecha, tipo, cuota, principal, interés, otros, total, usuario y **Anular** (solo el más reciente, con motivo y confirmación). Los anulados salen tachados con la etiqueta *Anulado* (el detalle de quién, cuándo y por qué aparece al pasar el mouse). |
| **Detalle de pagos (n)** | Una línea por tipo de pago: fecha de pago, tipo (código + nombre), monto, cuota, **fecha de cuota**, detalle y recibo de origen. |
| **Revisiones (n)** | Fecha efectiva, tasa, spread, plazo restante, usuario y **Anular** (solo la más reciente, con motivo). |

Si el formulario de recibo o de revisión devuelve un error, la pestaña correspondiente se abre sola.

## 4. Recibos (`/recibos`)
Listado general de los recibos de **todos** los sub préstamos, del más reciente al más antiguo.
- **Filtros:** texto (sub préstamo, banco, línea, usuario u observación), **tipo de recibo**, **estado** (activos por defecto / anulados / todos) y **Pagado desde / hasta** (con calendario).
- **Tarjetas de totales** de los recibos activos según los filtros: cantidad, principal, interés y total (incluye otros rubros).
- **Tabla:** fecha de pago, sub préstamo (enlace), banco/línea, tipo, cuota, principal, interés, otros, total, usuario, estado y **Ver**. El botón **Registrar recibo** lleva a la lista de sub préstamos (los recibos se registran desde el detalle).

## 5. Anulaciones (`/anulaciones`)
Cuatro pestañas y un buscador por sub préstamo, banco o línea. Un aviso recuerda la regla: **se anula del recibo más reciente al más antiguo**.

| Pestaña | Contenido |
|---|---|
| **Recibos por anular (n)** | Solo el recibo más reciente de cada sub préstamo, con cuántos recibos anteriores quedan, un campo **Motivo** obligatorio y el botón **Anular** (con confirmación). |
| **Revisiones por anular (n)** | Igual, con la revisión más reciente de cada sub préstamo. |
| **Recibos anulados (n)** | Auditoría: *Anulado el* (fecha y hora), *Anulado por*, *Motivo*, recibo, sub préstamo, fecha de pago, tipo, total y quién lo registró. Paginado. |
| **Revisiones anuladas (n)** | Auditoría equivalente. |

Al anular, la pantalla vuelve a Anulaciones con un mensaje; se restauran el saldo, la cuota en `planpagos` y el disponible de la línea.

## 5.1 Reportes (`/reportes`)

**Centro de reportes:** tarjetas con cada reporte. Los listos tienen **Abrir** y **Descargar Excel**; los anunciados muestran la etiqueta **Pronto**. En el menú lateral cada reporte es un subítem de "Reportes". Todos se consultan en pantalla y se descargan en Excel (`.xlsx`, generado por `App\Support\XlsxWriter` sin dependencias nuevas).

### Posición consolidada de deuda (`/reportes/posicion-deuda`)
Filtro por **moneda** (las monedas nunca se suman entre sí). Muestra la fecha de corte, tarjetas con los totales por moneda (saldo, límite, disponible, utilización, tasa ponderada, interés anual estimado) y cuatro pestañas:

| Pestaña | Contenido |
|---|---|
| **Resumen por banco** | Por banco y moneda: líneas, límite, saldo, disponible, % de utilización, tasa ponderada, % de la deuda (concentración), interés anual estimado, servicio de deuda (capital + interés de las cuotas abiertas) a 3, 6 y 12 meses, cuotas vencidas, próxima cuota y plazo promedio. Con fila de total por moneda. |
| **Detalle por línea** | Lo mismo por línea de crédito, con su tasa, vencimiento de la línea y cuotas vencidas. |
| **Sub préstamos** | Cada sub préstamo con saldo, tasa, cuota actual, servicio de deuda, próxima cuota, vencimiento final y condiciones de cálculo. |
| **Guía de decisión** | **Pagar primero** (los 5 sub préstamos de mayor tasa), **Usar primero** (las 5 líneas con disponible y menor tasa) y **Atención**: cuotas vencidas, línea vencida o que vence en ≤ 365 días, utilización ≥ 80 %, sub préstamo que vence en ≤ 365 días y concentración ≥ 50 % con 2 o más bancos. Ordenado por severidad (urgente, advertencia, informativo). |

El Excel trae una hoja por pestaña (`posicion-deuda[-moneda]-AAAAMMDD.xlsx`) con fechas y porcentajes como valores reales de Excel, encabezado congelado y filtro automático.

Para agregar un reporte nuevo: crear una clase que extienda `App\Reports\Report`, una vista `resources/views/reportes/{slug}.blade.php` y registrarla en `ReportCatalog::AVAILABLE` (hoy figura en `PLANNED` si está anunciado).

## 6. Configuración

Todas comparten el mismo diseño: caja **Buscar**, tabla con **Editar** y **Eliminar** (confirmación + bloqueo "En uso"), botón **Nuevo** y formulario con etiqueta a la izquierda y campo a la derecha.

### Empresas (`/empresas`)
Campo: **Nombre de la empresa**. Columnas: empresa y cantidad de líneas. No se elimina si tiene líneas.

### Bancos (`/bancos`)
Campo: **Nombre del banco**. Columnas: banco y cantidad de líneas. No se elimina si tiene líneas. Sirve para agrupar el disponible por banco.

### Líneas de crédito (`/lineas`)
Formulario con 4 pestañas:

| Pestaña | Campos |
|---|---|
| Datos generales | Nombre, Empresa, Banco (opcional), Tipo de línea, Moneda, Estado |
| Montos y plazos | Monto máximo, Capital de trabajo, Plazo máximo (meses), Vencimiento de la línea |
| Condiciones de pago | **Periodicidad de pago** (mensual a anual), **Tipo de cálculo de cuota** (nivelada, lineal, solo intereses, creciente), **Base de días** (Actual/360, Actual/365, 30/360), **Tipo de cálculo de interés** (nominal o efectiva), **Forma de pago del interés**, **Período de gracia** (meses), **Cuota global** (% del monto al final) y **Crecimiento de la cuota** (solo creciente) |
| Tasas | Tipo de interés, Tipo de tasa base, Tasa de interés, Spread |

La periodicidad (cuotas por año) se deriva de la modalidad de pago. La tabla muestra monto máximo y **disponible**. No se elimina si tiene sub préstamos. Al guardar se recalcula el disponible.

### Tipos de tasa (`/tipos-tasa`)
Campos: **Nombre** y **Valor base (%)**. Cada cambio del valor base queda en un **historial** (anterior, nuevo, usuario, fecha) visible al editar. No se elimina si hay líneas que lo usan; si no, se elimina con su historial.

### Tipos de pago (`/tipos-pago`)
Campos: **Código** (hasta 5 caracteres alfanuméricos, se guarda en mayúsculas, único sin distinguir mayúsculas), **Nombre**, **Descripción** y **Reduce el saldo** (Sí/No). Columnas: código, nombre, descripción, reduce el saldo y **Origen** (*Sistema* / *Propio*).
- Los 6 del sistema (PP, PI, CT, PA, PC, OT) **no se pueden borrar ni cambiar su código o su efecto**, pero sí su nombre y descripción.
- Los tipos propios (por ejemplo `SG = Seguro`) se usan como **otros rubros** en el recibo y no se pueden borrar si ya tienen líneas de detalle.

## 7. Mensajes y confirmaciones

- Éxito: franja verde ("Empresa creada correctamente.", "Recibo anulado…", etc.).
- Errores de validación: franja roja con la lista; los campos con error se resaltan y, en formularios con pestañas, la pestaña con errores muestra un punto rojo y se abre sola.
- Confirmación: diálogo "Confirmar acción" con **Cancelar** / **Aceptar** para borrar y anular.
