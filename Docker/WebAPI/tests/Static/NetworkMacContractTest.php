<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/mac_import_constants.php';
require_once dirname(__DIR__, 2) . '/lib/vm_network_contract.php';

final class NetworkMacContractTest extends TestCase
{
    public function testEveryQueueAndWorkerBoundaryConsumesTheSharedPreflight(): void
    {
        $queue = $this->source('lib/repo/deploy_job_queue.php');
        self::assertSame(3, substr_count($queue, 'repo_vm_network_assert_deploy_ready('), 'single, group-overall and group-member gates');
        self::assertStringContainsString('repo_transaction(', $queue);

        $worker = $this->source('lib/deploy_worker_mission.php');
        $preflight = strpos($worker, 'deploy_worker_network_preflight(');
        $credentials = strpos($worker, 'deploy_worker_credential(');
        self::assertIsInt($preflight);
        self::assertIsInt($credentials);
        self::assertLessThan($credentials, $preflight, 'preflight precedes credential materialization and remote work');
        $networkPreflight = $this->source('lib/deploy_worker_network_preflight.php');
        self::assertStringContainsString('repo_vm_network_preflight(', $networkPreflight);
        self::assertStringContainsString("'[' . \$position . '/' . \$total . '] RUN network/WDS preflight '", $networkPreflight);
        self::assertStringContainsString("'[' . \$position . '/' . \$total . '] OK network/WDS preflight '", $networkPreflight);
        self::assertStringContainsString("'[' . \$position . '/' . \$total . '] FAIL network/WDS preflight '", $networkPreflight);
    }

    public function testWriterFamiliesReachTheCentralBundleOrScopeGate(): void
    {
        $persistence = $this->source('lib/repo/vms_persistence.php');
        self::assertStringContainsString('repo_vm_network_assert_bundle_write_allowed(', $persistence);
        self::assertStringContainsString('repo_vm_network_assert_scope_idle(', $persistence);
        self::assertStringContainsString('repo_replace_interfaces(', $this->source('lib/mission_transfer_import.php'));
        self::assertStringContainsString('repo_replace_interfaces(', $this->source('lib/repo/vms_legacy.php'));
        self::assertStringContainsString('repo_vm_network_assert_scope_idle(', $this->source('lib/repo/vms_operations.php'));
        self::assertStringContainsString("require_once __DIR__ . '/repo/vlan_reassign.php';", $this->source('lib/system_status_page.php'));
        $vlanReassign = $this->source('lib/repo/vlan_reassign.php');
        self::assertStringContainsString("\$scope['active_jobs'] !== []", $vlanReassign);
        self::assertStringContainsString('throw new VmNetworkScopeActiveException(', $vlanReassign);

        $portal = $this->source('lib/system_status_page.php');
        self::assertStringContainsString('repo_reassign_vlan(', $portal);
        self::assertStringNotContainsString('UPDATE deploy_interfaces SET vlan', $portal, 'the portal must not bypass the exact repo writer');
    }

