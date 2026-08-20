<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/crypto.php';
require_once dirname(__DIR__, 2) . '/lib/directory_auth.php';
require_once dirname(__DIR__, 2) . '/lib/directory_service.php';
require_once dirname(__DIR__, 2) . '/lib/repo/directory.php';
require_once dirname(__DIR__, 2) . '/lib/repo/directory_users.php';
require_once dirname(__DIR__) . '/Support/DirectoryLdapFixture.php';

/**
 * Failover, credential-fanout and circuit-breaker proof through the real app
 * path (directory_read_with_failover / directory_authenticate_user), against
 * the same hermetic fixture as DirectoryLdapFixtureTest. See that file's
 * header for the skip contract.
 *
 * "Reached, or not reached" is proven with the fixture's own evidence
 * (DirectoryLdapFixture::monitorBindCount(), the LDAP monitor backend's real
 * completed-bind counter on the target server), never inferred from timing.
 */
final class DirectoryLdapFixtureFailoverTest extends TestCase
{
    private mysqli $db;
    private int $actorId;

    protected function setUp(): void
    {
        if (!DirectoryLdapFixture::available()) {
            self::markTestSkipped('LDAP fixture hostnames not resolvable (not running inside the QA compose network).');
        }
        $this->db = db(true);
        $row = $this->db->query('SELECT id FROM deploy_users ORDER BY id LIMIT 1')->fetch_assoc();
        self::assertIsArray($row);
        $this->actorId = (int) $row['id'];
        DirectoryLdapFixture::cleanup($this->db);
    }

    protected function tearDown(): void
    {
        // setUp() skips before it opens the connection when the fixture
        // hostnames do not resolve, and PHPUnit runs tearDown anyway: without
        // this guard the intended skip is reported as an uninitialised-property
        // error instead.
        if (!isset($this->db)) {
            return;
        }
        DirectoryLdapFixture::cleanup($this->db);
    }

    public function testAnUnreachablePrimaryFailsOverToTheSecondController(): void
    {
        DirectoryLdapFixture::importAlice($this->db);
        $config = DirectoryLdapFixture::insertConfig($this->db, DirectoryLdapFixture::trustedBundle(), $this->actorId);
        // Nothing listens on 6399 in the dc1 fixture container: a clean,
        // fast connection-refused transport failure, not a slow black hole.
        $deadControllerId = DirectoryLdapFixture::insertController($this->db, DirectoryLdapFixture::HOST_DC1, $config['revision'], 1, $this->actorId);
        $this->db->query("UPDATE deploy_ad_controllers SET port = 6399 WHERE id = $deadControllerId");
        $liveControllerId = DirectoryLdapFixture::insertController($this->db, DirectoryLdapFixture::HOST_DC2, $config['revision'], 2, $this->actorId);

        $result = directory_authenticate_user($this->db, DirectoryLdapFixture::ALICE_UPN, DirectoryLdapFixture::ALICE_PASSWORD);

        self::assertSame($liveControllerId, $result['controller_id'], 'Must have authenticated against the second, reachable controller.');
    }

    public function testWrongPasswordIsAuthoritativeAndNeverFansOutToTheSecondController(): void
    {
        DirectoryLdapFixture::importAlice($this->db);
        $config = DirectoryLdapFixture::insertConfig($this->db, DirectoryLdapFixture::trustedBundle(), $this->actorId);
        DirectoryLdapFixture::insertController($this->db, DirectoryLdapFixture::HOST_DC1, $config['revision'], 1, $this->actorId);
        DirectoryLdapFixture::insertController($this->db, DirectoryLdapFixture::HOST_DC2, $config['revision'], 2, $this->actorId);

        $before = DirectoryLdapFixture::monitorBindCount(DirectoryLdapFixture::HOST_DC2, DirectoryLdapFixture::trustedBundle());

        try {
            directory_authenticate_user($this->db, DirectoryLdapFixture::ALICE_UPN, 'not-the-real-password');
            self::fail('Expected the wrong password to be rejected.');
        } catch (DirectoryLdapException $exception) {
            self::assertSame(VIRTUSPHERE_DIRECTORY_OUTCOME_USER_BIND_REJECTED, $exception->outcome);
        }

        $after = DirectoryLdapFixture::monitorBindCount(DirectoryLdapFixture::HOST_DC2, DirectoryLdapFixture::trustedBundle());
        // The monitorBindCount() probe itself binds once; the fixture's own
        // counter is the proof, so the assertion is "no attempt in between",
        // not "count is zero".
        self::assertSame($before + 1, $after, 'dc2 must have been contacted only by this test\'s own probe bind, never for the rejected user bind.');
    }

