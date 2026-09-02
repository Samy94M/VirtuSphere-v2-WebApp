<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_supervisor_policy.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_supervisor_contract.php';

/**
 * The shape of the deploy supervisor (Etappe 14C).
 *
 * These are the assertions that source review keeps getting wrong because
 * everything reads correctly: the container still has to start the worker, the
 * supervisor still has to be able to receive a signal, and the supervisor still
 * has to know nothing about jobs.
 */
final class DeploySupervisorContractTest extends TestCase
{
    private function repoSource(string $relative): string
    {
        $path = dirname(__DIR__, 4) . '/' . $relative;
        self::assertFileExists($path, $relative . ' fehlt unter dem Pruef-Root');

        return (string) file_get_contents($path);
    }

    /**
     * The default process shape does NOT change with this etappe.
     *
     * The snapshot fails closed when the observed shape contradicts the stored
     * contract, so a compose file that started the supervisor while the row
     * still said `worker_v1` would put the deploy service into a permanent
     * degraded state that is not a fault. The switch is a maintenance window,
     * and the window is what changes both.
     */
    public function testComposeStillStartsTheWorkerDirectly(): void
    {
        $compose = $this->repoSource('docker-compose.yml');

        self::assertStringContainsString('lib/deploy_worker.php', $compose);
        self::assertStringNotContainsString(
            'lib/deploy_supervisor.php',
            $compose,
            'the supervised shape belongs in docker-compose.supervisor.yml, not in the default file'
        );
        self::assertStringContainsString('lib/worker_healthcheck.php', $compose);
    }

    /**
     * The override moves the command AND the healthcheck. Half of it is worse
     * than none: a supervisor judged by the worker's liveness file reports
     * unhealthy during every legitimate restart cooldown, because during a
     * cooldown the child is correctly gone.
     */
    public function testTheSupervisorOverrideMovesBothCommandAndHealthcheck(): void
    {
        $override = $this->repoSource('docker-compose.supervisor.yml');

        self::assertStringContainsString('lib/deploy_supervisor.php', $override);
        self::assertStringContainsString('lib/supervisor_healthcheck.php', $override);
        self::assertStringNotContainsString(
            'lib/worker_healthcheck.php',
            $override,
            'the supervised shape must not be judged by the child process liveness file'
        );
    }

    /**
     * Both new entrypoints carry the CLI guard. Without it they are reachable
     * through nginx AND invisible to the CLI require-closure walker, which is
     * the guard that proves a CLI file loads everything it calls.
     */
    public function testEveryNewEntrypointRefusesTheWebSapi(): void
    {
        foreach ([
            'Docker/WebAPI/lib/deploy_supervisor.php',
            'Docker/WebAPI/lib/deploy_supervisor_switch.php',
            'Docker/WebAPI/lib/supervisor_healthcheck.php',
        ] as $relative) {
            self::assertStringContainsString(
                "PHP_SAPI !== 'cli'",
                $this->repoSource($relative),
                $relative . ' is reachable from the web SAPI'
            );
        }
    }

    /**
     * The healthcheck judges supervisor liveness and nothing else.
     *
     * A healthcheck that touched the database would go red during a database
     * outage that the supervisor is correctly surviving, and Docker would then
     * report the one process that is behaving as broken.
     */
    public function testTheHealthcheckNeverTouchesTheDatabase(): void
    {
        $source = $this->repoSource('Docker/WebAPI/lib/supervisor_healthcheck.php');

        self::assertStringNotContainsString('db.php', $source);
        self::assertStringNotContainsString('mysqli', $source);
        self::assertStringNotContainsString('repo_', $source);
    }

    /**
     * The supervisor does not run jobs. Not tidiness: a supervisor that could
     * claim, finish or reap a job would be a second executor, and the whole
     * point of this process is that there is exactly one.
     */
    public function testTheSupervisorNeverTouchesAJob(): void
    {
        $forbidden = [
            'repo_claim_next_deploy_job',
            'repo_finish_deploy_job',
            'deploy_worker_reap_stale_jobs',
            'repo_touch_deploy_job_heartbeat',
        ];
        foreach ([
            'Docker/WebAPI/lib/deploy_supervisor.php',
            'Docker/WebAPI/lib/deploy_supervisor_loop.php',
            'Docker/WebAPI/lib/deploy_supervisor_policy.php',
            'Docker/WebAPI/lib/deploy_supervisor_process.php',
        ] as $relative) {
            $source = $this->repoSource($relative);
            foreach ($forbidden as $call) {
                self::assertStringNotContainsString($call, $source, $relative . ' reaches into job execution');
            }
        }
    }

    /**
     * The restart decision reads the child's liveness FILE and never the
     * database.
     *
     * This is the single most consequential line of the etappe. A worker
     * sitting out a database outage is healthy by explicit decision (Etappe 2,
     * which is why the worker has a db channel at all). A supervisor that asked
     * the database would answer a database outage by killing the one process
     * that is correctly surviving it, in the middle of a playbook that is
     * changing ESXi.
     */
    public function testTheDecisionInputsContainNoDatabaseRead(): void
    {
        $loop = $this->repoSource('Docker/WebAPI/lib/deploy_supervisor_loop.php');
        $decide = substr($loop, (int) strpos($loop, 'deploy_supervisor_decide('), 600);

        self::assertStringContainsString('supervisor_child_heartbeat_age', $decide);
        self::assertStringNotContainsString('db(', $decide, 'the tick decision must not read the database');

        $policy = $this->repoSource('Docker/WebAPI/lib/deploy_supervisor_policy.php');
        self::assertStringNotContainsString('mysqli', $policy, 'the policy is pure');
    }

