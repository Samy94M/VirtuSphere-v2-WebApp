<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/mission_transfer.php';
require_once dirname(__DIR__, 2) . '/lib/network_mac_constants.php';

/**
 * The four upload limits the mission import passes through, in order.
 *
 * VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES is the only one the product owns. The
 * other three exist so that the app's own, friendly "file too large (limit X)"
 * is the sentence the operator actually sees: PHP's upload_max_filesize and
 * post_max_size and nginx's client_max_body_size must all sit ABOVE it, or the
 * layer below answers first with something the portal cannot localize. That is
 * not hypothetical - upload_max_filesize used to be PHP's 2M default, exactly
 * equal to the app limit, and the page reported "no file selected" for a file
 * the operator had very much selected.
 *
 * None of the three can interpolate a PHP constant, so all three are mirrors,
 * and a mirror drifts silently: raising the app limit alone breaks the message
 * without breaking anything a functional test can see.
 */
final class MissionImportUploadLimitContractTest extends TestCase
{
    /** php shorthand ("4M", "512k", "1G") in bytes. */
    private function shorthandToBytes(string $value): int
    {
        $raw = trim($value);
        self::assertMatchesRegularExpression('/^\d+[kKmMgG]?$/', $raw, 'unparseable size shorthand: ' . $value);
        $number = (int) $raw;
        $suffix = strtolower(substr($raw, -1));

        return match ($suffix) {
            'k' => $number * 1024,
            'm' => $number * 1024 * 1024,
            'g' => $number * 1024 * 1024 * 1024,
            default => $number,
        };
    }

    /** @return array<string, int> */
    private function phpIniLimits(): array
    {
        // Docker/php is outside the container mount (only Docker/WebAPI becomes
        // /var/www/html), so this reads from a repo checkout. Both canonical
        // lanes mount the whole repo, so the skip below never fires there.
        $path = dirname(__DIR__, 3) . '/php/conf.d/zz-virtusphere.ini';
        if (!is_file($path)) {
            self::markTestSkipped('Docker/php/conf.d/zz-virtusphere.ini is not visible from this runtime');
        }
        $parsed = parse_ini_file($path, false, INI_SCANNER_RAW);
        self::assertIsArray($parsed, 'zz-virtusphere.ini is not parseable');
        foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
            self::assertArrayHasKey(
                $directive,
                $parsed,
                $directive . ' is no longer set in zz-virtusphere.ini; PHP would fall back to its 2M/8M defaults, and '
                    . 'the app size message would stop being the one the operator sees'
            );
        }

