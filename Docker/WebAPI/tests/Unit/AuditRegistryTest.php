<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/audit_registry.php';
require_once dirname(__DIR__, 2) . '/lib/audit_presenter.php';

/**
 * The registry is the whole Etappe 10C contract: everything a persisted event
 * may be is decided here, and a producer cannot widen it.
 *
 * The tests below are deliberately about the REFUSALS. A registry that accepts
 * what it should accept is easy to build and easy to keep; one that refuses
 * what it should refuse is what makes "this context cannot contain a secret" a
 * statement about the system rather than about the last person who reviewed a
 * call site.
 */
final class AuditRegistryTest extends TestCase
{
    /**
     * Every declared event resolves end to end, for every object type and every
     * result it declares, and renders a non-empty description.
     *
     * This is the walk that a new event cannot skip. Its predecessor was a hand
     * written list of the interesting events, which meant the interesting ones
     * were exactly the ones already known to work.
     */
    public function testEveryRegisteredEventResolvesAndRenders(): void
    {
        $registry = audit_event_registry();
        self::assertNotEmpty($registry, 'the registry scan matched nothing');

        foreach ($registry as $code => $entry) {
            self::assertNotEmpty($entry['objects'], $code . ' declares no object type');
            self::assertNotEmpty($entry['results'], $code . ' declares no result');
            foreach ($entry['results'] as $result) {
                self::assertContains($result, VIRTUSPHERE_AUDIT_RESULTS, $code . ' declares a result outside the closed set');
            }

            foreach ($entry['objects'] as $objectType) {
                foreach ($entry['results'] as $result) {
                    foreach ($this->objectIdsFor($entry) as $objectId) {
                        $definition = audit_event_definition($code, $objectType, $objectId, $result);
                        self::assertContains(
                            $definition['category'],
                            VIRTUSPHERE_LOG_CATEGORIES,
                            $code . ' resolves to a category outside the taxonomy'
                        );

                        $context = audit_context_normalize($this->minimalContext($definition, $result), $definition, $result);
                        $description = audit_event_description($code, $objectType, $objectId, $result, $context);
                        self::assertNotSame('', $description, $code . ' renders an empty description');
                        self::assertLessThanOrEqual(
                            VIRTUSPHERE_AUDIT_MESSAGE_MAX_BYTES,
                            strlen($description),
                            $code . ' renders past the message byte cap'
                        );
                    }
                }
            }
        }
    }