    /**
     * `start` appears in exactly one branch of the loop, and the fact it reads
     * comes from waitpid. A remembered "we signalled it" flag is what produces
     * two children.
     */
    public function testTheChildIsStartedOnlyOnAConfirmedExit(): void
    {
        $process = $this->repoSource('Docker/WebAPI/lib/deploy_supervisor_process.php');

        self::assertStringContainsString('proc_get_status', $process, 'liveness has to be established, not remembered');
        self::assertStringContainsString(
            'already open',
            $process,
            'a second start while a handle is open must be refused independently of the policy'
        );

        $loop = $this->repoSource('Docker/WebAPI/lib/deploy_supervisor_loop.php');
        self::assertSame(
            1,
            substr_count($loop, '$process->start('),
            'exactly one call site may start a child'
        );
    }

    /** PID 1 has to be able to receive a stop signal, which needs pcntl in the image. */
    public function testTheImageCarriesTheSignalExtension(): void
    {
        self::assertStringContainsString(
            'pcntl',
            $this->repoSource('Docker/php/Dockerfile'),
            'without pcntl_signal, PID 1 ignores the stop signal and docker stop ends in a SIGKILL mid-playbook'
        );
    }

    /**
     * SIGQUIT is in the handled set, and this assertion is the whole reason the
     * feature works.
     *
     * Measured, not reasoned: `docker stop` on this container took the full
     * grace period and ended in exit 137 while `docker kill -s TERM` exited
     * cleanly in the same build. The `php:*-fpm` base image declares
     * `STOPSIGNAL SIGQUIT` for php-fpm's graceful shutdown and this container
     * inherits it, so SIGQUIT is the signal that actually arrives. Handling only
     * TERM and INT reads correctly and changes nothing, which is exactly why a
     * source review would pass it.
     */
    public function testTheStopSignalSetContainsTheOneThisImageActuallySends(): void
    {
        $source = $this->repoSource('Docker/WebAPI/lib/worker_stop_signal.php');

        foreach (['SIGTERM', 'SIGINT', 'SIGQUIT'] as $signal) {
            self::assertStringContainsString($signal, $source, $signal . ' is not handled');
        }
        self::assertStringContainsString('STOPSIGNAL SIGQUIT', $source, 'the reason has to stay next to the list');
    }

    /**
     * All three long-running CLI processes install it. A supervisor that stops
     * cleanly while the worker it supervises does not would be half a fix, and
     * the half that is missing is the one holding a playbook.
     */
    public function testEveryLoopProcessInstallsTheStopHandler(): void
    {
        foreach ([
            'Docker/WebAPI/lib/deploy_supervisor_loop.php',
            'Docker/WebAPI/lib/deploy_worker_loop.php',
            'Docker/WebAPI/lib/maintenance_worker.php',
        ] as $relative) {
            $source = $this->repoSource($relative);
            self::assertStringContainsString('worker_install_stop_handler(', $source, $relative . ' cannot be stopped');
            self::assertStringContainsString('worker_stop_signal.php', $source, $relative . ' does not load the shared module');
            self::assertStringNotContainsString(
                'pcntl_signal(SIG',
                $source,
                $relative . ' keeps its own signal list; one list means one place to forget SIGQUIT'
            );
        }
    }

    /**
     * The idle wait is interruptible everywhere.
     *
     * A plain `sleep()` after the signal arrived would spend the stop grace that
     * `docker stop` allows, and the deploy worker's grace is what stands between
     * a clean exit and a SIGKILL on top of a running playbook.
     */
    public function testNoLoopSitsInAnUninterruptibleIdleSleep(): void
    {
        foreach ([
            'Docker/WebAPI/lib/deploy_worker_loop.php',
            'Docker/WebAPI/lib/maintenance_worker.php',
            'Docker/WebAPI/lib/deploy_supervisor_loop.php',
        ] as $relative) {
            self::assertStringContainsString(
                'worker_idle_wait(',
                $this->repoSource($relative),
                $relative . ' waits without a way to notice a stop'
            );
        }
    }

    /**
     * Every declared switch blocker is produced by the checker, and every code
     * the checker produces is declared. A blocker that exists only in the
     * constant is a promise nothing keeps; one that exists only in the code is
     * a refusal nobody documented.
     */
    public function testTheSwitchBlockerVocabularyIsClosedInBothDirections(): void
    {
        $source = $this->repoSource('Docker/WebAPI/lib/repo/deploy_supervisor_contract.php');
        preg_match_all("/'(switch_[a-z_]+)'/", $source, $matches);
        $used = array_values(array_unique(array_filter(
            $matches[1],
            static fn (string $code): bool => !str_contains($code, 'switch_blockers')
        )));

        sort($used);
        $declared = VIRTUSPHERE_SUPERVISOR_SWITCH_BLOCKERS;
        sort($declared);

        self::assertSame($declared, $used);
    }

    /** The phase vocabulary and its database mirror stay order-exact (ADR-0016). */
    public function testTheStoredPhaseVocabularyMirrorsTheConstant(): void
    {
        foreach ([
            'Docker/mysql/mysql-init/struktur.sql',
            'Docker/WebAPI/lib/migrations/0049_supervisor_runtime_state.php',
        ] as $relative) {
            $source = $this->repoSource($relative);
            foreach (VIRTUSPHERE_SUPERVISOR_PHASES as $phase) {
                self::assertStringContainsString("'" . $phase . "'", $source, $relative . ' is missing phase ' . $phase);
            }
        }
    }
}