        return [
            'upload_max_filesize' => $this->shorthandToBytes((string) $parsed['upload_max_filesize']),
            'post_max_size' => $this->shorthandToBytes((string) $parsed['post_max_size']),
        ];
    }

    /**
     * Both places nginx learns its body limit: the shipped HTTP config and the
     * HTTPS server block the portal generates (ADR-0027). They must agree, or
     * turning HTTPS on silently changes the upload ceiling.
     *
     * @return array<string, int>
     */
    private function nginxLimits(): array
    {
        $sources = [
            'Docker/nginx/default.conf' => dirname(__DIR__, 3) . '/nginx/default.conf',
            'lib/https_config.php' => dirname(__DIR__, 2) . '/lib/https_config.php',
        ];
        $limits = [];
        foreach ($sources as $label => $path) {
            if (!is_file($path)) {
                self::markTestSkipped($label . ' is not visible from this runtime');
            }
            $found = preg_match('/client_max_body_size\s+(\d+[kKmMgG]?)\s*;/', (string) file_get_contents($path), $match);
            self::assertSame(
                1,
                $found,
                'no client_max_body_size found in ' . $label . '; nginx would fall back to its 1m default and cut off '
                    . 'uploads before PHP ever sees them'
            );
            $limits[$label] = $this->shorthandToBytes($match[1]);
        }

        return $limits;
    }

    public function testTheLimitsRiseFromTheAppOutwards(): void
    {
        $php = $this->phpIniLimits();
        $nginx = $this->nginxLimits();
        $app = VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES;

        self::assertGreaterThan(
            $app,
            $php['upload_max_filesize'],
            sprintf(
                'upload_max_filesize (%d B) must be larger than VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES (%d B): equal or '
                    . 'smaller, PHP rejects the file first and the page reports "no file selected" instead of the '
                    . 'localized size message. Raise the .ini, not this test.',
                $php['upload_max_filesize'],
                $app
            )
        );
        self::assertGreaterThan(
            $php['upload_max_filesize'],
            $php['post_max_size'],
            'post_max_size must leave room for the multipart overhead on top of upload_max_filesize'
        );
        foreach ($nginx as $label => $limit) {
            self::assertGreaterThan(
                $php['post_max_size'],
                $limit,
                'client_max_body_size in ' . $label . ' must stay above post_max_size, or nginx answers 413 before PHP '
                    . 'can see the action, the CSRF token or the payload at all'
            );
        }
    }

    /**
     * The same ladder for the machine callback (correction plan 14.8).
     *
     * `db_importMAC.php` is the one place that enforces the exact 16 MiB
     * contract and answers its JSON `request_too_large`. That only happens
     * while the layers below let the body through: above `post_max_size` PHP
     * discards it before the endpoint runs, so the uploader would get an empty
     * body and a parse error instead of the documented 413, and the operator
     * would be told the Ansible host sent nonsense. nginx must stay above PHP
     * for the same reason one step further out.
     */
    public function testTheCallbackRequestBoundIsBelowTheInfrastructureLimits(): void
    {
        $php = $this->phpIniLimits();
        $nginx = $this->nginxLimits();

        self::assertGreaterThan(
            VIRTUSPHERE_MAC_IMPORT_REQUEST_MAX_BYTES,
            $php['post_max_size'],
            sprintf(
                'post_max_size (%d B) must exceed VIRTUSPHERE_MAC_IMPORT_REQUEST_MAX_BYTES (%d B), or PHP drops a '
                    . 'legal callback body before db_importMAC.php can answer its own 413.',
                $php['post_max_size'],
                VIRTUSPHERE_MAC_IMPORT_REQUEST_MAX_BYTES
            )
        );
        foreach ($nginx as $label => $limit) {
            self::assertGreaterThan(VIRTUSPHERE_MAC_IMPORT_REQUEST_MAX_BYTES, $limit, $label);
        }
    }

    /**
     * The uploader reads at most MAX_RESPONSE_BYTES of the answer, so the
     * response bound the endpoint enforces has to be the same number. If the
     * application allowed more, the Ansible side would truncate a valid answer
     * and report a protocol error for a job that succeeded.
     */
    public function testTheUploaderResponseCapMatchesTheApplicationBound(): void
    {
        $path = dirname(__DIR__, 4) . '/Ansible/upload_mac_list.py';
        if (!is_file($path)) {
            self::markTestSkipped('Ansible/upload_mac_list.py is not visible from this runtime');
        }
        $found = preg_match(
            '/^MAX_RESPONSE_BYTES\s*=\s*(.+)$/m',
            (string) file_get_contents($path),
            $match
        );
        self::assertSame(1, $found, 'upload_mac_list.py no longer declares MAX_RESPONSE_BYTES');
        $expression = trim($match[1]);
        self::assertMatchesRegularExpression('/^[0-9 *]+$/', $expression, 'unexpected MAX_RESPONSE_BYTES expression');
        $value = array_product(array_map('intval', preg_split('/\s*\*\s*/', $expression) ?: []));
        self::assertSame(
            VIRTUSPHERE_MAC_IMPORT_RESPONSE_MAX_BYTES,
            $value,
            'the uploader read cap and VIRTUSPHERE_MAC_IMPORT_RESPONSE_MAX_BYTES must be the same number'
        );
    }

    /**
     * The other side of the ladder (SEC1, C-01). nginx buffers a request body
     * to its tmpfs before PHP runs, for any client and without a sign-in, and
     * post_max_size already discards everything above it. Each byte the nginx
     * limit sits above post_max_size is therefore memory an anonymous client can
     * occupy for nothing. It used to be 100m against a 20M PHP limit; three
     * parallel uploads were enough to push the web server over its memory limit.
     * Raising post_max_size moves the allowed nginx value with it.
     */
    public function testNginxStaysCloseAbovePostMaxSize(): void
    {
        $php = $this->phpIniLimits();
        $headroom = 4 * 1024 * 1024;
        foreach ($this->nginxLimits() as $label => $limit) {
            self::assertLessThanOrEqual(
                $php['post_max_size'] + $headroom,
                $limit,
                sprintf(
                    'client_max_body_size in %s (%d B) is more than 4 MiB above post_max_size (%d B); PHP rejects '
                        . 'that body anyway, so nginx only buffers it for an anonymous sender',
                    $label,
                    $limit,
                    $php['post_max_size']
                )
            );
        }
    }

    public function testBothNginxSourcesAgree(): void
    {
        $limits = $this->nginxLimits();

        self::assertSame(
            array_values($limits)[0],
            array_values($limits)[1],
            'the shipped HTTP config and the generated HTTPS server block disagree on client_max_body_size, so '
                . 'switching the portal to HTTPS would change the upload ceiling without anyone deciding it'
        );
    }

    /**
     * The memory side of the ceiling (B4). Each body above
     * client_body_buffer_size is spooled to client_body_temp_path before PHP
     * runs. That path must lie on a sized tmpfs of the web server (a full
     * tmpfs fails one request, an unsized one or the read-only root fails the
     * container or every upload), and the tmpfs must hold several bodies at
     * the ceiling, or concurrent legitimate uploads (MAC callbacks during a
     * deploy, a mission import) start failing once the ceiling rises. Both
     * numbers are derived here, not listed: raising post_max_size and with it
     * client_max_body_size demands the larger tmpfs in the same change.
     */
    public function testTheBodySpoolIsASizedTmpfsThatHoldsSeveralBodies(): void
    {
        $minimumBodies = 5;
        $confPath = dirname(__DIR__, 3) . '/nginx/default.conf';
        $composePath = dirname(__DIR__, 4) . '/docker-compose.yml';
        foreach ([$confPath, $composePath] as $path) {
            if (!is_file($path)) {
                self::markTestSkipped(basename($path) . ' is not visible from this runtime');
            }
        }
        $conf = (string) file_get_contents($confPath);
        $generated = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/https_config.php');

        self::assertSame(
            1,
            preg_match_all('/^client_body_temp_path\s+(\S+)\s*;/m', $conf, $paths),
            'Docker/nginx/default.conf must name client_body_temp_path exactly once, in http context, so the '
                . 'generated HTTPS block inherits it and the spool does not depend on the base image'
        );
        self::assertSame(
            0,
            preg_match('/client_body_temp_path/', $generated),
            'the generated HTTPS block must inherit the body spool from default.conf, not name its own'
        );
        self::assertSame(
            1,
            preg_match('/^client_body_buffer_size\s+(\d+[kKmM]?)\s*;/m', $conf, $buffer),
            'Docker/nginx/default.conf must name client_body_buffer_size, the in-memory part of the body budget'
        );
        self::assertLessThanOrEqual(
            64 * 1024,
            $this->shorthandToBytes($buffer[1]),
            'client_body_buffer_size keeps every body up to it in worker memory per connection; keep it small'
        );
        $spool = rtrim($paths[1][0], '/');

        $compose = (string) file_get_contents($composePath);
        self::assertSame(
            1,
            preg_match('/^  webserver:\n(.*?)(?=^  [a-z][a-z0-9_-]*:\n|\z)/ms', $compose, $service),
            'no webserver service found in docker-compose.yml'
        );
        preg_match_all('/^\s+-\s+([^\s:]+):size=(\d+[kKmMgG])\s*$/m', $service[1], $mounts, PREG_SET_ORDER);
        $tmpfsBytes = null;
        foreach ($mounts as $mount) {
            $target = rtrim($mount[1], '/');
            if ($spool === $target || str_starts_with($spool, $target . '/')) {
                $tmpfsBytes = $this->shorthandToBytes($mount[2]);
            }
        }
        self::assertNotNull(
            $tmpfsBytes,
            sprintf('client_body_temp_path %s lies on no sized tmpfs of the webserver service', $spool)
        );

        foreach ($this->nginxLimits() as $label => $limit) {
            self::assertGreaterThanOrEqual(
                $minimumBodies * $limit,
                $tmpfsBytes,
                sprintf(
                    'the body spool tmpfs (%d B) holds fewer than %d bodies at client_max_body_size in %s (%d B)',
                    $tmpfsBytes,
                    $minimumBodies,
                    $label,
                    $limit
                )
            );
        }
    }

    /**
     * The form's early-detection hint. It must sit BEFORE the file input (PHP
     * ignores it otherwise) and take its value from the constant, never from a
     * number typed into the markup.
     */
    public function testTheFormCarriesMaxFileSizeFromTheConstant(): void
    {
        $path = dirname(__DIR__, 2) . '/lib/missions_import_panel.php';
        $source = (string) file_get_contents($path);

        $hidden = strpos($source, 'name="MAX_FILE_SIZE"');
        self::assertNotFalse(
            $hidden,
            'the upload form has no MAX_FILE_SIZE field, so an oversize file is buffered to disk before PHP rejects it'
        );
        $fileInput = strpos($source, 'type="file"');
        self::assertNotFalse($fileInput, 'the upload form has no file input; this test needs re-anchoring');
        self::assertLessThan(
            $fileInput,
            $hidden,
            'MAX_FILE_SIZE must precede the file input; PHP ignores it otherwise, which is the exact failure it looks '
                . 'like it prevents'
        );

        $line = substr($source, $hidden, 200);
        self::assertStringContainsString(
            'VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES',
            $line,
            'MAX_FILE_SIZE carries a literal number instead of the constant, so it starts lying the moment the limit moves'
        );
    }

    /**
     * The hidden field is a hint, not a boundary: a client can change or drop
     * it, so the server compares the reported size against the same constant.
     */
    public function testTheServerStillChecksTheSizeItself(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/mission_import_portal.php');

        self::assertMatchesRegularExpression(
            '/\$size\s*>\s*VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES/',
            $source,
            'the server no longer compares the uploaded size against the app limit, leaving MAX_FILE_SIZE (which the '
                . 'client controls) as the only check'
        );
    }
}
