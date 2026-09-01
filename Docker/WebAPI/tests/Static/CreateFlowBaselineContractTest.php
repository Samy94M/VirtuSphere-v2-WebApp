<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/defaults.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';
require_once dirname(__DIR__, 2) . '/lib/ansible_command.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_job_input.php';

/**
 * Charakterisierung des Create-Pfads VOR Etappe 14B (Teiletappe A des Plans
 * docs/audits/2026-08-13-create-flow-reliability-implementation-plan.md).
 *
 * Dieser Test aendert nichts. Er haelt fest, wie der Create-Ablauf HEUTE
 * aussieht, damit die folgenden Teiletappen ihre Aenderung beweisen statt
 * behaupten muessen. Jede Zusicherung nennt die Teiletappe, die sie
 * ausdruecklich umschreibt; wer sie ohne diese Etappe rot sieht, hat den
 * Create-Pfad versehentlich verschoben.
 *
 * Der Anlass steht im Plan, Abschnitt 3.1: Ein Auftrag ueber fuenfzehn VMs
 * endete nach 1800 Sekunden ohne Ausgabe mit einem Idle-Timeout, waehrend
 * vierzehn VMs auf ESXi entstanden. Der Worker konnte danach nicht sagen,
 * welche - weil es bis heute kein dauerhaftes Ergebnis je VM gibt.
 */
final class CreateFlowBaselineContractTest extends TestCase
{
    private function repoSource(string $relative): string
    {
        $path = dirname(__DIR__, 4) . '/' . $relative;
        self::assertFileExists($path, $relative . ' fehlt unter dem Pruef-Root');

        return (string) file_get_contents($path);
    }

    /**
     * Von Teiletappe D zur Haelfte umgeschrieben. Die Ausgabe laeuft jetzt
     * ungebuffert, was den eigentlichen Vorfall behebt; die per-VM-Grenze fehlt
     * weiterhin, weil der Worker die neue Folge erst in Teiletappe E treibt.
     */
    public function testTheCreateSequenceIsUnbufferedButStillOneCallForTheWholeSelection(): void
    {
        $steps = ansible_remote_steps('/tmp/vs-job-1', ['mode' => 'create']);

        self::assertCount(1, $steps, 'Create ist heute genau ein Remote-Schritt');
        self::assertSame(VIRTUSPHERE_PLAYBOOKS['create'], $steps[0]['playbook']);

        $command = $steps[0]['command'];
        self::assertStringContainsString('ansible-playbook', $command);
        // Der Kern des Vorfalls: Python puffert stdout, sobald er kein Terminal
        // ist, und der Worker liest ueber eine SSH-Pipe. Das Gate
        // ansible-output-buffering misst denselben Sachverhalt am echten
        // Prozess, statt ihn nur im Commandstring zu behaupten.
        self::assertStringContainsString('export PYTHONUNBUFFERED=1', $command);
        // Ein Aufruf, keine per-VM-Grenze: es gibt bis Teiletappe E keine
        // Stelle, an der der Worker zwischen zwei VMs etwas Dauerhaftes
        // schreiben koennte.
        self::assertSame(
            1,
            substr_count($command, 'ansible-playbook'),
            'Baseline: eine Auswahl ist bis Teiletappe E ein einziger Playbookaufruf'
        );
    }

    /**
     * Etappe D ersetzt die Schleife durch genau eine Ziel-VM je Aufruf plus
     * Async-Start, Statusabfrage und Cleanup.
     */
    public function testTheCreatePlaybookMutatesTheWholeSelectionInOneLoopedTask(): void
    {
        $playbook = $this->repoSource('Ansible/' . VIRTUSPHERE_PLAYBOOKS['create']);

        self::assertSame(
            1,
            substr_count($playbook, 'community.vmware.vmware_guest:'),
            'Baseline: genau ein mutierender vmware_guest-Task'
        );
        self::assertStringContainsString(
            'loop: "{{ vm_configurations }}"',
            $playbook,
            'Baseline: dieser Task laeuft ueber die gesamte Auswahl'
        );
        // Ohne async/poll haelt der Controller die Verbindung ueber die ganze
        // Arbeit offen, und ein Transportabbruch nimmt das Ergebnis mit.
        self::assertStringNotContainsString(
            'async:',
            $playbook,
            'Baseline: der Create-Task laeuft heute synchron (Etappe D fuehrt async ein)'
        );
    }

