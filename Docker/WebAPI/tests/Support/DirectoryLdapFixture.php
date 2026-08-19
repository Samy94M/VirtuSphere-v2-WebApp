<?php

declare(strict_types=1);

// Hermetic LDAP-TLS fixture support (Plan section 18.3). The compose network
// (Docker/qa/docker-compose.qa.yml) publishes six real hostnames on the QA
// project network; outside that network (a plain dev-container `composer
// test`) they simply don't resolve, so DirectoryLdapFixture::available()
// lets the integration tests skip instead of failing on missing infra.
// Cert material lives in Docker/WebAPI/tests/fixtures/ldap/ (see its README
// for which broken TLS property each file isolates).

final class DirectoryLdapFixture
{
    public const HOST_DC1 = 'dc1.vs-ldap.test';
    public const HOST_DC2 = 'dc2.vs-ldap.test';
    public const HOST_DC_ROTATED = 'dc-rotated.vs-ldap.test';
    public const HOST_UNKNOWN_CA = 'dc-unknown-ca.vs-ldap.test';
    public const HOST_EXPIRED = 'expired.vs-ldap.test';
    public const HOST_WRONGNAME = 'dc-wrongname.vs-ldap.test';
    public const HOST_BLACKHOLE = 'dc-blackhole.vs-ldap.test';
    public const PORT = 636;

    public const SERVICE_BIND_DN = 'cn=svc-bind,dc=vs-ldap,dc=test';
    public const SERVICE_BIND_PASSWORD = 'fixture-svc-Pass123!';
    public const USER_SEARCH_BASE = 'ou=people,dc=vs-ldap,dc=test';

    public const ALICE_UPN = 'alice@vs-ldap.test';
    public const ALICE_DN = 'cn=alice,ou=people,dc=vs-ldap,dc=test';
    public const ALICE_PASSWORD = 'fixture-alice-Pass123!';
    // Matches seed/base.ldif's objectGUID:: AAECAwQFBgcICQoLDA0ODw==
    public const ALICE_GUID_HEX = '000102030405060708090a0b0c0d0e0f';

    public static function available(): bool
    {
        return gethostbyname(self::HOST_DC1) !== self::HOST_DC1;
    }

    public static function aliceGuidBytes(): string
    {
        $bytes = hex2bin(self::ALICE_GUID_HEX);
        assert(is_string($bytes));

        return $bytes;
    }

    /** Concatenates one or more fixture root certs into a trust bundle PEM. */
    public static function bundle(string ...$rootNames): string
    {
        $pem = '';
        foreach ($rootNames as $name) {
            $path = dirname(__DIR__) . '/fixtures/ldap/' . $name . '.crt.txt';
            $contents = file_get_contents($path);
            assert(is_string($contents));
            $pem .= $contents . "\n";
        }

        return $pem;
    }

    /** The trust bundle covering dc1, dc2 and every root-a-signed leaf. */
    public static function trustedBundle(): string
    {
        return self::bundle('root-a');
    }

    /**
     * Inserts a minimal, directly-usable deploy_ad_config row. Bypasses the
     * validated candidate/RootDSE flow on purpose: login and failover
     * (directory_read_with_failover, directory_authenticate_user) never read
     * RootDSE themselves, only the admin "test controller" action does, and
     * that AD-specific semantics is real-AD-only (Gate 0B), not hermetic.
     *
     * @return array{id:int,revision:int}
     */
    public static function insertConfig(mysqli $db, string $caBundlePem, int $actorId): array
    {
        $ciphertext = crypto_encrypt_secret(self::SERVICE_BIND_PASSWORD);
        $stmt = $db->prepare(
            'INSERT INTO deploy_ad_config
                (id, enabled, revision, default_naming_context, user_search_base_dn,
                 bind_upn, bind_secret_ciphertext, ca_certificate_pem, created_by, updated_by)
             VALUES (1, 1, 1, \'dc=vs-ldap,dc=test\', ?, ?, ?, ?, ?, ?)'
        );
        $bindUpn = self::SERVICE_BIND_DN;
        $searchBase = self::USER_SEARCH_BASE;
        $stmt->bind_param('ssssii', $searchBase, $bindUpn, $ciphertext, $caBundlePem, $actorId, $actorId);
        $stmt->execute();

        return ['id' => 1, 'revision' => 1];
    }

    /** Inserts a controller already admitted for login (validated_revision set). */
    public static function insertController(mysqli $db, string $host, int $revision, int $priority, int $actorId, bool $enabled = true): int
    {
        $enabledInt = $enabled ? 1 : 0;
        $stmt = $db->prepare(
            'INSERT INTO deploy_ad_controllers
                (config_id, host, port, priority, enabled, validated_revision, validated_at, created_by, updated_by)
             VALUES (1, ?, ?, ?, ?, ?, NOW(), ?, ?)'
        );
        $port = self::PORT;
        $stmt->bind_param('siiiiii', $host, $port, $priority, $enabledInt, $revision, $actorId, $actorId);
        $stmt->execute();

        return (int) $db->insert_id;
    }

    /** Imports alice as a ready-to-authenticate AD portal user. */
    public static function importAlice(mysqli $db, string $role = VIRTUSPHERE_ROLE_USER): int
    {
        $result = repo_directory_import_user($db, [
            'guid_bytes' => self::aliceGuidBytes(),
            'upn' => self::ALICE_UPN,
            'sam' => 'alice',
            'display_name' => 'Alice Example',
            'email' => self::ALICE_UPN,
            'enabled' => true,
        ], $role);
        assert($result['created'] === true);

        return $result['user_id'];
    }

    /** Removes every row this fixture may have written, config-cascade first. */
    public static function cleanup(mysqli $db): void
    {
        $db->query('DELETE FROM deploy_ad_config WHERE id = 1');
        $guid = self::aliceGuidBytes();
        $stmt = $db->prepare('DELETE FROM deploy_users WHERE auth_source = ? AND ad_object_guid = ?');
        $source = VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY;
        $stmt->bind_param('ss', $source, $guid);
        $stmt->execute();
    }

    /**
     * Reads the LDAP monitor backend's completed-bind counter for one
     * fixture host directly (native ext-ldap, not the app's adapter): the
     * proof that a controller was, or was not, contacted at all.
     */
    public static function monitorBindCount(string $host, string $caBundlePem): int
    {
        $caFile = directory_ca_file($caBundlePem);
        $connection = directory_ldap_connect($host, self::PORT, $caFile, directory_deadline_now() + 5);
        try {
            directory_ldap_bind($connection, self::SERVICE_BIND_DN, self::SERVICE_BIND_PASSWORD, VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, directory_deadline_now() + 5);
            $result = ldap_read($connection, 'cn=Bind,cn=Operations,cn=Monitor', '(objectclass=*)', ['monitorOpCompleted']);
            assert($result !== false);
            $entry = directory_ldap_single_entry($connection, $result);

            return (int) directory_ldap_first_text($entry, 'monitorOpCompleted');
        } finally {
            @ldap_unbind($connection);
        }
    }
}
