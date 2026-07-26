<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A deploy job is opened from queue flashes, mission-job rows and ESXi status
 * cards. `deploy_job_log_url()` is the route SSoT for all PHP callers; the JS
 * polling endpoint stays outside this PHP-only contract.
 */
final class DeployLogDeepLinkContractTest extends TestCase
{
    private const BUILDER = 'lib/deploy_urls.php';

    private function root(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    private function stripComments(string $php): string
    {
        $stripped = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $stripped .= $token[1];
                continue;
            }
            $stripped .= $token;
        }

        return $stripped;
    }

    /** @return array<string,string> */
    private function sources(): array
    {
        $root = $this->root();
        $paths = array_merge(
            glob($root . '/portal/*.php') ?: [],
            glob($root . '/lib/*.php') ?: [],
            glob($root . '/lib/repo/*.php') ?: []
        );
        self::assertNotSame([], $paths, 'no portal or library PHP sources found');

        $sources = [];
        foreach ($paths as $path) {
            $relative = substr(str_replace('\\', '/', $path), strlen($root) + 1);
            if ($relative === self::BUILDER) {
                continue;
            }
            $sources[$relative] = $this->stripComments((string) file_get_contents($path));
        }

        return $sources;
    }

    public function testNoPhpCallerHandBuildsAJobLogLink(): void
    {
        $offenders = [];
        foreach ($this->sources() as $relative => $source) {
            if (preg_match('/deploy_log[.]php[?].*?id=/i', $source) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'build deploy-job links with deploy_job_log_url()');
    }

    public function testTheBuilderExistsAndIsUsed(): void
    {
        $builder = (string) file_get_contents($this->root() . '/' . self::BUILDER);
        self::assertStringContainsString('function deploy_job_log_url(', $builder);

        $callers = array_filter(
            $this->sources(),
            static fn (string $source): bool => str_contains($source, 'deploy_job_log_url(')
        );
        self::assertNotSame([], $callers, 'no PHP page uses the canonical job-log URL builder');
    }
}
