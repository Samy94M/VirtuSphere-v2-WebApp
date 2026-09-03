<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The rollout-hostname contract (Etappe 14D, ADR-0043).
 *
 * Four separate promises, each of which was made in one place and consumed in
 * another, which is exactly where they drift:
 *
 *  - the closed reset-blocker vocabulary against the exhaustive `match` that
 *    turns it into a sentence, and against the bulk labels;
 *  - the fence decision table, driven through every branch without a database;
 *  - the normalisation, which the PHP side and the PowerShell side both
 *    implement and must answer identically;
 *  - REPO_VM_COLUMNS, which must NOT carry a rollout column, because that list
 *    is what a template capture, a clone and a JSON export copy.
 */
final class MecmRolloutContractTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../..';

    public static function setUpBeforeClass(): void
    {
        require_once self::REPO_ROOT . '/lib/constants.php';
        require_once self::REPO_ROOT . '/lib/mecm_hostname.php';
        require_once self::REPO_ROOT . '/lib/mecm_rollout_fence.php';
    }

    /**
     * A blocker that reaches the portal without a sentence would be rendered as
     * a raw token, or worse under somebody else's wording. The match has no
     * default arm, so this walk is what makes "the build fails until you give it
     * words" true rather than intended.
     */
    public function testEveryResetBlockerHasASentenceInBothLocales(): void
    {
        $source = file_get_contents(self::REPO_ROOT . '/lib/mecm_rollout_display.php');
        self::assertIsString($source);

        $de = require self::REPO_ROOT . '/lang/de/portal.php';
        $en = require self::REPO_ROOT . '/lang/en/portal.php';

        self::assertNotSame([], VIRTUSPHERE_MECM_RESET_BLOCKERS);
        foreach (VIRTUSPHERE_MECM_RESET_BLOCKERS as $code) {
            self::assertMatchesRegularExpression(
                "/'" . preg_quote($code, '/') . "' => __t\('portal\.([a-z0-9_]+)'/",
                $source,
                $code . ' has no arm in mecm_reset_blocker_message()'
            );
            preg_match("/'" . preg_quote($code, '/') . "' => __t\('portal\.([a-z0-9_]+)'/", $source, $m);
            self::assertArrayHasKey($m[1], $de, $code . ' has no German sentence');
            self::assertArrayHasKey($m[1], $en, $code . ' has no English sentence');
        }
    }

    /**
     * The `@param` union of mecm_reset_blocker_message() is a second copy of the
     * constant, and it exists for a reason: it is what lets PHPStan see the
     * match as exhaustive instead of demanding the `default` arm the function
     * must not have. A second copy that nothing compares is drift waiting to
     * happen, so it is compared here (the shape DiskTypeLabelTest established).
     */
    public function testTheDocblockUnionMatchesTheClosedVocabulary(): void
    {
        $source = file_get_contents(self::REPO_ROOT . '/lib/mecm_rollout_display.php');
        self::assertIsString($source);

        self::assertSame(
            1,
            preg_match('/@param ([^ ]+) \$reasonCode/', $source, $m),
            'the union that keeps the match exhaustive is gone'
        );
        $union = array_map(
            static fn (string $part): string => trim($part, "'"),
            explode('|', $m[1])
        );
        sort($union);
        $expected = VIRTUSPHERE_MECM_RESET_BLOCKERS;
        sort($expected);
        self::assertSame($expected, $union, 'the docblock union drifted from VIRTUSPHERE_MECM_RESET_BLOCKERS');
    }

    /**
     * A pending delete tombstone is deliberately NOT a reset blocker (decision
     * of 2026-09-03): it is fail-closed in the device sync, where the hand-off
     * happens, and blocking here as well locked the operator out of correcting
     * a hostname they had just got wrong. Pinned in both directions so nobody
     * reintroduces it as a refusal without reading why it went.
     */
    public function testAPendingTombstoneIsAStateAndNotAResetBlocker(): void
    {
        self::assertNotContains('previous_device', VIRTUSPHERE_MECM_RESET_BLOCKERS);

        $repo = file_get_contents(self::REPO_ROOT . '/lib/repo/vms_mecm_reset.php');
        self::assertIsString($repo);
        self::assertStringNotContainsString("RepoMecmResetBlocked('previous_device')", $repo);
        // What replaced it: the reset keeps a tombstone it cannot replace,
        // instead of overwriting it with the NULL mecm_id of an unbound VM.
        self::assertStringContainsString('$existingTombstone', $repo);

        $display = file_get_contents(self::REPO_ROOT . '/lib/mecm_rollout_display.php');
        self::assertIsString($display);
        self::assertStringContainsString('function mecm_rollout_awaits_device_deletion', $display);
    }

    /**
     * Every context shape the two new producers can actually build must survive
     * normalisation.
     *
     * This exists because the shape that did NOT survive cost a silent reset.
     * The registry refuses an empty context field, the reset's audit shares its
     * transaction with the reset itself, and so a VM without a previous rollout
     * name (`previous_rollout_hostname => ''`) rolled the whole reset back: the
     * operator saw a generic error and the MECM ID was still there. Nothing in
     * the source review caught it, because both the audit call and the registry
     * were individually correct; only the combination was not, and only for a
     * VM whose optional value happened to be absent.
     *
     * The rule the producers now follow, and what this pins: an absent optional
     * value is OMITTED, never passed as an empty string.
     */
    public function testBothProducersSurviveTheirAbsentOptionalValues(): void
    {
        require_once self::REPO_ROOT . '/lib/audit_event_definitions.php';
        require_once self::REPO_ROOT . '/lib/audit_registry.php';

        $cases = [
            // Reset of a VM that has a bound ResourceID but no previous snapshot.
            [VIRTUSPHERE_AUDIT_EVENT_VM_MECM_CHANGED, ['action' => 'reset_mecm_id', 'mission_id' => 1, 'rollout_hostname' => 'HOST-1', 'rollout_revision' => 1, 'previous_resource_id' => 'RID-1']],
            // First reset ever: neither a previous snapshot nor a previous id.
            [VIRTUSPHERE_AUDIT_EVENT_VM_MECM_CHANGED, ['action' => 'reset_mecm_id', 'mission_id' => 1, 'rollout_hostname' => 'HOST-1', 'rollout_revision' => 1]],
            // Idempotent second click.
            [VIRTUSPHERE_AUDIT_EVENT_VM_MECM_CHANGED, ['action' => 'reset_mecm_id_noop', 'mission_id' => 1, 'rollout_hostname' => 'HOST-1', 'rollout_revision' => 4]],
            // Hostname change on a VM that has no snapshot and had no old name.
            [VIRTUSPHERE_AUDIT_EVENT_VM_ROLLOUT_HOSTNAME, ['action' => 'updated', 'mission_id' => 1, 'effect' => 'next_rollout', 'new_value' => 'HOST-2', 'rollout_revision' => 0]],
            // The fully populated shape, both effects.
            [VIRTUSPHERE_AUDIT_EVENT_VM_ROLLOUT_HOSTNAME, ['action' => 'updated', 'mission_id' => 1, 'effect' => 'current_pending', 'old_value' => 'HOST-1', 'new_value' => 'HOST-2', 'rollout_hostname' => 'HOST-2', 'rollout_revision' => 2]],
        ];

        foreach ($cases as [$code, $context]) {
            $definition = audit_event_definition($code, 'vm', '1', VIRTUSPHERE_AUDIT_RESULT_SUCCESS);
            $normalized = audit_context_normalize($context, $definition, VIRTUSPHERE_AUDIT_RESULT_SUCCESS);
            self::assertNotSame([], $normalized, $code . ' normalised to nothing');
        }
    }

    /**
     * The other half of the same rule: an empty string really is refused, so the
     * test above is proving something rather than restating that the registry is
     * permissive.
     */
    public function testAnEmptyOptionalContextValueIsStillRefused(): void
    {
        require_once self::REPO_ROOT . '/lib/audit_event_definitions.php';
        require_once self::REPO_ROOT . '/lib/audit_registry.php';

        $definition = audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_VM_MECM_CHANGED, 'vm', '1', VIRTUSPHERE_AUDIT_RESULT_SUCCESS);

        $this->expectException(\InvalidArgumentException::class);
        audit_context_normalize(
            ['action' => 'reset_mecm_id', 'mission_id' => 1, 'rollout_hostname' => 'HOST-1', 'rollout_revision' => 1, 'previous_rollout_hostname' => ''],
            $definition,
            VIRTUSPHERE_AUDIT_RESULT_SUCCESS
        );
    }

    /** Neither producer may hand the registry a blank optional value. */
    public function testNoProducerPassesAnEmptyStringFallback(): void
    {
        foreach (['portal/vms.php', 'lib/vm_edit_page.php'] as $producer) {
            $source = file_get_contents(self::REPO_ROOT . '/' . $producer);
            self::assertIsString($source);
            foreach (['previous_rollout_hostname', 'previous_resource_id', 'old_value', 'rollout_hostname'] as $field) {
                self::assertDoesNotMatchRegularExpression(
                    "/'" . $field . "' => \(string\) \([^)]*\?\? ''\)/",
                    $source,
                    $producer . ' passes ' . $field . " as an empty-string fallback; omit the key instead"
                );
            }
        }
    }

    /** The match must carry no arm that the constant does not know. */
    public function testTheMatchCarriesNoCodeOutsideTheVocabulary(): void
    {
        $source = file_get_contents(self::REPO_ROOT . '/lib/mecm_rollout_display.php');
        self::assertIsString($source);
        preg_match('/function mecm_reset_blocker_message.*?\n}/s', $source, $body);
        self::assertNotSame([], $body);

        preg_match_all("/^\s*'([a-z_]+)' => __t\(/m", $body[0], $arms);
        self::assertNotSame([], $arms[1], 'no arms found: the scan would be silently green');
        foreach ($arms[1] as $code) {
            self::assertContains($code, VIRTUSPHERE_MECM_RESET_BLOCKERS, $code . ' is an arm outside the closed vocabulary');
        }
        self::assertSame([], array_diff(VIRTUSPHERE_MECM_RESET_BLOCKERS, $arms[1]));
    }

    /**
     * The bulk result builds its label key at runtime (`vms.skip_<reason>`), so
     * a grep for literals never finds it. Every reason the repo can produce has
     * to exist in both catalogs or the aggregate line echoes the key.
     */
    public function testEveryBulkSkipReasonHasALabelInBothLocales(): void
    {
        $de = require self::REPO_ROOT . '/lang/de/vms.php';
        $en = require self::REPO_ROOT . '/lang/en/vms.php';

        foreach ([...VIRTUSPHERE_MECM_RESET_BLOCKERS, 'active_job'] as $reason) {
            self::assertArrayHasKey('skip_' . $reason, $de, 'vms.skip_' . $reason . ' missing in DE');
            self::assertArrayHasKey('skip_' . $reason, $en, 'vms.skip_' . $reason . ' missing in EN');
        }
    }

    /**
     * The fence decision table. Written out rather than derived, because the
     * point of a table is that a reader can check it against the rule; a table
     * generated from the implementation only restates it.
     *
     * @return array<string, array{0: ?int, 1: ?int, 2: ?string, 3: ?string, 4: ?string, 5: string}>
     */
    public static function fenceCases(): array
    {
        // A data provider runs BEFORE setUpBeforeClass(), so the verdict
        // constants have to be loaded here or the whole table is an undefined
        // constant. Spelling the verdicts out as literals instead would let the
        // table keep passing after somebody renamed one.
        require_once self::REPO_ROOT . '/lib/constants.php';
        require_once self::REPO_ROOT . '/lib/mecm_rollout_fence.php';

        return [
            // Legacy caller, no revision. Accepted ONLY at revision 1 with no tombstone.
            'legacy caller on an untouched VM binds' => [null, 1, null, 'RID', null, VIRTUSPHERE_MECM_FENCE_ACCEPT],
            'legacy caller after a reset is refused' => [null, 2, null, 'RID', null, VIRTUSPHERE_MECM_FENCE_STALE],
            'legacy caller with a tombstone is refused' => [null, 1, null, 'RID', 'OLD', VIRTUSPHERE_MECM_FENCE_STALE],
            'legacy caller re-reporting the same binding is a noop' => [null, 1, 'RID', 'RID', null, VIRTUSPHERE_MECM_FENCE_NOOP],
            'legacy caller with a different binding is refused' => [null, 1, 'RID', 'OTHER', null, VIRTUSPHERE_MECM_FENCE_STALE],
            // Current caller.
            'current caller binds a free row' => [3, 3, null, 'RID', null, VIRTUSPHERE_MECM_FENCE_ACCEPT],
            'current caller binds over an empty string' => [3, 3, '', 'RID', null, VIRTUSPHERE_MECM_FENCE_ACCEPT],
            'current caller repeating its own binding is a noop' => [3, 3, 'RID', 'RID', null, VIRTUSPHERE_MECM_FENCE_NOOP],
            'current caller with a second ResourceID is refused' => [3, 3, 'RID', 'OTHER', null, VIRTUSPHERE_MECM_FENCE_STALE],
            'current caller passes even with a tombstone' => [3, 3, null, 'RID', 'OLD', VIRTUSPHERE_MECM_FENCE_ACCEPT],
            // Wrong revision, in both directions.
            'older revision is refused' => [2, 3, null, 'RID', null, VIRTUSPHERE_MECM_FENCE_STALE],
            'future revision is refused just as firmly' => [4, 3, null, 'RID', null, VIRTUSPHERE_MECM_FENCE_STALE],
            // A NULL stored revision is a pre-migration row and counts as 1.
            'null stored revision behaves as revision 1' => [1, null, null, 'RID', null, VIRTUSPHERE_MECM_FENCE_ACCEPT],
            // No binding involved at all (membership, client ACK).
            'membership of the current rollout is accepted' => [3, 3, null, null, null, VIRTUSPHERE_MECM_FENCE_ACCEPT],
            'membership of an old rollout is refused' => [2, 3, null, null, null, VIRTUSPHERE_MECM_FENCE_STALE],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fenceCases')]
    public function testTheFenceDecidesTheWholeTable(?int $reported, ?int $stored, ?string $storedBinding, ?string $incoming, ?string $tombstone, string $expected): void
    {
        self::assertSame($expected, mecm_rollout_fence_decide($reported, $stored, $storedBinding, $incoming, $tombstone));
    }

    /**
     * A future revision cannot be a legal caller: only this server hands them
     * out. Accepting one would bind a ResourceID to a rollout that does not
     * exist here, which is why it is refused as firmly as an old one.
     */
    public function testNoRevisionAboveTheStoredOneIsEverAccepted(): void
    {
        for ($reported = 2; $reported <= 12; $reported++) {
            self::assertSame(
                VIRTUSPHERE_MECM_FENCE_STALE,
                mecm_rollout_fence_decide($reported, 1, null, 'RID', null),
                'revision ' . $reported . ' was accepted against a stored 1'
            );
        }
    }

    /**
     * A reported revision is either absent or a positive integer. Anything else
     * is a malformed BODY and answers 400, because a 409 would promise that
     * retrying after a resync could work.
     */
    public function testTheReportedRevisionParserSeparates400From409(): void
    {
        self::assertNull(mecm_rollout_fence_reported_revision(null));
        self::assertNull(mecm_rollout_fence_reported_revision(''));
        self::assertSame(3, mecm_rollout_fence_reported_revision(3));
        self::assertSame(3, mecm_rollout_fence_reported_revision('3'));

        $refused = 0;
        $cases = ['abc', '-1', '0', 0, -2, '1.5', ' 3', '3 ', [], true, 1.5];
        foreach ($cases as $bad) {
            try {
                mecm_rollout_fence_reported_revision($bad);
                self::fail('accepted an unusable revision: ' . var_export($bad, true));
            } catch (\InvalidArgumentException) {
                $refused++;
            }
        }
        // Counted rather than asserted per iteration: a `assertTrue(true)` in a
        // catch proves nothing, and a loop whose body never ran would otherwise
        // pass as a clean run.
        self::assertCount($refused, $cases);
    }

    /**
     * Identity normalisation. It must fold case and trim, and it must NEVER
     * truncate: a normaliser that silently repairs input collapses two different
     * desired names onto one claim and hands the operator a rollout they never
     * asked for.
     */
    public function testHostnameKeyFoldsCaseAndTrimsButNeverShortens(): void
    {
        self::assertSame('backup-12345', mecm_hostname_key('  Backup-12345 '));
        self::assertSame('backup-12345', mecm_hostname_key('BACKUP-12345'));
        self::assertSame('', mecm_hostname_key(null));
        self::assertSame('', mecm_hostname_key('   '));

        $long = str_repeat('A', 40);
        self::assertSame(40, strlen(mecm_hostname_key($long)), 'the key must not truncate');
        self::assertSame('host.local', mecm_hostname_key('host.local'), 'the key must not repair');
    }

    /** A pure change of spelling is the same machine, and an empty name is nobody. */
    public function testSameCompares(): void
    {
        self::assertTrue(mecm_hostname_same('Backup-1', 'backup-1'));
        self::assertFalse(mecm_hostname_same('Backup-1', 'Backup-2'));
        self::assertFalse(mecm_hostname_same('', ''), 'two absent names are not the same machine');
        self::assertFalse(mecm_hostname_same(null, 'x'));
    }

    /**
     * The rollout predicate mirrors Validator::netbiosHostname. It is separate
     * because the repo answers it for a STORED value (a grandfathered legacy
     * hostname no edit ever touched) where no Validator instance is in play.
     */
    public function testRolloutValidityMirrorsTheNetbiosRule(): void
    {
        foreach (['A', 'Backup-12345', str_repeat('A', VIRTUSPHERE_MECM_ROLLOUT_HOSTNAME_MAX_LENGTH)] as $ok) {
            self::assertTrue(mecm_hostname_is_rollout_valid($ok), $ok . ' should be usable');
        }
        foreach ([
            str_repeat('A', VIRTUSPHERE_MECM_ROLLOUT_HOSTNAME_MAX_LENGTH + 1),
            'host.local', '-host', 'host-', '', '   ', null, 'hö st',
        ] as $bad) {
            self::assertFalse(mecm_hostname_is_rollout_valid($bad), var_export($bad, true) . ' should be refused');
        }
    }

    /**
     * 14D.1.6 is satisfied by construction, and this is the guard that keeps it
     * that way. REPO_VM_COLUMNS is what a template capture, a template clone and
     * the mission JSON export copy field by field. A rollout column in there
     * would carry a snapshot, a revision or a foreign ResourceID into a VM that
     * was never handed to MECM.
     */
    public function testRepoVmColumnsCarriesNoRolloutRuntime(): void
    {
        require_once self::REPO_ROOT . '/lib/repo/vms.php';

        self::assertNotSame([], REPO_VM_COLUMNS);
        foreach (['mecm_rollout_hostname', 'mecm_rollout_revision', 'mecm_previous_id', 'mecm_id', 'mecm_sync_state', 'updated'] as $runtime) {
            self::assertNotContains($runtime, REPO_VM_COLUMNS, $runtime . ' must never be copied by a capture, a clone or an export');
        }
        self::assertContains('vm_hostname', REPO_VM_COLUMNS, 'the desired name IS transported: it is the business SSoT');
    }

    /**
     * `deploy_vm_hostname_claims` has exactly ONE writer. Two writers is how a
     * snapshot moves without its claim, which is the state that lets two
     * machines answer to one name.
     */
    public function testTheClaimTableHasExactlyOneWriter(): void
    {
        $writers = [];
        foreach ($this->phpSources() as $file) {
            $source = file_get_contents($file);
            if ($source === false) {
                continue;
            }
            if (preg_match('/(INSERT INTO|DELETE FROM|UPDATE)\s+deploy_vm_hostname_claims/i', $source) === 1) {
                $writers[] = str_replace(realpath(self::REPO_ROOT) . DIRECTORY_SEPARATOR, '', $file);
            }
        }

        self::assertNotSame([], $writers, 'no writer found at all: the scan would be silently green');
        sort($writers);
        self::assertSame(
            ['lib' . DIRECTORY_SEPARATOR . 'repo' . DIRECTORY_SEPARATOR . 'vm_rollout.php'],
            $writers,
            'the claim table must be written only by repo_vm_hostname_claims_sync()'
        );
    }

    /** @return list<string> */
    private function phpSources(): array
    {
        $root = realpath(self::REPO_ROOT . '/lib');
        self::assertIsString($root);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile() && $entry->getExtension() === 'php' && !str_contains($entry->getPathname(), 'migrations')) {
                $files[] = $entry->getPathname();
            }
        }
        self::assertNotSame([], $files);

        return $files;
    }
}
