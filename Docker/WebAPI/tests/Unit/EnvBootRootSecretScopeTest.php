<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EnvBootRootSecretScopeTest extends TestCase
{
    public function testReadableDotenvDoesNotImportDatabaseRootSecret(): void
    {
        $envboot = dirname(__DIR__, 2) . '/lib/envboot.php';
        $code = 'require ' . var_export($envboot, true) . ';'
            . 'envboot_load_dotenv();'
            . 'echo json_encode(['
            . '"root" => getenv("MYSQL_ROOT_PASSWORD"),'
            . '"app" => getenv("DB_PASS") !== false && trim((string) getenv("DB_PASS")) !== ""'
            . '], JSON_THROW_ON_ERROR);';

        $environment = getenv();
        unset($environment['MYSQL_ROOT_PASSWORD'], $environment['DB_PASS']);

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(0, $exit, (string) $stderr);
        self::assertSame(['root' => false, 'app' => true], json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRuntimeAssertionDoesNotRequireRootSecret(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/envboot.php');
        preg_match('/function envboot_assert_secure_runtime\(\): void\s*\{(.*?)\n\}/s', $source, $match);
        self::assertArrayHasKey(1, $match);
        self::assertStringNotContainsString('MYSQL_ROOT_PASSWORD', $match[1]);
        self::assertStringContainsString("envboot_required('DB_PASS')", $match[1]);
        self::assertStringContainsString('envboot_app_key_bytes()', $match[1]);
    }
}
