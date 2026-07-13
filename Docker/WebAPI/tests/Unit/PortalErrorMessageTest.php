<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';

/**
 * portal_error_message() is the boundary between an operator diagnostic and what
 * the portal actually shows. The repos throw English RuntimeExceptions on
 * purpose (they also serve the workers and the machine layer), and this function
 * decides which of them a portal user is allowed to read verbatim.
 *
 * The rule the function states about itself: a condition an operator can reach by
 * clicking a button the portal renders is localized; a crafted-only condition
 * (a POSTed id that does not exist) falls through to the raw message. A reachable
 * guard that is missing from the map therefore shows raw English in the German
 * portal, which is exactly how the credential-in-use rejection shipped.
 *
 * Pure string mapping, no DB.
 */
final class PortalErrorMessageTest extends TestCase
{
    protected function setUp(): void
    {
        Lang::load('de');
    }

    /**
     * Every guard a rendered button can trip must come back localized. The
     * assertion is deliberately "not the English original" rather than an exact
     * string: it pins the mapping, not the wording, so improving a sentence does
     * not break the test while dropping the key does.
     *
     * @return array<string, array{string}>
     */
    public static function operatorReachableMessages(): array
    {
        return [
            // deploy.php renders Start for a mission with no VMs.
            'mission without VMs' => ['Mission has no VMs to deploy.'],
            // deploy.php renders Start while a job is already queued.
            'mission already deploying' => ['This mission already has an active deploy job.'],
            // deploy.php renders Start for a mission that has no datastore yet.
            'mission without datastore' => ['Mission datastore is required before deployment.'],
            // The VM selection was deleted between rendering the form and posting.
            'selection gone' => ['None of the selected VMs belong to this mission.'],
            // credentials.php renders Delete for every credential, including one
            // an active job holds.
            'credential in use' => ['Credential is used by an active deploy job.'],
            // Two operators editing the same VM: the second save is rejected by
            // the optimistic-locking guard in repo_save_vm.
            'vm edit conflict' => ['VM was changed by another user. Reload before saving.'],
        ];
    }

    #[DataProvider('operatorReachableMessages')]
    public function testOperatorReachableGuardsAreLocalized(string $englishMessage): void
    {
        $rendered = portal_error_message(new RuntimeException($englishMessage));

        self::assertNotSame(
            $englishMessage,
            $rendered,
            'this guard is one click away in the portal, so it must not render its raw English diagnostic'
        );
        self::assertNotSame('', $rendered);
        // A missing key renders as the key itself; that is a gap, not a message.
        self::assertStringNotContainsString('.err_', $rendered, 'the translation key is missing from the catalog');
    }

    /**
     * The diagnostic itself is unchanged: the workers and the logs still read the
     * English text. Only the portal rendering is localized.
     */
    public function testTheDiagnosticTextIsNotRewritten(): void
    {
        $exception = new RuntimeException('Credential is used by an active deploy job.');

        self::assertSame('Credential is used by an active deploy job.', $exception->getMessage());
    }

    /**
     * A crafted-only condition (you cannot reach it without POSTing an id that
     * does not exist) is allowed to fall through. Pinning this keeps the map
     * honest about what it is for, instead of growing into a catch-all.
     */
    public function testCraftedOnlyConditionsFallThroughToTheRawMessage(): void
    {
        self::assertSame('Mission not found.', portal_error_message(new RuntimeException('Mission not found.')));
    }

    /** An exception with no message must still say something. */
    public function testEmptyMessageFallsBackToTheGenericFailure(): void
    {
        $rendered = portal_error_message(new RuntimeException(''));

        self::assertNotSame('', $rendered);
        self::assertSame(__t('layout.err_action_failed'), $rendered);
    }

    /** A ValidationException carries its own field wording and passes through. */
    public function testValidationExceptionKeepsItsOwnMessage(): void
    {
        $exception = new ValidationException(['f' => 'x'], 'Feld ist ungültig.');

        self::assertSame('Feld ist ungültig.', portal_error_message($exception));
    }
}
