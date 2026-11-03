<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/login_return.php';

/**
 * QL-04 A: the sign-in returns to the portal page that asked for it, and to
 * nothing else. The accepted pages are derived from the page registry, the
 * rejected ones from the entrypoints on disk and the machine endpoint list, so
 * a new page or endpoint is covered without editing this test.
 */
final class LoginReturnTest extends TestCase
{
    private static function root(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    /** @param array<string, string> $server @return array<string, string> */
    private static function request(string $method, string $script, string $query = '', array $server = []): array
    {
        return $server + ['REQUEST_METHOD' => $method, 'SCRIPT_NAME' => $script, 'QUERY_STRING' => $query];
    }

    public function testAPortalPageWithItsQueryIsAValidTarget(): void
    {
        self::assertSame('vms.php?mission_id=3', login_return_validate('vms.php?mission_id=3'));
        self::assertSame(
            'vm_overview.php?filter=mecm_pending&page=2',
            login_return_validate('vm_overview.php?filter=mecm_pending&page=2')
        );
        self::assertSame('deploy_log.php?id=42', login_return_validate('deploy_log.php?id=42'));
        self::assertSame('vms.php?q=a%20b&ids%5B%5D=1', login_return_validate('vms.php?q=a%20b&ids%5B%5D=1'));
        self::assertSame('missions.php', login_return_validate('missions.php'));
    }

    public function testEveryRegisteredPageExceptTheSignInIsATarget(): void
    {
        self::assertNotSame([], VIRTUSPHERE_LAYOUT_PAGES);
        foreach (VIRTUSPHERE_LAYOUT_PAGES as $page) {
            $expected = $page === VIRTUSPHERE_LOGIN_PAGE ? null : $page;
            self::assertSame($expected, login_return_validate($page), $page);
        }
        self::assertSame(array_values(array_diff(VIRTUSPHERE_LAYOUT_PAGES, [VIRTUSPHERE_LOGIN_PAGE])), login_return_pages());
    }

    public function testEntrypointsOutsideTheRegistryAreNeverATarget(): void
    {
        $unregistered = [];
        foreach (glob(self::root() . '/portal/*.php') ?: [] as $file) {
            $page = basename($file);
            if (!in_array($page, VIRTUSPHERE_LAYOUT_PAGES, true)) {
                $unregistered[] = $page;
            }
        }
        self::assertContains('logout.php', $unregistered, 'the derivation found the portal entrypoints');
        foreach ($unregistered as $page) {
            self::assertNull(login_return_validate($page), $page);
        }
        self::assertNotSame([], VIRTUSPHERE_MACHINE_API_ENDPOINTS);
        foreach (VIRTUSPHERE_MACHINE_API_ENDPOINTS as $endpoint) {
            self::assertNull(login_return_validate($endpoint), $endpoint);
            self::assertNull(login_return_validate('../' . $endpoint), $endpoint);
        }
    }

    /** @return array<string, array{string}> */
    public static function rejectedTargets(): array
    {
        return [
            'foreign host' => ['https://example.invalid/'],
            'foreign host with page' => ['https://example.invalid/vms.php'],
            'scheme relative' => ['//evil.example'],
            'scheme relative with page' => ['//evil.example/vms.php'],
            'backslash host' => ['/\\evil.example'],
            'backslash in query' => ['vms.php?x=\\evil'],
            'encoded slashes' => ['%2F%2Fevil.example'],
            'encoded backslash' => ['%5cevil.example'],
            'encoded page name' => ['vms%2Ephp'],
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:text/html,x'],
            'absolute path' => ['/portal/vms.php'],
            'sub path' => ['portal/vms.php'],
            'traversal' => ['../vms.php'],
            'path info' => ['vms.php/evil'],
            'sign-in page' => ['login.php'],
            'sign-in page with query' => ['login.php?next=vms.php'],
            'other case' => ['VMS.php'],
            'fragment' => ['vms.php#top'],
            'carriage return' => ["vms.php?x=1\r\nSet-Cookie: a=b"],
            'tab' => ["vms.php?x=\t1"],
            'space' => ['vms.php?x= 1'],
            'quote' => ['vms.php?x="1"'],
            'angle bracket' => ['vms.php?x=<b>'],
            'broken escape' => ['vms.php?x=%zz'],
            'non ascii' => ['vms.php?x=ä'],
            'empty' => [''],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('rejectedTargets')]
    public function testEverythingElseIsRejected(string $target): void
    {
        self::assertNull(login_return_validate($target));
    }

    public function testTheLengthIsBoundedByItsConstant(): void
    {
        $prefix = 'vms.php?q=';
        $fits = $prefix . str_repeat('a', VIRTUSPHERE_LOGIN_RETURN_MAX_LENGTH - strlen($prefix));
        self::assertSame($fits, login_return_validate($fits));
        self::assertNull(login_return_validate($fits . 'a'));
        self::assertNull(login_return_validate(null));
    }

    public function testTheRequestTargetIsTheRefusedPageWithItsQuery(): void
    {
        self::assertSame(
            'vm_overview.php?filter=mecm_pending&page=2',
            login_return_target_from_request(self::request('GET', '/portal/vm_overview.php', 'filter=mecm_pending&page=2'))
        );
        self::assertSame('vms.php', login_return_target_from_request(self::request('GET', '/portal/vms.php')));
        // A refused POST returns to the page it was posted to, by GET; the
        // action URL's query (mission, work context) is part of that address.
        self::assertSame(
            'deploy.php?mission_id=3',
            login_return_target_from_request(self::request('POST', '/portal/deploy.php', 'mission_id=3'))
        );
        self::assertNull(login_return_target_from_request(self::request('GET', '/portal/dashboard.php')), 'the default page needs no target');
        self::assertSame('dashboard.php?x=1', login_return_target_from_request(self::request('GET', '/portal/dashboard.php', 'x=1')));
        self::assertNull(login_return_target_from_request(self::request('GET', '/portal/session_ping.php')));
        self::assertNull(login_return_target_from_request(self::request('GET', '/portal/login.php', 'next=vms.php')));
        self::assertNull(login_return_target_from_request(self::request('GET', '/mecm-api.php')));
        self::assertNull(login_return_target_from_request(self::request('GET', '/portal/vms.php', str_repeat('a', VIRTUSPHERE_LOGIN_RETURN_MAX_LENGTH))));
        self::assertNull(login_return_target_from_request([]));
    }

    public function testTheSignInUrlCarriesTheTargetAndTheTwoNotices(): void
    {
        self::assertSame('login.php', login_return_login_url(null));
        self::assertSame('login.php', login_return_login_url('https://example.invalid/'));
        self::assertSame('login.php?next=vms.php%3Fmission_id%3D3', login_return_login_url('vms.php?mission_id=3'));
        self::assertSame('login.php?reason=session_expired', login_return_login_url(null, true));
        self::assertSame(
            'login.php?reason=session_expired&discarded=1&next=deploy.php%3Fmission_id%3D3',
            login_return_login_url('deploy.php?mission_id=3', true, true)
        );
    }

    public function testARefusedRequestIsSentToTheSignInWithItsTarget(): void
    {
        self::assertSame(
            'login.php?reason=session_expired&next=vm_overview.php%3Ffilter%3Dmecm_pending%26page%3D2',
            login_return_login_url_for_request(self::request('GET', '/portal/vm_overview.php', 'filter=mecm_pending&page=2'), true)
        );
        self::assertSame('login.php?next=vms.php', login_return_login_url_for_request(self::request('GET', '/portal/vms.php'), false));
        self::assertSame('login.php', login_return_login_url_for_request(self::request('GET', '/portal/dashboard.php'), false));
        self::assertSame(
            'login.php?reason=session_expired&discarded=1&next=os.php',
            login_return_login_url_for_request(self::request('POST', '/portal/os.php'), true),
            'a refused POST keeps none of its input and says so'
        );
        self::assertSame('login.php?discarded=1', login_return_login_url_for_request(self::request('POST', '/portal/dashboard.php'), false));
    }

    public function testThePasswordChangeAlwaysWinsOverTheTarget(): void
    {
        self::assertSame('account.php', login_return_after_sign_in('vms.php?mission_id=3', true));
        self::assertSame('account.php', login_return_after_sign_in(null, true));
        self::assertSame('vms.php?mission_id=3', login_return_after_sign_in('vms.php?mission_id=3', false));
        self::assertSame('dashboard.php', login_return_after_sign_in(null, false));
        self::assertSame('dashboard.php', login_return_after_sign_in('https://example.invalid/', false));
        self::assertSame('dashboard.php', login_return_after_sign_in('//example.invalid', false));
        self::assertSame('dashboard.php', login_return_after_sign_in('login.php', false));
    }
}
