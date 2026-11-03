<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/OperationsFlowDoc.php';
require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/machine_api_roles.php';
require_once dirname(__DIR__, 2) . '/lib/mecm_rollout_fence.php';

/**
 * docs/operations/machine-api-flows.md has one table row per endpoint and action
 * and draws the rollout fence and the report channel. Nothing is listed here:
 * the endpoints come from VIRTUSPHERE_MACHINE_API_ENDPOINTS, the actions from the
 * literals each endpoint compares `action` against, the gates and roles from
 * VIRTUSPHERE_MACHINE_API_GATE_ROLES and the fence outcomes from the
 * VIRTUSPHERE_MECM_FENCE_* constants. A new action, endpoint or gate without a
 * row, or a row for one that is gone, turns this red.
 */
final class MachineApiFlowsDocContractTest extends TestCase
{
    private const DOC = 'docs/operations/machine-api-flows.md';
    private const INDEX = 'docs/operations/flows.md';
    private const NO_ACTION = '–';

    public function testEndpointTableHasOneRowPerEndpointActionWithItsGateAndRole(): void
    {
        $sources = [];
        foreach (self::endpoints() as $endpoint) {
            $sources[$endpoint] = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $endpoint);
        }

        self::assertSame([], self::endpointTableProblems(
            $sources,
            VIRTUSPHERE_MACHINE_API_GATE_ROLES,
            OperationsFlowDoc::section(self::doc(), 'Endpunkte und Rollen')
        ));
    }

    public function testFenceChartNamesEveryOutcomeAndCause(): void
    {
        $values = [];
        foreach (get_defined_constants(true)['user'] ?? [] as $name => $value) {
            if (str_starts_with((string) $name, 'VIRTUSPHERE_MECM_FENCE_')) {
                $values[] = (string) $value;
            }
        }
        $flows = self::flows();
        $section = OperationsFlowDoc::section(self::doc(), 'Rollout-Fence');

        self::assertSame([], array_merge(
            OperationsFlowDoc::sectionChartProblems('Fence chart', $section, 1, (int) OperationsFlowDoc::nodeLimit($flows), OperationsFlowDoc::legend($flows)),
            OperationsFlowDoc::missingTokens('Fence value', $values, implode("\n", OperationsFlowDoc::mermaidBlocks($section)))
        ));
    }

    public function testReportChartNamesEveryReportAction(): void
    {
        $section = OperationsFlowDoc::section(self::doc(), 'Berichtskanal');
        $actions = self::actionsOf((string) file_get_contents(dirname(__DIR__, 2) . '/mecm_report.php'));

        self::assertSame([], OperationsFlowDoc::missingTokens('Report action', $actions, implode("\n", OperationsFlowDoc::mermaidBlocks($section))));
    }

    public function testEveryChartFollowsTheLegend(): void
    {
        $flows = self::flows();
        $doc = self::doc();
        $problems = [];
        foreach (['Rollout-Fence', 'Geräte-Sync', 'Client-Ready-ACK', 'Berichtskanal', 'Paketkatalog'] as $heading) {
            $problems = array_merge($problems, OperationsFlowDoc::sectionChartProblems($heading, OperationsFlowDoc::section($doc, $heading), 1, (int) OperationsFlowDoc::nodeLimit($flows), OperationsFlowDoc::legend($flows)));
        }

        self::assertSame([], $problems);
    }

    public function testFlowsIndexLinksTheDocument(): void
    {
        self::assertStringContainsString('(machine-api-flows.md)', self::flows());
    }

    public function testActionDerivationReadsEveryComparisonForm(): void
    {
        self::assertSame(['a', 'b', 'c', 'd', 'e'], self::actionsOf(
            "\$action === 'a'; in_array(\$action, ['b', 'c'], true); match (\$action) { 'd' => 1, default => 2 }; request_string(\$_GET, 'action') !== 'e'"
        ));
        self::assertSame([], self::actionsOf('no action here'));
        self::assertSame(['getDeviceInfos', 'getDeviceList'], self::actionsOf((string) file_get_contents(dirname(__DIR__, 2) . '/mecm-api.php')));
    }

    public function testEndpointTableRejectsMissingExtraDuplicateAndWrongRows(): void
    {
        $sources = ['one.php' => "\$action === 'x'; machine_api_ip_allowed(\$db, \$ip, 'gate_a');", 'two.php' => "machine_api_ip_allowed(\$db, \$ip, 'gate_b');"];
        $roles = ['gate_a' => ['role_a'], 'gate_b' => ['role_b']];
        $ok = "| Endpunkt | Aktion | Gate | Rolle |\n|---|---|---|---|\n| `one.php` | `x` | `gate_a` | `role_a` |\n| `two.php` | – | `gate_b` | `role_b` |\n";
        self::assertSame([], self::endpointTableProblems($sources, $roles, $ok));

        self::assertSame(['Endpoint `two.php` action `–` has no row.', 'Gate `gate_b` is named by no row.'], self::endpointTableProblems($sources, $roles, str_replace("| `two.php` | – | `gate_b` | `role_b` |\n", '', $ok)));
        self::assertSame(['Row `one.php` action `y` is not derived from the code.'], self::endpointTableProblems($sources, $roles, $ok . "| `one.php` | `y` | `gate_a` | `role_a` |\n"));
        self::assertSame(['Endpoint `one.php` action `x` has 2 rows.'], self::endpointTableProblems($sources, $roles, $ok . "| `one.php` | `x` | `gate_a` | `role_a` |\n"));
        self::assertSame(
            ['Row `two.php` action `–` names gate `gate_a`, which the endpoint does not use.', 'Gate `gate_b` is named by no row.'],
            self::endpointTableProblems($sources, $roles, str_replace('| – | `gate_b` | `role_b` |', '| – | `gate_a` | `role_a` |', $ok))
        );
        self::assertSame(['Row `one.php` action `x` names role `role_b` instead of `role_a`.'], self::endpointTableProblems($sources, $roles, str_replace('`gate_a` | `role_a`', '`gate_a` | `role_b`', $ok)));
        self::assertSame(['Row `one.php` action `x` names unknown gate `gate_z`.', 'Gate `gate_a` is named by no row.'], self::endpointTableProblems($sources, $roles, str_replace('| `x` | `gate_a` |', '| `x` | `gate_z` |', $ok)));
        self::assertSame(['The endpoint table has no endpoints derived from code (zero-match must not pass).'], self::endpointTableProblems([], $roles, $ok));

        $split = ['one.php' => "machine_api_ip_allowed(\$db, \$ip, \$action === 'x' ? 'gate_a' : 'gate_b'); \$action === 'y';"];
        $both = "| Endpunkt | Aktion | Gate | Rolle |\n|---|---|---|---|\n| `one.php` | `x` | `gate_a` | `role_a` |\n| `one.php` | `y` | `gate_b` | `role_b` |\n";
        self::assertSame([], self::endpointTableProblems($split, $roles, $both));
        self::assertSame(
            ['Row `one.php` action `x` names gate `gate_b`, the endpoint binds `gate_a`.', 'Gate `gate_a` is named by no row.'],
            self::endpointTableProblems($split, $roles, str_replace('| `x` | `gate_a` | `role_a` |', '| `x` | `gate_b` | `role_b` |', $both))
        );
    }

    public function testGateBindingsReadMatchAndTernary(): void
    {
        self::assertSame(['a' => 'g1', 'b' => 'g2', '*' => 'g3'], self::gateBindings("machine_api_ip_allowed(\$c, \$ip, match (\$action) {\n 'a' => 'g1',\n 'b' => 'g2',\n default => 'g3',\n});"));
        self::assertSame(['a' => 'g1', '*' => 'g2'], self::gateBindings("machine_api_ip_allowed(\$c, \$ip, \$action === 'a' ? 'g1' : 'g2');"));
        self::assertSame([], self::gateBindings("machine_api_ip_allowed(\$c, \$ip, 'g1');"));
    }

    /**
     * @param array<string,string> $sources endpoint file => its source
     * @param array<string,list<string>> $gateRoles
     * @return list<string>
     */
    public static function endpointTableProblems(array $sources, array $gateRoles, string $section): array
    {
        if ($sources === []) {
            return ['The endpoint table has no endpoints derived from code (zero-match must not pass).'];
        }
        $expected = [];
        foreach ($sources as $endpoint => $source) {
            $actions = self::actionsOf($source);
            foreach ($actions === [] ? [self::NO_ACTION] : $actions as $action) {
                $expected[$endpoint . ' ' . $action] = [$endpoint, $action];
            }
        }
        $problems = [];
        $seen = [];
        $usedGates = [];
        foreach (OperationsFlowDoc::tableRows($section) as $cells) {
            $endpoint = OperationsFlowDoc::ticks($cells[0] ?? '')[0] ?? '';
            if ($endpoint === '') {
                continue;
            }
            $action = OperationsFlowDoc::ticks($cells[1] ?? '')[0] ?? trim($cells[1] ?? '');
            $key = $endpoint . ' ' . $action;
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            if (!isset($expected[$key])) {
                $problems[] = sprintf('Row `%s` action `%s` is not derived from the code.', $endpoint, $action);
                continue;
            }
            $gate = OperationsFlowDoc::ticks($cells[2] ?? '')[0] ?? '';
            if (!isset($gateRoles[$gate])) {
                $problems[] = sprintf('Row `%s` action `%s` names unknown gate `%s`.', $endpoint, $action, $gate);
                continue;
            }
            $usedGates[$gate] = true;
            $bindings = self::gateBindings($sources[$endpoint]);
            $bound = $bindings[$action] ?? $bindings['*'] ?? null;
            if (preg_match("/'" . preg_quote($gate, '/') . "'/", $sources[$endpoint]) !== 1) {
                $problems[] = sprintf('Row `%s` action `%s` names gate `%s`, which the endpoint does not use.', $endpoint, $action, $gate);
            } elseif ($bound !== null && $bound !== $gate) {
                $problems[] = sprintf('Row `%s` action `%s` names gate `%s`, the endpoint binds `%s`.', $endpoint, $action, $gate, $bound);
            }
            $roles = OperationsFlowDoc::ticks($cells[3] ?? '');
            if ($roles !== $gateRoles[$gate]) {
                $problems[] = sprintf('Row `%s` action `%s` names role `%s` instead of `%s`.', $endpoint, $action, implode('`, `', $roles), implode('`, `', $gateRoles[$gate]));
            }
        }
        foreach ($expected as $key => [$endpoint, $action]) {
            $count = $seen[$key] ?? 0;
            if ($count === 0) {
                $problems[] = sprintf('Endpoint `%s` action `%s` has no row.', $endpoint, $action);
            } elseif ($count > 1) {
                $problems[] = sprintf('Endpoint `%s` action `%s` has %d rows.', $endpoint, $action, $count);
            }
        }
        foreach (array_keys($gateRoles) as $gate) {
            if (!isset($usedGates[$gate])) {
                $problems[] = sprintf('Gate `%s` is named by no row.', $gate);
            }
        }

        return $problems;
    }

    /**
     * Every literal an endpoint compares its `action` parameter against, in the
     * four forms the endpoints use.
     *
     * @return list<string> sorted, unique
     */
    public static function actionsOf(string $source): array
    {
        $actions = [];
        preg_match_all("/\\\$action\\s*===\\s*'([A-Za-z]+)'/", $source, $equal);
        $actions = array_merge($actions, $equal[1]);
        preg_match_all("/in_array\\(\\\$action,\\s*\\[([^\\]]*)\\]/", $source, $lists);
        foreach ($lists[1] as $list) {
            preg_match_all("/'([A-Za-z]+)'/", $list, $items);
            $actions = array_merge($actions, $items[1]);
        }
        preg_match_all("/match \\(\\\$action\\)\\s*\\{([^}]*)\\}/", $source, $matches);
        foreach ($matches[1] as $arms) {
            preg_match_all("/'([A-Za-z]+)'\\s*=>/", $arms, $items);
            $actions = array_merge($actions, $items[1]);
        }
        preg_match_all("/request_string\\(\\\$_GET,\\s*'action'\\)\\s*!==\\s*'([A-Za-z]+)'/", $source, $negated);
        $actions = array_merge($actions, $negated[1]);
        $actions = array_values(array_unique($actions));
        sort($actions);

        return $actions;
    }

    /**
     * The gate an endpoint picks per action when one file serves several gates:
     * a `match ($action)` or an `$action === 'x' ? 'gate' : 'other'` inside the
     * machine_api_ip_allowed() call. `*` is the default arm.
     *
     * @return array<string,string> action => gate
     */
    public static function gateBindings(string $source): array
    {
        $bindings = [];
        if (preg_match("/machine_api_ip_allowed\\([^;]*?match \\(\\\$action\\)\\s*\\{([^}]*)\\}/", $source, $match) === 1) {
            preg_match_all("/'([A-Za-z]+)'\\s*=>\\s*'([a-z0-9_]+)'/", $match[1], $arms, PREG_SET_ORDER);
            foreach ($arms as $arm) {
                $bindings[$arm[1]] = $arm[2];
            }
            if (preg_match("/default\\s*=>\\s*'([a-z0-9_]+)'/", $match[1], $default) === 1) {
                $bindings['*'] = $default[1];
            }
        }
        if (preg_match("/machine_api_ip_allowed\\([^;]*?\\\$action\\s*===\\s*'([A-Za-z]+)'\\s*\\?\\s*'([a-z0-9_]+)'\\s*:\\s*'([a-z0-9_]+)'/", $source, $ternary) === 1) {
            $bindings[$ternary[1]] = $ternary[2];
            $bindings['*'] = $ternary[3];
        }

        return $bindings;
    }

    /** @return list<string> */
    private static function endpoints(): array
    {
        // Wherever the sentinel sits in the list, it is no endpoint file.
        $endpoints = [];
        foreach (VIRTUSPHERE_MACHINE_API_ENDPOINTS as $endpoint) {
            if ($endpoint !== VIRTUSPHERE_MACHINE_API_ENDPOINT_UNKNOWN) {
                $endpoints[] = $endpoint;
            }
        }
        self::assertNotSame([], $endpoints);

        return $endpoints;
    }

    private static function doc(): string
    {
        $doc = OperationsFlowDoc::load(self::DOC);
        if ($doc === null) {
            self::markTestSkipped('Repo root not visible; docs/ only exists outside the container mount.');
        }
        self::assertNotSame('', $doc, self::DOC . ' is missing.');

        return $doc;
    }

    private static function flows(): string
    {
        $flows = OperationsFlowDoc::load(self::INDEX);
        if ($flows === null) {
            self::markTestSkipped('Repo root not visible; docs/ only exists outside the container mount.');
        }
        self::assertNotNull(OperationsFlowDoc::nodeLimit($flows), 'flows.md has no flow-node-limit comment.');

        return $flows;
    }
}