    /**
     * Von Teiletappe C umgeschrieben. Die Frage des Vorfalls, welche der
     * fuenfzehn VMs fertig waren, hat seitdem eine dauerhafte Antwort; was noch
     * fehlt, ist der Worker, der sie schreibt (Teiletappe E).
     */
    public function testThePerVmCreateResultExistsButNoWorkerWritesItYet(): void
    {
        $schema = $this->repoSource('Docker/mysql/mysql-init/struktur.sql');

        self::assertStringContainsString('deploy_create_vm_results', $schema);
        self::assertStringContainsString('create_started_at', $schema);
        // `result_json` bleibt der MAC-Import-/Pipelinevertrag und wird NICHT
        // mit Create-Zwischenstaenden ueberladen.
        self::assertStringContainsString('result_json', $schema);

        // Kein Workermodul liest oder schreibt die Zeilen. Solange das gilt, ist
        // der Create-Pfad unveraendert und diese Etappe additiv; Teiletappe E
        // ersetzt diese Zusicherung durch die Orchestrierung.
        foreach (['deploy_worker_mission.php', 'deploy_worker_finish.php', 'deploy_worker_reaper.php'] as $module) {
            self::assertStringNotContainsString(
                'deploy_create_',
                $this->repoSource('Docker/WebAPI/lib/' . $module),
                $module . ' benutzt die Create-Ergebniszeilen bereits'
            );
        }
    }

    /**
     * Etappe F macht daraus die modusabhaengige Retry-Matrix: bestaetigte
     * Erfolge werden uebersprungen, laufende oder unklare Einheiten sperren den
     * Retry.
     */
    public function testARetriedCreateRepeatsTheEntireOriginalSelection(): void
    {
        $originalVmIds = [11, 12, 13];

        // Der einzige Weg, auf dem ein Retry den Umfang heute veraendert, ist
        // der MAC-/Export-Nachlauf eines partiellen oder abweichend
        // gescheiterten Auftrags. Ein gescheiterter Create faellt nicht darunter
        // und wiederholt deshalb jede VM, auch die bereits erstellten.
        self::assertNull(
            deploy_job_retry_plan(VIRTUSPHERE_DEPLOY_STATUS_FAILED, null, $originalVmIds),
            'Baseline: ein gescheiterter Create-Auftrag wird unveraendert wiederholt'
        );

        $partial = deploy_job_retry_plan(
            VIRTUSPHERE_DEPLOY_STATUS_PARTIAL,
            ['outcome' => 'partial', 'successful_vm_ids' => [11, 13], 'failed_vm_ids' => [12]],
            $originalVmIds
        );
        self::assertNotNull($partial);
        self::assertSame('export', $partial['mode'], 'Baseline: der einzige verkleinernde Plan ist der Export-Nachlauf');
    }

    /**
     * Etappe E muss diesen Vertrag erhalten, nicht ersetzen: Entscheidung F3 des
     * Plans sagt, dass "Create only" den fachlichen Lifecycle nicht veraendert.
     * Heute wird das durch Markieren und Zuruecksetzen erreicht, nicht durch
     * Nichtstun, und genau diese Klammer muss die neue Orchestrierung behalten.
     */
    public function testCreateOnlyLeavesNoNetLifecycleChange(): void
    {
        $finish = $this->repoSource('Docker/WebAPI/lib/deploy_worker_finish.php');
        $mission = $this->repoSource('Docker/WebAPI/lib/deploy_worker_mission.php');

        self::assertStringContainsString('deploy_worker_mark_vms_deploying(', $mission);
        self::assertStringContainsString('deploy_worker_restore_deploying_vms(', $finish);
    }

    /**
     * Soll/Ist zu Plan-Abschnitt 12.4: Der vollstaendige Logtail wurde bereits
     * in Etappe 10A geliefert. Etappe G baut deshalb nur noch die
     * Fortschrittskarte, und diese Zusicherung haelt fest, dass sie den
     * vorhandenen Cursorvertrag nicht wieder aufmacht.
     */
    public function testTheFullLogTailContractIsAlreadyInPlace(): void
    {
        $queries = $this->repoSource('Docker/WebAPI/lib/repo/deploy_job_queries.php');
        $client = $this->repoSource('Docker/WebAPI/portal/assets/deploy_log.js');

        self::assertStringContainsString('caught_up', $queries);
        self::assertStringContainsString('has_more', $queries);
        // Ein terminaler Auftrag hoert erst auf zu pollen, wenn er den Tail
        // wirklich gelesen hat; das war Plan-Abschnitt 12.4 Punkt 2.
        self::assertStringContainsString('payload.caught_up', $client);
    }
}
