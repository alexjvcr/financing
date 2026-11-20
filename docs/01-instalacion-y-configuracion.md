# 01 · Instalación y configuración

## 1. Versiones y dependencias

### Entorno de ejecución

| Componente | Versión / detalle |
|---|---|
| Sistema operativo | Windows Server 2022 Standard |
| Servidor web | IIS con FastCGI (`E:\php85\php-cgi.exe`) y módulo URL Rewrite |
| PHP | **8.5.12** NTS x64 (el `composer.json` exige `^8.3`, pero las dependencias instaladas exigen **≥ 8.4.1**) |
| Framework | **Laravel 13.43.0** |
| Base de datos | SQL Server (`10.0.7.54:1433`, base `Lineas`), controlador ODBC 18 |
| Directorio | AD `corp.obgroup.com` (controlador de dominio `CEO-AD-03`, 10.0.7.24, LDAPS 636) |
| Node.js | **No instalado** en el servidor (por eso no se compila Tailwind; ver [05-frontend-css.md](05-frontend-css.md)) |

### Paquetes de Composer (directos)

| Paquete | Versión instalada | Uso |
|---|---|---|
| `laravel/framework` | v13.43.0 | Framework |
| `laravel/tinker` | v3.2.0 | Consola interactiva |
| `fakerphp/faker` (dev) | v1.24.1 | Datos de prueba |
| `laravel/boost` (dev) | v2.10.4 | Herramientas MCP (consulta de esquema/BD) |
| `laravel/pail` (dev) | v1.3.0 | Visor de logs |
| `laravel/pao` (dev) | v1.1.5 | Salida compacta de pruebas |
| `laravel/pint` (dev) | v1.32.3 | Formato de código |
| `mockery/mockery` (dev) | 1.6.15 | Dobles de prueba |
| `nunomaduro/collision` (dev) | v8.9.6 | Errores en consola |
| `phpunit/phpunit` (dev) | 12.5.44 | Pruebas |

Total instalado: 77 paquetes de producción y 38 de desarrollo (ver `composer.lock`). **No se agregaron dependencias propias**: LDAP usa la extensión nativa de PHP, no un paquete.

### Paquetes de npm (declarados, sin instalar)

`package.json` trae `vite ^8`, `laravel-vite-plugin ^3.1`, `tailwindcss ^4`, `@tailwindcss/vite ^4`, `concurrently ^10`. No hay `node_modules` ni `public/build`; el estilo usa CSS escrito a mano en `public/css`.

### Extensiones de PHP requeridas

`ldap`, `pdo_sqlsrv`, `sqlsrv` (archivos `php_sqlsrv_85_nts_x64.dll` y `php_pdo_sqlsrv_85_nts_x64.dll` en `E:\php85\ext`), `mbstring`, `openssl`, `intl`, `fileinfo`, `curl`, `zip`, `bcmath`, `ctype`, `dom`, `iconv`, `tokenizer`, `xml`.

Solo para pruebas: `pdo_sqlite` y `sqlite3` (no están cargadas en `php.ini`; se activan por línea de comandos, ver [06](06-pruebas-y-datos.md)).

## 2. Instalación desde cero

```bash
composer install
cp .env.example .env          # luego completar DB_USERNAME y DB_PASSWORD
php artisan key:generate
php artisan migrate           # no toca tablas existentes (ver 02-base-de-datos.md)
```

Para entornos sin Node no hace falta nada más: los estilos ya están en `public/css/dashboard.css` y `public/css/theme.css`.

## 3. Variables de `.env`

Solo las propias del proyecto; el resto son las estándar de Laravel.

### Base de datos (SQL Server)

| Variable | Valor / ejemplo | Descripción |
|---|---|---|
| `DB_CONNECTION` | `sqlsrv` | Controlador |
| `DB_HOST` / `DB_PORT` | `10.0.7.54` / `1433` | Servidor |
| `DB_DATABASE` | `Lineas` | Base de datos |
| `DB_USERNAME` / `DB_PASSWORD` | *(secreto)* | Login SQL. **Hoy se usa `sa`; se recomienda un login con permisos solo sobre `Lineas`.** Si `DB_USERNAME` queda vacío el driver usa autenticación de Windows, que falla porque la cuenta del pool de IIS (`CORP\ATI-APP-PD$`) no tiene login. |
| `DB_ENCRYPT` | `yes` | Cifra la conexión |
| `DB_TRUST_SERVER_CERTIFICATE` | `true` | Acepta el certificado del servidor SQL |

### Active Directory (`config/ldap.php`)

| Variable | Valor | Descripción |
|---|---|---|
| `LDAP_HOST` | `corp.obgroup.com` | Servidor/dominio |
| `LDAP_PORT` | `636` | LDAPS |
| `LDAP_USE_SSL` | `true` | Usa `ldaps://` |
| `LDAP_VERIFY_CERTIFICATE` | `false` en producción actual | PHP no confía en la CA interna. Dejar en `true` cuando se instale la CA de la empresa. |
| `LDAP_DOMAIN` | `corp.obgroup.com` | Se usa para armar `usuario@dominio` en el bind |
| `LDAP_NETBIOS_DOMAIN` | `CORP` | Para aceptar solo identidades `CORP\usuario` de IIS |
| `LDAP_BASE_DN` | `DC=corp,DC=obgroup,DC=com` | Base de búsqueda del `displayName` |
| `LDAP_WINDOWS_SSO` | `true` | Permite entrar con la sesión de Windows si IIS la entrega |
| `LDAP_TIMEOUT` | `5` (opcional) | Segundos de espera de red |

