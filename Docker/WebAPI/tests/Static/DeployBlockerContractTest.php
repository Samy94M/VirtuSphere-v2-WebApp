<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DeployBlockerContractTest extends TestCase
{
    private function source(string $relative): string
    {
        return (string) file_get_contents(str_replace('\\', '/', dirname(__DIR__, 2)) . '/' . $relative);
    }

    public function testOneModelOwnsRenderingLiveReadAndFinalWriteRecheck(): void
    {
        $model = $this->source('lib/deploy_blockers.php');
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
}
