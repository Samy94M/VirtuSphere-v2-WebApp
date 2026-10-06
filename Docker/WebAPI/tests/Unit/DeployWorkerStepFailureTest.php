<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible_paths.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_step_failure.php';

/**
 * FC2-05 and FC2-16. A failed playbook step keeps the task and failure line it
 * printed last, redacted like its log line, so the terminal reason names the
 * cause; the autostart step also says how many VMs received their new entry.
 */
final class DeployWorkerStepFailureTest extends TestCase
{
    private const SECRET = 'Esxi-Secret-4711';

    public function testTheLastFailureLineWithItsTaskBecomesTheRedactedDetail(): void
    {
        $failure = deploy_worker_step_failed($this->channel(), 2, VIRTUSPHERE_PLAYBOOKS['autostart'], $this->trace([
            'TASK [Ziel-VMs fuer die Identitaetspruefung lesen] *****',
            'ok: [localhost] => (item=VM-01)',
            // ansible-core 2.19 output, as measured with the QA image: the
            // cause on one [ERROR] line, the item failure as a multi-line
            // block, then the loop's closing summary.
            'TASK [Autostart je VM schreiben] *****',
            'ok: [localhost] => (item=VM-01)',
            '[ERROR]: Task failed: Module failed: Login ' . self::SECRET . ' refused',
            'Origin: /tmp/vs/autostartVMs-ESXi_playbook.yml:112:11',
            'failed: [localhost] (item=VM-02) => {',
            '    "changed": false,',
            '    "msg": "Login ' . self::SECRET . ' refused"',
            '}',
            'fatal: [localhost]: FAILED! => {"msg": "One or more items failed"}',
            'TASK [Zahl geschriebener Autostart-Eintraege melden] *****',
            'ok: [localhost] => {',
            '    "msg": "::virtusphere-autostart:: written=1 total=2"',
            '}',
            'PLAY RECAP *****',
        ]));

        self::assertSame(
            'TASK [Autostart je VM schreiben] failed: [localhost] (item=VM-02): Module failed: Login *** refused',
            $failure->detail
        );
        self::assertSame('failed: [localhost] (item=VM-02)', deploy_log_failure_needle($failure->detail));
        self::assertStringStartsWith('Ansible command failed with exit code 2 (playbook step: autostartVMs-ESXi_playbook.yml).', $failure->getMessage());
        self::assertStringContainsString('1 of 2 VMs received their autostart entry before the step failed', $failure->getMessage());
    }

    public function testTheLogSearchFindsTheStoredLineTheDetailQuotes(): void
    {
        $line = 'fatal: [localhost]: FAILED! => {"msg": "fail Powercycle VM-07 (4201-ab): Zustand dieser UUID im ESXi Host Client prüfen"}';
        $failure = deploy_worker_step_failed($this->channel(), 2, VIRTUSPHERE_PLAYBOOKS['powercycle'], $this->trace([
            'TASK [Powercycle je VM] ***',
            $line,
        ]));
        $gate = new DeployJobOutputGate();
        $stored = $gate->accept(VIRTUSPHERE_DEPLOY_LOG_ANSIBLE, $line)[0]['line'];

        // Before ansible-core 2.19 there is no [ERROR] line; the one-line
        // failure itself is the cause.
        self::assertSame('TASK [Powercycle je VM] ' . $line, $failure->detail);
        $needle = deploy_log_failure_needle($failure->detail);
        self::assertSame('fatal: [localhost]: FAILED!', $needle);
        self::assertStringContainsString((string) $needle, $stored);
        self::assertStringNotContainsString('autostart entry', $failure->getMessage(), 'only the autostart step reports a written count');
    }

    public function testAnIgnoredFailureIsNoCauseAndNoLineMeansNoDetail(): void
    {
        $ignored = deploy_worker_step_failed($this->channel(), 2, VIRTUSPHERE_PLAYBOOKS['start'], $this->trace([
            'TASK [Optionale Abfrage] ***',
            'fatal: [localhost]: FAILED! => {"msg": "tolerated"}',
            '...ignoring',
        ]));
        self::assertNull($ignored->detail);

        // A step that died without a marker states no count: no number is
        // derived from what the worker happened to see.
        $silent = deploy_worker_step_failed($this->channel(), 4, VIRTUSPHERE_PLAYBOOKS['autostart'], $this->trace([]));
        self::assertNull($silent->detail);
        self::assertSame('Ansible command failed with exit code 4 (playbook step: autostartVMs-ESXi_playbook.yml).', $silent->getMessage());
    }

    /**
     * FC2-16, the playbook side: the count is reported in an `always` part
     * after the per-VM write, so it also arrives when one write failed, and in
     * exactly the shape the worker reads.
     */
    public function testTheAutostartPlaybookReportsItsWrittenCountAlsoAfterAFailedWrite(): void
    {
        $playbook = (string) file_get_contents(ansible_source_dir() . '/' . VIRTUSPHERE_PLAYBOOKS['autostart']);
        $write = strpos($playbook, '- name: Autostart je VM schreiben' . "\n");
        $always = strpos($playbook, 'always:');
        $marker = strpos($playbook, VIRTUSPHERE_ANSIBLE_AUTOSTART_MARKER . ' written={{');

        self::assertNotFalse($write, 'the per-VM write task is missing');
        self::assertNotFalse($always, 'the count must be reported from an always part');
        self::assertNotFalse($marker, 'the playbook must print ' . VIRTUSPHERE_ANSIBLE_AUTOSTART_MARKER . ' written=N total=M');
        self::assertLessThan($always, $write);
        self::assertLessThan($marker, $always);
        self::assertStringContainsString('register: autostart_power_results', $playbook);
        self::assertMatchesRegularExpression('/\}\} total=\{\{/', $playbook);
    }

    /** @param list<string> $lines */
    private function trace(array $lines): DeployWorkerStepTrace
    {
        $trace = new DeployWorkerStepTrace();
        foreach ($lines as $line) {
            $trace->observe($line);
        }

        return $trace;
    }

    private function channel(): DeployWorkerDbChannel
    {
        $channel = new DeployWorkerDbChannel(new mysqli(), static fn (): mysqli => new mysqli(), 1, 'fixture');
        $channel->withSecrets([self::SECRET]);

        return $channel;
    }
}
