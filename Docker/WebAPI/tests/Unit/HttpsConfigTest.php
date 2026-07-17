<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/https_config.php';

/**
 * lib/https_config.php is the validation and write layer of the WP7 HTTPS
 * admin flow (ADR-0012/ADR-0027): everything it writes is picked up blindly
 * by the nginx watcher, so a bad pair, an expired leaf or a world-readable
 * key must be stopped HERE. Fixtures under tests/fixtures/https are
 * committed, long-lived self-signed material (air-gap safe).
 */
final class HttpsConfigTest extends TestCase
{
    private string $tmpDir = '';

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/https/' . $name);
    }

    /**
     * The PFX is committed base64-encoded: the release gate rejects tracked
     * *.pfx files, and text survives git/editor line-ending handling.
     */
    private static function pfxFixture(): string
    {
        return (string) base64_decode(self::fixture('valid.pfx.b64'), true);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach ((array) glob($this->tmpDir . '/*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($this->tmpDir);
        }
    }

    private function tmpDir(): string
    {
        $this->tmpDir = sys_get_temp_dir() . '/virtusphere-https-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);

        return $this->tmpDir;
    }

    public function testPemPairParses(): void
    {
        $material = https_parse_upload(self::fixture('valid.crt.txt'), self::fixture('valid.key.txt'), '');
        self::assertStringContainsString('BEGIN CERTIFICATE', $material['cert_pem']);
        self::assertStringContainsString('PRIVATE KEY', $material['key_pem']);
    }

    public function testPfxParsesWithCorrectPassword(): void
    {
        $material = https_parse_upload(self::pfxFixture(), '', 'test-passphrase');
        self::assertStringContainsString('BEGIN CERTIFICATE', $material['cert_pem']);
        self::assertStringContainsString('PRIVATE KEY', $material['key_pem']);
    }

    public function testPfxRejectsWrongPassword(): void
    {
        try {
            https_parse_upload(self::pfxFixture(), '', 'wrong');
            self::fail('a wrong PFX password must be rejected');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('pfx_password', $exception->errors());
        }
    }

    public function testPemWithoutKeyIsRejected(): void
    {
        try {
            https_parse_upload(self::fixture('valid.crt.txt'), '', '');
            self::fail('a PEM cert without its key must be rejected');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('key_file', $exception->errors());
        }
    }

    public function testMismatchedKeyIsRejected(): void
    {
        try {
            https_parse_upload(self::fixture('valid.crt.txt'), self::fixture('other.key.txt'), '');
            self::fail('a key from another certificate must be rejected');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('key_file', $exception->errors());
        }
    }

    public function testExpiredCertificateIsRejected(): void
    {
        try {
            https_parse_upload(self::fixture('expired.crt.txt'), self::fixture('expired.key.txt'), '');
            self::fail('an expired certificate must be rejected');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('cert_file', $exception->errors());
        }
    }

    public function testMetadataExtraction(): void
    {
        $meta = https_cert_metadata(self::fixture('valid.crt.txt'));
        self::assertSame('virtusphere.local', $meta['subject']);
        self::assertStringContainsString('portal.virtusphere.local', $meta['sans']);
        self::assertGreaterThan(time(), $meta['valid_to']);
        self::assertGreaterThan(0, $meta['days_remaining']);
        self::assertMatchesRegularExpression('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $meta['fingerprint']);
    }

    public function testWriteMaterialSetsKeyPermissionsAndLeavesNoTmp(): void
    {
        $dir = $this->tmpDir();
        $material = https_parse_upload(self::fixture('valid.crt.txt'), self::fixture('valid.key.txt'), '');
        https_write_material($material['cert_pem'], $material['chain_pem'], $material['key_pem'], $dir);

        self::assertTrue(https_material_present($dir));
        self::assertSame([], glob($dir . '/*.tmp') ?: [], 'no tmp file may survive the atomic rename');
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame('0600', substr(sprintf('%o', (int) fileperms($dir . '/server.key')), -4));
        }
        $meta = https_installed_metadata($dir);
        self::assertNotNull($meta);
        self::assertSame('virtusphere.local', $meta['subject']);
    }

    public function testBothImagesInitializeSharedHttpsVolumesForPhpAndNginx(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $expected = [
            'chown 33:0 /etc/nginx/ssl /etc/nginx/virtusphere-conf.d',
            'chmod 0770 /etc/nginx/ssl /etc/nginx/virtusphere-conf.d',
        ];

        foreach (['Docker/php/Dockerfile', 'Docker/nginx/Dockerfile'] as $relativePath) {
            $path = $repoRoot . '/' . $relativePath;
            self::assertFileExists($path);
            $dockerfile = (string) file_get_contents($path);
            foreach ($expected as $contract) {
                self::assertStringContainsString(
                    $contract,
                    $dockerfile,
                    $relativePath . ' must initialize empty shared HTTPS volumes with the same ownership contract'
                );
            }
        }
    }

    public function testRenderedConfServesTlsAndContainsNoKeyMaterial(): void
    {
        $conf = https_render_nginx_conf();
        self::assertStringContainsString('listen 8443 ssl;', $conf);
        self::assertStringContainsString('ssl_certificate /etc/nginx/ssl/server.crt;', $conf);
        self::assertStringContainsString('ssl_certificate_key /etc/nginx/ssl/server.key;', $conf);
        self::assertStringContainsString('fastcgi_param HTTPS on;', $conf);
        self::assertStringNotContainsString('PRIVATE KEY', $conf);
        // The deny rules must mirror the HTTP block in Docker/nginx/default.conf.
        self::assertStringContainsString('location ~ ^/(lib|vendor|var|logs|tests)/ { deny all; }', $conf);
    }

    public function testHttpAndHttpsDenyRulesStayInSync(): void
    {
        // The generated HTTPS block duplicates the HTTP block's deny rules; a
        // rule added to default.conf but not the generator would silently be
        // served over TLS only. The file only exists on a repo checkout, not
        // inside the php container (Docker/nginx is not mounted there).
        $path = dirname(__DIR__, 3) . '/nginx/default.conf';
        if (!is_file($path)) {
            self::markTestSkipped('Docker/nginx/default.conf is not visible from this runtime');
        }
        $httpConf = (string) file_get_contents($path);
        preg_match_all('/^\s*(location ~[^\n]*deny all;[^\n]*)$/m', $httpConf, $httpRules);
        $generated = https_render_nginx_conf();
        foreach ($httpRules[1] as $rule) {
            self::assertStringContainsString(trim($rule), $generated, 'deny rule missing from the generated HTTPS block: ' . trim($rule));
        }
    }

    /**
     * The lockout this guards against: init.sh quarantines a generated config
     * that nginx rejects by renaming it to `*.bad`, and it does so on boot. The
     * redirect, however, lives in PHP and reads a database flag that knows
     * nothing about the quarantine. Left alone it keeps 301-ing the operator to
     * a port nothing listens on, while HTTP, the documented way back, is the very
     * thing doing the sending. Reproduced on the running stack: after a boot
     * quarantine, HTTPS refused the connection and HTTP still redirected to it,
     * leaving the portal unreachable except through a manual database edit.
     *
     * The presence of the generated config is the only evidence PHP has, so it is
     * what the redirect must depend on.
     */
    public function testTheListenerCountsAsLiveOnlyWhileItsGeneratedConfigExists(): void
    {
        $confDir = sys_get_temp_dir() . '/vs-https-conf-' . bin2hex(random_bytes(4));
        $sslDir = sys_get_temp_dir() . '/vs-https-ssl-' . bin2hex(random_bytes(4));
        mkdir($confDir);
        mkdir($sslDir);

        try {
            file_put_contents($sslDir . '/server.crt', self::fixture('valid.crt.txt'));
            file_put_contents($sslDir . '/server.key', self::fixture('valid.key.txt'));

            self::assertFalse(
                https_listener_live($confDir, $sslDir),
                'certificate material alone is not a listener: nginx only serves TLS once the generated config is in place'
            );

            file_put_contents($confDir . '/virtusphere-https.conf', https_render_nginx_conf());
            self::assertTrue(https_listener_live($confDir, $sslDir), 'config plus material means the listener is up');

            // Exactly what init.sh does to a config nginx rejected.
            rename($confDir . '/virtusphere-https.conf', $confDir . '/virtusphere-https.conf.bad');
            self::assertFalse(
                https_listener_live($confDir, $sslDir),
                'a quarantined config is not a listener; redirecting to it would lock the operator out'
            );
        } finally {
            foreach ([$confDir, $sslDir] as $dir) {
                foreach ((array) glob($dir . '/*') as $file) {
                    @unlink((string) $file);
                }
                @rmdir($dir);
            }
        }
    }

    /**
     * nginx answers the deny rules and a missing file itself, so those responses
     * never reach lib/headers.php. The add_header fallbacks are what give them a
     * policy at all, and they are declared per server block: present in the HTTP
     * block but forgotten in the generated one, enabling HTTPS would silently
     * serve unprotected 403/404 responses. The map variables they read live in
     * default.conf, which nginx loads before the generated file.
     */
    public function testHttpAndHttpsFallbackSecurityHeadersStayInSync(): void
    {
        $path = dirname(__DIR__, 3) . '/nginx/default.conf';
        if (!is_file($path)) {
            self::markTestSkipped('Docker/nginx/default.conf is not visible from this runtime');
        }
        $httpConf = (string) file_get_contents($path);
        $generated = https_render_nginx_conf();

        preg_match_all('/^\s*(add_header [^\n]*always;)$/m', $httpConf, $httpHeaders);
        self::assertNotSame([], $httpHeaders[1], 'the HTTP block must declare the fallback headers');

        foreach ($httpHeaders[1] as $header) {
            self::assertStringContainsString(
                trim($header),
                $generated,
                'fallback header missing from the generated HTTPS block: ' . trim($header)
            );
        }

        // The maps are the reason a PHP response does not end up with two CSP
        // headers (a browser enforces the intersection, so a nonce-less second
        // policy would block the portal's own styles and scripts).
        foreach (['$virtusphere_csp', '$virtusphere_nosniff', '$virtusphere_referrer'] as $variable) {
            self::assertStringContainsString(
                'map $upstream_http_',
                $httpConf,
                'the fallback headers must stay conditional on the upstream not having sent one'
            );
            self::assertStringContainsString($variable, $httpConf);
        }
    }
}
