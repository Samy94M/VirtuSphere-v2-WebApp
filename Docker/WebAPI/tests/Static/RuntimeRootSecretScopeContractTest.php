<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RuntimeRootSecretScopeContractTest extends TestCase
{
    public function testRootSecretHasDatabaseOwnerAndNoAppRuntimePath(): void
    {
        $root = dirname(__DIR__, 4);
        $compose = (string) file_get_contents($root . '/docker-compose.yml');
        $qaCompose = (string) file_get_contents($root . '/Docker/qa/docker-compose.qa.yml');
        $envboot = (string) file_get_contents($root . '/Docker/WebAPI/lib/envboot.php');
        $mysqlDockerfile = (string) file_get_contents($root . '/Docker/mysql/Dockerfile');
        $mysqlEntrypoint = (string) file_get_contents($root . '/Docker/mysql/validate-secrets.sh');

        self::assertDoesNotMatchRegularExpression('/^\s+env_file:/m', $compose);
        self::assertDoesNotMatchRegularExpression('/^\s+env_file:/m', $qaCompose);
        self::assertStringNotContainsString('/var/www/html/.env', $qaCompose);
        self::assertStringContainsString("\$name === 'MYSQL_ROOT_PASSWORD'", $envboot);

        preg_match('/function envboot_assert_secure_runtime\(\): void\s*\{(.*?)\n\}/s', $envboot, $runtimeAssertion);
        self::assertArrayHasKey(1, $runtimeAssertion);
        self::assertStringNotContainsString('MYSQL_ROOT_PASSWORD', $runtimeAssertion[1]);

        self::assertStringContainsString('MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}', $compose);
        self::assertStringContainsString('ENTRYPOINT ["virtusphere-mysql-entrypoint.sh"]', $mysqlDockerfile);
        self::assertStringContainsString('CMD ["mysqld"]', $mysqlDockerfile);
        self::assertStringContainsString('assert_strong_secret MYSQL_ROOT_PASSWORD', $mysqlEntrypoint);
        self::assertStringContainsString('assert_strong_secret MYSQL_PASSWORD', $mysqlEntrypoint);
        self::assertStringContainsString('exec /usr/local/bin/docker-entrypoint.sh "$@"', $mysqlEntrypoint);
    }
}
