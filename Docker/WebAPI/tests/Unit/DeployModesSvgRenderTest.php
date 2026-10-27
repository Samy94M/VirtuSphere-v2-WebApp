<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_mode_labels.php';
require_once dirname(__DIR__, 2) . '/lib/ansible_command_modes.php';

/**
 * The deploy-mode figure of the help page (lib/help_deploy_modes.php), rendered
 * in both locales with no database. Nothing is listed here: the rows are
 * virtusphere_user_deploy_modes() in code order, the step columns are the
 * playbook keys of VIRTUSPHERE_PLAYBOOKS that any of those modes runs, and each
 * row marks exactly the steps ansible_playbooks_for_mode() returns for it, the
 * steps that only the mission's autostart adds drawn as conditional.
 *
 * The figure names steps, never playbook files (portal display restraint; the
 * project documentation names them). It is checked for the accessible frame and
 * for the absence of scripts, inline styles, handlers, links, dashes and raw
 * tokens, like the VM path figure.
 *
 * The partial is required inside the helper, after its existence is asserted:
 * a missing file must fail these tests by name, not abort the unit run.
 */
final class DeployModesSvgRenderTest extends TestCase
{
    private const PARTIAL = 'lib/help_deploy_modes.php';
    private const ROW_PATTERN = '/<g class="help-fig-row">(.*?)<\/g><!-- row -->/s';
    private const STEP_PATTERN = '/<g class="help-fig-step help-fig-step-(on|cond|off)">(.*?)<\/g>/s';

    protected function tearDown(): void
    {
        Lang::load(Lang::DEFAULT_LOCALE);
    }

