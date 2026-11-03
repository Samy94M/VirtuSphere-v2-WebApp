<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RuntimeLoggingPolicyContractTest extends TestCase
{
    public function testContainerAndNginxLogsHaveOneBoundedOwner(): void
    {
        $root = dirname(__DIR__, 4);
        $compose = str_replace("\r\n", "\n", (string) file_get_contents($root . '/docker-compose.yml'));
        $qaCompose = str_replace("\r\n", "\n", (string) file_get_contents($root . '/Docker/qa/docker-compose.qa.yml'));
        $http = (string) file_get_contents($root . '/Docker/nginx/default.conf');
        $https = (string) file_get_contents($root . '/Docker/WebAPI/lib/https_config.php');

        self::assertStringContainsString("x-container-logging: &container-logging\n  driver: json-file\n  options:\n    max-size: \"10m\"\n    max-file: \"5\"", $compose);
        self::assertSame(6, substr_count($compose, 'logging: *container-logging'));
        self::assertStringNotContainsString('./Docker/logs/nginx:/var/log/nginx', $compose);
        self::assertStringNotContainsString('qa-nginx-logs', $qaCompose);

        foreach ([$http, $https] as $nginxSource) {
            self::assertStringContainsString('access_log /dev/stdout virtusphere;', $nginxSource);
            self::assertStringContainsString('error_log stderr error;', $nginxSource);
            self::assertStringNotContainsString('access_log /var/log/nginx/', $nginxSource);
            self::assertStringNotContainsString('error_log /var/log/nginx/', $nginxSource);
        }
    }

    public function testMysqlBinaryLogPolicyMatchesLogicalBackupContract(): void
    {
        $root = dirname(__DIR__, 4);
        $compose = (string) file_get_contents($root . '/docker-compose.yml');
        $backupAdr = (string) file_get_contents($root . '/docs/adr/ADR-0017-backup-and-restore.md');

        self::assertStringContainsString('command: ["mysqld", "--skip-log-bin"]', $compose);
        self::assertStringContainsString('Point-in-time/binlog recovery is out of scope', $backupAdr);
    }
}
