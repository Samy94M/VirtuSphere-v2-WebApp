<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/audit_event_definitions.php';
require_once dirname(__DIR__, 2) . '/lib/audit_registry.php';

/**
 * LG-05: a list that would push its audit row over the context cap is cut before
 * the row is built. A refused row is a lost trail (MECM plan decision 32), so the
 * cut is what keeps a sync with a long catalog change from being rolled back.
 *
 * Pure registry checks, no database.
 */
final class AuditFitStringListTest extends TestCase
{
    /** One entry at the per-entry cap, so 100 of them exceed the context cap on their own. */
    private static function longEntry(int $index): string
    {
        return str_repeat('P', 200) . $index;
    }

    public function testAListThatFitsIsStoredUnchanged(): void
    {
        $items = ['Paket A -> Paket B (1 Vorlagen-VM(s))', 'Paket C -> Paket D (2 Vorlagen-VM(s))'];

        self::assertSame($items, audit_fit_string_list($items));
    }

    /**
     * The unbounded list is what the registry refuses: 100 entries of the cap are
     * over the 4096-byte context. This is the failure that would roll the sync back.
     */
    public function testTheUnboundedListWouldBeRefusedByTheContextCap(): void
    {
        $items = [];
        for ($index = 0; $index < 100; $index++) {
            $items[] = self::longEntry($index);
        }
        $definition = audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGES_RELINKED, 'catalog', 'packages', VIRTUSPHERE_AUDIT_RESULT_SUCCESS);
        $context = audit_context_normalize(['items' => $items, 'item_count' => 100], $definition, VIRTUSPHERE_AUDIT_RESULT_SUCCESS);

        $this->expectException(LengthException::class);
        audit_context_json($context);
    }

    /**
     * Over the cap: the row is stored, its list is cut, and the last entry names how
     * many entries were left out. Kept plus named equals the complete count.
     */
    public function testAListOverTheCapIsStoredTruncatedWithItsCounter(): void
    {
        $items = [];
        for ($index = 0; $index < 100; $index++) {
            $items[] = self::longEntry($index);
        }

        $fitted = audit_fit_string_list($items);

        $marker = array_pop($fitted);
        self::assertIsString($marker);
        self::assertMatchesRegularExpression('/^\+\d+ weitere$/', $marker);
        $omitted = (int) sscanf($marker, '+%d weitere')[0];
        self::assertGreaterThan(0, $omitted);
        self::assertSame(100, count($fitted) + $omitted, 'kept entries and the counter must add up to the complete list');

        $definition = audit_event_definition(VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGES_RELINKED, 'catalog', 'packages', VIRTUSPHERE_AUDIT_RESULT_SUCCESS);
        $context = audit_context_normalize(
            ['items' => [...$fitted, $marker], 'item_count' => 100],
            $definition,
            VIRTUSPHERE_AUDIT_RESULT_SUCCESS
        );
        $json = audit_context_json($context);
        self::assertIsString($json);
        self::assertLessThanOrEqual(VIRTUSPHERE_AUDIT_CONTEXT_MAX_BYTES, strlen($json), 'the cut row must fit the context cap');
        self::assertStringContainsString('"item_count":100', $json, 'item_count keeps the complete count');
    }

    /** The count cap is respected even for short entries, which never hit the byte budget. */
    public function testTheCountCapIsRespectedForShortEntries(): void
    {
        $items = [];
        for ($index = 0; $index < 150; $index++) {
            $items[] = 'P' . $index;
        }

        $fitted = audit_fit_string_list($items);

        self::assertLessThanOrEqual(100, count($fitted));
        self::assertMatchesRegularExpression('/^\+\d+ weitere$/', (string) end($fitted));
    }
}
