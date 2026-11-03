<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/permissions.php';
require_once dirname(__DIR__, 2) . '/lib/audit_registry.php';

/**
 * The refusal path writes an audit row before it exits, so a permission name it
 * cannot represent turns a 403 into a 500 - an unauthenticated crash on a page
 * that was correctly refusing access.
 *
 * That is not hypothetical. `system_status.php` refuses its VLAN reassign with
 * `missions.write+vms.write`, a composite of the two rights the action needs;
 * the `+` is in the identifier charset for exactly this reason, and this test
 * is why it stays there. Every real permission is walked too, so a future one
 * with a character the context cannot hold fails here rather than in front of
 * an operator.
 */
final class AuditPermissionContextTest extends TestCase
{
    private const ACCESS_DENIED_CONTEXT = ['permission'];

    public function testTheCompositePermissionIsRepresentableSoTheRefusalStays403(): void
    {
        $definition = audit_event_definition(
            VIRTUSPHERE_AUDIT_EVENT_AUTH_ACCESS_DENIED,
            'request',
            'portal',
            VIRTUSPHERE_AUDIT_RESULT_DENIED
        );

        $context = audit_context_normalize(
            ['permission' => 'missions.write+vms.write'],
            $definition,
            VIRTUSPHERE_AUDIT_RESULT_DENIED
        );

        self::assertSame(['permission' => 'missions.write+vms.write'], $context);
        self::assertSame(self::ACCESS_DENIED_CONTEXT, $definition['required']);
    }

    /** Every permission the product defines survives the same path. */
    public function testEveryDefinedPermissionIsRepresentable(): void
    {
        $definition = audit_event_definition(
            VIRTUSPHERE_AUDIT_EVENT_AUTH_ACCESS_DENIED,
            'request',
            'portal',
            VIRTUSPHERE_AUDIT_RESULT_DENIED
        );

        foreach ($this->allPermissions() as $permission) {
            $context = audit_context_normalize(['permission' => $permission], $definition, VIRTUSPHERE_AUDIT_RESULT_DENIED);
            self::assertSame($permission, $context['permission'], $permission . ' cannot be recorded');
        }
    }

    /**
     * A page name is the CSRF refusal's object AND its context, so the same
     * argument applies to every script that can reject a POST.
     */
    public function testEveryPortalPageNameIsRepresentableAsACsrfRejection(): void
    {
        $definition = audit_event_definition(
            VIRTUSPHERE_AUDIT_EVENT_AUTH_CSRF_REJECTED,
            'request',
            'logs.php',
            VIRTUSPHERE_AUDIT_RESULT_DENIED
        );

        foreach (glob(dirname(__DIR__, 2) . '/portal/*.php') ?: [] as $path) {
            $page = basename($path);
            self::assertSame($page, audit_object_id($page), $page . ' is not a usable object id');
            $context = audit_context_normalize(['page' => $page], $definition, VIRTUSPHERE_AUDIT_RESULT_DENIED);
            self::assertSame($page, $context['page'], $page . ' cannot be recorded');
        }
    }

    /**
     * A permission-shaped string that is NOT a permission is still refused. The
     * charset is opened for a composite, not for free text: a value with a
     * space or a quote in it has no business being an identifier.
     */
    public function testTheIdentifierCharsetIsStillClosed(): void
    {
        $definition = audit_event_definition(
            VIRTUSPHERE_AUDIT_EVENT_AUTH_ACCESS_DENIED,
            'request',
            'portal',
            VIRTUSPHERE_AUDIT_RESULT_DENIED
        );

        $bad = ['missions.write and vms.write', "vms.write';--", "vms.write\nfaked line"];

        $refused = [];
        foreach ($bad as $value) {
            try {
                audit_context_normalize(['permission' => $value], $definition, VIRTUSPHERE_AUDIT_RESULT_DENIED);
            } catch (InvalidArgumentException) {
                $refused[] = $value;
            }
        }

        self::assertSame($bad, $refused, 'the context accepted free text as a permission');
    }

    /** @return list<string> */
    private function allPermissions(): array
    {
        self::assertNotEmpty(VIRTUSPHERE_PERMISSIONS, 'the permission list is empty');

        // Plus the composites the pages build themselves; a composite is not a
        // grant, so it appears in no constant.
        return [...VIRTUSPHERE_PERMISSIONS, 'missions.write+vms.write'];
    }
}
