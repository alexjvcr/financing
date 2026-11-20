# Sistema de Préstamos Pasivos (Financing)

Aplicación web para administrar **préstamos pasivos** (créditos que la empresa recibe de bancos): líneas de crédito, sub préstamos, plan de pagos, recibos de pago, revisiones de tasa/plazo, anulaciones con auditoría y un dashboard con el disponible por banco.

- **Estado:** en desarrollo activo (fecha de esta documentación: 2026-11-19).
- **Usuarios:** autenticación contra Active Directory (`CORP`, dominio `corp.obgroup.com`).
- **Datos:** SQL Server (`10.0.7.54`, base `Lineas`).
- **Servidor:** IIS + PHP 8.5 (FastCGI) en Windows Server 2022.

## Índice de la documentación

| Documento | Contenido |
|---|---|
| [01-instalacion-y-configuracion.md](01-instalacion-y-configuracion.md) | Versiones, dependencias, extensiones PHP, `.env`, IIS, SQL Server, LDAP, comandos |
| [02-base-de-datos.md](02-base-de-datos.md) | Diagrama, tablas, columnas, PK, FK, índices, restricciones, migraciones |
| [03-arquitectura-y-calculos.md](03-arquitectura-y-calculos.md) | Capas, servicios, motor del plan de pagos, recibos, anulaciones, reglas de negocio |
| [04-pantallas.md](04-pantallas.md) | Explicación de cada pantalla: ruta, campos, validaciones y acciones |
| [05-frontend-css.md](05-frontend-css.md) | CSS, tema, componentes Blade, JavaScript, íconos, formato de fecha |
| [06-pruebas-y-datos.md](06-pruebas-y-datos.md) | Suite de pruebas, cómo ejecutarla, datos de prueba y datos cargados |
| [07-decisiones-y-pendientes.md](07-decisiones-y-pendientes.md) | Decisiones tomadas, limitaciones, lecciones aprendidas y trabajo pendiente |
| [referencia/](referencia/README.md) | **Referencia de clases y métodos**: qué hace cada clase, función y procedimiento del código |

## Glosario

| Término | Significado |
|---|---|
| **Empresa** | Quien recibe el crédito (hoy: ATI). |
| **Banco** | Entidad que otorga la línea (tabla `lineas_bancos`). |
| **Línea de crédito** | Cupo aprobado por un banco con sus condiciones (monto máximo, moneda, forma de pago, tasa). Tabla `lineas_prestamos`. |
| **Sub Préstamo** | Desembolso individual contra una línea. Tabla `prestamos` (en la interfaz se llama "Sub Préstamo"; en la base y las rutas sigue siendo `prestamos`). |
| **Plan de pagos** | Cuotas del sub préstamo. Se **reconstruye cada vez que se consulta** (no se guarda). |
| **Recibo** | Pago registrado contra un sub préstamo (tabla `recibos`). |
| **Detalle de pago** | Desglose de un recibo por tipo de pago (tabla `detalle_pago`). |
| **Tipo de pago** | Catálogo de conceptos: PP, PI, CT, PA, PC, OT (tabla `tipos_pago`). |
| **Revisión** | Cambio de tasa, spread o plazo con fecha efectiva (tabla `prestamo_revisiones`). |
| **Disponible** | Monto máximo de la línea menos los saldos de sus sub préstamos activos. |
| **planpagos** | Tabla histórica (legacy) con una fila por cuota. Se mantiene sincronizada pero el cálculo ya no depende de ella. |

## Cronología de lo construido

1. **Diagnóstico de IIS:** el handler `php-8.3.4` apuntaba a un `php-cgi.exe` no registrado en FastCGI (error 0x80070585). Además las dependencias exigen PHP ≥ 8.4.1. Se registró `E:\php85\php-cgi.exe` (PHP 8.5.12).
2. **Conexión a SQL Server:** la autenticación de Windows falló (cuenta de máquina sin login); se configuró usuario SQL en `.env`.
3. **Login con Active Directory:** acceso automático con la sesión de Windows (si IIS la entrega) o formulario contra AD por LDAPS.
4. **Dashboard:** menú lateral, cabecera con tema claro/oscuro y menú de usuario, indicadores y "Disponible por banco" a 3, 6, 9, 12, 18 y 24 meses.
5. **Motor del plan de pagos dinámico:** interés tasa/12 (30/360), abonos que acortan el plazo, pago de solo interés que difiere principal, revisiones de tasa/plazo y cancelación total.
6. **Base de datos:** migraciones (con guarda para tablas existentes), modelos, fábricas, tabla `recibos`, revisiones, bancos e historial de tipos de tasa.
7. **Mantenimientos de configuración:** empresas, bancos, líneas de crédito, tipos de tasa; luego tipos de pago.
8. **Tema empresarial** (inspirado en SAP Blue Crystal): pestañas, formularios con etiqueta a la izquierda, botones dorados/grises.
9. **Buscar, insertar, actualizar y borrar** con confirmación y validación de llaves foráneas; paginación.
10. **Formato de fecha configurable** (`DATE_FORMAT`) con máscara y calendario.
11. **Renombre** de "Préstamos" a "Sub Préstamo" en la interfaz.
12. **Tipos de pago, detalle de pago y disponible de la línea** actualizado con cada recibo.
13. **Pantalla Recibos** y **Anulaciones** (del más reciente al más antiguo, con auditoría).
14. **Condiciones de cálculo** de cuota e interés (nivelada, lineal, solo intereses, creciente, gracia, global; Actual/360, Actual/365, 30/360; nominal o efectiva), heredables por sub préstamo, y **reflejo del plan en `planpagos`** con comando de reconstrucción.
15. **Reportes** con subreportes y descarga en Excel: *Posición consolidada de deuda* (por banco, línea y sub préstamo, con guía de decisión); los demás figuran como "Pronto".

## Cómo correr las pruebas (resumen)

```bash
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit
```

Detalle en [06-pruebas-y-datos.md](06-pruebas-y-datos.md). Última ejecución documentada: **235 pruebas, 1,230 aserciones, todas en verde**.

## Seguridad: qué NO subir a git

- `.env` está en `.gitignore` (contiene la contraseña de SQL Server y `APP_KEY`). Solo se versiona `.env.example`, sin credenciales.
- Nunca documentar ni versionar contraseñas, `APP_KEY` ni cadenas de conexión con clave.
