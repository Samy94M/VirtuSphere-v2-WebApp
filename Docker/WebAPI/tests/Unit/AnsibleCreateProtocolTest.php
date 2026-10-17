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
            'replaced_instance_uuid' => null,
            'precheck_power_state' => null,
        ], $overrides);
    }

    /** DF-E1: the power state of the existing own VM travels with the prepared event. */
    public function testAnExistingVmCarriesItsPowerState(): void
    {
        $marker = ansible_create_marker_parse($this->marker($this->prepared([
            'existed_before' => true,
            'precheck_moid' => 'vm-7',
            'precheck_instance_uuid' => '503c-1',
            'precheck_power_state' => 'poweredOn',
        ])));

        self::assertSame('poweredOn', $marker['precheck_power_state']);
    }

    public function testAPowerStateWithoutAnExistingVmIsRefused(): void
    {
        $this->expectException(CreateMarkerProtocolException::class);
        $this->expectExceptionMessage('precheck_power_state contradicts');
        ansible_create_marker_parse($this->marker($this->prepared(['precheck_power_state' => 'poweredOff'])));
    }

    /** DF-E1: the launch recheck refuses a VM switched on since prepare with its own closed code. */
    public function testTheLaunchRecheckHasItsOwnClosedCode(): void
    {
        $marker = ansible_create_marker_parse($this->marker([
            'event' => VIRTUSPHERE_CREATE_EVENT_REJECTED,
            'portal_vm_id' => 5,
            'vm_name' => 'VM',
            'error_code' => 'vm_not_powered_off',
            'error' => 'the existing VM is not powered off',
        ]));

        self::assertSame('vm_not_powered_off', $marker['error_code']);
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

    /** The error limit counts characters, as the emitter does (AN-10). */
    public function testAFullLengthErrorTextWithUmlautsIsAcceptedAndOneMoreCharacterIsRefused(): void
    {
        $marker = ansible_create_marker_parse($this->marker([
            'event' => VIRTUSPHERE_CREATE_EVENT_FAILED,
            'portal_vm_id' => 5,
            'async_jid' => '123456789.4711',
            'error' => str_repeat('ä', 1024),
        ]));
        self::assertSame(str_repeat('ä', 1024), $marker['error']);

        $this->expectException(CreateMarkerProtocolException::class);
        ansible_create_marker_parse($this->marker([
            'event' => VIRTUSPHERE_CREATE_EVENT_FAILED,
            'portal_vm_id' => 5,
            'async_jid' => '123456789.4711',
            'error' => str_repeat('ä', 1025),
        ]));
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

    /** IDR-P02: the proven-absent binding travels with the prepared event. */
    public function testAProvenAbsentBindingIsAccepted(): void
    {
        $marker = ansible_create_marker_parse($this->marker($this->prepared([
            'replaced_instance_uuid' => '52677625-4986-ed5b-89b5-ee9597f0f4cb',
        ])));

        self::assertSame('52677625-4986-ed5b-89b5-ee9597f0f4cb', $marker['replaced_instance_uuid']);
    }

    /** A VM that was found cannot at the same time have its binding proven absent. */
    public function testAReplacementClaimNextToAnExistingVmIsRefused(): void
    {
        $this->expectException(CreateMarkerProtocolException::class);
        $this->expectExceptionMessage('replaced_instance_uuid contradicts');
        ansible_create_marker_parse($this->marker($this->prepared([
            'existed_before' => true,
            'precheck_moid' => 'vm-7',
            'precheck_instance_uuid' => '503c-1',
            'replaced_instance_uuid' => '503c-2',
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

    /**
     * AN-10: the closed error codes are written once in the emitter and once in
     * PHP. Both sets must be equal, with one explicit exception:
     * host_identity_rejected is a worker-only code. The worker refuses a host
     * identity before any create unit has a marker, so the emitter never emits
     * it and must not know it.
     */
    public function testTheClosedErrorCodesMatchThePythonEmitterExceptTheWorkerOnlyCode(): void
    {
        $emitter = $this->emitterSource();
        self::assertSame(
            1,
            preg_match('/^ERROR_CODES = \(\R(.*?)^\)/ms', $emitter, $block),
            'the emitter declares no ERROR_CODES tuple'
        );
        preg_match_all('/"(\w+)"/', $block[1], $names);
        $python = $names[1];
        self::assertNotSame([], $python, 'the ERROR_CODES tuple names no code');
        self::assertSame(array_values(array_unique($python)), $python, 'the emitter lists a code twice');

        $workerOnly = [VIRTUSPHERE_CREATE_ERROR_HOST_IDENTITY_REJECTED];
        self::assertSame(
            $workerOnly,
            array_values(array_diff(VIRTUSPHERE_CREATE_ERROR_CODES, $python)),
            'PHP knows an error code the emitter does not, other than the worker-only host_identity_rejected'
        );
        self::assertSame(
            [],
            array_values(array_diff($python, VIRTUSPHERE_CREATE_ERROR_CODES)),
            'the emitter emits an error code PHP refuses'
        );
    }

    /**
     * AN-10: the character classes and the length limits of the marker fields
     * exist once in the emitter (re.compile and MAX_* constants) and once in PHP.
     * Every emitter pattern must appear in PHP unchanged, and the two name and
     * error limits must agree.
     */
    public function testTheMarkerCharacterClassesAndLimitsMatchThePythonEmitter(): void
    {
        $emitter = $this->emitterSource();
        preg_match_all('/^(\w+)_PATTERN = re\.compile\(r"([^"]+)"\)\s*$/m', $emitter, $patterns, PREG_SET_ORDER);
        $python = [];
        foreach ($patterns as $match) {
            $python[$match[1]] = $match[2];
        }
        self::assertSame(['JID', 'MOID', 'UUID', 'POWER_STATE'], array_keys($python), 'the emitter pattern set changed');

        $php = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/ansible_create_protocol.php')
            . (string) file_get_contents(dirname(__DIR__, 2) . '/lib/deploy_create_constants.php');
        foreach ($python as $name => $pattern) {
            self::assertStringContainsString(
                "'/" . $pattern . "/'",
                $php,
                $name . ' has another character class in PHP than in emit_create_result.py'
            );
        }

        self::assertSame(1, preg_match('/^MAX_NAME_LENGTH = (\d+)\s*$/m', $emitter, $nameLimit), 'the emitter declares no MAX_NAME_LENGTH');
        self::assertSame(1, preg_match('/^MAX_ERROR_LENGTH = (\d+)\s*$/m', $emitter, $errorLimit), 'the emitter declares no MAX_ERROR_LENGTH');
        $protocol = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/ansible_create_protocol.php');
        self::assertSame(1, preg_match("/'vm_name' => ansible_create_marker_text\\(\\\$field, \\\$value, (\\d+)\\)/", $protocol, $phpName));
        self::assertSame(1, preg_match("/'error' => ansible_create_marker_text\\(\\\$field, \\\$value, (\\d+)\\)/", $protocol, $phpError));
        self::assertSame((int) $nameLimit[1], (int) $phpName[1], 'the vm_name limit differs between PHP and the emitter');
        self::assertSame((int) $errorLimit[1], (int) $phpError[1], 'the error limit differs between PHP and the emitter');
    }

    private function emitterSource(): string
    {
        $emitter = (string) file_get_contents(dirname(__DIR__, 4) . '/Ansible/emit_create_result.py');
        self::assertNotSame('', $emitter, 'the emitter must be readable from the repo root');

        return $emitter;
    }
}
