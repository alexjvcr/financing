# 07 · Decisiones y pendientes

## 1. Decisiones de diseño

| # | Decisión | Motivo / alternativa descartada |
|---|---|---|
| 1 | **PHP 8.5.12** en IIS (no 8.3.4) | Las dependencias de Composer exigen PHP ≥ 8.4.1; el handler 8.3.4 además no estaba registrado en FastCGI. |
| 2 | **Login SQL** (`DB_USERNAME`) y no autenticación de Windows | La cuenta del pool (`CORP\ATI-APP-PD$`) y los usuarios de dominio no tenían login en el servidor SQL. Pendiente: cambiar `sa` por un login limitado. |
| 3 | **LDAPS (636)** con verificación de certificado desactivable | Seguro para la contraseña; la CA interna no es de confianza para PHP todavía. |
| 4 | **Bind como el propio usuario** (sin cuenta de servicio) | Menos credenciales que cuidar. Consecuencia: en el acceso con Windows el nombre es el usuario (no se puede consultar `displayName` sin contraseña). |
| 5 | **Usuarios locales** con `email = usuario@dominio` y contraseña aleatoria | Reutiliza la tabla `users` sin migraciones; no hay login local. |
| 6 | **Plan de pagos dinámico** (no se guarda) | Evita desincronización: cualquier recibo, abono o revisión reconstruye el plan completo. Sustituye a `planpagos`, cuyo plan estaba truncado a 12 cuotas. |
| 7 | **Interés según la base de días de la línea** (Actual/360, Actual/365 o 30/360) | Reemplaza la decisión inicial de usar siempre tasa/12 (30/360). Con `360/360` se obtiene el interés fijo del plan original; con `365/360` el interés sigue los días reales. Las bases se eligen por línea y se pueden cambiar por sub préstamo. |
| 8 | **Abonos reducen el plazo y mantienen la cuota** | Práctica habitual en créditos corporativos. Alternativas: bajar la cuota o configurarlo por préstamo. |
| 9 | **Solo interés difiere el principal** y recalcula la cuota | Alternativa descartada: dejar el principal como mora pendiente. |
| 10 | **`cuota_calculada` como cuota contractual de partida** | La cuota del banco no coincide con la anualidad teórica; el motor respeta la del banco. Las revisiones recalculan. |
| 11 | **Encabezado + detalle**: `recibos` (cabecera) y `detalle_pago` (una línea por tipo de pago) | Conserva el motor y las pruebas; cada recibo se desglosa en PP/PI/CT/PA/PC/OT. Alternativa descartada: rediseñar todo desde cero. |
| 12 | **PC = Pago parcial** | El pedido original tenía `PP` (principal) y `pp` (parcial), iguales salvo mayúsculas. |
| 13 | **OT (otros rubros) no afecta el saldo** | Comisiones y seguros se registran pero no amortizan principal. |
| 14 | **Columna nueva `disponible`** en la línea (no `capital_trabajo`) | `capital_trabajo` no tiene un significado definido y sus valores no cuadran con los saldos. |
| 15 | **Anulación solo del recibo más reciente** | Mantiene la secuencia de pagos sin huecos (coherente con no pagar fechas anteriores a un pago existente). Los recibos nunca se borran. |
| 16 | **Auditoría de anulación** (`anulado_at`, `anulado_por`, `motivo_anulacion`) | Trazabilidad de quién, cuándo y por qué. |
| 17 | **`planpagos` se mantiene sincronizada** (`C`/`P`) | Compatibilidad con consultas del sistema anterior; el cálculo no depende de ella. |
| 18 | **Migraciones con `hasTable`** para las tablas existentes | La base real ya existía; así `migrate` no la altera y los entornos nuevos/pruebas obtienen el esquema completo. |
| 19 | **Sin dependencias nuevas** | LDAP con la extensión nativa; íconos SVG propios; sin Chart.js ni librerías de fechas. |
| 20 | **CSS propio sin compilación** | El servidor no tiene Node.js. Si se instala, puede migrarse a Tailwind (v4, declarado en `package.json`). |
| 21 | **Formato de fecha por parámetro** (`DATE_FORMAT`) con conversión en el servidor | Una sola fuente de verdad; la base siempre guarda ISO. |
| 22 | **Etiqueta "Sub Préstamo"** solo en la interfaz | Las tablas, rutas y nombres internos siguen siendo `prestamos`. |
| 23 | **Edición bloqueada con actividad** | Con recibos o revisiones solo se cambian número y observación; tasa y plazo van por revisión (protege el historial). |
| 24 | **Borrado validado por FK** con confirmación | Evita errores de la base y huérfanos; el motivo se muestra en pantalla. |
| 25 | **Importación cronológica** | Si una cuota figura pagada antes que la anterior, se importa con la fecha de la previa y se anota la real. |
| 26 | **Condiciones de cálculo heredables**: la línea las define y el sub préstamo puede cambiarlas (campos nulos = heredar) | Permite que un mismo banco tenga sub préstamos con distinta cuota, calendario o gracia sin duplicar líneas. |
| 27 | **Tipos de cuota**: nivelada, lineal, solo intereses (bullet), creciente; con período de gracia y cuota global (balloon) | Cubre los esquemas habituales de financiamiento empresarial en Costa Rica y en el exterior. |
| 28 | **Bases de días**: Actual/360, Actual/365 y 30/360 | Dólares (SOFR/Prime) usan Actual/360; colones y bancos locales suelen usar Actual/365; 30/360 conserva el plan original. 30E/360 y Actual/Actual quedaron fuera. |
| 29 | **Tasa nominal o efectiva** (`tipo_cuota`) | Nominal = interés simple; efectiva = tasa efectiva anual capitalizada por período. |
| 30 | **El plan se refleja en `planpagos`** (sigue siendo dinámico) | El sistema anterior sigue viendo las cuotas; el cálculo no depende de la tabla. Se respaldan las filas antes de reconstruir. |
| 31 | **Cancelación total exige cuotas anteriores pagadas** | Con cuotas sin pagar el saldo proyectado subestimaba lo adeudado; se exige pagarlas primero. |
| 32 | **Cuotas de solo interés (gracia, bullet) se pagan como cuota completa con principal 0** | Evita usar el pago parcial (que difiere principal y recalcula la cuota) para una cuota que legítimamente no lleva principal. |
| 33 | **Reportes como subreportes registrados en un catálogo** (`ReportCatalog`) | Agregar un reporte es crear una clase y una vista; el menú, el centro de reportes y las rutas salen del catálogo. Los anunciados se ven como "Pronto". |
| 34 | **Excel generado con `XlsxWriter` propio** (ZipArchive) | No se aprobó añadir dependencias (PhpSpreadsheet); cubre estilos, fechas, porcentajes y totales que necesitan los reportes. |
| 35 | **Los reportes nunca suman monedas** | No hay tipo de cambio; los totales y la concentración se calculan por moneda. |
| 36 | **Comentarios y PHPDoc en español** (código, pruebas, vistas y CSS) | Convención del proyecto; los nombres de clases, métodos y pruebas siguen en inglés. La referencia de cada clase y método está en `docs/referencia/`. |
| 37 | **Condiciones de cálculo por sub préstamo** (base `365/360`, `360/360`, etc.) | Cada sub préstamo puede fijar su base, tipo de cuota y gracia; si no, hereda de la línea. Por eso no se cambió la base de las líneas BCT y BNCR. |