### Formato de fecha (`config/formats.php`)

| Variable | Valor | Descripción |
|---|---|---|
| `DATE_FORMAT` | `d/m/Y` | Formato mostrado y digitado en toda la aplicación. Valores permitidos: `d/m/Y`, `m/d/Y`, `Y/m/d`, `d-m-Y`, `m-d-Y`, `Y-m-d`. Cualquier otro valor usa `d/m/Y`. Después de cambiarlo: `php artisan config:clear`. |

### Sesión, caché y colas (valores del proyecto)

`SESSION_DRIVER=database` (tabla `sessions`), `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, `SESSION_LIFETIME=120`.

## 4. Configuración de IIS

1. **FastCGI:** registrar `E:\php85\php-cgi.exe` en *FastCGI Settings*.
2. **Handler Mapping:** `*.php` → `FastCgiModule` con ese ejecutable (el handler antiguo `php-8.3.4` apuntaba a `E:\PHP\v8.3.4\php-cgi.exe`, no registrado, y producía el error 500.0 / `0x80070585`).
3. **Sitio:** carpeta física `E:\sistema\financing\public`. `public/web.config` ya trae la regla de reescritura hacia `index.php`.
4. **Permisos NTFS** para la identidad del application pool sobre:
   - `E:\php85` (lectura y ejecución),
   - `storage` y `bootstrap\cache` (**modificar**).
5. **Acceso con Windows (opcional):** habilitar *Windows Authentication* en el sitio para que los equipos del dominio entren sin escribir contraseña (el sitio lee `REMOTE_USER`). Con *Anonymous* activo, siempre se verá el formulario de login.

> **Aviso operativo:** ejecutar `php artisan optimize:clear` borra `bootstrap/cache/packages.php`. Si el usuario del pool no tiene permiso de escritura en esa carpeta, el sitio responde **500** hasta que una ejecución de `artisan` lo regenere. Dar permiso de escritura al pool evita el problema.

## 5. Autenticación con Active Directory

Flujo (`LoginController` + `ActiveDirectoryAuthenticator`):

1. `GET /` exige sesión; si no hay, redirige a `/login`.
2. En `/login`, si IIS entrega `REMOTE_USER` con formato `CORP\usuario`, se crea/actualiza el usuario local y se inicia sesión sin contraseña. Identidades de otro dominio se ignoran.
3. Si no hay identidad de Windows, se muestra el formulario. Las credenciales se validan haciendo *bind* LDAPS como `usuario@corp.obgroup.com` (no se necesita cuenta de servicio). Se rechazan contraseñas vacías (un bind LDAP con clave vacía sería anónimo).
4. El usuario se guarda en la tabla `users` con `email = usuario@dominio`, `name = displayName` de AD (o el usuario si no hay) y una contraseña aleatoria (no se usa).
5. Límite de intentos: `throttle:5,1` (5 por minuto) para no bloquear cuentas de AD.
6. Al cerrar sesión se marca `windows_sso_disabled` para que no vuelva a entrar solo con Windows.

## 6. Comandos útiles

| Comando | Para qué |
|---|---|
| `php artisan migrate` | Aplica migraciones (las tablas preexistentes se omiten con `hasTable`). |
| `php artisan lending:import-paid-installments --dry-run` | Muestra qué cuotas pagadas de `planpagos` (estado `C`) se importarían como recibos. |
| `php artisan lending:import-paid-installments` | Las importa como recibos (tipo cuota completa) con su detalle de pago y refresca el saldo del sub préstamo y el disponible de la línea. Es **idempotente**. |
| `php artisan lending:rebuild-planpagos --dry-run` | Simula la escritura del plan calculado en `planpagos` de los sub préstamos activos (sin escribir). Sin `--dry-run` guarda un respaldo JSON y reconstruye; `--loan=9090` limita a un sub préstamo. |
| `php artisan config:clear` | Tras cambiar `.env` (por ejemplo `DATE_FORMAT`). |
| `vendor/bin/pint --format agent` | Formatea el código (estándar del proyecto). |
| `php artisan route:list --except-vendor` | Lista las rutas propias (48). |

## 7. Notas para Windows / Git Bash

- Las rutas con `/tmp` no existen para PHP en Windows; usar `sys_get_temp_dir()`.
- `php artisan tinker archivo.php` abre una consola interactiva y no termina; para scripts de una sola ejecución usar un `.php` con *bootstrap* de la aplicación o `tinker --execute '...'`.
- Para ver el error real en pruebas cuando `storage/logs/laravel.log` no es escribible por el usuario actual: `LOG_CHANNEL=stderr php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit`.
