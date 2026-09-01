<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DeployBlockerContractTest extends TestCase
{
    private function source(string $relative): string
    {
        return (string) file_get_contents(str_replace('\\', '/', dirname(__DIR__, 2)) . '/' . $relative);
    }

    /**
     * The decision module plus every renderer that consumes it. Globbed, never
     * listed: naming one file is what made this contract go red the moment the
     * render moved into lib/deploy_queue_blocker_view.php, while the rule it
     * protects had not changed at all.
     */
    private function blockerModules(): string
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $paths = array_merge(
            glob($root . '/lib/deploy_blockers.php') ?: [],
            glob($root . '/lib/deploy_queue_blocker*.php') ?: []
        );
        self::assertNotSame([], $paths, 'no deploy blocker module was scanned');

        return implode("\n", array_map(static fn (string $path): string => (string) file_get_contents($path), $paths));
    }

    public function testOneModelOwnsRenderingLiveReadAndFinalWriteRecheck(): void
    {
        $model = $this->blockerModules();
        $actions = $this->source('lib/deploy_actions.php');
        self::assertStringContainsString('function deploy_queue_blockers(mysqli $db, array $input)', $model);
        self::assertStringContainsString("\$blocker['target_id']", $model);
        self::assertStringContainsString("\$blocker['action']", $model);
        self::assertStringContainsString('Unknown deploy blocker kind', $model);
        self::assertMatchesRegularExpression('/deploy_assert_queue_unblocked\([^;]+\);\s*\$result = repo_enqueue_deploy_group/s', $actions);
        self::assertMatchesRegularExpression('/deploy_assert_queue_unblocked\([^;]+\);\s*\$jobId = repo_create_deploy_job/s', $actions);
        self::assertStringContainsString('deploy_queue_normalize_input($_POST)', $actions);
    }

    public function testEndpointIsReadOnlyJsonWithSessionAndRbacSemantics(): void
    {
        $endpoint = $this->source('portal/deploy_blockers.php');
        self::assertStringContainsString("\$_SERVER['REQUEST_METHOD'] !== 'GET'", $endpoint);
        self::assertStringContainsString("Content-Type: application/json", $endpoint);
        self::assertStringContainsString('current_user($connection)', $endpoint);
        self::assertStringContainsString('http_response_code(401)', $endpoint);
        self::assertStringContainsString('http_response_code(403)', $endpoint);
        self::assertStringContainsString("can('deploy.run'", $endpoint);
        self::assertStringNotContainsString('audit_event(', $endpoint);
        self::assertStringNotContainsString('error_log(', $endpoint);
    }

    public function testClientIsDebouncedSingleFlightStaleSafeAndTextOnly(): void
    {
        $client = $this->source('portal/assets/deploy_blockers.js');
        foreach (['setTimeout(refresh, 250)', 'AbortController', 'requestSequence', 'content-type', 'response.status === 401', 'response.status === 403', 'stopped = true', 'renderFailure(data.message)', 'textContent'] as $needle) {
            self::assertStringContainsString($needle, $client);
        }
        self::assertStringNotContainsString('innerHTML', $client);
        self::assertStringNotContainsString('console.', $client);
    }

    /**
     * The one asymmetric branch of the live client, pinned because it looks
     * like a bug from the outside: when the check itself fails, the submit is
     * blocked for the modes with a hard network gate and left alone for the
     * rest. The client has no blocker data in that moment, so a global lock
     * would be an invention, and the server re-checks the complete union at
     * submit time regardless. Flipping it in either direction is a decision
     * that has to be made here, not a diff nobody reads.
     */
    public function testTheFailureBranchBlocksExactlyTheHardNetworkModes(): void
    {
        $client = $this->source('portal/assets/deploy_blockers.js');
        self::assertStringContainsString('button.disabled = hardMode;', $client);
        self::assertStringNotContainsString('button.disabled = true;', $client, 'a global lock on a failed check is an invention');
        self::assertStringContainsString("hardNetworkModes.indexOf(mode) !== -1", $client);
        self::assertStringContainsString(
            "JSON.parse(root.getAttribute('data-hard-network-modes')",
            $client,
            'the mode list is served, never hardcoded in JS'
        );
        // And the served list is the derived one, not a second literal.
        $view = $this->source('lib/deploy_queue_blocker_view.php');
        self::assertStringContainsString(
            'data-hard-network-modes="<?php echo h(json_encode(deploy_modes_with_hard_network_gate()',
            $view
        );
        // The render bound reaches the client from the same constant.
        self::assertStringContainsString('data-initial-limit', $view);
        self::assertStringContainsString("root.getAttribute('data-initial-limit')", $client);
    }
}
