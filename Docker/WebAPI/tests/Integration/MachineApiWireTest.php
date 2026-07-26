<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ClientIpAllowlist.php';

final class MachineApiWireTest extends TestCase
{
    use ClientIpAllowlist;

    protected function setUp(): void
    {
        $health = @file_get_contents(virtusphere_test_base_url() . '/portal/health.php');
        if ($health === false) {
            self::markTestSkipped('VirtuSphere test stack is not reachable.');
        }
    }

    protected function tearDown(): void
    {
        $this->restoreClientIpAllowlistIfTouched();
    }

    public function testInvalidMacKeepsMachineApiWireEnvelope(): void
    {
        [$status, $headers, $body] = $this->get('/mecm-api.php?action=getDeviceInfos&mac=not-a-mac');

        self::assertSame(400, $status);
        self::assertStringContainsString('application/json', strtolower($headers));
        self::assertSame(['error' => 'Invalid MAC address'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testForbiddenEnvelopeIncludesClientIp(): void
    {
        // Zustand herstellen statt skippen (ADR-0015-Ergaenzung): der Test
        // behauptet die 403-Envelope, also entfernt er die eigene IP aus der
        // Allowlist; tearDown stellt den Ausgangszustand wieder her.
        $this->ensureClientIpNotAllowlisted(db(true));

        [$status, $headers, $body] = $this->get('/mecm-api.php?action=getDeviceList');

        self::assertSame(403, $status);
        self::assertStringContainsString('application/json', strtolower($headers));
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $payload);
        self::assertStringStartsWith('Zugriff verweigert. Ihre IP: ', (string) $payload['error']);
    }

    public function testSeparatorAndCaseVariantsAreNeverRejectedAsInvalid(): void
    {
        // E2: any FILTER_VALIDATE_MAC-valid notation must reach the lookup
        // (403 or data), never the 400 invalid-MAC envelope.
        foreach (['00-50-56-aa-bb-cc', '00:50:56:aa:bb:cc', '0050.56aa.bbcc'] as $mac) {
            [$status] = $this->get('/mecm-api.php?action=getDeviceInfos&mac=' . urlencode($mac));
            self::assertNotSame(400, $status, $mac);
        }
    }

    public function testWrongMethodIs405ButUnknownActionIs400(): void
    {
        // db_importMAC.php and mecm_updateid.php must keep the two cases
        // apart like mecm-api.php and mecm_report.php do: a wrong HTTP method
        // answers 405, an unknown action answers the 400 invalid-action
        // envelope. Both gates sit behind the IP allowlist, also traegt sich
        // der Test selbst ein statt zu skippen (ADR-0015-Ergaenzung).
        $this->ensureClientIpAllowlisted(db(true));

        foreach (['/db_importMAC.php?action=updateInterface', '/mecm_updateid.php?action=updateDevice'] as $path) {
            [$status, , $body] = $this->get($path);
            self::assertSame(405, $status, $path);
            self::assertSame(['error' => 'Method not allowed'], json_decode($body, true, 512, JSON_THROW_ON_ERROR), $path);
        }

        foreach (['/db_importMAC.php?action=nope', '/mecm_updateid.php?action=nope'] as $path) {
            [$status, , $body] = $this->post($path, ['probe' => true]);
            self::assertSame(400, $status, $path);
            self::assertSame(['message' => 'Invalid action specified'], json_decode($body, true, 512, JSON_THROW_ON_ERROR), $path);
        }
    }

    /**
     * A ResourceID reported for a VM id that does not exist is a failure, and the
     * wire now says so.
     *
     * It answered 200 "Data updated successfully" for it. The device-sync reads
     * that as done and never reports the device again, for a row somebody deleted
     * in the portal: the machine sat in MECM with nothing in VirtuSphere pointing
     * at it, and no log line anywhere named the mismatch. This is a deliberate
     * wire-behaviour change, and the PowerShell side already treats a non-2xx as
     * a failure it counts and reports (resource_update_failed).
     */
    public function testAResourceIdForAnUnknownVmIsRejectedInsteadOfConfirmed(): void
    {
        $this->ensureClientIpAllowlisted(db(true));

        [$status, $headers, $body] = $this->post('/mecm_updateid.php?action=updateDevice', [
            'deviceName' => 'phpunit-does-not-exist',
            'deviceResourceID' => '16777999',
            'deviceid' => 999000111,
        ]);

        self::assertSame(404, $status, $body);
        self::assertStringContainsString('application/json', strtolower($headers));
        self::assertSame(['error' => 'Unknown VM id'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    /** The two input rejections keep their frozen 400 envelope. */
    public function testMissingResourceIdOrVmIdKeepsTheInvalidDataEnvelope(): void
    {
        $this->ensureClientIpAllowlisted(db(true));

        foreach ([['deviceResourceID' => '', 'deviceid' => 1], ['deviceResourceID' => '4711', 'deviceid' => 0]] as $payload) {
            [$status, , $body] = $this->post('/mecm_updateid.php?action=updateDevice', $payload);
            self::assertSame(400, $status, $body);
            self::assertSame(['error' => 'Invalid data format'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * @return array{0:int,1:string,2:string}
     */
    private function post(string $path, array $payload): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);

        return $this->request($path, $context);
    }

    /**
     * @return array{0:int,1:string,2:string}
     */
    private function get(string $path): array
    {
        $context = stream_context_create([
            'http' => [
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);

        return $this->request($path, $context);
    }

    /**
     * @param resource $context
     * @return array{0:int,1:string,2:string}
     */
    private function request(string $path, $context): array
    {
        $body = @file_get_contents(virtusphere_test_base_url() . $path, false, $context);
        if ($body === false) {
            self::markTestSkipped('VirtuSphere test endpoint is not reachable.');
        }

        $status = 0;
        $headers = [];
        foreach (($http_response_header ?? []) as $header) {
            $headers[] = $header;
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return [$status, implode("\n", $headers), $body];
    }
}