    /** @return list<array{0:string}> */
    public static function locales(): array
    {
        return [['de'], ['en']];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testFigureAndSvgCarryTheAccessibleStructure(string $locale): void
    {
        $html = self::figure($locale);

        self::assertStringContainsString('<figure class="help-fig">', $html);
        self::assertStringContainsString('<svg class="help-fig-svg"', $html);
        self::assertStringContainsString('viewBox="', $html);
        self::assertStringContainsString('role="img"', $html);
        self::assertStringContainsString('aria-labelledby="deploy-modes-title deploy-modes-desc"', $html);
        self::assertStringContainsString('focusable="false"', $html);
        self::assertSame(1, preg_match('/<title id="deploy-modes-title">[^<]+<\/title>/', $html), 'the svg needs a non-empty title');
        self::assertSame(1, preg_match('/<desc id="deploy-modes-desc">[^<]+<\/desc>/', $html), 'the svg needs a non-empty desc');
        self::assertStringContainsString('<figcaption', $html);
    }

    public function testStepColumnsAreEveryPlaybookAUserModeRunsInPlaybookOrder(): void
    {
        $columns = self::columns();

        self::assertNotSame([], $columns, 'no user mode runs a playbook (zero-match must not pass)');
        foreach (virtusphere_user_deploy_modes() as $mode) {
            $indices = array_map(static fn (string $key): int => (int) array_search($key, $columns, true), self::steps($mode, true));
            $sorted = $indices;
            sort($sorted);
            self::assertSame($sorted, $indices, $mode . ' runs its steps out of column order; a left-to-right row would misstate the sequence');
            self::assertSame(count(array_unique($indices)), count($indices), $mode . ' runs a step twice');
        }
    }

    #[PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testLegendNumbersEveryStepColumnWithItsCatalogName(string $locale): void
    {
        $html = self::figure($locale);

        self::assertSame(1, preg_match('/<g class="help-fig-legend">(.*?)<\/g><!-- legend -->/s', $html, $legend), 'the figure has no legend');
        $position = 0;
        foreach (self::columns() as $index => $key) {
            $catalogKey = 'help_deploy_figures.step_' . $key;
            $name = __t($catalogKey);
            self::assertNotSame($catalogKey, $name, $catalogKey . ' is missing from the help catalog');
            $numbered = '>' . ($index + 1) . '</text>';
            $found = strpos($legend[1], $numbered, $position);
            self::assertIsInt($found, $key . ' has no legend number ' . ($index + 1));
            $found = strpos($legend[1], '>' . h($name) . '</text>', $found);
            self::assertIsInt($found, $key . ' legend name "' . $name . '" is missing or out of order');
            $position = $found;
        }
    }

    #[PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testEveryUserModeIsOneRowMarkingExactlyItsPlaybookSequence(string $locale): void
    {
        $html = self::figure($locale);
        $modes = virtusphere_user_deploy_modes();
        $columns = self::columns();

        self::assertNotSame([], $modes, 'no user deploy mode (zero-match must not pass)');
        preg_match_all(self::ROW_PATTERN, $html, $rows);
        self::assertCount(count($modes), $rows[1], 'one row per user deploy mode');

        foreach ($modes as $index => $mode) {
            $row = $rows[1][$index];
            self::assertStringContainsString('>' . h(deploy_mode_label($mode)) . '</text>', $row, $mode . ' row is missing its label or is out of mode order');

            $always = self::steps($mode, false);
            $withAutostart = self::steps($mode, true);
            $expected = [];
            foreach ($columns as $column => $key) {
                $expected[] = in_array($key, $always, true) ? 'on' : (in_array($key, $withAutostart, true) ? 'cond' : 'off');
            }

            preg_match_all(self::STEP_PATTERN, $row, $steps);
            self::assertSame($expected, $steps[1], $mode . ' marks other steps than ansible_playbooks_for_mode() runs');
            foreach ($steps[1] as $column => $state) {
                if ($state === 'off') {
                    self::assertStringNotContainsString('<text', $steps[2][$column], $mode . ' numbers a step it does not run');
                    continue;
                }
                self::assertStringContainsString('>' . ($column + 1) . '</text>', $steps[2][$column], $mode . ' step ' . $columns[$column] . ' carries the wrong number');
            }

            $run = count(array_filter($expected, static fn (string $state): bool => $state !== 'off'));
            self::assertSame(max(0, $run - 1), substr_count($row, 'class="help-fig-connector'), $mode . ' needs one connector between each pair of neighbouring steps');
            self::assertSame(
                count(array_filter($expected, static fn (string $state): bool => $state === 'cond')),
                substr_count($row, 'class="help-fig-connector help-fig-connector-cond"'),
                $mode . ' must reach a conditional step with a dashed connector'
            );
        }
    }

    public function testTheFullPipelineIsTheOnlyModeWithAConditionalStep(): void
    {
        foreach (virtusphere_user_deploy_modes() as $mode) {
            $conditional = array_values(array_diff(self::steps($mode, true), self::steps($mode, false)));
            if ($mode === VIRTUSPHERE_DEPLOY_MODE_FULL) {
                self::assertSame(['autostart'], $conditional, 'the full pipeline adds exactly the autostart step for an autostart mission');
                continue;
            }
            self::assertSame([], $conditional, $mode . ' must not depend on the mission autostart setting');
        }
    }

    #[PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testDescriptionNamesEveryModeAndEveryStep(string $locale): void
    {
        $html = self::figure($locale);

        self::assertSame(1, preg_match('/<desc id="deploy-modes-desc">(.*?)<\/desc>/s', $html, $match), 'the svg has no desc');
        foreach (virtusphere_user_deploy_modes() as $mode) {
            self::assertStringContainsString(h(deploy_mode_label($mode)), $match[1], $mode . ' is missing from the desc');
        }
        foreach (self::columns() as $key) {
            self::assertStringContainsString(h(__t('help_deploy_figures.step_' . $key)), $match[1], $key . ' is missing from the desc');
        }
    }

    #[PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testFigureHasNoScriptStyleHandlerLinkDashPlaybookOrRawToken(string $locale): void
    {
        $html = self::figure($locale);

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString(' style=', $html);
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+=/i', $html);
        self::assertStringNotContainsString('href=', $html);
        self::assertStringNotContainsString("\u{2014}", $html);
        self::assertStringNotContainsString("\u{2013}", $html);
        self::assertStringNotContainsString('.yml', $html, 'the figure names steps, not playbook files');
        foreach (VIRTUSPHERE_PLAYBOOKS as $key => $file) {
            self::assertStringNotContainsString($file, $html);
            self::assertStringNotContainsString('>' . $key . '<', $html, 'the raw step token ' . $key . ' is shown');
        }
        foreach (virtusphere_user_deploy_modes() as $mode) {
            self::assertStringNotContainsString('>' . $mode . '<', $html, 'the raw mode token ' . $mode . ' is shown');
        }
    }

    public function testGermanAndEnglishFiguresDiffer(): void
    {
        $key = 'help_deploy_figures.step_create';

        $de = self::figure('de');
        $deName = __t($key);
        $en = self::figure('en');
        $enName = __t($key);

        self::assertNotSame($deName, $enName, $key . ' is not localised');
        self::assertNotSame($de, $en, 'the German and English figures render the same');
    }

    /**
     * The playbook keys a mode runs, in run order, read from the deploy SSoT.
     *
     * @return list<string>
     */
    private static function steps(string $mode, bool $autostartEnabled): array
    {
        $keys = [];
        foreach (ansible_playbooks_for_mode($mode, $autostartEnabled) as $file) {
            $key = array_search($file, VIRTUSPHERE_PLAYBOOKS, true);
            self::assertIsString($key, $file . ' is not a VIRTUSPHERE_PLAYBOOKS entry');
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * The playbook keys any user mode runs, in VIRTUSPHERE_PLAYBOOKS order.
     *
     * @return list<string>
     */
    private static function columns(): array
    {
        $used = [];
        foreach (virtusphere_user_deploy_modes() as $mode) {
            $used = array_merge($used, self::steps($mode, true));
        }

        return array_values(array_filter(array_keys(VIRTUSPHERE_PLAYBOOKS), static fn (string $key): bool => in_array($key, $used, true)));
    }

    private static function figure(string $locale): string
    {
        $partial = dirname(__DIR__, 2) . '/' . self::PARTIAL;
        self::assertFileExists($partial, self::PARTIAL . ' (the deploy-mode figure) does not exist yet.');
        require_once $partial;
        Lang::load($locale);

        return help_deploy_modes_figure();
    }
}
