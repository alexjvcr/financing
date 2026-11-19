<?php

namespace App\Services;

use Illuminate\Http\Request;
use LDAP\Connection;
use RuntimeException;

class ActiveDirectoryAuthenticator
{
    /**
     * Verify the credentials against Active Directory.
     *
     * @return array{username: string, name: string, email: string}|null
     *
     * @throws RuntimeException When the domain controller cannot be reached.
     */
    public function authenticate(string $username, string $password): ?array
    {
        $accountName = $this->normalizeUsername($username);

        // An LDAP bind with an empty password succeeds as an anonymous bind.
        if ($accountName === '' || $password === '') {
            return null;
        }

        $connection = $this->connect();

        if (! @ldap_bind($connection, $accountName.'@'.config('ldap.domain'), $password)) {
            $isInvalidCredentials = ldap_errno($connection) === 49;
            $error = ldap_error($connection);

            if ($isInvalidCredentials) {
                return null;
            }

            throw new RuntimeException("Active Directory bind failed: {$error}");
        }

        return $this->fetchProfile($connection, $accountName);
    }

    /**
     * Get the domain account name of the Windows user signed in to IIS.
     */
    public function windowsAccountName(Request $request): ?string
    {
        if (! config('ldap.windows_sso')) {
            return null;
        }

        $identity = (string) ($request->server('REMOTE_USER') ?: $request->server('AUTH_USER'));

        if (str_contains($identity, '\\')) {
            [$domain, $identity] = explode('\\', $identity, 2);

            if (strcasecmp($domain, config('ldap.netbios_domain')) !== 0) {
                return null;
            }
        }

        $accountName = $this->normalizeUsername($identity);

        return $accountName === '' ? null : $accountName;
    }

    /**
     * Build the unique e-mail used to store a directory account locally.
     */
    public function emailFor(string $accountName): string
    {
        return $accountName.'@'.config('ldap.domain');
    }

    /**
     * Strip the CORP\ prefix or @domain suffix from a typed username.
     */
    public function normalizeUsername(string $username): string
    {
        $username = trim($username);

        if (str_contains($username, '\\')) {
            $username = substr($username, strrpos($username, '\\') + 1);
        }

        if (str_contains($username, '@')) {
            $username = substr($username, 0, strrpos($username, '@'));
        }

        return strtolower(trim($username));
    }

    protected function connect(): Connection
    {
        if (! config('ldap.verify_certificate')) {
            ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
        }

        $scheme = config('ldap.use_ssl') ? 'ldaps' : 'ldap';
        $connection = ldap_connect($scheme.'://'.config('ldap.host').':'.config('ldap.port'));

        if ($connection === false) {
            throw new RuntimeException('Invalid Active Directory connection settings.');
        }

        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, config('ldap.timeout'));

        return $connection;
    }

    /**
     * @return array{username: string, name: string, email: string}
     */
    protected function fetchProfile(Connection $connection, string $accountName): array
    {
        $profile = [
            'username' => $accountName,
            'name' => $accountName,
            'email' => $this->emailFor($accountName),
        ];

        $filter = '(sAMAccountName='.ldap_escape($accountName, '', LDAP_ESCAPE_FILTER).')';
        $search = @ldap_search($connection, config('ldap.base_dn'), $filter, ['displayName'], 0, 1);
        $entries = $search ? ldap_get_entries($connection, $search) : null;

        if ($entries && $entries['count'] > 0 && isset($entries[0]['displayname'][0])) {
            $profile['name'] = $entries[0]['displayname'][0];
        }

        return $profile;
    }
}
