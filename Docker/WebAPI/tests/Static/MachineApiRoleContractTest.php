<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/machine_api_roles.php';

/**
 * K20 (VT-01): every IP gate of the machine API names the caller role it
 * expects. The gates are derived from the endpoint files, not listed by hand:
 * a new endpoint that calls the IP check without a gate, or with a key the role
 * map does not know, fails here. The expected table is the contract of who
 * calls what (MECM server scripts, Ansible playbooks, deploy clients).
 */
final class MachineApiRoleContractTest extends TestCase
{
    private const EXPECTED = [
        'db_importMAC.php' => ['mac_import'],
        'deploy_vm_touch.php' => ['vm_touch'],
        'mecm-api.php' => ['device_infos', 'device_list'],
        'mecm_client_ack.php' => ['client_ack'],
        'mecm_packages.php' => ['package_catalog'],
        'mecm_report.php' => ['client_phase', 'package_report', 'server_report'],
        'mecm_updateid.php' => ['device_update'],
    ];

    public function testEveryIpGateNamesAKnownGateAndEveryEndpointHasItsRoles(): void
    {
        $found = self::gatedEndpoints();

        self::assertSame(self::EXPECTED, $found, 'derived from every IP gate in the machine endpoint files');
        $used = array_unique(array_merge(...array_values($found)));
        sort($used);
        $known = array_keys(VIRTUSPHERE_MACHINE_API_GATE_ROLES);
        sort($known);
        self::assertSame($known, $used, 'every gate of the role map is used, and nothing outside it');
    }

    public function testEveryEndpointWithAnIpGateIsListedInTheEndpointTable(): void
    {
        // An endpoint missing from the table is audited under the unknown
        // sentinel, so its refusals lose the endpoint name in the security trail.
        self::assertSame(
            [],
            array_values(array_diff(array_keys(self::gatedEndpoints()), VIRTUSPHERE_MACHINE_API_ENDPOINTS)),
            'every endpoint file with an IP gate is listed in VIRTUSPHERE_MACHINE_API_ENDPOINTS'
        );
    }

    public function testTheFreshSchemaSeedsNoAllowlistEntryWithoutARole(): void
    {
        // A role-less entry opens every machine endpoint. A fresh install must
        // not start with one, because nothing in the install names its role.
        $path = dirname(__DIR__, 4) . '/Docker/mysql/mysql-init/struktur.sql';
        self::assertFileExists($path, 'struktur.sql is the fresh-schema source and must be visible to the unit lane');
        $schema = (string) file_get_contents($path);

        preg_match_all('/INSERT\s+INTO\s+deploy_accessToWebAPI\s*\(([^)]*)\)/i', $schema, $inserts);
        $withoutRole = array_values(array_filter(
            $inserts[1],
            static fn (string $columns): bool => preg_match('/\brole\b/i', $columns) !== 1
        ));
        self::assertSame([], $withoutRole, 'the fresh schema seeds an allowlist entry without a role');
    }

    public function testTheRolesOfEachGateMatchItsCaller(): void
    {
        $mecm = VIRTUSPHERE_MACHINE_API_ROLE_MECM_SERVER;
        $ansible = VIRTUSPHERE_MACHINE_API_ROLE_ANSIBLE_HOST;
        $client = VIRTUSPHERE_MACHINE_API_ROLE_CLIENT;
        self::assertSame([
            'mac_import' => [$ansible],
            'vm_touch' => [$ansible],
            'device_list' => [$mecm],
            'device_update' => [$mecm],
            'package_catalog' => [$mecm],
            'server_report' => [$mecm],
            'device_infos' => [$client],
            'client_phase' => [$client],
            'client_ack' => [$client],
            'package_report' => [$client],
        ], VIRTUSPHERE_MACHINE_API_GATE_ROLES);
    }

    public function testTheDetectorSeesAGateWithoutKeyAndAnUnknownKey(): void
    {
        self::assertSame(['<missing>'], self::gatesIn('if (!machine_api_ip_allowed($connection, $clientIp)) {}'));
        self::assertSame(['typo_gate'], self::gatesIn("machine_api_ip_allowed(\$connection, \$clientIp, 'typo_gate');"));
        self::assertSame(['a', 'b'], self::gatesIn("machine_api_ip_allowed(\$db, \$ip, \$x === 'y' ? 'b' : 'a');"));
        self::assertSame([], self::gatesIn('// machine_api_ip_decision only'));
    }

    /**
     * Endpoint file name => its gate keys, for every top-level endpoint file
     * that calls machine_api_ip_allowed().
     *
     * @return array<string, list<string>>
     */
    private static function gatedEndpoints(): array
    {
        $found = [];
        foreach (glob(dirname(__DIR__, 2) . '/*.php') ?: [] as $file) {
            $gates = self::gatesIn((string) file_get_contents($file));
            if ($gates !== []) {
                $found[basename($file)] = $gates;
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * Gate keys of every machine_api_ip_allowed() call: the quoted literals
     * of its third argument (a literal, a ternary or a match), or `<missing>`.
     * Comparison literals of a ternary condition (`$action === 'x'`) are not
     * gates and are dropped.
     *
     * @return list<string>
     */
    private static function gatesIn(string $source): array
    {
        $gates = [];
        $offset = 0;
        while (($at = strpos($source, 'machine_api_ip_allowed(', $offset)) !== false) {
            $depth = 0;
            $end = $at + strlen('machine_api_ip_allowed');
            for ($i = $end; $i < strlen($source); $i++) {
                $depth += $source[$i] === '(' ? 1 : ($source[$i] === ')' ? -1 : 0);
                if ($depth === 0) {
                    break;
                }
            }
            $arguments = substr($source, $end + 1, $i - $end - 1);
            $offset = $i;
            $parts = preg_split('/,(?![^(]*\))/', $arguments, 3) ?: [];
            if (count($parts) < 3) {
                $gates[] = '<missing>';
                continue;
            }
            $third = (string) preg_replace("/===\\s*'[^']*'/", '', $parts[2]);
            $third = (string) preg_replace("/'[^']*'\\s*=>/", '', $third);
            preg_match_all("/'([a-z_]+)'/", $third, $match);
            foreach ($match[1] as $gate) {
                $gates[] = $gate;
            }
        }
        $gates = array_values(array_unique($gates));
        sort($gates);

        return $gates;
    }
}
