<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/https_config.php';

/**
 * SEC3: the generated HTTPS block lives on the host volume and used to keep the
 * previous release's rules after an update until the next certificate upload.
 * The PHP container now refreshes an existing block on every start, before FPM
 * and therefore before nginx, which waits for a healthy PHP.
 */
final class HttpsConfigRefreshTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/virtusphere-https-refresh-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->dir);
    }

    public function testAStaleBlockIsRewrittenWithItsMode(): void
    {
        $target = $this->dir . '/virtusphere-https.conf';
        file_put_contents($target, "server { listen 8443 ssl; location ~ ^/(lib|vendor)/ { deny all; } }\n");
        chmod($target, 0640);

        self::assertSame('refreshed', https_refresh_generated_conf($this->dir));
        self::assertSame(https_render_nginx_conf(), file_get_contents($target));
        self::assertSame([], glob($this->dir . '/*.tmp') ?: [], 'no tmp file may survive the atomic rename');
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame('640', substr(sprintf('%o', (int) fileperms($target)), -3), 'the mode of the existing block is kept');
        }
        self::assertSame('current', https_refresh_generated_conf($this->dir), 'a second start has nothing to do');
    }

    /**
     * No block means HTTPS is off, or the nginx boot quarantine renamed a broken
     * block to *.bad. Recreating it would point the portal's redirect at a
     * listener that does not exist (https_listener_live()).
     */
    public function testWithoutABlockNothingIsCreated(): void
    {
        file_put_contents($this->dir . '/virtusphere-https.conf.bad', "broken\n");

        self::assertSame('absent', https_refresh_generated_conf($this->dir));
        self::assertFileDoesNotExist($this->dir . '/virtusphere-https.conf');
        self::assertSame("broken\n", file_get_contents($this->dir . '/virtusphere-https.conf.bad'));
    }

    /** The image runs the refresh before php-fpm, and php-fpm stays PID 1. */
    public function testThePhpImageRefreshesBeforeFpmStarts(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        if (!is_file($repoRoot . '/Docker/php/Dockerfile')) {
            self::markTestSkipped('Repo root not visible; Dockerfiles only exist outside the container mount.');
        }
        $dockerfile = (string) file_get_contents($repoRoot . '/Docker/php/Dockerfile');
        $runtime = (string) strstr((string) strstr($dockerfile, 'AS runtime'), 'FROM runtime AS tooling', true);
        self::assertStringContainsString('COPY start-fpm.sh /usr/local/bin/virtusphere-start-fpm', $runtime);
        self::assertStringContainsString('CMD ["virtusphere-start-fpm"]', $runtime);

        $script = (string) file_get_contents($repoRoot . '/Docker/php/start-fpm.sh');
        $refresh = strpos($script, 'php "$refresh"');
        $exec = strpos($script, 'exec php-fpm "$@"');
        self::assertNotFalse($refresh, 'the start script must run lib/https_refresh.php');
        self::assertNotFalse($exec, 'the start script must exec php-fpm so it stays PID 1');
        self::assertLessThan($exec, $refresh);
        self::assertStringContainsString('refresh=/var/www/html/lib/https_refresh.php', $script);
        self::assertFileExists(dirname(__DIR__, 2) . '/lib/https_refresh.php');
        self::assertStringNotContainsString('set -e', $script, 'a failed refresh must never keep FPM down');
    }
}