## 2. Supuestos y limitaciones conocidas

- **Forma de pago adelantada (`ADELANTADO`):** se guarda y se puede elegir, pero el interés todavía se calcula como vencido (al vencimiento de cada cuota). Pendiente de implementar con cuota 0 al desembolso.
- **Otras bases de días:** 30E/360 y Actual/Actual (ISDA) no están soportadas; tampoco el ajuste de la fecha de pago por feriados o fines de semana ni el primer período corto o largo.
- **Cuota global y cuotas recalculadas:** la cuota global es un monto fijo (% del monto original); si los abonos reducen el saldo por debajo de ese monto, la última cuota paga solo el saldo restante.
- **Revisiones:** la tasa de un período usa el valor vigente al inicio del período salvo que la revisión caiga dentro; el prorrateo es 30/360.
- **Moneda:** los montos se muestran con `$` fijo; los totales se separan por moneda pero sin conversión.
- **Nombre de la columna `fecha_vencmiento`:** conserva el error ortográfico original de la base.
- **Línea BCT:** su plazo máximo es 12 meses pero sus sub préstamos son de 96; no se valida.
- **Roles:** cualquier usuario autenticado puede ejecutar todas las operaciones.
- **Dashboard:** los textos dicen "sub préstamo"; el login conserva "Préstamos pasivos y líneas de crédito" (describe el tipo de operación).

## 3. Lecciones aprendidas / incidentes

