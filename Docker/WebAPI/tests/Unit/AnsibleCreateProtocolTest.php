<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible_create_protocol.php';

/**
 * The reading half of the per-VM create marker (Etappe 14B).
 *
 * A marker is a statement about a real VM on a real host, so the interesting
 * cases are all refusals: an event nobody declared, a field that arrived twice
 * as much or once too little, an id that could reach a shell, and the two
 * failure shapes that would silently pick a winner - no marker at all, and two
 * markers claiming the same unit.
 */
final class AnsibleCreateProtocolTest extends TestCase
{
    /** @param array<string, mixed> $payload */
    private function marker(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return VIRTUSPHERE_CREATE_MARKER_PREFIX . ' v' . VIRTUSPHERE_CREATE_PROTOCOL_VERSION . ' '
            . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private function prepared(array $overrides = []): array
    {
        return array_merge([
            'event' => VIRTUSPHERE_CREATE_EVENT_PREPARED,
            'portal_vm_id' => 123,
            'vm_name' => 'ATeP04-001',
            'existed_before' => false,
            'precheck_moid' => null,
            'precheck_instance_uuid' => null,
        ], $overrides);
    }

    public function testAValidMarkerSurvivesTheRoundTrip(): void
    {
        $output = "TASK [something] ***\nok: [localhost]\n" . $this->marker($this->prepared()) . "\nPLAY RECAP ***\n";
        $marker = ansible_create_marker_extract($output);

        self::assertSame(VIRTUSPHERE_CREATE_EVENT_PREPARED, $marker['event']);
        self::assertSame(123, $marker['portal_vm_id']);
        self::assertSame('ATeP04-001', $marker['vm_name']);
        self::assertFalse($marker['existed_before']);
        self::assertNull($marker['precheck_moid']);
    }

    public function testNoMarkerAndTwoMarkersAreBothProtocolErrors(): void
    {
        $this->expectException(CreateMarkerProtocolException::class);
        ansible_create_marker_extract("TASK [something] ***\nok: [localhost]\n");
    }

    public function testTwoMarkersAreRefusedInsteadOfPickingTheLastOne(): void
    {
        $output = $this->marker($this->prepared()) . "\n" . $this->marker($this->prepared(['portal_vm_id' => 999]));

        $this->expectException(CreateMarkerProtocolException::class);
        $this->expectExceptionMessage('2 create markers');
        ansible_create_marker_extract($output);
    }

    public function testAnUnsupportedProtocolVersionIsRefusedRatherThanGuessed(): void
    {
        $line = str_replace(' v1 ', ' v2 ', $this->marker($this->prepared()));

        $this->expectException(CreateMarkerProtocolException::class);
        $this->expectExceptionMessage('Unsupported create protocol version');
        ansible_create_marker_parse($line);
    }

    public function testAnUnknownEventIsRefused(): void
    {
        $this->expectException(CreateMarkerProtocolException::class);
        $this->expectExceptionMessage('Unknown create event');
        ansible_create_marker_parse($this->marker(['event' => 'almost_done', 'portal_vm_id' => 1]));
    }

    public function testAnExtraOrMissingFieldIsRefused(): void
    {
        try {
            ansible_create_marker_parse($this->marker($this->prepared(['power_state' => 'poweredOn'])));
            self::fail('an extra field must be refused');
        } catch (CreateMarkerProtocolException $exception) {
            self::assertStringContainsString('Unexpected', $exception->getMessage());
        }

        $missing = $this->prepared();
        unset($missing['precheck_moid']);
        $this->expectException(CreateMarkerProtocolException::class);
        $this->expectExceptionMessage('Missing');
        ansible_create_marker_parse($this->marker($missing));
    }

    public function testAJobIdThatCouldReachAShellIsRefused(): void
    {
        foreach (['123; rm -rf /', '12 34', '../../etc/passwd', '', str_repeat('9', 192)] as $jid) {
            try {
                ansible_create_marker_parse($this->marker([
                    'event' => VIRTUSPHERE_CREATE_EVENT_RUNNING,
                    'portal_vm_id' => 5,
                    'async_jid' => $jid,
                ]));
                self::fail('a malformed async job id must be refused: ' . $jid);
            } catch (CreateMarkerProtocolException $exception) {
                self::assertStringContainsString('async_jid', $exception->getMessage());
            }
        }
    }

    public function testAHalfIdentityIsRefused(): void
    {
        $this->expectException(CreateMarkerProtocolException::class);
        $this->expectExceptionMessage('existed_before contradicts');
        ansible_create_marker_parse($this->marker($this->prepared([
            'existed_before' => true,
            'precheck_moid' => 'vm-7',
        ])));
    }

    public function testAnErrorCodeMustComeFromTheClosedSet(): void
    {
        $payload = [
            'event' => VIRTUSPHERE_CREATE_EVENT_REJECTED,
            'portal_vm_id' => 5,
            'vm_name' => 'VM',
            'error_code' => 'the module said something red',
            'error' => 'detail',
        ];
        try {
            ansible_create_marker_parse($this->marker($payload));
            self::fail('a free error code must be refused');
        } catch (CreateMarkerProtocolException $exception) {
            self::assertStringContainsString('closed codes', $exception->getMessage());
        }

        $payload['error_code'] = VIRTUSPHERE_CREATE_ERROR_IDENTITY_CONFLICT;
        self::assertSame(
            VIRTUSPHERE_CREATE_ERROR_IDENTITY_CONFLICT,
            ansible_create_marker_parse($this->marker($payload))['error_code']
        );
    }

    public function testAMarkerIsNotReconstructedFromOrdinaryAnsibleProse(): void
    {
        // A line that mentions the prefix inside a task name must not become a
        // marker; only a line that IS one counts.
        $output = "TASK [Write ::virtusphere-create:: result] ***\nok: [localhost]\n";

        $this->expectException(CreateMarkerProtocolException::class);
        $this->expectExceptionMessage('no create marker');
        ansible_create_marker_extract($output);
    }

    public function testAMarkerForAnotherUnitDoesNotMatch(): void
    {
        $marker = ansible_create_marker_parse($this->marker([
            'event' => VIRTUSPHERE_CREATE_EVENT_SUCCEEDED,
            'portal_vm_id' => 123,
            'vm_name' => 'ATeP04-001',
            'async_jid' => '747689456876.6394',
            'changed' => true,
            'moid' => 'vm-123',
            'instance_uuid' => '503c9ab1-0000-0000-0000-000000000001',
            'power_state' => 'poweredOff',
        ]));

        self::assertTrue(ansible_create_marker_matches($marker, 123, 'ATeP04-001', '747689456876.6394'));
        self::assertFalse(ansible_create_marker_matches($marker, 124, 'ATeP04-001', '747689456876.6394'));
        self::assertFalse(ansible_create_marker_matches($marker, 123, 'ATeP04-002', '747689456876.6394'));
        // A stale result file from the previous unit of the same VM.
        self::assertFalse(ansible_create_marker_matches($marker, 123, 'ATeP04-001', '111111111111.1111'));
    }

    public function testTheEventFieldTableMatchesThePythonEmitter(): void
    {
        // The two halves are one contract. If they drift, one side emits what
        // the other refuses, and the failure surfaces on a real host instead of
        // here.
        $emitter = (string) file_get_contents(dirname(__DIR__, 4) . '/Ansible/emit_create_result.py');
        self::assertNotSame('', $emitter, 'the emitter must be readable from the repo root');

        foreach (VIRTUSPHERE_CREATE_EVENT_FIELDS as $event => $fields) {
            self::assertMatchesRegularExpression(
                '/"' . preg_quote($event, '/') . '":\s*\(([^)]*)\)/',
                $emitter,
                'the emitter declares no field list for ' . $event
            );
            preg_match('/"' . preg_quote($event, '/') . '":\s*\(([^)]*)\)/', $emitter, $matches);
            preg_match_all('/"(\w+)"/', $matches[1], $names);
            self::assertSame($fields, $names[1], 'field list drift for event ' . $event);
        }
    }
}
