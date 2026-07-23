<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/ansible.php';
require_once dirname(__DIR__, 2) . '/lib/repo/settings.php';

final class AnsibleApiBaseUrlConfigurationTest extends TestCase
{
    private ?mysqli $db = null;

    /** @var string|false */
    private string|false $originalEnv = false;

    protected function setUp(): void
    {
        $this->originalEnv = getenv('APP_PUBLIC_BASE_URL');
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->db->begin_transaction();
        repo_delete_setting($this->db, VIRTUSPHERE_SETTING_API_BASE_URL);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->rollback();
        }

        if ($this->originalEnv === false) {
            putenv('APP_PUBLIC_BASE_URL');
            unset($_ENV['APP_PUBLIC_BASE_URL']);
        } else {
            putenv('APP_PUBLIC_BASE_URL=' . $this->originalEnv);
            $_ENV['APP_PUBLIC_BASE_URL'] = $this->originalEnv;
        }
    }

    public function testPortalValueWinsAndReportsItsSource(): void
    {
        putenv('APP_PUBLIC_BASE_URL=http://env.example:8021');
        repo_set_setting($this->db, VIRTUSPHERE_SETTING_API_BASE_URL, 'portal.example:8021/');

        self::assertSame(
            ['value' => 'portal.example:8021/', 'source' => 'portal'],
            ansible_api_base_url_configuration($this->db)
        );
        self::assertSame('http://portal.example:8021', ansible_resolve_api_base_url($this->db));
    }

    public function testEnvironmentIsTheFallbackAndReportsItsSource(): void
    {
        putenv('APP_PUBLIC_BASE_URL=https://env.example:8443/');

        self::assertSame(
            ['value' => 'https://env.example:8443/', 'source' => 'env'],
            ansible_api_base_url_configuration($this->db)
        );
        self::assertSame('https://env.example:8443', ansible_resolve_api_base_url($this->db));
    }

    public function testMissingValuesReportNoneAndStillBlockDeployResolution(): void
    {
        putenv('APP_PUBLIC_BASE_URL=');

        self::assertSame(
            ['value' => '', 'source' => 'none'],
            ansible_api_base_url_configuration($this->db)
        );

        $this->expectException(RuntimeException::class);
        ansible_resolve_api_base_url($this->db);
    }
}
