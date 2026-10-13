<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * F-UI (K21-F): the places that show the ESXi access files still open on an
 * Ansible host, and the form that confirms them.
 *
 * - The System status notice renders the groups of repo_remote_secret_notice_groups()
 *   and nothing else: no secret, no remote directory; one acknowledge form per group
 *   for users with credentials.manage.
 * - The credentials page line under an Ansible credential uses the same form.
 * - The status page and the credentials page are checked for their wiring
 *   (order, anchor, permission guard) from their source, since the pages themselves
 *   need a session and a database.
 *
 * Renders through ob_start, as SystemStatusPanelBranchTest does, without a database.
 */
final class RemoteSecretNoticeRenderTest extends TestCase
{
    private const MARKER = 'data-remote-secret-files';
    private const ACTION = 'remote_secret_acknowledge';

    /** Planned keys of the notice, the form, the refusals and the audit name, by catalog module. */
    private const PLANNED_KEYS = [
        'system_status' => [
            'remote_secret_notice_title', 'remote_secret_group_line', 'remote_secret_group_line_orphan',
            'remote_secret_reason_overdue', 'remote_secret_reason_orphaned', 'remote_secret_reason_moved',
            'remote_secret_remedy', 'remote_secret_password_hint',
        ],
        'credentials' => [
            'remote_secret_count_one', 'remote_secret_count_other', 'remote_secret_open_line', 'remote_secret_no_permission',
            'remote_secret_reason_label', 'remote_secret_reason_hint', 'remote_secret_acknowledge_submit',
            'remote_secret_acknowledge_confirm', 'remote_secret_open_action', 'remote_secret_fence',
            'remote_secret_reason_required', 'remote_secret_acknowledged', 'remote_secret_nothing_open',
        ],
        'logs' => ['eventcode_deploy_remote_secret_acknowledged'],
    ];

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/portal_time.php';
        require_once dirname(__DIR__, 2) . '/lib/csrf.php';
        require_once dirname(__DIR__, 2) . '/lib/layout.php';
        require_once dirname(__DIR__, 2) . '/lib/layout_response.php';
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_start();
        }
    }

    /**
     * The renderer is the interface under test. Only the render cases need it, so
     * the wiring and language cases report their own findings on their own.
     */
    private function requireRenderer(): void
    {
        $path = dirname(__DIR__, 2) . '/lib/system_status_remote_secret_notice.php';
        if (!is_file($path)) {
            self::fail('Interface missing: lib/system_status_remote_secret_notice.php does not exist yet.');
        }
        require_once $path;
    }

    /** @param list<array<string, mixed>> $groups @param array<string, mixed> $user */
    private function render(array $groups, array $user): string
    {
        $this->requireRenderer();
        ob_start();
        system_status_render_remote_secret_notice($groups, $user);

        return (string) ob_get_clean();
    }

    /**
     * @param list<array{target_host: string, target_port: int, count: int, oldest: string}> $targets
     * @param array<string, mixed> $user
     */
    private function renderOpenLine(int $credentialId, array $targets, array $user): string
    {
        $this->requireRenderer();
        ob_start();
        remote_secret_render_open_line($credentialId, $targets, $user);

        return (string) ob_get_clean();
    }

    /** @return array<string, mixed> */
    private function group(string $reason, ?int $credentialId = 5, ?string $name = 'esxi-ansible', string $host = 'ubuntu-102.example.test', int $port = 22, int $count = 2): array
    {
        return [
            'credential_id' => $credentialId,
            'credential_name' => $name,
            'target_host' => $host,
            'target_port' => $port,
            'count' => $count,
            'oldest' => '2026-10-10 08:00:00',
            'reason' => $reason,
        ];
    }

    /** @return array<string, mixed> */
    private function manager(): array
    {
        return ['id' => 1, 'role' => VIRTUSPHERE_ROLE_ADMIN];
    }

    /** @return array<string, mixed> */
    private function plainUser(): array
    {
        return ['id' => 2, 'role' => VIRTUSPHERE_ROLE_USER];
    }

    private function hours(): int
    {
        return intdiv(VIRTUSPHERE_REMOTE_SECRET_NOTICE_AFTER_SECONDS, 3600);
    }

    public function testWithoutGroupsTheNoticeIsNotRendered(): void
    {
        self::assertStringNotContainsString(self::MARKER, $this->render([], $this->manager()));
    }

    public function testOneGroupRendersTheNoticeWithOneAcknowledgeFormForAManager(): void
    {
        $html = $this->render([$this->group('overdue')], $this->manager());

        self::assertStringContainsString(self::MARKER, $html);
        self::assertSame(1, substr_count($html, '<form'));
        self::assertMatchesRegularExpression('/<form[^>]*action="credentials\.php"/', $html);
        self::assertMatchesRegularExpression('/<input[^>]*name="action"[^>]*value="' . self::ACTION . '"/', $html);
        self::assertMatchesRegularExpression('/<input[^>]*name="credential_id"[^>]*value="5"/', $html);
        self::assertMatchesRegularExpression('/<input[^>]*name="target_host"[^>]*value="ubuntu-102\.example\.test"/', $html);
        self::assertMatchesRegularExpression('/<input[^>]*name="target_port"[^>]*value="22"/', $html);
        self::assertMatchesRegularExpression('/<input[^>]*name="return_to"[^>]*value="system_status"/', $html, 'the notice returns to the status page');
        self::assertMatchesRegularExpression('/<textarea[^>]*name="reason"[^>]*required/', $html, 'the reason is a required field');
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertMatchesRegularExpression('/data-confirm="[^"]+"/', $html, 'the acknowledgement asks before it writes');
        self::assertStringContainsString(h(__t('credentials.remote_secret_acknowledge_confirm', ['name' => 'ubuntu-102.example.test:22'])), $html, 'the question names its target');
    }

    public function testOrphanedGroupPostsNoCredentialId(): void
    {
        $html = $this->render([$this->group('orphaned', null, null)], $this->manager());

        self::assertStringContainsString(self::ACTION, $html);
        self::assertDoesNotMatchRegularExpression('/name="credential_id" value="[1-9]/', $html);
        self::assertStringContainsString(h(__t('system_status.remote_secret_group_line_orphan', [
            'target' => 'ubuntu-102.example.test:22',
            'files' => __t('credentials.remote_secret_count_other', ['count' => 2]),
            'oldest' => portal_format_timestamp('2026-10-10 08:00:00'),
            'reason' => __t('system_status.remote_secret_reason_orphaned'),
        ])), $html, 'the line of a deleted credential names no credential');
    }

    public function testUserWithoutCredentialsManageSeesTheNoticeButNoForm(): void
    {
        $html = $this->render([$this->group('moved')], $this->plainUser());

        self::assertStringContainsString(self::MARKER, $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString(self::ACTION, $html);
        self::assertSame(1, substr_count($html, h(__t('credentials.remote_secret_no_permission'))), 'the permission sentence is shown once');
    }

    public function testEveryGroupHasItsOwnFormAndItsOwnReasonSentence(): void
    {
        $html = $this->render([
            $this->group('overdue', 5),
            $this->group('orphaned', null, null, 'ubuntu-103.example.test'),
            $this->group('moved', 6, 'esxi-ansible-2', 'ubuntu-104.example.test', 2222),
        ], $this->manager());

        self::assertSame(3, substr_count($html, '<form'));
        self::assertStringContainsString(h(__t('system_status.remote_secret_reason_overdue', ['hours' => $this->hours()])), $html);
        self::assertStringContainsString(h(__t('system_status.remote_secret_reason_orphaned')), $html);
        self::assertStringContainsString(h(__t('system_status.remote_secret_reason_moved')), $html);
        self::assertStringContainsString('ubuntu-104.example.test:2222', $html);
    }

    public function testTheCountUsesItsSingularOrPluralSentence(): void
    {
        self::assertStringContainsString(h(__t('credentials.remote_secret_count_one')), $this->render([$this->group('overdue', 5, 'a', 'h1.example.test', 22, 1)], $this->manager()));
        self::assertStringContainsString(h(__t('credentials.remote_secret_count_other', ['count' => 3])), $this->render([$this->group('overdue', 5, 'a', 'h2.example.test', 22, 3)], $this->manager()));
    }

    public function testTheOldestFindingIsShownInTheDisplayTimezone(): void
    {
        $html = $this->render([$this->group('overdue')], $this->manager());

        self::assertStringContainsString(h(portal_format_timestamp('2026-10-10 08:00:00')), $html);
    }

    public function testRemedyAndPasswordRecommendationAreShownOnceEach(): void
    {
        $html = $this->render([$this->group('overdue'), $this->group('moved', 6, 'x', 'ubuntu-103.example.test')], $this->manager());

        self::assertSame(1, substr_count($html, h(__t('system_status.remote_secret_remedy'))));
        self::assertSame(1, substr_count($html, h(__t('system_status.remote_secret_password_hint'))));
    }

    public function testEveryFieldIsEscaped(): void
    {
        $html = $this->render([$this->group('overdue', 5, '<script>alert(1)</script>', 'x"><b>.example.test')], $this->manager());

        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<b>', $html);
    }

    public function testTheNoticeNeverPrintsASecretOrARemoteDirectory(): void
    {
        $html = $this->render([$this->group('overdue')], $this->manager());

        self::assertStringNotContainsString('/tmp/', $html);
        self::assertStringNotContainsString('remote_dir', $html);
        self::assertStringNotContainsString('ciphertext', $html);
    }

    public function testTheCredentialLineCarriesTheSameFormWithTheCredentialsReturnToken(): void
    {
        $html = $this->renderOpenLine(7, [['target_host' => 'ubuntu-102.example.test', 'target_port' => 22, 'count' => 1, 'oldest' => '2026-10-13 08:00:00']], $this->manager());

        self::assertSame(1, substr_count($html, '<form'));
        self::assertMatchesRegularExpression('/<input[^>]*name="credential_id"[^>]*value="7"/', $html);
        self::assertMatchesRegularExpression('/<input[^>]*name="return_to"[^>]*value="credentials"/', $html);
        self::assertMatchesRegularExpression('/<textarea[^>]*name="reason"[^>]*required/', $html);
        self::assertStringContainsString(h(__t('credentials.remote_secret_open_line', [
            'target' => 'ubuntu-102.example.test:22',
            'files' => __t('credentials.remote_secret_count_one'),
            'oldest' => portal_format_timestamp('2026-10-13 08:00:00'),
        ])), $html);
    }

    public function testTheCredentialLineRendersNothingWithoutOpenTargets(): void
    {
        self::assertSame('', $this->renderOpenLine(7, [], $this->manager()));
    }

    public function testStatusPageRendersTheNoticeAboveTheOverviewFromTheRepository(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/portal/system_status.php');

        $groups = strpos($source, 'repo_remote_secret_notice_groups($connection)');
        $notice = strpos($source, 'system_status_render_remote_secret_notice(');
        $overview = strpos($source, 'system_status_render_overview(');

        self::assertIsInt($groups, 'the status page reads the notice groups from the repository');
        self::assertIsInt($notice, 'the status page renders the notice');
        self::assertIsInt($overview, 'the overview renders on the status page');
        self::assertLessThan($overview, $notice, 'the notice sits above the overview, beside the other notices');
    }

    public function testCredentialsPageRendersTheAnchoredLineAndReadsTheOpenRowsOnce(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/credentials_panels.php');

        self::assertStringContainsString('repo_remote_secret_open_by_credential($connection)', $source);
        self::assertStringContainsString('id="remote-secret-<?php echo h((string) $rowId); ?>"', $source, 'the lock message links to credentials.php#remote-secret-<id>');
        self::assertStringContainsString('remote_secret_render_open_line($rowId, $remoteSecretOpen[$rowId], $user)', $source);
    }

    public function testTheCredentialsGuardsEveryPostBeforeItsDispatch(): void
    {
        // The acknowledgement is one more action of this page: its permission is the page's own
        // guard, checked before the dispatch, as for every other action of credentials.php.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/portal/credentials.php');

        $guard = strpos($source, "can('credentials.manage', \$user)");
        $dispatch = strpos($source, 'credentials_handle_post(');
        self::assertIsInt($guard);
        self::assertIsInt($dispatch);
        self::assertLessThan($dispatch, $guard, 'the permission check precedes the POST dispatch');
    }

    public function testPlannedTextsExistInBothLocalesWithoutAnEmDash(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['de', 'en'] as $locale) {
            foreach (self::PLANNED_KEYS as $module => $keys) {
                $catalog = require $root . '/lang/' . $locale . '/' . $module . '.php';
                foreach ($keys as $key) {
                    self::assertArrayHasKey($key, $catalog, sprintf('lang/%s/%s.php lacks %s', $locale, $module, $key));
                    self::assertStringNotContainsString('—', (string) $catalog[$key], sprintf('%s.%s (%s) uses an em dash', $module, $key, $locale));
                }
            }
        }
    }
}
