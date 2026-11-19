<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active Directory Connection
    |--------------------------------------------------------------------------
    |
    | Credentials are verified by binding to the domain controller as the
    | user (user@domain), so no service account is required.
    |
    */

    'host' => env('LDAP_HOST', 'corp.obgroup.com'),

    'port' => (int) env('LDAP_PORT', 636),

    'use_ssl' => (bool) env('LDAP_USE_SSL', true),

    'verify_certificate' => (bool) env('LDAP_VERIFY_CERTIFICATE', true),

    'timeout' => (int) env('LDAP_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Directory Layout
    |--------------------------------------------------------------------------
    */

    'domain' => env('LDAP_DOMAIN', 'corp.obgroup.com'),

    'netbios_domain' => env('LDAP_NETBIOS_DOMAIN', 'CORP'),

    'base_dn' => env('LDAP_BASE_DN', 'DC=corp,DC=obgroup,DC=com'),

    /*
    |--------------------------------------------------------------------------
    | Windows Single Sign-On
    |--------------------------------------------------------------------------
    |
    | When IIS Windows Authentication is enabled, the signed-in Windows user
    | is read from REMOTE_USER and logged in without asking for a password.
    |
    */

    'windows_sso' => (bool) env('LDAP_WINDOWS_SSO', true),

];
