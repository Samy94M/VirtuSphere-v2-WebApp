<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/PortalActionInventory.php';

/**
 * The E2E coverage contract (plan v2, decision E6): every action a portal form
 * can POST is proven exactly once in a real browser; a confirmed action proves
 * both dialog branches (Cancel changes nothing, by DB proof; Confirm executes).
 *
 * The inventory is the same closed action scan PortalConfirmContractTest
 * classifies (tests/Support/PortalActionInventory.php). A spec claims coverage
 * with a marker comment next to the covering test:
 *
 *     // e2e-covers: missions.php:delete
 *     // e2e-covers-cancel: missions.php:delete
 *
 * The marker is a claim about what the spec actually drives, not a wish; it
 * belongs in the same commit as the test it annotates. Actions not yet proven
 * live in PENDING_ACTIONS with the slice that owes them. A new portal action
 * therefore fails this build until somebody either writes the spec or openly
 * books the debt, which is the point: E6 dies silently otherwise, one
 * unmarked action at a time.
 */
final class E2eActionCoverageContractTest extends TestCase
{
    /**
     * Actions without browser proof yet, each with the owing slice. Delete the
     * entry in the commit that adds the covering spec.
     *
     * @var array<string, string>
     */
    private const PENDING_ACTIONS = [
        // Etappe 7, HTTPS slice: the enable/disable dance needs cert material
        // and a stack whose listener may flip (QA stack, not the dev stack).
        'settings.php:save_https_enabled' => 'HTTPS cert upload flow spec',
        'settings.php:save_https_redirect' => 'HTTPS cert upload flow spec',
        'settings.php:save_https_hsts' => 'HTTPS cert upload flow spec',
        'settings.php:upload_https_cert' => 'HTTPS cert upload flow spec',
    ];

    private function webApiRoot(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    /** @return array<string, bool> "page.php:action" => confirmed */
    private function inventory(): array
    {
        $actions = PortalActionInventory::postableActions(
            PortalActionInventory::portalPages($this->webApiRoot())
        );
        self::assertNotSame([], $actions, 'the action scan matched nothing; the markup or the regex changed');

        return $actions;
    }

    /**
     * @return array{covers: array<string, list<string>>, cancel: array<string, list<string>>}
     *         action key => spec files claiming it
     */
    private function specMarkers(): array
    {
        // tests/e2e lives at the repo root, outside the container mount (only
        // Docker/WebAPI is /var/www/html), so this runs from a repo checkout and
        // skips inside the container, like SessionHardeningContractTest.
        $specDir = dirname($this->webApiRoot(), 2) . '/tests/e2e/specs';
        if (!is_dir($specDir)) {
            self::markTestSkipped('tests/e2e/specs is not visible from this runtime');
        }

        $specs = glob($specDir . '/*.js') ?: [];
        self::assertNotSame([], $specs, 'tests/e2e/specs exists but holds no spec files');

        $markers = ['covers' => [], 'cancel' => []];
        foreach ($specs as $path) {
            $source = (string) file_get_contents($path);
            preg_match_all('#//\s*e2e-covers(-cancel)?:\s*([a-z_]+\.php:[a-z_]+)#', $source, $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $kind = $hit[1] === '' ? 'covers' : 'cancel';
                $markers[$kind][$hit[2]][] = basename($path);
            }
        }

        return $markers;
    }

    public function testEveryPostableActionIsCoveredOrOpenlyPending(): void
    {
        $markers = $this->specMarkers();

        $unclassified = [];
        foreach (array_keys($this->inventory()) as $key) {
            $covered = array_key_exists($key, $markers['covers']);
            $pending = array_key_exists($key, self::PENDING_ACTIONS);
            if (!$covered && !$pending) {
                $unclassified[] = $key;
            }
        }

        sort($unclassified);
        self::assertSame(
            [],
            $unclassified,
            "portal action with neither an e2e-covers marker nor a PENDING_ACTIONS entry.\n"
            . 'Write the browser proof, or book the debt with the slice that owes it (E6).'
        );
    }

    public function testConfirmedActionsProveTheCancelBranchToo(): void
    {
        $markers = $this->specMarkers();
        $inventory = $this->inventory();

        $missingCancel = [];
        foreach ($markers['covers'] as $key => $files) {
            if (($inventory[$key] ?? false) === true && !array_key_exists($key, $markers['cancel'])) {
                $missingCancel[] = $key . ' (covered by ' . implode(', ', $files) . ')';
            }
        }

        sort($missingCancel);
        self::assertSame(
            [],
            $missingCancel,
            "confirmed action whose spec never proves the Cancel branch.\n"
            . 'E6: Cancel must change nothing, by DB proof, or the dialog is decoration.'
        );
    }

    public function testMarkersAndPendingListCarryNoStaleOrContradictoryEntries(): void
    {
        $markers = $this->specMarkers();
        $inventory = $this->inventory();

        $staleMarkers = array_diff(array_keys($markers['covers']), array_keys($inventory));
        sort($staleMarkers);
        self::assertSame([], $staleMarkers, 'e2e-covers names an action no portal form posts any more; fix the marker');

        $stalePending = array_diff(array_keys(self::PENDING_ACTIONS), array_keys($inventory));
        sort($stalePending);
        self::assertSame([], $stalePending, 'PENDING_ACTIONS names an action no portal form posts any more; delete the entry');

        $contradictory = array_intersect(array_keys($markers['covers']), array_keys(self::PENDING_ACTIONS));
        sort($contradictory);
        self::assertSame([], $contradictory, 'covered by a spec but still booked as pending; delete the PENDING_ACTIONS entry');

        // A cancel marker only makes sense next to a covers marker, on a
        // confirmed action.
        $orphanCancel = array_diff(array_keys($markers['cancel']), array_keys($markers['covers']));
        sort($orphanCancel);
        self::assertSame([], $orphanCancel, 'e2e-covers-cancel without the matching e2e-covers marker');

        $needlessCancel = [];
        foreach (array_keys($markers['cancel']) as $key) {
            if (($inventory[$key] ?? false) === false) {
                $needlessCancel[] = $key;
            }
        }
        sort($needlessCancel);
        self::assertSame([], $needlessCancel, 'e2e-covers-cancel on an action whose markup renders no confirm dialog');

        foreach (self::PENDING_ACTIONS as $key => $reason) {
            self::assertNotSame('', trim($reason), $key . ' must name the slice that owes the spec');
        }
    }
}
