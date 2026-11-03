<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The three location pickers (datacenter, datastore, VLAN), at the markup level.
 *
 * They had no test at all, including the one promise that matters most: a stored
 * value the list does not know is never silently dropped. That value is a real
 * assignment on ESXi; losing it on a save the operator did not intend is the
 * whole reason these are hard selects with an escape option rather than plain
 * selects (ADR-0023 decoupling).
 */
final class InventorySelectFieldTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/layout.php';
        require_once dirname(__DIR__, 2) . '/lib/inventory_field.php';
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $config
     */
    private function render(array $options, array $config): string
    {
        ob_start();
        inventory_select_field($options, $config + [
            'name' => 'hypervisor_datastorage',
            'empty_label' => 'choose',
            'unknown_suffix' => 'not in inventory',
        ]);

        return (string) ob_get_clean();
    }

    /**
     * @param array<int, string> $names
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function options(array $names, array $extra = []): array
    {
        return $extra + [
            'names' => $names,
            'exact' => true,
            'buckets' => $names === [] ? [] : [['scope' => 'all', 'credentials' => [], 'names' => $names, 'free_by_key' => [], 'unusable_by_key' => []]],
            'free_by_key' => [],
            'unusable_by_key' => [],
            'credential_count' => 1,
            'eligible_count' => 1,
        ];
    }

    public function testAStoredValueOutsideTheInventoryStaysSelectable(): void
    {
        // The datastore a mission was assigned before the host stopped reporting
        // it. Dropping it would rewrite the assignment on the next save without
        // anybody asking.
        $html = $this->render($this->options(['ds-new']), ['value' => 'ds-retired']);

        self::assertStringContainsString('<option value="ds-retired" selected>ds-retired (not in inventory)</option>', $html);
        self::assertStringContainsString('value="ds-new"', $html);
    }

    public function testACaseVariantAlsoShowsTheSuffixRatherThanSnappingSilently(): void
    {
        // Documented exception (ADR-0023): the match is exact here while every
        // warn path stays case-insensitive, so this never produces a warning,
        // and the next save cannot quietly rewrite the stored spelling.
        $html = $this->render($this->options(['DataStore1']), ['value' => 'datastore1']);

        self::assertStringContainsString('<option value="datastore1" selected>datastore1 (not in inventory)</option>', $html);
    }

    public function testAnEmptyInventoryFallsBackToFreeTextAndKeepsTheValue(): void
    {
        // Nothing could be offered, so a select would lock the operator out of a
        // field with no admin UI behind it.
        $html = $this->render($this->options([]), ['value' => 'ds-legacy', 'input_placeholder' => 'from the mission']);

        self::assertStringContainsString('<input name="hypervisor_datastorage" value="ds-legacy"', $html);
        self::assertStringContainsString('placeholder="from the mission"', $html);
        self::assertStringNotContainsString('<select', $html);
    }

    public function testTheValueIsEscapedInBothTheAttributeAndTheLabel(): void
    {
        // Datastore names come from ESXi and are user-controlled.
        $html = $this->render($this->options(['ds1']), ['value' => '"><script>x</script>']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
    }

    public function testAReadOnlyUserGetsADisabledSelectAndAReadonlyInput(): void
    {
        self::assertStringContainsString('disabled', $this->render($this->options(['ds1']), ['value' => '', 'disabled' => true]));
        self::assertStringContainsString('readonly', $this->render($this->options([]), ['value' => 'x', 'disabled' => true]));
    }

    public function testASingleBucketRendersFlatWithoutAnOptgroup(): void
    {
        // One bucket means every credential agrees; a group heading over the
        // whole list would imply a distinction that is not there.
        $html = $this->render($this->options(['ds1', 'ds2']), ['value' => 'ds1']);

        self::assertStringNotContainsString('<optgroup', $html);
        self::assertStringContainsString('<option value="ds1" selected>ds1</option>', $html);
    }

    public function testBucketsRenderAsGroupsAndANameAppearsExactlyOnce(): void
    {
        // The defect the buckets replace: a shared datastore used to appear once
        // per credential, carrying `selected` in each group, and the browser
        // silently kept the last one.
        $options = $this->options(['ds-shared', 'ds-local'], [
            'buckets' => [
                ['scope' => 'all', 'credentials' => [], 'names' => ['ds-shared'], 'free_by_key' => ['ds-shared' => null], 'unusable_by_key' => []],
                ['scope' => 'only', 'credentials' => [['id' => 2, 'name' => 'esxi-02', 'host' => '10.0.5.12']], 'names' => ['ds-local'], 'free_by_key' => ['ds-local' => null], 'unusable_by_key' => []],
            ],
        ]);
        $html = $this->render($options, ['value' => 'ds-shared']);

        self::assertSame(2, substr_count($html, '<optgroup'));
        self::assertStringContainsString(__t('common.inventory_bucket_all'), $html);
        self::assertStringContainsString('esxi-02 (10.0.5.12)', $html, 'the address is what makes "esxi1..esxi6" usable');
        self::assertSame(1, substr_count($html, 'value="ds-shared"'));
        self::assertSame(1, substr_count($html, 'selected'));
    }

    public function testAKnownFreeValueDecoratesTheLabelAndMaintenanceReplacesIt(): void
    {
        $free = $this->options(['ds1'], ['free_by_key' => ['ds1' => 1024 * 1024 * 1024]]);
        self::assertStringContainsString(__t('common.free_suffix', ['free' => virtusphere_human_bytes(1024 * 1024 * 1024)]), $this->render($free, ['value' => '']));

        // A datastore in maintenance has a size but no space anybody can deploy
        // onto, so it says so instead of showing a number.
        $maintenance = $this->options(['ds1'], ['unusable_by_key' => ['ds1' => true], 'free_by_key' => ['ds1' => null]]);
        $html = $this->render($maintenance, ['value' => '']);
        self::assertStringContainsString(__t('common.maintenance_suffix'), $html);
        self::assertStringNotContainsString(__t('common.free_suffix', ['free' => '0 B']), $html);
    }

    public function testAnUnknownFreeValueDecoratesNothingAtAll(): void
    {
        // Kinds without bytes (datacenters) would otherwise carry a suffix on
        // every single option, which states nothing and hides the ones that do.
        $html = $this->render($this->options(['ha-datacenter']), ['value' => '']);

        self::assertStringContainsString('<option value="ha-datacenter" >ha-datacenter</option>', $html);
    }

    public function testTheVlanSelectKeepsAStoredValueTheCatalogRetired(): void
    {
        ob_start();
        vlan_select_field('wds_vlan', 'VLAN_OLD', [['vlan_name' => 'VLAN_701']], ['none' => 'none', 'unknown_suffix' => 'retired']);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('<option value="VLAN_OLD" selected>VLAN_OLD (retired)</option>', $html);
        self::assertStringContainsString('<option value="VLAN_701" >VLAN_701</option>', $html);
    }

    public function testAnEmptyStoredVlanSelectsNothingRatherThanInventingAnOption(): void
    {
        ob_start();
        vlan_select_field('wds_vlan', '', [['vlan_name' => 'VLAN_701']], ['none' => 'none', 'unknown_suffix' => 'retired']);
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('selected', $html);
    }
}
