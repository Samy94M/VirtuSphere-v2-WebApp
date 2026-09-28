<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_display.php';
require_once dirname(__DIR__, 2) . '/lib/vm_network_contract.php';
require_once dirname(__DIR__, 2) . '/lib/ansible_command_modes.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_job_input.php';

/**
 * Portal texts name deploy modes only by the display labels the job list and
 * status pages use (status.mode_*). Lists of modes inside a sentence come from
 * the same code predicates that decide the behaviour, so a text cannot keep
 * naming a mode the code no longer includes (powercycle review 27.09.2026).
 */
final class DeployModeTextTest extends TestCase
{
    private string $previousLocale;

    protected function setUp(): void
    {
        $this->previousLocale = Lang::locale();
    }

    protected function tearDown(): void
    {
        Lang::load($this->previousLocale);
    }

    public function testDeployFormOffersTheLocalizedModeLabels(): void
    {
        $panel = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/deploy_queue_panel.php');
        self::assertStringContainsString('foreach (virtusphere_user_deploy_modes() as $modeValue)', $panel);
        self::assertStringContainsString('<?php echo h(deploy_mode_label((string) $modeValue)); ?></option>', $panel);
        self::assertStringNotContainsString('$modeLabel', $panel);
    }

    public function testModeListsInTextsAreDerivedFromTheDecidingPredicates(): void
    {
        $user = virtusphere_user_deploy_modes();
        $wds = array_values(array_filter($user, 'deploy_mode_requires_wds_ready'));
        $mapping = array_values(array_filter($user, 'deploy_mode_requires_unique_network_mapping'));
        $cases = [
            'deploy.stagger_hint' => ['modes' => VIRTUSPHERE_DEPLOY_STAGGER_MODES],
            'validate.deploy_stagger_mode' => ['modes' => VIRTUSPHERE_DEPLOY_STAGGER_MODES],
            'help_deploy.deploy_schedule_p2' => ['modes' => VIRTUSPHERE_DEPLOY_STAGGER_MODES],
            'mission_details.wds_vlan_hint' => ['modes' => $wds],
            'help_deploy.deploy_powercycle_wait_p1' => ['modes' => ansible_modes_using_powercycle()],
            'help_deploy.deploy_start_wait_p2' => ['modes' => ansible_modes_using_start()],
            'help_deploy.network_contract_p2' => [
                'mapping_modes' => $mapping,
                'mapping_notice_modes' => array_values(array_diff($user, $mapping)),
                'wds_modes' => $wds,
                'wds_notice_modes' => array_values(array_diff($user, $wds)),
            ],
        ];
        foreach (Lang::LOCALES as $locale) {
            Lang::load($locale);
            foreach ($cases as $key => $lists) {
                $raw = $this->rawCatalogValue($locale, $key);
                $replace = ['max' => '1', 'idle' => '2'];
                foreach ($lists as $placeholder => $modes) {
                    self::assertNotSame([], $modes, "$key $placeholder");
                    self::assertStringContainsString(':' . $placeholder, $raw, "$locale $key");
                    $replace[$placeholder] = deploy_mode_label_list($modes);
                }
                // With every list removed, no mode may still be named by hand.
                $withoutLists = __t($key, array_merge($replace, array_map(static fn (): string => '', $lists)));
                foreach ($user as $mode) {
                    self::assertStringNotContainsString(deploy_mode_label($mode), $withoutLists, "$locale $key names $mode outside a derived list");
                }
                $text = __t($key, $replace);
                foreach ($lists as $modes) {
                    foreach ($modes as $mode) {
                        self::assertStringContainsString(deploy_mode_label($mode), $text, "$locale $key must name $mode");
                    }
                }
            }
        }
    }

    public function testStaggerValidationNamesTheStaggerableModesInTheActiveLanguage(): void
    {
        Lang::load('de');
        try {
            deploy_parse_schedule(['mode' => 'create', 'stagger_minutes' => '5'], 'UTC');
            self::fail('A non-staggerable mode must be refused.');
        } catch (ValidationException $e) {
            self::assertStringContainsString(deploy_mode_label_list(VIRTUSPHERE_DEPLOY_STAGGER_MODES), $e->getMessage());
        }
    }

    public function testNoCatalogNamesAModeByADivergentTechnicalLabel(): void
    {
        foreach (Lang::LOCALES as $locale) {
            Lang::load($locale);
            foreach (virtusphere_deploy_mode_labels() as $mode => $technical) {
                if ($technical === deploy_mode_label($mode)) {
                    continue;
                }
                foreach ($this->catalogValues($locale) as $key => $value) {
                    self::assertStringNotContainsString($technical, $value, "$locale $key names mode $mode by its technical label");
                }
            }
        }
    }

    public function testGermanPowercycleNameStatesTheRealOrderOnThenOff(): void
    {
        // The playbook powers each VM on, waits and powers it off again.
        Lang::load('de');
        $label = mb_strtolower(deploy_mode_label('powercycle'));
        self::assertLessThan(strpos($label, 'aus'), strpos($label, 'ein'), $label);
    }

    private function rawCatalogValue(string $locale, string $key): string
    {
        return $this->catalogValues($locale)[$key] ?? '';
    }

    /** @return array<string,string> */
    private function catalogValues(string $locale): array
    {
        $values = [];
        foreach (glob(dirname(__DIR__, 2) . '/lang/' . $locale . '/*.php') ?: [] as $file) {
            $module = basename($file, '.php');
            foreach ((array) require $file as $key => $value) {
                if (is_string($value)) {
                    $values[$module . '.' . $key] = $value;
                }
            }
        }

        return $values;
    }
}