    public function testANonexistentUpnContinuesPastNotFoundToTheSecondController(): void
    {
        $config = DirectoryLdapFixture::insertConfig($this->db, DirectoryLdapFixture::trustedBundle(), $this->actorId);
        DirectoryLdapFixture::insertController($this->db, DirectoryLdapFixture::HOST_DC1, $config['revision'], 1, $this->actorId);
        DirectoryLdapFixture::insertController($this->db, DirectoryLdapFixture::HOST_DC2, $config['revision'], 2, $this->actorId);

        $before = DirectoryLdapFixture::monitorBindCount(DirectoryLdapFixture::HOST_DC2, DirectoryLdapFixture::trustedBundle());

        try {
            directory_authenticate_user($this->db, 'ghost@vs-ldap.test', 'anything');
            self::fail('Expected a not-found rejection.');
        } catch (DirectoryLdapException $exception) {
            self::assertSame(VIRTUSPHERE_DIRECTORY_OUTCOME_NOT_FOUND, $exception->outcome);
        }

        $after = DirectoryLdapFixture::monitorBindCount(DirectoryLdapFixture::HOST_DC2, DirectoryLdapFixture::trustedBundle());
        self::assertGreaterThan($before, $after, 'A not-found search must still have reached dc2, unlike an authoritative credential rejection.');
    }

    public function testARejectedServiceAccountPasswordPausesAutomaticBindsForTheRevision(): void
    {
        $badCiphertext = crypto_encrypt_secret('definitely-not-the-service-password');
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_ad_config
                (id, enabled, revision, default_naming_context, user_search_base_dn,
                 bind_upn, bind_secret_ciphertext, ca_certificate_pem, created_by, updated_by)
             VALUES (1, 1, 1, \'dc=vs-ldap,dc=test\', ?, ?, ?, ?, ?, ?)'
        );
        $searchBase = DirectoryLdapFixture::USER_SEARCH_BASE;
        $bindUpn = DirectoryLdapFixture::SERVICE_BIND_DN;
        $caBundle = DirectoryLdapFixture::trustedBundle();
        $stmt->bind_param('ssssii', $searchBase, $bindUpn, $badCiphertext, $caBundle, $this->actorId, $this->actorId);
        $stmt->execute();
        DirectoryLdapFixture::insertController($this->db, DirectoryLdapFixture::HOST_DC1, 1, 1, $this->actorId);

        try {
            directory_find_user_by_upn($this->db, DirectoryLdapFixture::ALICE_UPN);
            self::fail('Expected the service account bind to be rejected.');
        } catch (DirectoryLdapException $exception) {
            self::assertSame(VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, $exception->outcome);
            self::assertFalse($exception->transportFailure, 'A rejected service account is authoritative, not a transport outage.');
        }

        $config = repo_directory_config($this->db);
        self::assertSame(1, (int) $config['automatic_bind_blocked_revision'], 'The circuit breaker must record the blocked revision for the whole config, not just this one attempt.');

        // The breaker pauses every subsequent automatic attempt on this
        // revision without touching LDAP again at all.
        try {
            directory_find_user_by_upn($this->db, DirectoryLdapFixture::ALICE_UPN);
            self::fail('Expected the breaker to keep pausing automatic binds.');
        } catch (DirectoryLdapException $exception) {
            self::assertSame(VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, $exception->outcome);
        }
    }
}
