<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_storage.php';
require_once dirname(__DIR__, 2) . '/lib/format.php';

/**
 * The deploy storage table is rendered by PHP and then kept live by deploy.js,
 * so the same row is produced by two implementations of the same rules: the
 * verdict thresholds, the badge variant per verdict, and the byte formatting.
 *
 * `deploy_storage_verdict_badge()` calls itself "the single mapping both tables
 * and deploy.js agree on", and nothing checked that claim. A drift here is
 * invisible in every test the project has: the page renders, the numbers look
 * plausible, and the verdict is simply wrong after the first change event.
 *
 * This is a source-level contract on purpose. Running the JS would need a
 * browser (the E2E tier, ADR-0028); what it would catch here is a disagreement
 * that a plain string comparison catches at build time, on every push.
 */
final class DeployStorageMirrorContractTest extends TestCase
{
    /** Verdict => badge variant, the mapping the PHP badge helper renders. */
    private const VERDICTS = ['ok' => 'success', 'insufficient' => 'warning', 'unknown' => 'neutral'];

    private function deployJs(): string
    {
        $path = str_replace('\\', '/', dirname(__DIR__, 2)) . '/portal/assets/deploy.js';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testEveryVerdictKeepsTheSameBadgeVariantOnBothSides(): void
    {
        // PHP side: the rendered badge really carries that variant.
        foreach (self::VERDICTS as $state => $variant) {
            self::assertStringContainsString(
                'badge-' . $variant,
                deploy_storage_verdict_badge($state),
                'PHP renders verdict ' . $state . ' with a different badge'
            );
        }

        // JS side: the literal map it builds the same badge from.
        $matched = preg_match('/var badges = \{([^}]*)\}/', $this->deployJs(), $matches);
        self::assertSame(1, $matched, 'deploy.js no longer carries a verdict badge map; re-point this contract');

        $jsMap = [];
        preg_match_all("/(\w+):\s*'([^']+)'/", $matches[1], $pairs, PREG_SET_ORDER);
        foreach ($pairs as $pair) {
            $jsMap[$pair[1]] = $pair[2];
        }

        self::assertSame(
            self::VERDICTS,
            $jsMap,
            "deploy.js and lib/deploy_storage.php disagree about the verdict badges.\n"
            . 'Change both, or the same row shows one colour before the first change event and another after it.'
        );
    }

    public function testTheVerdictThresholdIsTheSameComparisonOnBothSides(): void
    {
        // PHP: null is unknown, otherwise free >= required is ok. The boundary
        // matters: exactly enough space is enough.
        self::assertSame('unknown', deploy_storage_state(100, null));
        self::assertSame('ok', deploy_storage_state(100, 100));
        self::assertSame('insufficient', deploy_storage_state(100, 99));

        // JS: the same three-way expression, with the same >= boundary.
        self::assertStringContainsString(
            "freeBytes === null ? 'unknown' : (freeBytes >= bytes ? 'ok' : 'insufficient')",
            $this->deployJs(),
            'deploy.js no longer mirrors deploy_storage_state(); a > instead of >= turns an exact fit into a warning'
        );
    }

    public function testTheByteFormatterProducesTheSameStringsOnBothSides(): void
    {
        // humanBytes() in deploy.js is a hand-written mirror of
        // virtusphere_human_bytes(). Pin the unit table and the one-decimal rule
        // it depends on; the PHP side is the source of truth.
        $js = $this->deployJs();
        self::assertStringContainsString("var units = ['B', 'KB', 'MB', 'GB', 'TB'];", $js);
        self::assertStringContainsString('value >= 1024', $js);
        self::assertStringContainsString('value.toFixed(1)', $js);
        self::assertStringContainsString('Math.floor(value)', $js, 'bytes render without a decimal on both sides');

        // The same expectations, run through the PHP implementation.
        self::assertSame('512 B', virtusphere_human_bytes(512));
        self::assertSame('1.0 KB', virtusphere_human_bytes(1024));
        self::assertSame('1.5 TB', virtusphere_human_bytes((int) (1.5 * 1024 ** 4)));
    }

    public function testTheAccessibleCapacityLabelComesFromTheCatalogAndNotFromTheScript(): void
    {
        // Portal i18n rule: JS reads translated text from the PHP island, never
        // from a German/English literal of its own. The placeholder is the only
        // thing the script fills in.
        self::assertStringContainsString(':pct', __t('deploy.storage_usage_aria'));
        self::assertStringContainsString("data.labels.usage_aria || ''", $this->deployJs());
        self::assertStringContainsString("replace(':pct'", $this->deployJs());
    }
}
