<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Conexión a Active Directory
    |--------------------------------------------------------------------------
    |
    | Las credenciales se verifican enlazándose al controlador de dominio como
    | el propio usuario (usuario@dominio), por lo que no se requiere una cuenta
    | de servicio.
    |
    */

    'host' => env('LDAP_HOST', 'corp.obgroup.com'),

    'port' => (int) env('LDAP_PORT', 636),

    'use_ssl' => (bool) env('LDAP_USE_SSL', true),

    'verify_certificate' => (bool) env('LDAP_VERIFY_CERTIFICATE', true),

    'timeout' => (int) env('LDAP_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Estructura del directorio
    |--------------------------------------------------------------------------
    */

    'domain' => env('LDAP_DOMAIN', 'corp.obgroup.com'),

    'netbios_domain' => env('LDAP_NETBIOS_DOMAIN', 'CORP'),

    'base_dn' => env('LDAP_BASE_DN', 'DC=corp,DC=obgroup,DC=com'),

    /*
    |--------------------------------------------------------------------------
    | Inicio de sesión único de Windows
    |--------------------------------------------------------------------------
    |
    | Cuando la autenticación de Windows de IIS está habilitada, el usuario de
    | Windows con sesión iniciada se lee de REMOTE_USER y se autentica sin
    | pedir contraseña.
    |
    */

    'windows_sso' => (bool) env('LDAP_WINDOWS_SSO', true),

];
