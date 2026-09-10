<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_supervisor_publish.php';

final class DeploySupervisorPublisherTest extends TestCase
{
    public function testADeadConnectionIsReplacedAfterTheThrottleWithoutChangingTheChildPid(): void
    {
        $connections = 0;
        $writes = 0;
        $childPids = [];
        $writtenGenerations = [];
        $publisher = new DeploySupervisorPublisher(
            static function () use (&$connections): object {
                $connections++;

                return (object) ['generation' => $connections];
            },
            static function (object $db, array $state, ?int $supervisorPid, ?int $childPid) use (&$writes, &$childPids, &$writtenGenerations): void {
                $writes++;
                $childPids[] = $childPid;
                $writtenGenerations[] = $db->generation;
                if ($writes === 1) {
                    throw new RuntimeException('synthetic lost mysqli');
                }
            }
        );
        $state = deploy_supervisor_initial_state();
        $state['phase'] = VIRTUSPHERE_SUPERVISOR_PHASE_RUNNING;

        $publisher->publish($state, 11, 77, 1_000, VIRTUSPHERE_SUPERVISOR_ACTION_START);
        $publisher->publish($state, 11, 77, 1_001, VIRTUSPHERE_SUPERVISOR_ACTION_OBSERVE);
        self::assertSame(1, $connections, 'the reconnect must be throttled');

        $publisher->publish(
            $state,
            11,
            77,
            1_000 + VIRTUSPHERE_SUPERVISOR_PUBLISH_INTERVAL_SECONDS,
            VIRTUSPHERE_SUPERVISOR_ACTION_OBSERVE
        );
        self::assertSame(2, $connections, 'the failed connection must be discarded');
        self::assertSame(2, $writes);
        self::assertSame([1, 2], $writtenGenerations, 'the writer must receive the replacement connection');
        self::assertSame([77, 77], $childPids, 'publication recovery must observe the same child');
    }

    public function testQuietSuccessfulObservationsStayThrottled(): void
    {
        $writes = 0;
        $publisher = new DeploySupervisorPublisher(
            static fn (): object => new stdClass(),
            static function () use (&$writes): void {
                $writes++;
            }
        );
        $state = deploy_supervisor_initial_state();

        $publisher->publish($state, 1, 2, 1_000, VIRTUSPHERE_SUPERVISOR_ACTION_START);
        $publisher->publish($state, 1, 2, 1_001, VIRTUSPHERE_SUPERVISOR_ACTION_OBSERVE);
        self::assertSame(1, $writes);
    }
}