    public function testUnknownEventCodeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        audit_event_definition('auth.definitely_not_an_event', 'user', '1', VIRTUSPHERE_AUDIT_RESULT_SUCCESS);
    }

    public function testUnknownObjectTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGOUT, 'spaceship', '1', VIRTUSPHERE_AUDIT_RESULT_SUCCESS);
    }

    public function testResultOutsideTheEventsOwnSetIsRefused(): void
    {
        // `success` is a valid result, just not one this event may carry: a
        // refused sign-in that recorded itself as a success would be the single
        // most misleading row the audit trail could hold.
        $this->expectException(InvalidArgumentException::class);
        audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_AUTH_ACCOUNT_LOCKED, 'user', '1', VIRTUSPHERE_AUDIT_RESULT_SUCCESS);
    }

    public function testObjectIdOutsideAClosedAllowlistIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_DENIED, 'machine_endpoint', 'wp-login.php', VIRTUSPHERE_AUDIT_RESULT_DENIED);
    }

    public function testMissingObjectIdIsRefusedWhereItIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_USER_ROLE_CHANGED, 'user', null, VIRTUSPHERE_AUDIT_RESULT_SUCCESS);
    }

    public function testMissingObjectIdIsAcceptedWhereTheEventDeclaresItNullable(): void
    {
        // A rejected sign-in for a username that matches no account has no user
        // id to name, and refusing the row would lose the very event that says
        // somebody is guessing account names.
        $definition = audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN, 'user', null, VIRTUSPHERE_AUDIT_RESULT_DENIED);
        self::assertSame(VIRTUSPHERE_LOG_CATEGORY_AUTH, $definition['category']);
    }

    /**
     * Over-length is a refusal, not a cut. A truncated id silently points at a
     * different object than the one the event happened to.
     */
    public function testOverlongObjectIdIsRefusedRatherThanTruncated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        audit_object_id(str_repeat('a', VIRTUSPHERE_AUDIT_OBJECT_ID_MAX_BYTES + 1));
    }

    public function testObjectIdAtTheLimitIsAccepted(): void
    {
        $id = str_repeat('a', VIRTUSPHERE_AUDIT_OBJECT_ID_MAX_BYTES);
        self::assertSame($id, audit_object_id($id));
    }

    public function testIdentifierObjectIdRefusesFreeText(): void
    {
        $this->expectException(InvalidArgumentException::class);
        audit_object_id('vlan with spaces');
    }

    /**
     * The one event whose object is a name. It must accept a space, and it must
     * still refuse a line break: an id that can carry a newline can splice a
     * forged line into a log export.
     */
    public function testNameObjectIdAcceptsSpacesAndRefusesControlCharacters(): void
    {
        self::assertSame('VLAN 42 DMZ', audit_object_id('  VLAN  42   DMZ ', 'name'));

        $this->expectException(InvalidArgumentException::class);
        audit_object_id("VLAN\x0042", 'name');
    }

    public function testEventDeclaringANameObjectIdIsTheVlanReassignAlone(): void
    {
        $named = [];
        foreach (audit_event_registry() as $code => $entry) {
            if (($entry['objectIdKind'] ?? 'identifier') === 'name') {
                $named[] = $code;
            }
        }

        self::assertSame([VIRTUSPHERE_AUDIT_EVENT_DEPLOY_VLAN_REASSIGNED], $named);
    }

    public function testMissingRequiredContextFieldIsRefused(): void
    {
        $definition = audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_USER_ROLE_CHANGED, 'user', '1', VIRTUSPHERE_AUDIT_RESULT_SUCCESS);

        $this->expectException(InvalidArgumentException::class);
        audit_context_normalize([], $definition, VIRTUSPHERE_AUDIT_RESULT_SUCCESS);
    }

    public function testFieldRequiredOnlyByOneResultIsEnforcedForThatResult(): void
    {
        $definition = audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN, 'user', '1', VIRTUSPHERE_AUDIT_RESULT_DENIED);

        // Accepted without a reason when the login succeeded ...
        $ok = audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN, 'user', '1', VIRTUSPHERE_AUDIT_RESULT_SUCCESS);
        self::assertSame(
            ['source' => 'local'],
            audit_context_normalize(['source' => 'local'], $ok, VIRTUSPHERE_AUDIT_RESULT_SUCCESS)
        );

        // ... and refused without one when it was denied, because a refusal
        // that does not say why is not evidence of anything.
        $this->expectException(InvalidArgumentException::class);
        audit_context_normalize(['source' => 'local'], $definition, VIRTUSPHERE_AUDIT_RESULT_DENIED);
    }

    public function testUnknownContextFieldIsRefused(): void
    {
        $definition = audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_USER_ROLE_CHANGED, 'user', '1', VIRTUSPHERE_AUDIT_RESULT_SUCCESS);

        $this->expectException(InvalidArgumentException::class);
        audit_context_normalize(['role' => 'admin', 'smuggled' => 'x'], $definition, VIRTUSPHERE_AUDIT_RESULT_SUCCESS);
    }

    /**
     * A field the event does not declare is refused even when the field
     * registry knows its type. Allowed-anywhere would make the per-event lists
     * documentation rather than a boundary.
     */
    public function testKnownFieldOnAnEventThatDoesNotDeclareItIsRefused(): void
    {
        $definition = audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_USER_ROLE_CHANGED, 'user', '1', VIRTUSPHERE_AUDIT_RESULT_SUCCESS);

        $this->expectException(InvalidArgumentException::class);
        audit_context_normalize(['role' => 'admin', 'permission' => 'users.manage'], $definition, VIRTUSPHERE_AUDIT_RESULT_SUCCESS);
    }

    /**
     * An explicit null is not "omitted". Accepting it would put a key in the
     * JSON whose value says nothing, and a reader cannot tell that apart from a
     * producer that meant to send something and computed nothing.
     */
    public function testExplicitNullIsRefusedRatherThanTreatedAsAbsent(): void
    {
        $definition = audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_USER_CREATED, 'user', '1', VIRTUSPHERE_AUDIT_RESULT_SUCCESS);

        $this->expectException(InvalidArgumentException::class);
        audit_context_normalize(['source' => 'local', 'name' => null], $definition, VIRTUSPHERE_AUDIT_RESULT_SUCCESS);
    }

    public function testWrongTypeIsRefusedRatherThanCoerced(): void
    {
        $definition = audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_AUTH_ACCOUNT_LOCKED, 'user', '1', VIRTUSPHERE_AUDIT_RESULT_DENIED);

        $this->expectException(InvalidArgumentException::class);
        audit_context_normalize(
            ['duration_minutes' => '15', 'failure_count' => 5],
            $definition,
            VIRTUSPHERE_AUDIT_RESULT_DENIED
        );
    }

    /**
     * A typed list must be a list. An associative array would serialise as a
     * JSON object where every reader expects an array, and a cast would have
     * turned a mistake into a plausible-looking value instead.
     */
    public function testTypedListRefusesAMapAndAnOverlongList(): void
    {
        $definition = audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_VM_BULK_CHANGED, 'mission', '1', VIRTUSPHERE_AUDIT_RESULT_SUCCESS);
        $base = ['action' => 'bulk_deleted', 'affected_count' => 2];

        $map = null;
        try {
            audit_context_normalize([...$base, 'vm_ids' => ['a' => 1]], $definition, VIRTUSPHERE_AUDIT_RESULT_SUCCESS);
        } catch (InvalidArgumentException $exception) {
            $map = $exception;
        }
        self::assertInstanceOf(InvalidArgumentException::class, $map, 'an associative array passed as a typed list');

        $this->expectException(InvalidArgumentException::class);
        audit_context_normalize(
            [...$base, 'vm_ids' => range(1, VIRTUSPHERE_AUDIT_ID_LIST_MAX + 1)],
            $definition,
            VIRTUSPHERE_AUDIT_RESULT_SUCCESS
        );
    }

    /**
     * Every secret-shaped field name is refused by the second, name-based gate,
     * independently of whether any event happens to declare it. Two independent
     * reasons to refuse means neither one alone has to be complete.
     */
    public function testSecretShapedContextKeysAreRefusedByName(): void
    {
        $definition = audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_USER_ROLE_CHANGED, 'user', '1', VIRTUSPHERE_AUDIT_RESULT_SUCCESS);
        $keys = ['password', 'api_token', 'client_secret', 'raw_payload', 'exception_message', 'search_term', 'authorization', 'session_id'];

        $refused = [];
        foreach ($keys as $key) {
            try {
                audit_context_normalize(['role' => 'admin', $key => 'x'], $definition, VIRTUSPHERE_AUDIT_RESULT_SUCCESS);
            } catch (InvalidArgumentException) {
                $refused[] = $key;
            }
        }

        self::assertSame($keys, $refused, 'the context accepted a forbidden key');
    }

    /** A context value is redacted before it can be stored, not on display. */
    public function testContextStringsAreRedactedOnTheWayIn(): void
    {
        $definition = audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_DEPLOY_OUTCOME, 'deploy_job', '9', VIRTUSPHERE_AUDIT_RESULT_FAILURE);
        $context = audit_context_normalize(
            ['mission_id' => 1, 'mode' => 'full', 'status' => 'failed', 'reason' => 'curl failed: Authorization: Bearer sekrit-value'],
            $definition,
            VIRTUSPHERE_AUDIT_RESULT_FAILURE
        );

        self::assertStringNotContainsString('sekrit-value', $context['reason']);
        self::assertStringContainsString('[redacted]', $context['reason']);
    }

    public function testContextJsonIsAStableObjectUnderTheByteCap(): void
    {
        $definition = audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_USER_ACTIVE_CHANGED, 'user', '1', VIRTUSPHERE_AUDIT_RESULT_SUCCESS);
        $json = audit_context_json(audit_context_normalize(['enabled' => true], $definition, VIRTUSPHERE_AUDIT_RESULT_SUCCESS));

        self::assertSame('{"enabled":true}', $json);
        self::assertLessThanOrEqual(VIRTUSPHERE_AUDIT_CONTEXT_MAX_BYTES, strlen((string) $json));
        self::assertNull(audit_context_json([]), 'an empty context is stored as NULL, not as "{}"');
    }

    /**
     * The application-side cap is measured on the bytes that are actually
     * stored, which is the same expression the database CHECK evaluates. The
     * three values around the limit are pinned here and, against a real MySQL,
     * in the integration suite.
     */
    public function testContextJsonRefusesExactlyAboveTheByteLimit(): void
    {
        $overhead = strlen('{"reason":""}');
        $atLimit = str_repeat('a', VIRTUSPHERE_AUDIT_CONTEXT_MAX_BYTES - $overhead);

        self::assertSame(
            VIRTUSPHERE_AUDIT_CONTEXT_MAX_BYTES,
            strlen((string) audit_context_json(['reason' => $atLimit])),
            '4096 bytes must still be storable'
        );
        self::assertSame(
            VIRTUSPHERE_AUDIT_CONTEXT_MAX_BYTES - 1,
            strlen((string) audit_context_json(['reason' => substr($atLimit, 1)]))
        );

        $this->expectException(LengthException::class);
        audit_context_json(['reason' => $atLimit . 'a']);
    }

    /**
     * An integration source outside the closed map is refused. The predecessor
     * resolved "not maintenance" to `mecm`, so the first non-MECM source added
     * would have filed its outage in the tab an operator opens to read MECM
     * sync results.
     */
    public function testUnknownIntegrationSourceIsRefusedInsteadOfDefaultingToMecm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_INTEGRATION_STATE, 'integration_source', 'some-future-worker', VIRTUSPHERE_AUDIT_RESULT_WARNING);
    }

    public function testIntegrationSourcesResolveToTheirOwnCategory(): void
    {
        foreach (audit_integration_category_map() as $source => $expected) {
            $definition = audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_INTEGRATION_STATE, 'integration_source', $source, VIRTUSPHERE_AUDIT_RESULT_WARNING);
            self::assertSame($expected, $definition['category'], $source . ' resolved to the wrong category');
        }
    }

    /**
     * A rejected password change must not be filed under the code that means a
     * password WAS changed. The neutral attempt code plus the result is the
     * only shape in which both outcomes can share one event.
     */
    public function testTheRejectedPasswordChangeDoesNotClaimAChangeHappened(): void
    {
        $codes = array_keys(audit_event_registry());
        self::assertNotContains('auth.password_changed', $codes);
        self::assertContains(VIRTUSPHERE_AUDIT_EVENT_AUTH_PASSWORD_CHANGE_ATTEMPT, $codes);

        $rejected = audit_event_description(
            VIRTUSPHERE_AUDIT_EVENT_AUTH_PASSWORD_CHANGE_ATTEMPT,
            'user',
            '3',
            VIRTUSPHERE_AUDIT_RESULT_DENIED,
            ['scope' => 'own', 'reason' => 'current password did not match']
        );
        self::assertStringContainsString('rejected', $rejected);
        self::assertStringNotContainsString('changed own password change', $rejected);
    }

    /**
     * A cancel that was only REQUESTED and a job that was actually cancelled are
     * different facts. A running job keeps changing ESXi after the request is
     * accepted (ADR-0033), so one code for both would let the audit trail say a
     * job stopped at a moment when it demonstrably had not.
     */
    public function testCancelRequestAndCancellationAreSeparateEvents(): void
    {
        $registry = audit_event_registry();
        self::assertArrayHasKey(VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CANCEL_REQUESTED, $registry);
        self::assertArrayHasKey(VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CANCELLED, $registry);
        self::assertNotSame(VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CANCEL_REQUESTED, VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CANCELLED);

        // The request is about one job; a group cancel concludes several.
        self::assertSame(['deploy_job'], $registry[VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CANCEL_REQUESTED]['objects']);
        self::assertSame(['deploy_job', 'deploy_group'], $registry[VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CANCELLED]['objects']);

        self::assertStringContainsString(
            'requested cancel',
            audit_event_description(VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CANCEL_REQUESTED, 'deploy_job', '5', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [])
        );
    }

    /** Required and optional field lists may not overlap, in any event. */
    public function testRequiredAndOptionalContextFieldsStayDisjoint(): void
    {
        foreach (audit_event_registry() as $code => $entry) {
            self::assertSame(
                [],
                array_intersect($entry['required'], $entry['optional']),
                $code . ' lists a field as both required and optional'
            );
        }
    }

    /** Every field any event names must exist in the typed field registry. */
    public function testEveryDeclaredContextFieldIsTyped(): void
    {
        $fields = audit_context_field_registry();
        foreach (audit_event_registry() as $code => $entry) {
            $declared = [...$entry['required'], ...$entry['optional']];
            foreach ($entry['requiredByResult'] as $conditional) {
                $declared = [...$declared, ...$conditional];
            }
            foreach ($declared as $field) {
                self::assertArrayHasKey($field, $fields, $code . ' names the untyped context field ' . $field);
            }
        }
    }

    /** @param array<string,mixed> $entry @return list<string|null> */
    private function objectIdsFor(array $entry): array
    {
        if ($entry['objectIds'] !== []) {
            return $entry['objectIds'];
        }

        return ($entry['objectIdKind'] ?? 'identifier') === 'name' ? ['VLAN 42'] : ['42'];
    }

    /** @param array<string,mixed> $definition @return array<string,mixed> */
    private function minimalContext(array $definition, string $result): array
    {
        $fields = audit_context_field_registry();
        $context = [];
        foreach ([...$definition['required'], ...($definition['requiredByResult'][$result] ?? [])] as $field) {
            $context[$field] = match ($fields[$field]['type']) {
                'int' => 1,
                'bool' => true,
                'identifier', 'string' => 'probe',
                'hex' => str_repeat('a', (int) ($fields[$field]['length'] ?? 64)),
                'int_list' => [1, 2],
                'string_list' => ['probe'],
                default => self::fail('untyped context field ' . $field),
            };
        }

        return $context;
    }
}
