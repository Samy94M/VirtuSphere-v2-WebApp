<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/auth.php';
require_once dirname(__DIR__, 2) . '/lib/crypto.php';
require_once dirname(__DIR__, 2) . '/lib/directory_auth.php';
require_once dirname(__DIR__, 2) . '/lib/repo/directory.php';
require_once dirname(__DIR__, 2) . '/lib/repo/directory_users.php';
require_once dirname(__DIR__) . '/Support/DirectoryLdapFixture.php';

/**
 * N1a: the AD sign-in and session recheck against the hermetic fixture, through
 * login() and the AD half of current_user(). Skip contract as in
 * DirectoryLdapFixtureTest: never skipped in the Integration lane.
 */
final class DirectoryLoginSessionTest extends TestCase
{
    private const CLIENT_IP = '198.51.100.41';
    // Not in seed/base.ldif: an imported object AD no longer has.
    private const GHOST_GUID_HEX = 'f0f1f2f3f4f5f6f7f8f9fafbfcfdfeff';

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
        $this->cleanup();
        $_SERVER['REMOTE_ADDR'] = self::CLIENT_IP;
        $_SERVER['HTTPS'] = 'on';
        session_start_secure();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $_SESSION = [];
        $this->cleanup();
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTPS']);
    }

    private function cleanup(): void
    {
        DirectoryLdapFixture::cleanup($this->db);
        $ghost = (string) hex2bin(self::GHOST_GUID_HEX);
        $stmt = $this->db->prepare('DELETE FROM deploy_users WHERE ad_object_guid = ?');
        $stmt->bind_param('s', $ghost);
        $stmt->execute();
        $this->db->query("DELETE FROM deploy_login_attempts WHERE ip_address = '" . self::CLIENT_IP . "'");
        $this->db->query("DELETE FROM deploy_logs WHERE category = 'auth' AND ip = '" . self::CLIENT_IP . "'");
    }

    private function configure(string ...$hosts): int
    {
        $config = DirectoryLdapFixture::insertConfig($this->db, DirectoryLdapFixture::trustedBundle(), $this->actorId);
        $first = 0;
        foreach ($hosts as $index => $host) {
            $id = DirectoryLdapFixture::insertController($this->db, $host, $config['revision'], $index + 1, $this->actorId);
            $first = $first === 0 ? $id : $first;
        }

        return $first;
    }

    private function attempts(): int
    {
        $row = $this->db->query("SELECT COUNT(*) AS c FROM deploy_login_attempts WHERE ip_address = '" . self::CLIENT_IP . "'")->fetch_assoc();

        return (int) ($row['c'] ?? -1);
    }

    /** @return array<string,mixed> the context of the newest auth row of this test */
    private function lastAuthContext(string $eventCode): array
    {
        $stmt = $this->db->prepare("SELECT context_json FROM deploy_logs WHERE category = 'auth' AND event_code = ? AND ip = ? ORDER BY id DESC LIMIT 1");
        $ip = self::CLIENT_IP;
        $stmt->bind_param('ss', $eventCode, $ip);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        self::assertIsArray($row, 'no ' . $eventCode . ' row');

        return (array) json_decode((string) $row['context_json'], true);
    }

    // --- sign-in -----------------------------------------------------------

    /**
     * A1: a NUL byte made PHP's ldap_bind() throw for imported names only,
     * which answered "directory unavailable" and freed the budget: an
     * unthrottled oracle for imported UPNs. Now both answer alike and neither
     * reserves an attempt.
     */
    public function testANulPasswordAnswersAlikeForImportedAndUnknownNamesWithoutCounting(): void
    {
        $this->configure(DirectoryLdapFixture::HOST_DC1);
        DirectoryLdapFixture::importAlice($this->db);

        $imported = login(DirectoryLdapFixture::ALICE_UPN, "fixture\0alice", $this->db, VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY);
        $unknown = login('ghost@vs-ldap.test', "fixture\0alice", $this->db, VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY);

        self::assertSame(['ok' => false, 'reason' => 'invalid'], $imported);
        self::assertSame($imported, $unknown);
        self::assertSame(0, $this->attempts(), 'neither attempt may reserve or count');
    }

    /** A8, F4: an overlong password or a non-UPN name never reach LDAP or the counter. */
    public function testOverlongPasswordsAndNonUpnNamesAreRefusedBeforeTheCounter(): void
    {
        $this->configure(DirectoryLdapFixture::HOST_DC1);
        DirectoryLdapFixture::importAlice($this->db);
        $ad = VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY;

        self::assertSame('invalid', login(DirectoryLdapFixture::ALICE_UPN, str_repeat('a', VIRTUSPHERE_DIRECTORY_PASSWORD_MAX_BYTES + 1), $this->db, $ad)['reason']);
        self::assertSame('invalid', login('VS-LDAP\\alice', DirectoryLdapFixture::ALICE_PASSWORD, $this->db, $ad)['reason']);
        self::assertSame('invalid', login('alice', DirectoryLdapFixture::ALICE_PASSWORD, $this->db, $ad)['reason']);
        self::assertSame(0, $this->attempts());

        // Control: a wrong password of the right shape is a counted attempt.
        self::assertSame('invalid', login(DirectoryLdapFixture::ALICE_UPN, 'not-the-real-password', $this->db, $ad)['reason']);
        self::assertSame(1, $this->attempts());
    }

    /** F5: the typed AD reason lands in the audit row only; the answer stays generic. */
    public function testARejectedPasswordCarriesItsTypedDirectoryReasonInTheAuditOnly(): void
    {
        $this->configure(DirectoryLdapFixture::HOST_DC1);
        DirectoryLdapFixture::importAlice($this->db);

        $result = login(DirectoryLdapFixture::ALICE_UPN, 'not-the-real-password', $this->db, VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY);

        self::assertSame(['ok' => false, 'reason' => 'invalid'], $result);
        $context = $this->lastAuthContext(VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN);
        // slapd sends no AD sub-code; the closed fallback word is "invalid".
        self::assertSame(VIRTUSPHERE_DIRECTORY_BIND_REASON_INVALID, $context['directory_reason'] ?? null);
        self::assertSame(VIRTUSPHERE_AUDIT_LOGIN_REASON_INVALID_CREDENTIALS, $context['reason'] ?? null);
    }

    /** A11: an account disabled in AD is refused at sign-in and counts like a bad password. */
    public function testAnAccountDisabledInAdIsRefusedAtSignIn(): void
    {
        $this->configure(DirectoryLdapFixture::HOST_DC1);
        DirectoryLdapFixture::importUser($this->db, DirectoryLdapFixture::BOB_GUID_HEX, DirectoryLdapFixture::BOB_UPN);

        $result = login(DirectoryLdapFixture::BOB_UPN, 'fixture-bob-Pass123!', $this->db, VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY);

        self::assertSame(['ok' => false, 'reason' => 'invalid'], $result);
        self::assertSame(1, $this->attempts());
    }

    /** F1: a sign-in remembers the password generation it was made with. */
    public function testASignInRemembersThePasswordGenerationOfTheAccount(): void
    {
        $this->configure(DirectoryLdapFixture::HOST_DC1);
        DirectoryLdapFixture::importAlice($this->db);

        $result = login(DirectoryLdapFixture::ALICE_UPN, DirectoryLdapFixture::ALICE_PASSWORD, $this->db, VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY);

        self::assertTrue($result['ok']);
        self::assertSame(DirectoryLdapFixture::ALICE_PWD_LAST_SET, $_SESSION['directory_pwd_last_set'] ?? null);
    }

    // --- session recheck ---------------------------------------------------

    /** @return array<string,mixed> */
    private function sessionFor(string $guidHex, string $upn, int $verifiedAgo, ?string $baseline): array
    {
        $userId = $guidHex === DirectoryLdapFixture::ALICE_GUID_HEX
            ? DirectoryLdapFixture::importAlice($this->db)
            : DirectoryLdapFixture::importUser($this->db, $guidHex, $upn);
        $_SESSION = [
            'user_id' => $userId,
            'session_expires_at' => time() + 600,
            'auth_source' => VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY,
            'directory_verified_at' => time() - $verifiedAgo,
        ];
        if ($baseline !== null) {
            $_SESSION['directory_pwd_last_set'] = $baseline;
        }
        $user = repo_directory_user_by_guid($this->db, (string) hex2bin($guidHex));
        self::assertIsArray($user);

        return $user;
    }

    private function lastSessionEndReason(int $userId): string
    {
        $stmt = $this->db->prepare("SELECT context_json FROM deploy_logs WHERE event_code = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
        $event = VIRTUSPHERE_AUDIT_EVENT_AUTH_SESSION_ENDED;
        $stmt->bind_param('si', $event, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        self::assertIsArray($row, 'no auth.session_ended row');

        return (string) (json_decode((string) $row['context_json'], true)['reason'] ?? '');
    }

    public function testADueRecheckOfAnUnchangedAccountKeepsTheSession(): void
    {
        $this->configure(DirectoryLdapFixture::HOST_DC1);
        $user = $this->sessionFor(DirectoryLdapFixture::ALICE_GUID_HEX, DirectoryLdapFixture::ALICE_UPN, VIRTUSPHERE_DIRECTORY_SESSION_RECHECK_SECONDS + 1, DirectoryLdapFixture::ALICE_PWD_LAST_SET);

        self::assertNotNull(auth_directory_session_user($this->db, $user, (int) $user['id']));
        self::assertGreaterThanOrEqual(time() - 1, (int) $_SESSION['directory_verified_at']);
        self::assertSame(DirectoryLdapFixture::ALICE_PWD_LAST_SET, $_SESSION['directory_pwd_last_set']);
    }

    public function testASessionFromBeforeF1LearnsItsBaselineAtTheFirstRecheck(): void
    {
        $this->configure(DirectoryLdapFixture::HOST_DC1);
        $user = $this->sessionFor(DirectoryLdapFixture::ALICE_GUID_HEX, DirectoryLdapFixture::ALICE_UPN, VIRTUSPHERE_DIRECTORY_SESSION_RECHECK_SECONDS + 1, null);

        self::assertNotNull(auth_directory_session_user($this->db, $user, (int) $user['id']));
        self::assertSame(DirectoryLdapFixture::ALICE_PWD_LAST_SET, $_SESSION['directory_pwd_last_set']);
    }

    /** F1: a password set after the sign-in ends the session. */
    public function testAPasswordSetAfterTheSignInEndsTheSession(): void
    {
        $this->configure(DirectoryLdapFixture::HOST_DC1);
        $user = $this->sessionFor(DirectoryLdapFixture::ALICE_GUID_HEX, DirectoryLdapFixture::ALICE_UPN, VIRTUSPHERE_DIRECTORY_SESSION_RECHECK_SECONDS + 1, '133000000000000000');

        self::assertNull(auth_directory_session_user($this->db, $user, (int) $user['id']));
        self::assertSame(VIRTUSPHERE_AUDIT_SESSION_REASON_DIRECTORY_PASSWORD_CHANGED, $this->lastSessionEndReason((int) $user['id']));
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    /** F1: an account locked in AD ends the session. */
    public function testALockedAccountEndsTheSession(): void
    {
        $this->configure(DirectoryLdapFixture::HOST_DC1);
        $user = $this->sessionFor(DirectoryLdapFixture::CAROL_GUID_HEX, DirectoryLdapFixture::CAROL_UPN, VIRTUSPHERE_DIRECTORY_SESSION_RECHECK_SECONDS + 1, DirectoryLdapFixture::ALICE_PWD_LAST_SET);

        self::assertNull(auth_directory_session_user($this->db, $user, (int) $user['id']));
        self::assertSame(VIRTUSPHERE_AUDIT_SESSION_REASON_DIRECTORY_ACCOUNT_LOCKED, $this->lastSessionEndReason((int) $user['id']));
    }

    /** A9: a disabled account ends the session and the user list learns it. */
    public function testADisabledAccountEndsTheSessionAndUpdatesTheCache(): void
    {
        $this->configure(DirectoryLdapFixture::HOST_DC1);
        $user = $this->sessionFor(DirectoryLdapFixture::BOB_GUID_HEX, DirectoryLdapFixture::BOB_UPN, VIRTUSPHERE_DIRECTORY_SESSION_RECHECK_SECONDS + 1, DirectoryLdapFixture::ALICE_PWD_LAST_SET);
        self::assertSame(1, (int) $user['ad_account_enabled'], 'precondition: imported as enabled');

        self::assertNull(auth_directory_session_user($this->db, $user, (int) $user['id']));
        self::assertSame(VIRTUSPHERE_AUDIT_SESSION_REASON_DIRECTORY_ACCOUNT_DISABLED, $this->lastSessionEndReason((int) $user['id']));
        $cached = repo_directory_user_by_guid($this->db, (string) hex2bin(DirectoryLdapFixture::BOB_GUID_HEX));
        self::assertSame(0, (int) $cached['ad_account_enabled'], 'the cache must follow the disabled answer');
    }

    public function testAnObjectAdNoLongerHasEndsTheSession(): void
    {
        $this->configure(DirectoryLdapFixture::HOST_DC1);
        $user = $this->sessionFor(self::GHOST_GUID_HEX, 'ghost@vs-ldap.test', VIRTUSPHERE_DIRECTORY_SESSION_RECHECK_SECONDS + 1, DirectoryLdapFixture::ALICE_PWD_LAST_SET);

        self::assertNull(auth_directory_session_user($this->db, $user, (int) $user['id']));
        self::assertSame(VIRTUSPHERE_DIRECTORY_OUTCOME_NOT_FOUND, $this->lastSessionEndReason((int) $user['id']));
    }

    /** A transport failure inside the grace period keeps the session and schedules a retry. */
    public function testAnUnreachableDirectoryInsideTheGracePeriodKeepsTheSession(): void
    {
        $controllerId = $this->configure(DirectoryLdapFixture::HOST_DC1);
        $this->db->query('UPDATE deploy_ad_controllers SET port = 6399 WHERE id = ' . $controllerId);
        $user = $this->sessionFor(DirectoryLdapFixture::ALICE_GUID_HEX, DirectoryLdapFixture::ALICE_UPN, VIRTUSPHERE_DIRECTORY_SESSION_RECHECK_SECONDS + 1, DirectoryLdapFixture::ALICE_PWD_LAST_SET);

        self::assertNotNull(auth_directory_session_user($this->db, $user, (int) $user['id']));
        self::assertGreaterThan(time(), (int) ($_SESSION['directory_retry_at'] ?? 0));
    }

    /** After the grace period an unreachable directory ends the session. */
    public function testAnUnreachableDirectoryAfterTheGracePeriodEndsTheSession(): void
    {
        $controllerId = $this->configure(DirectoryLdapFixture::HOST_DC1);
        $this->db->query('UPDATE deploy_ad_controllers SET port = 6399 WHERE id = ' . $controllerId);
        $user = $this->sessionFor(DirectoryLdapFixture::ALICE_GUID_HEX, DirectoryLdapFixture::ALICE_UPN, VIRTUSPHERE_DIRECTORY_SESSION_GRACE_SECONDS + 1, DirectoryLdapFixture::ALICE_PWD_LAST_SET);

        self::assertNull(auth_directory_session_user($this->db, $user, (int) $user['id']));
        self::assertSame(VIRTUSPHERE_AUDIT_SESSION_REASON_DIRECTORY_UNAVAILABLE, $this->lastSessionEndReason((int) $user['id']));
    }
}
