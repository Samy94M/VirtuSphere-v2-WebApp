<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/deploy_supervisor_constants.php';
require_once __DIR__ . '/deploy_supervisor_policy.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/repo/deploy_supervisor_state.php';

/**
 * Best-effort portal publication with a connection lifecycle of its own.
 * Nothing here is an input to child liveness or restart decisions.
 */
final class DeploySupervisorPublisher
{
    /** @var Closure():mysqli */
    private readonly Closure $connector;

    /** @var Closure(mixed,array<string,mixed>,?int,?int):void */
    private readonly Closure $writer;

    private mixed $connection = null;
    private int $publishedAt = 0;
    private int $retryAt = 0;
    private bool $announced = false;

    public function __construct(?callable $connector = null, ?callable $writer = null)
    {
        $this->connector = Closure::fromCallable($connector ?? static fn (): mysqli => db(true));
        $this->writer = Closure::fromCallable(
            $writer ?? static function (mysqli $db, array $state, ?int $supervisorPid, ?int $childPid): void {
                repo_deploy_supervisor_publish($db, $state, $supervisorPid, $childPid);
            }
        );
    }

    /** @param array<string,mixed> $state */
    public function publish(
        array $state,
        ?int $supervisorPid,
        ?int $childPid,
        int $now,
        string $action
    ): void {
        if ($now < $this->retryAt) {
            return;
        }
        $due = ($now - $this->publishedAt) >= VIRTUSPHERE_SUPERVISOR_PUBLISH_INTERVAL_SECONDS;
        if (!$due && $action === VIRTUSPHERE_SUPERVISOR_ACTION_OBSERVE) {
            return;
        }

        try {
            if ($this->connection === null) {
                // db(true), not db(): a query failure invalidates the static
                // mysqli handle and the next allowed attempt must replace it.
                $this->connection = ($this->connector)();
            }
            ($this->writer)($this->connection, $state, $supervisorPid, $childPid);
            $this->publishedAt = $now;
            $this->retryAt = 0;
            $this->announced = false;
        } catch (Throwable $exception) {
            $this->connection = null;
            $this->retryAt = $now + VIRTUSPHERE_SUPERVISOR_PUBLISH_INTERVAL_SECONDS;
            if (!$this->announced) {
                $this->announced = true;
                fwrite(STDERR, '[deploy-supervisor] state not publishable, still supervising: '
                    . virtusphere_redact_log_text($exception->getMessage()) . "\n");
            }
        }
    }
}
