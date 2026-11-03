<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/vm_phase.php';
require_once dirname(__DIR__, 2) . '/lib/vm_inventory_observation.php';

/**
 * One reading of the cached ESXi inventory for a bound VM (plan ST-2, section 1):
 * unbound, none, unanswered, stale, missing or present. The order of the checks
 * is the rule: an unanswered pull is not stale, and an unbound VM is never looked
 * up at all. The twin test pins the same reading against the findings of
 * lib/vm_phase.php, so the two can never disagree about an absence or a power state.
 */
final class VmInventoryObservationTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const UUID = '42422d12-0000-4000-8000-000000000001';
    private const FRESH_AT = '2026-10-20 10:00:00';

    /** @return iterable<string, array{0:array<string,mixed>, 1:?array<string,mixed>, 2:string, 3:?string, 4:string}> */
    public static function observations(): iterable
    {
        $bound = ['vm_instance_uuid' => self::UUID];

        yield 'unbound without an inventory' => [['vm_instance_uuid' => null], null, VIRTUSPHERE_VM_INVENTORY_UNBOUND, null, ''];
        yield 'unbound with a fresh and answered inventory' => [
            ['vm_instance_uuid' => null],
            self::inventory(['vms' => [self::UUID => 'poweredOn']]),
            VIRTUSPHERE_VM_INVENTORY_UNBOUND,
            null,
            '',
        ];
        yield 'an empty UUID is unbound' => [['vm_instance_uuid' => ''], self::inventory(), VIRTUSPHERE_VM_INVENTORY_UNBOUND, null, ''];
        yield 'bound without any inventory' => [$bound, null, VIRTUSPHERE_VM_INVENTORY_NONE, null, ''];
        yield 'unanswered although fresh' => [$bound, self::inventory(['answered' => false]), VIRTUSPHERE_VM_INVENTORY_UNANSWERED, null, self::FRESH_AT];
        yield 'unanswered and stale is unanswered first' => [
            $bound,
            self::inventory(['answered' => false, 'fresh' => false]),
            VIRTUSPHERE_VM_INVENTORY_UNANSWERED,
            null,
            self::FRESH_AT,
        ];
        yield 'answered but stale' => [$bound, self::inventory(['fresh' => false]), VIRTUSPHERE_VM_INVENTORY_STALE, null, self::FRESH_AT];
        yield 'fresh and answered without the UUID is missing' => [
            $bound,
            self::inventory(['vms' => ['other-uuid' => 'poweredOn']]),
            VIRTUSPHERE_VM_INVENTORY_MISSING,
            null,
            self::FRESH_AT,
        ];
        yield 'fresh and answered with an empty listing is missing' => [$bound, self::inventory(['vms' => []]), VIRTUSPHERE_VM_INVENTORY_MISSING, null, self::FRESH_AT];
        yield 'present and powered on' => [
            $bound,
            self::inventory(['vms' => [self::UUID => 'poweredOn']]),
            VIRTUSPHERE_VM_INVENTORY_PRESENT,
            'poweredOn',
            self::FRESH_AT,
        ];
        yield 'present and powered off' => [
            $bound,
            self::inventory(['vms' => [self::UUID => 'poweredOff']]),
            VIRTUSPHERE_VM_INVENTORY_PRESENT,
            'poweredOff',
            self::FRESH_AT,
        ];
        yield 'present without a reported power state' => [
            $bound,
            self::inventory(['vms' => [self::UUID => null]]),
            VIRTUSPHERE_VM_INVENTORY_PRESENT,
            null,
            self::FRESH_AT,
        ];
    }

    /**
     * Every combination of the four inputs that decide a finding for an installing,
     * registered VM: freshness, answer, whether the UUID is listed and its power.
     *
     * @return iterable<string, array{0:bool,1:bool,2:bool,3:bool}>
     */
    public static function inventoryCombinations(): iterable
    {
        foreach ([true, false] as $fresh) {
            foreach ([true, false] as $answered) {
                foreach ([true, false] as $listed) {
                    foreach ([true, false] as $poweredOff) {
                        $name = sprintf(
                            'fresh=%s answered=%s listed=%s poweredOff=%s',
                            $fresh ? 'yes' : 'no',
                            $answered ? 'yes' : 'no',
                            $listed ? 'yes' : 'no',
                            $poweredOff ? 'yes' : 'no'
                        );
                        yield $name => [$fresh, $answered, $listed, $poweredOff];
                    }
                }
            }
        }
    }

    public function testTheClosedVocabularyIsTheSixStatesInTheSpecifiedOrder(): void
    {
        self::assertSame(
            [
                VIRTUSPHERE_VM_INVENTORY_UNBOUND,
                VIRTUSPHERE_VM_INVENTORY_NONE,
                VIRTUSPHERE_VM_INVENTORY_UNANSWERED,
                VIRTUSPHERE_VM_INVENTORY_STALE,
                VIRTUSPHERE_VM_INVENTORY_MISSING,
                VIRTUSPHERE_VM_INVENTORY_PRESENT,
            ],
            VIRTUSPHERE_VM_INVENTORY_STATES
        );
    }

    /**
     * @param array<string,mixed> $vm
     * @param ?array<string,mixed> $inventory
     */
    #[PHPUnit\Framework\Attributes\DataProvider('observations')]
    public function testEachReadingNamesItsStateTheSourceAndTheTime(
        array $vm,
        ?array $inventory,
        string $state,
        ?string $power,
        string $time
    ): void {
        $observation = virtusphere_vm_inventory_observation($vm, $inventory);

        self::assertSame($state, $observation['state']);
        self::assertSame($power, $observation['power'], 'the power state is only read when the VM is present');
        self::assertSame($time, $observation['time']);
    }

    /**
     * The twin: for an installing, registered VM the observation and the findings
     * of lib/vm_phase.php agree on a missing VM and on a powered-off VM. Both read
     * the same evidence, so neither may claim what the other does not see.
     */
    #[PHPUnit\Framework\Attributes\DataProvider('inventoryCombinations')]
    public function testTheObservationAndTheFindingsAgreeOnEveryCombination(bool $fresh, bool $answered, bool $listed, bool $poweredOff): void
    {
        $vm = [
            'id' => 1,
            'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLING,
            'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_REGISTERED,
            'vm_instance_uuid' => self::UUID,
            'vm_bound_esxi_host' => 'esxi01',
            'vm_bound_credential_id' => 5,
            'mecm_previous_id' => null,
            'mecm_pending_since' => null,
            'os_install_watch_started_at' => null,
            'updated_at' => null,
        ];
        $inventory = [
            'fresh' => $fresh,
            'answered' => $answered,
            'fresh_at' => gmdate('Y-m-d H:i:s', self::NOW - 60),
            'vms' => $listed
                ? [self::UUID => $poweredOff ? 'poweredOff' : 'poweredOn']
                : ['other-uuid' => 'poweredOff'],
        ];
        $evidence = [
            'is_template' => false,
            'last_job' => null,
            'create_uncertain' => [],
            'client_phases' => array_fill_keys(VIRTUSPHERE_CLIENT_PHASES, null),
            'last_package_run' => null,
            'inventory' => $inventory,
        ];

        $observation = virtusphere_vm_inventory_observation($vm, $inventory);
        $kinds = array_column(virtusphere_vm_findings($vm, $evidence, self::NOW), 'kind');

        self::assertSame(
            $observation['state'] === VIRTUSPHERE_VM_INVENTORY_MISSING,
            in_array(VIRTUSPHERE_VM_FINDING_MISSING_FROM_INVENTORY, $kinds, true),
            'the missing finding follows the missing state exactly'
        );
        self::assertSame(
            $observation['state'] === VIRTUSPHERE_VM_INVENTORY_PRESENT && $observation['power'] === VIRTUSPHERE_VM_INVENTORY_POWERED_OFF,
            in_array(VIRTUSPHERE_VM_FINDING_POWERED_OFF, $kinds, true),
            'the powered-off finding follows a present VM that is powered off, and nothing else'
        );
    }

    /** @param array<string,mixed> $overrides */
    private static function inventory(array $overrides = []): array
    {
        return $overrides + [
            'fresh' => true,
            'answered' => true,
            'fresh_at' => self::FRESH_AT,
            'vms' => [],
        ];
    }
}
