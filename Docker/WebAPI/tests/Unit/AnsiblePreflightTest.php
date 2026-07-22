<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible_command.php';

/**
 * The Ansible-host preflight: the shell command the credential test and the
 * deploy worker run, and the reader that names the component that broke the
 * && chain. These pin the marker-before-check grammar, the lenient/strict
 * collection split, the optional portal probe and the failure attribution.
 */
final class AnsiblePreflightTest extends TestCase
{
    public function testEachComponentEchoesItsMarkerBeforeItsCheck(): void
    {
        $command = ansible_preflight_command();
        $cursor = 0;
        foreach (array_keys(ansible_preflight_checks()) as $component) {
            $marker = strpos($command, VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . ' ' . $component, $cursor);
            self::assertNotFalse($marker, 'marker missing for ' . $component);
            $cursor = $marker;
        }
        // One && chain, so the first failing check stops before the next marker.
        self::assertStringContainsString(' && ', $command);
    }

    public function testLenientProbeUsesVmwareGuestAndStrictUsesAutostartModule(): void
    {
        $lenient = ansible_preflight_command();
        self::assertStringContainsString('community.vmware.vmware_guest', $lenient);
        self::assertStringNotContainsString('vmware_host_auto_start', $lenient);

        $strict = ansible_preflight_command('', true);
        self::assertStringContainsString('community.vmware.vmware_host_auto_start', $strict);
    }

    public function testPortalProbeIsAppendedOnlyWithAnApiBaseUrl(): void
    {
        self::assertStringNotContainsString('health.php', ansible_preflight_command(''));

        $withUrl = ansible_preflight_command('http://portal.lan:8021/', true);
        // Trailing slash trimmed, health path appended, and named as its own step.
        self::assertStringContainsString('http://portal.lan:8021/portal/health.php', $withUrl);
        self::assertStringContainsString(VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . ' ' . VIRTUSPHERE_ANSIBLE_PREFLIGHT_PORTAL, $withUrl);
    }

    public function testPortalProbePassesUrlThroughEnvNotSourceInterpolation(): void
    {
        // The URL is operator-controlled: it must reach python via the environment,
        // never spliced into the -c source, so a crafted value cannot break quoting.
        $command = ansible_preflight_command('http://portal.lan:8021', true);
        self::assertStringContainsString('VS_PF_URL=', $command);
        self::assertStringContainsString('os.environ["VS_PF_URL"]', $command);
    }

    public function testAllowlistProbeIsAppendedOnlyWithAnApiBaseUrlAndUsesTheEnv(): void
    {
        self::assertStringNotContainsString('db_importMAC.php', ansible_preflight_command(''));

        $withUrl = ansible_preflight_command('http://portal.lan:8021/', true);
        // Probes the exact endpoint the deploy's MAC upload posts to, named as
        // its own step, with the URL through the environment like the portal probe.
        self::assertStringContainsString('http://portal.lan:8021/db_importMAC.php', $withUrl);
        self::assertStringContainsString(VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . ' ' . VIRTUSPHERE_ANSIBLE_PREFLIGHT_ALLOWLIST, $withUrl);
        self::assertStringContainsString('VS_PF_MAC_URL=', $withUrl);
        self::assertStringContainsString('os.environ["VS_PF_MAC_URL"]', $withUrl);
        // Only the legacy 403 may read as denied; the expected 405 must not.
        self::assertStringContainsString('403', $withUrl);
    }

    public function testAllowlistVerdictReadsOkDeniedAndUnknown(): void
    {
        $chain = VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . " portal\n" . VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . " allowlist\n";

        self::assertSame(
            ['status' => 'ok', 'ip' => ''],
            ansible_preflight_allowlist_verdict($chain . VIRTUSPHERE_ANSIBLE_ALLOWLIST_MARKER . ' ok')
        );
        self::assertSame(
            ['status' => 'denied', 'ip' => '10.89.7.4'],
            ansible_preflight_allowlist_verdict($chain . VIRTUSPHERE_ANSIBLE_ALLOWLIST_MARKER . ' denied 10.89.7.4')
        );
        self::assertSame(
            ['status' => 'unknown', 'ip' => ''],
            ansible_preflight_allowlist_verdict($chain . VIRTUSPHERE_ANSIBLE_ALLOWLIST_MARKER . ' unknown')
        );
    }

    public function testAllowlistVerdictIsAbsentWithoutTheProbeAndDropsANonIpEcho(): void
    {
        // Output from a run without an API base URL, or from before the probe
        // existed: no verdict line at all.
        self::assertSame(
            ['status' => 'absent', 'ip' => ''],
            ansible_preflight_allowlist_verdict(VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . " portal\nok")
        );
        // The echoed IP crossed a remote shell, so anything that does not parse
        // as an IP is discarded rather than carried into audit lines.
        self::assertSame(
            ['status' => 'denied', 'ip' => ''],
            ansible_preflight_allowlist_verdict(VIRTUSPHERE_ANSIBLE_ALLOWLIST_MARKER . ' denied not-an-ip')
        );
    }

    public function testFailedComponentRecognisesTheAllowlistToken(): void
    {
        $output = VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . " portal\n"
            . VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . " allowlist\nTraceback (most recent call last):";
        self::assertSame(VIRTUSPHERE_ANSIBLE_PREFLIGHT_ALLOWLIST, ansible_preflight_failed_component($output));
    }

    public function testStripMarkersRemovesTheAllowlistVerdictLineToo(): void
    {
        $output = VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . " allowlist\n"
            . VIRTUSPHERE_ANSIBLE_ALLOWLIST_MARKER . " denied 10.89.7.4\nreal remote output";
        self::assertSame('real remote output', ansible_preflight_strip_markers($output));
    }

    public function testFailedComponentIsTheLastMarkerReached(): void
    {
        $output = VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . " ansible-playbook\nansible-playbook 2.19\n"
            . VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . " python3\nPython 3.13\n"
            . VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . " pyvmomi\nModuleNotFoundError: No module named 'pyVmomi'";
        self::assertSame('pyvmomi', ansible_preflight_failed_component($output));
    }

    public function testFailedComponentRecognisesTheOptionalPortalToken(): void
    {
        $output = VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . " community.vmware\n"
            . VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . " portal\nurllib.error.URLError";
        self::assertSame(VIRTUSPHERE_ANSIBLE_PREFLIGHT_PORTAL, ansible_preflight_failed_component($output));
    }

    public function testFailedComponentIsNullWithoutAMarkerAndIgnoresUnknownTokens(): void
    {
        self::assertNull(ansible_preflight_failed_component("bash: command failed\n"));
        self::assertNull(ansible_preflight_failed_component(VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . ' not-a-component'));
    }

    public function testStripMarkersLeavesOnlyTheRemoteOutput(): void
    {
        $output = VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . " pyvmomi\nModuleNotFoundError: No module named 'pyVmomi'\n"
            . VIRTUSPHERE_ANSIBLE_PREFLIGHT_MARKER . ' community.vmware';
        self::assertSame("ModuleNotFoundError: No module named 'pyVmomi'", ansible_preflight_strip_markers($output));
    }
}
