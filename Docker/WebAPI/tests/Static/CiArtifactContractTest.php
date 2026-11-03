<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CiArtifactContractTest extends TestCase
{
    private string $workflow;

    protected function setUp(): void
    {
        $this->workflow = (string) file_get_contents(dirname(__DIR__, 4) . '/.github/workflows/ci.yml');
    }

    public function testEveryCanonicalJsonProducerMatchesItsRequiredUpload(): void
    {
        foreach (['fast', 'integration', 'ps51'] as $lane) {
            self::assertStringContainsString('-Json qa-artifacts/qa-' . $lane . '.json', $this->workflow, $lane);
            self::assertMatchesRegularExpression(
                '/path:\s*qa-artifacts\/qa-' . preg_quote($lane, '/') . '\.json\s+retention-days:\s*14\s+if-no-files-found:\s*error/',
                $this->workflow,
                $lane
            );
        }
    }

    public function testFailureEvidenceUsesTheActualScopedOutputFolders(): void
    {
        self::assertStringContainsString("hashFiles('tests/e2e/playwright-report-chromium/**')", $this->workflow);
        self::assertStringContainsString('path: tests/e2e/playwright-report-chromium', $this->workflow);
        self::assertStringContainsString("hashFiles('qa-artifacts/visual-baselines-*/**')", $this->workflow);
        self::assertStringContainsString('path: qa-artifacts/visual-baselines-*', $this->workflow);
    }

    public function testArtifactUploadsNeverSweepSecretsOrTheCheckout(): void
    {
        self::assertDoesNotMatchRegularExpression('/path:\s*(?:\.|\.env|.*accounts\.yml)\s*$/m', $this->workflow);
    }

}