| Tema | Qué pasó | Cómo evitarlo |
|---|---|---|
| **Error 500 breve tras `optimize:clear`** | Se borra `bootstrap/cache/packages.php` y la identidad de IIS no puede regenerarlo. | Dar permiso de escritura al pool sobre `bootstrap\cache` y `storage`; no limpiar cachés con el sitio en uso. |
| **Log no escribible por la consola** | `storage/logs/laravel.log` pertenece a la identidad de IIS. | Usar `LOG_CHANNEL=stderr` en pruebas; revisar permisos. |
| **Pruebas fallan con `touch(): Utime failed`** | Tras editar una vista, Laravel intenta actualizar la fecha del archivo compilado, que es de la identidad de IIS. | Ejecutar con `VIEW_COMPILED_PATH` apuntando a una carpeta temporal, o dar permiso de escritura sobre `storage`. |
| **Escritura omitida de la instantánea** | Si el objeto en memoria ya tenía los mismos valores que una copia vieja, Eloquent no escribía. | `refreshSnapshot` y `refreshAvailable` usan `UPDATE` directo. |
| **Blade interpreta `<x-…>` en comentarios JS** | Rompía el layout completo. | No escribir etiquetas de componente en comentarios de script. |
| **`@php(...)` con paréntesis anidados** | Blade lo trata como bloque sin cerrar. | Usar `@php … @endphp`. |
| **Reemplazos masivos con `sed`** | Se perdieron barras invertidas de nombres de clase. | Preferir ediciones puntuales y ejecutar la suite después. |
| **Archivos fuera de lugar al generar enums** | `php artisan make:enum Enums/Nombre` creó los archivos en `app/Enums/Enums/` (más de una vez). | Escribir los enums directamente en `app/Enums/` y revisar el árbol después de generar. |
| **Consola interactiva colgada** | `tinker archivo.php` no termina y dejó una transacción abierta. | Usar scripts de una sola ejecución; se verificó que no quedaron bloqueos. |

## 4. Pendientes (por prioridad sugerida)

### Datos y puesta en marcha
1. ~~Importar las cuotas pagadas del 9090~~ (hecho el 2026-11-20).
1a. **Decidir la base de días de las líneas BCT y BNCR** (`365/360` con interés por días reales, o `360/360` para conservar el plan original) y luego ejecutar `lending:rebuild-planpagos` (la simulación indica 276 filas nuevas y 12 actualizadas; guarda respaldo JSON).
2. Registrar los pagos de los sub préstamos **4040 y 6060** o confirmar que son datos de prueba.
3. **Recorrido visual** de todas las pantallas en el navegador (pestañas, calendario, diálogos, modo oscuro, móvil).
4. Corregir el plazo máximo de la línea BCT.
5. Decidir el destino de `planpagos` y `bitacora_cambio_tasas` (archivar o eliminar).

### Seguridad y control
6. **Roles y permisos** (grupos de AD → roles) y habilitar la opción del menú.
7. **Auditoría general** de ediciones de líneas, sub préstamos y catálogos (tabla de cambios con valor anterior y nuevo) y habilitar la opción del menú.
8. Cambiar `sa` por un login SQL con permisos solo sobre `Lineas`.
9. Instalar la CA de la empresa y poner `LDAP_VERIFY_CERTIFICATE=true`.
10. Dar permisos de escritura al pool de IIS sobre `bootstrap\cache` y `storage`.
11. Habilitar *Windows Authentication* en IIS para entrar sin contraseña.

### Funcionalidad
12. **Reportes pendientes:** *Flujo de efectivo proyectado (13 semanas)* (servicio de deuda automático + captura manual de operación y caja mínima), *Ciclo de conversión de efectivo* (requiere una fuente de datos contables), **Estado de cuenta** y comprobante de recibo imprimible. *Posición consolidada de deuda* ya está lista.
13. **Alertas de vencimiento** (cuotas por vencer y líneas que vencen; pantalla y/o correo).
14. **Gráficos del dashboard** (calendario de pagos, saldo por banco, evolución de tasas); falta decidir Chart.js o SVG propio.
15. Parámetro de **formato de montos** (decimales, símbolo) y soporte **multimoneda** completo.
16. Traducir los mensajes de validación estándar de Laravel (hoy algunos salen en inglés).

### Calidad
17. Pruebas contra SQL Server y pruebas de navegador.
18. Si se instala Node.js: migrar el CSS a Tailwind v4 compilado con Vite.
19. Reemplazar el `README.md` de la raíz (es el de Laravel) por un resumen que enlace a esta carpeta `docs/`.