    public function testProductionInterfaceMutationOwnerGlobHasNegativeAndZeroMatchProof(): void
    {
        $root = dirname(__DIR__, 2);
        $allowed = [
            'db_importMAC.php',
            'lib/repo/vm_network.php',
            'lib/repo/vms_persistence.php',
        ];
        $seen = [];
        $files = glob($root . '/*.php') ?: [];
        foreach ([$root . '/lib', $root . '/portal'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                    $files[] = $fileInfo->getPathname();
                }
            }
        }
        self::assertNotSame([], $files, 'zero-match: the production PHP owner glob found no files');
        foreach ($files as $path) {
            $fileInfo = new SplFileInfo($path);
            $relative = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($root) + 1));
            if (str_starts_with($relative, 'tests/') || str_starts_with($relative, 'lib/migrations/') || $relative === 'lib/migrate.php') {
                continue;
            }
            $source = (string) file_get_contents($fileInfo->getPathname());
            if (!$this->hasDirectInterfaceMutation($source)) {
                continue;
            }
            $seen[] = $relative;
            self::assertContains($relative, $allowed, 'direct deploy_interfaces writer bypasses the registered owner set');
        }
        sort($seen, SORT_STRING);
        sort($allowed, SORT_STRING);
        self::assertSame($allowed, $seen, 'zero-match/stale-owner: every registered direct owner must still be found');
        self::assertTrue(
            $this->hasDirectInterfaceMutation("<?php repo_execute(\$db, 'UPDATE deploy_interfaces SET vlan = ? WHERE id = ?');"),
            'negative fixture: a rogue direct writer must match the owner glob'
        );
        foreach ([
            "<?php repo_execute(\$db, 'UPDATE `deploy_interfaces` SET vlan = ?');",
            "<?php repo_execute(\$db, 'INSERT IGNORE INTO `deploy_interfaces` (`vm_id`) VALUES (?)');",
            "<?php repo_execute(\$db, 'REPLACE INTO deploy_interfaces (vm_id) VALUES (?)');",
        ] as $fixture) {
            self::assertTrue($this->hasDirectInterfaceMutation($fixture), 'negative fixture: quoted or variant SQL writer escaped the owner glob');
        }
    }

    public function testModePolicyIsDerivedFromThePlaybookSsoTAndHasNoSecondList(): void
    {
        $contract = $this->source('lib/vm_network_contract.php');
        self::assertStringContainsString('ansible_playbooks_for_mode($mode)', $contract);
        self::assertStringContainsString('ansible_mode_expects_mac_result($mode)', $contract);
        self::assertStringNotContainsString("['create', 'full', 'powercycle', 'export']", $contract);
        self::assertNotEmpty(deploy_modes_with_hard_network_gate(), 'zero-match: a drifted mode derivation cannot vacuously pass');
    }

    public function testCallbackOwnsOneRawTransactionAndClosedBoundedWire(): void
    {
        $endpoint = $this->source('db_importMAC.php');
        $callback = $this->source('lib/mac_import_callback.php');
        self::assertSame(2, substr_count($endpoint, '$connection->begin_transaction();'), 'one import transaction plus one post-rollback observability transaction');
        self::assertStringContainsString('VIRTUSPHERE_MAC_IMPORT_REQUEST_MAX_BYTES + 1', $endpoint);
        self::assertStringContainsString('VIRTUSPHERE_MAC_IMPORT_RESULT_MAX_BYTES', $callback);
        self::assertStringContainsString('VIRTUSPHERE_MAC_IMPORT_RESPONSE_MAX_BYTES', $callback);
        self::assertStringContainsString('VIRTUSPHERE_MAC_IMPORT_CALLBACK_OBSERVABILITY_THROTTLE_SECONDS', $endpoint);
        self::assertStringContainsString('callback_fingerprint', $this->source('lib/mac_import_result.php'));
        self::assertStringContainsString("remote_step_callback_expectation(\$mode, 'export') !== 'db_import_mac'", $callback);
        self::assertStringNotContainsString('repo_transaction(', $endpoint);

        self::assertNotEmpty(virtusphere_mac_import_callback_reasons(), 'zero-match: callback conflicts need a closed reason registry');
        self::assertSame(
            ['request_too_large'],
            array_keys(array_filter(VIRTUSPHERE_MAC_IMPORT_CALLBACK_REASON_META, static fn (array $meta): bool => $meta['http'] === 413))
        );
        $reasonProducers = $endpoint . $callback;
        foreach (virtusphere_mac_import_callback_reasons() as $reason) {
            self::assertStringContainsString("'" . $reason . "'", $reasonProducers, 'reason has no producer: ' . $reason);
        }
        self::assertSame(VIRTUSPHERE_MAC_IMPORT_ERROR_CODES, array_keys(VIRTUSPHERE_MAC_IMPORT_ERROR_META));
    }

    public function testExactNameAndBodyContractsHaveNoRetiredFallback(): void
    {
        $import = $this->source('lib/mac_import.php') . $this->source('lib/mac_import_network.php');
        self::assertStringNotContainsString('strtolower($vmName)', $import);
        self::assertStringNotContainsString('strcasecmp($vmName', $import);
        self::assertStringNotContainsString('substr($requestBody', $this->source('db_importMAC.php'));
        self::assertStringNotContainsString('esxi_inventory_name_key', implode("\n", [
            $this->source('lib/esxi_inventory.php'),
            $this->source('lib/repo/esxi_inventory_cache.php'),
            $this->source('lib/repo/esxi_inventory_queries.php'),
        ]));
    }

    /**
     * Correction plan 16.4: one owner for the display/JSON bounds, and every
     * consumer through it. The negative half matters more than the positive
     * one: a page that slices a finding list itself would look correct and
     * would quietly disagree with the numbers printed next to it.
     */
    public function testDisplayBoundsHaveOneOwnerAndNoSecondSlicer(): void
    {
        $bounds = $this->source('lib/deploy_preflight_bounds.php');
        foreach ([
            'VIRTUSPHERE_DEPLOY_PREFLIGHT_DETAIL_LIMIT',
            'VIRTUSPHERE_ESXI_SIMILAR_NAME_CANDIDATE_LIMIT',
            'VIRTUSPHERE_DEPLOY_PREFLIGHT_JSON_MAX_BYTES',
        ] as $constant) {
            self::assertStringContainsString($constant, $bounds, 'the bounds owner must consume ' . $constant);
        }
        self::assertStringContainsString('truncated_by_bytes', $bounds);
        self::assertStringContainsString('array_pop($items)', $bounds, 'byte capping removes from the list END');

        // The consumers: server render, live endpoint, stored worker result.
        // The initial limit is a RENDER decision, so it is passed at those two
        // call sites and must reach both, or the browser and the page bound the
        // same list differently and their "N more" lines disagree.
        $view = $this->source('lib/deploy_queue_blocker_view.php');
        self::assertStringContainsString(
            'deploy_preflight_bounded_findings($blockers, VIRTUSPHERE_DEPLOY_PREFLIGHT_INITIAL_LIMIT)',
            $view
        );
        self::assertStringContainsString(
            'deploy_preflight_bounded_findings($warnings, VIRTUSPHERE_DEPLOY_PREFLIGHT_INITIAL_LIMIT)',
            $view
        );
        self::assertStringContainsString(
            'data-initial-limit="<?php echo h((string) VIRTUSPHERE_DEPLOY_PREFLIGHT_INITIAL_LIMIT); ?>"',
            $view,
            'the client learns the render bound from the same constant'
        );
        self::assertStringContainsString('data-initial-limit', $this->source('portal/assets/deploy_blockers.js'));
        $endpoint = $this->source('portal/deploy_blockers.php');
        self::assertStringContainsString('$count = count($blockers);', $endpoint);
        self::assertStringContainsString('deploy_preflight_bounded_json(', $endpoint);
        self::assertStringContainsString("'can_queue' => \$count === 0", $endpoint);
        self::assertStringContainsString(
            'deploy_preflight_bounded_result($result',
            $this->source('lib/repo/deploy_job_worker.php'),
            'the terminal preflight write bounds instead of throwing'
        );
        self::assertStringNotContainsString(
            'exceeds its JSON bound',
            $this->source('lib/repo/deploy_job_worker.php'),
            'a size guard must not turn configuration_blocked into execution_failed'
        );

        // Zero-match proof plus the negative: nobody else slices a finding
        // list, and nobody names its own number. The count matters as much as
        // the slice: `vm_network_finding_summary($findings, 20)` was a second
        // bound that looked like a parameter, and its flash message would have
        // disagreed with the blocker block about how much was left out.
        $owners = array_merge(
            glob(dirname(__DIR__, 2) . '/lib/deploy_*blocker*.php') ?: [],
            glob(dirname(__DIR__, 2) . '/lib/vm_network*.php') ?: [],
            glob(dirname(__DIR__, 2) . '/lib/esxi_object_names.php') ?: [],
            glob(dirname(__DIR__, 2) . '/lib/system_status_page.php') ?: [],
            glob(dirname(__DIR__, 2) . '/portal/deploy_blockers.php') ?: []
        );
        self::assertNotEmpty($owners, 'zero-match: no blocker module was scanned');
        $scanned = 0;
        foreach ($owners as $path) {
            $relative = basename($path);
            if ($relative === 'deploy_preflight_bounds.php') {
                continue;
            }
            $scanned++;
            $source = (string) file_get_contents($path);
            self::assertDoesNotMatchRegularExpression(
                '/array_slice\([^;]*\$(?:blockers|warnings|findings|issues|rows|byVm)\b/',
                $source,
                $relative . ' must bound a finding list through deploy_preflight_bounds.php, not with its own array_slice'
            );
            self::assertDoesNotMatchRegularExpression(
                '/(?:vm_network_finding_summary|deploy_preflight_(?:sorted_)?bounded_(?:findings|candidates))\([^)]*,\s*\d+\s*\)/',
                $source,
                $relative . ' must pass a bounds constant, never a literal limit'
            );
        }
        self::assertGreaterThanOrEqual(4, $scanned, 'zero-match: the owner glob collapsed');
        // The candidate producers are bounded where the candidates are made.
        foreach (['lib/vm_network_contract.php', 'lib/esxi_object_names.php'] as $producer) {
            self::assertStringContainsString(
                'VIRTUSPHERE_ESXI_SIMILAR_NAME_CANDIDATE_LIMIT',
                $this->source($producer),
                $producer . ' produces candidates and must bound them'
            );
            self::assertStringContainsString('candidate_omitted_count', $this->source($producer));
        }
    }

    /**
     * Correction plan 14.8: the job scope is capped before remote work, in the
     * repo gate every queue and worker path already passes through.
     */
    public function testJobScopeBoundsAreEnforcedBeforeRemoteWork(): void
    {
        $repo = $this->source('lib/repo/vm_network.php');
        self::assertStringContainsString('VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_VMS', $repo);
        self::assertStringContainsString('VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_INTERFACES_PER_VM', $repo);
        self::assertStringContainsString('repo_vm_network_assert_scope_within_bounds($preflight[\'vms\'])', $repo);
        // Queue, stagger member and worker recheck all reach it through
        // repo_vm_network_assert_deploy_ready(); only the group UNION opts out.
        $queue = $this->source('lib/repo/deploy_job_queue.php');
        self::assertSame(
            1,
            preg_match_all('/repo_vm_network_assert_deploy_ready\([^;]*,\s*true,\s*false\)/', $queue),
            'exactly the stagger group union may skip the per-job scope cap'
        );
        self::assertStringContainsString(
            'repo_vm_network_assert_scope_within_bounds(',
            $this->source('lib/deploy_blockers.php'),
            'the operator must see the cap as a queue blocker, not as a post-submit exception'
        );
        // A retry re-queues through repo_create_deploy_job(), so it meets the
        // same gate; its own module only RECOMPUTES the current scope and must
        // never insert past it.
        self::assertMatchesRegularExpression(
            '/function repo_retry_deploy_job\(.*?repo_create_deploy_job\(/s',
            $queue,
            'a retry must re-enter the queue gate rather than insert its own row'
        );
        $retry = $this->source('lib/repo/deploy_job_retry.php');
        self::assertStringContainsString('repo_vm_network_preflight(', $retry);
        self::assertStringNotContainsString('INSERT INTO deploy_jobs', $retry);
    }

    public function testVmListNetworkIndicatorIsRbacAndEvidenceSafe(): void
    {
        $portal = $this->source('portal/vms.php');
        self::assertStringContainsString('$hasNetworkIssues = array_filter($networkIssuesByVm) !== [];', $portal);
        self::assertStringContainsString("echo '&mdash;'", $portal, 'a valid DB bundle is not proof of live ESXi safety');
        self::assertStringContainsString('vm_network_finding_message($networkIssues[0])', $portal);
        self::assertStringContainsString('if ($canWrite)', $portal, 'only writers receive the repair deep link');
        self::assertStringNotContainsString("portal_badge('success', __t('vms.network_valid'))", $portal);
        self::assertStringNotContainsString('$firstNetworkIssue[\'code\']', $portal, 'raw issue codes are not accessible labels');
    }

    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $source = file_get_contents($path);
        self::assertIsString($source, $relative);
        return $source;
    }

    private function hasDirectInterfaceMutation(string $source): bool
    {
        return preg_match('/\\b(?:INSERT(?:\\s+IGNORE)?\\s+INTO|REPLACE\\s+INTO|UPDATE|DELETE\\s+FROM)\\s+`?deploy_interfaces`?(?=\\s|\\(|$)/is', $source) === 1;
    }
}
