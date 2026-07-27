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

    public function testEveryPythonLibraryTheCollectionNeedsIsProbedInOneInterpreter(): void
    {
        // The preflight checked pyvmomi and then asked ansible-doc whether the
        // collection is present. ansible-doc only reads documentation, so it
        // succeeds on a host where not a single module can run: every
        // community.vmware module the playbooks call imports the collection's
        // vmware_rest_client, which aborts with "Failed to import the required
        // Python library (requests)" before it reads an argument. pyvmomi does
        // not pull requests in, so the test reported a healthy host and six of
        // the seven inventory queries would have answered "0 datastores" under
        // ignore_errors.
        //
        // What the collection really needs is proven where the collection is
        // installed: check.ps1 -Gate ansible-module-contract calls every used
        // module against 127.0.0.1:443 in the QA image. This test only keeps the
        // portal's side from silently losing a probe again, because
        // Docker/qa-ansible is not mounted into the PHP container and a glob on
        // it here would be permanently empty.
        $checks = ansible_preflight_checks();

        foreach (['pyvmomi', 'requests'] as $library) {
            self::assertArrayHasKey($library, $checks, $library . ' has no preflight component');
        }
        // One interpreter: two libraries importable in two different pythons are
        // not a working host, and the deploy runs whatever `python3` resolves to.
        foreach (['pyvmomi', 'requests'] as $library) {
            self::assertStringStartsWith('python3 -c ', $checks[$library]);
        }
        self::assertStringContainsString('import requests', $checks['requests']);
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

    public function testHttpsProbesCarryTheUploadsPinLogic(): void
    {
        // The MAC upload pins the portal certificate by SHA-256 when the portal
        // runs on a self-signed certificate (upload_mac_list.py). The probes
        // must accept exactly the portals the upload accepts: a bare urlopen()
        // failed the preflight against a self-signed portal whose callback
        // would have worked, so the deploy died in its own precheck.
        $pin = str_repeat('ab', 32);
        $command = ansible_preflight_command('https://portal.lan', false, $pin);

        // The fingerprint travels in the environment like the URLs do, and both
        // probes route through the shared pinned opener.
        self::assertStringContainsString("VS_PF_PIN='" . $pin . "'", $command);
        self::assertSame(2, substr_count($command, 'VS_PF_PIN='), 'portal and allowlist probe both need the pin');
        self::assertSame(2, substr_count($command, 'getpeercert'), 'both probes verify the served certificate');
        self::assertStringContainsString('hashlib.sha256', $command);
        self::assertStringContainsString('vs_urlopen', $command);
    }

    public function testAnHttpPortalKeepsAnEmptyPinAndTheProbesStillRun(): void
    {
        // http has nothing to pin: the pin env is empty and the opener falls
        // back to plain urlopen, exactly like the upload's default_opener().
        $command = ansible_preflight_command('http://portal.lan:8021', false, '');
        self::assertStringContainsString("VS_PF_PIN=''", $command);
        self::assertStringContainsString('portal/health.php', $command);
        self::assertStringContainsString('db_importMAC.php', $command);
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
