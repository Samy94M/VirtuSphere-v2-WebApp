<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible.php';

final class AnsibleMacExportContractTest extends TestCase
{
    public function testExportKeepsPerVmFailuresAndClassifiesUploadExitCodes(): void
    {
        $playbook = $this->source('exportVMs-Informations-ESXi_playbook.yml');
        // K15 moved the per-VM query into the include so each VM is admitted
        // right before its own read; a failed read still stays a per-VM result.
        self::assertStringContainsString('include_tasks: ./' . VIRTUSPHERE_EXPORT_VM_INFO_TASKS, $playbook);
        self::assertMatchesRegularExpression(
            '/register:\\s+vm_info_current.*?ignore_errors:\\s+true/s',
            $this->source(VIRTUSPHERE_EXPORT_VM_INFO_TASKS)
        );
        self::assertStringContainsString(
            'content: \'{{ vm_info_identity_results | to_nice_json }}\'',
            str_replace(chr(34), chr(39), $playbook)
        );
        self::assertMatchesRegularExpression(
            '/register:\\s+mac_upload.*?failed_when:\\s+mac_upload\\.rc not in \\[0, 20\\]/s',
            $playbook
        );
    }

    public function testWorkerPatchesApiMissionAndJobWhileTemplateStaysDesktopCompatible(): void
    {
        $source = $this->source('upload_mac_list.py');
        self::assertStringContainsString('api_base_url = \'http://{{apiUrl}}\'', $source);
        self::assertStringContainsString('mission_id = \'{{missionId}}\'', $source);
        self::assertStringContainsString('job_id = \'{{jobId}}\'', $source);

        $temporary = tempnam(sys_get_temp_dir(), 'vs-upload-');
        self::assertIsString($temporary);
        self::assertTrue(copy($this->sourcePath('upload_mac_list.py'), $temporary));

        try {
            ansible_patch_upload_script($temporary, 'https://portal.invalid:8443', 123, 456);
            $patched = file_get_contents($temporary);
            self::assertIsString($patched);
            self::assertStringContainsString('api_base_url = \'https://portal.invalid:8443\'', $patched);
            self::assertStringContainsString('mission_id = \'123\'', $patched);
            self::assertStringContainsString('job_id = \'456\'', $patched);
            self::assertStringNotContainsString('{{apiUrl}}', $patched);
            self::assertStringNotContainsString('{{missionId}}', $patched);
            self::assertStringNotContainsString('{{jobId}}', $patched);
        } finally {
            unlink($temporary);
        }
    }

    public function testUploadClientIsStdlibOnlyFailClosedAndDoesNotLogRawResponses(): void
    {
        $script = $this->source('upload_mac_list.py');

        // Air-gap rule (.claude/rules/ansible.md): stdlib HTTP only, never
        // `requests`. Pinned as "every imported module is on the stdlib list"
        // rather than as one exact import line: the line grew when the script
        // learned TLS, and a contract that breaks on adding `import ssl` tests
        // its own wording instead of the rule.
        $allowed = ['hashlib', 'http.client', 'io', 'json', 'socket', 'ssl', 'time', 'urllib.error', 'urllib.request'];
        // One capture group for both forms: `from X import ...` and `import X`.
        // Indented imports count too, or a lazy import inside a function would be
        // the one place the rule does not reach.
        preg_match_all('/^\s*(?:from|import)\s+([\w.]+)/m', $script, $imports);
        self::assertNotSame([], $imports[1], 'no imports found; the scan stopped working');
        foreach (array_unique($imports[1]) as $module) {
            self::assertContains($module, $allowed, sprintf('upload_mac_list.py imports "%s", which is not on the air-gap stdlib list.', $module));
        }
        self::assertStringContainsString('from urllib.request import', $script);
        self::assertStringContainsString('urlopen', $script);
        self::assertStringContainsString('EXIT_SUCCESS = 0', $script);
        self::assertStringContainsString('EXIT_PARTIAL = 20', $script);
        self::assertStringContainsString('EXIT_FAILED = 21', $script);
        self::assertStringContainsString('EXIT_LOCAL_DATA_ERROR = 22', $script);
        self::assertStringContainsString('EXIT_HTTP_ERROR = 23', $script);
        self::assertStringContainsString('EXIT_RESPONSE_ERROR = 24', $script);
        self::assertStringContainsString('response.get(\'outcome\') not in OUTCOME_EXIT_CODES', $script);
        self::assertStringNotContainsString('Antwort vom Server', $script);
        self::assertStringNotContainsString('response.read().decode', $script);
    }

    public function testVmTouchReporterUsesTheSharedTransportAndKeepsTlsClosed(): void
    {
        $script = $this->source('report_vm_touch.py');
        // upload_mac_list is the sibling script this one shares its transport
        // with; it is uploaded together and held to the stdlib list above.
        $allowed = ['argparse', 'json', 're', 'socket', 'ssl', 'urllib.error', 'urllib.parse', 'urllib.request', 'upload_mac_list'];
        preg_match_all('/^\\s*(?:from|import)\\s+([\\w.]+)/m', $script, $imports);
        self::assertNotSame([], $imports[1], 'no imports found; the scan stopped working');
        foreach (array_unique($imports[1]) as $module) {
            self::assertContains($module, $allowed, sprintf('report_vm_touch.py imports "%s", outside the air-gap stdlib list.', $module));
        }
        self::assertStringContainsString('transport.default_opener(url, fingerprint)', $script);
        self::assertStringContainsString('transport.retry_open_request(request, opener, sleeper, operation)', $script);
        self::assertStringNotContainsString('verify=False', $script);
        self::assertStringNotContainsString('ssl._create_unverified_context', $script);
    }

    public function testWorkerRejectsATemplateWhoseJobPlaceholderDriftedAway(): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'vs-upload-');
        self::assertIsString($temporary);
        $source = preg_replace('/^job_id = .*\\R/m', '', $this->source('upload_mac_list.py'), 1);
        self::assertIsString($source);
        self::assertNotFalse(file_put_contents($temporary, $source));

        try {
            $this->expectException(RuntimeException::class);
            ansible_patch_upload_script($temporary, 'https://portal.invalid:8443', 123, 456);
        } finally {
            unlink($temporary);
        }
    }

    private function source(string $file): string
    {
        $source = file_get_contents($this->sourcePath($file));
        self::assertIsString($source);
        return $source;
    }

    private function sourcePath(string $file): string
    {
        return ansible_source_dir() . DIRECTORY_SEPARATOR . $file;
    }
}
