<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A confirmation dialog for a row action has to name the row it is about.
 * `.claude/rules/portal.md` states the reason: without the name, a dialog raised
 * from one row is indistinguishable from the same dialog raised from another, and
 * an operator with several similar-looking rows confirms blind. The dialog is
 * shared markup, so nothing in the toolchain notices when a new row action ships
 * a generic question.
 *
 * Every confirm message therefore has to be classified here: it either names its
 * target (ROW_ACTIONS, and then `:name` must be in both catalogs) or it has no
 * single target to name (NO_TARGET, with the reason). A new confirm key fails the
 * build until somebody decides which it is, the same contract SAFE_ACTIONS uses
 * for whether an action is confirmed at all.
 *
 * Static source and catalog scan, no DB.
 */
final class PortalConfirmNamingContractTest extends TestCase
{
    /** Confirms that act on one identifiable object: the question must name it. */
    private const ROW_ACTIONS = [
        'credentials.confirm_delete',
        'deploy.confirm_cancel',
        'deploy.confirm_retry',
        'deploy.confirm_retry_partial',
        'deploy.confirm_retry_partial_many',
        'deploy.confirm_retry_partial_one',
        'missions.confirm_delete',
        'missions.confirm_delete_empty',
        'missions.confirm_delete_scheduled',
        'missions.confirm_delete_template',
        'os.confirm_delete_many',
        'os.confirm_delete_one',
        'os.confirm_delete_unused',
        'portal.vm_mecm_reset_confirm',
        'settings.allowlist_confirm_delete',
        'users.confirm_deactivate',
        'users.confirm_reset_password',
        'users.confirm_role',
        'vlans.confirm_delete',
        'vms.confirm_delete',
    ];

    /** Confirms with no single target, and why naming one would be wrong. */
    private const NO_TARGET = [
        'deploy.confirm_cancel_group' => 'cancels a whole staggered batch; the batch is the target, not a row',
        'integrations.reassign_confirm' => 'the target field (vlan_from) is an editable input, so a name rendered server-side would state a value the operator may have changed since',
        'settings.https_confirm_disable' => 'a global switch, not a row',
        'settings.https_confirm_overwrite' => 'a global switch, not a row',
        'settings.https_confirm_redirect' => 'a global switch, not a row',
        'settings.report_token_confirm_clear' => 'the one machine-API token, not a row',
        'settings.report_token_confirm_regenerate' => 'the one machine-API token, not a row',
        'users.confirm_role_self' => 'the target is the signed-in user themselves; "your own role" is the name',
        'vms.bulk_confirm_delete' => 'acts on the checkbox selection, which has no single name',
        'vms.bulk_confirm_reset' => 'acts on the checkbox selection, which has no single name',
    ];

    /** @return array<string, string> path => source */
    private function portalPages(): array
    {
        $root = dirname(__DIR__, 2);
        $pages = [];
        foreach (glob($root . '/portal/*.php') ?: [] as $file) {
            $pages[$file] = (string) file_get_contents($file);
        }
        self::assertNotSame([], $pages, 'no portal pages found');

        return $pages;
    }

    /**
     * The catalog key behind every rendered confirmation. A prompt is usually
     * inlined in the attribute, but three pages pick the sentence first (the
     * mission's delete wording depends on template/scheduled/empty, the OS one on
     * the usage count, the role one on whether it is your own), so a key assigned
     * to a variable counts as rendered too.
     *
     * @return string[]
     */
    private function renderedConfirmKeys(): array
    {
        $keys = [];
        foreach ($this->portalPages() as $source) {
            // Inlined: the __t() call sits inside the attribute itself. Non-greedy
            // on purpose: an attribute may hold a second __t() as a substitution
            // value (the deploy cancel prompt falls back to the system-job label
            // when the job has no mission), and a greedy match would capture that
            // one as the message key.
            preg_match_all('/data-confirm="[^"]*?__t\(\'([a-z0-9_.]+)\'/', $source, $inline);
            foreach ($inline[1] as $key) {
                $keys[$key] = true;
            }

            // Picked first: the attribute echoes nothing but a variable that was
            // assigned the sentence above. Anchored to the whole attribute value,
            // because a variable merely *appearing* inside a longer expression
            // (the deploy prompt reads $job) would otherwise drag every __t() from
            // that variable's unrelated assignments into the scan.
            preg_match_all('/data-confirm="<\?php echo h\(\$([a-zA-Z_]+)\); \?>"/', $source, $vars);
            foreach (array_unique($vars[1]) as $var) {
                if (!preg_match_all('/\$' . preg_quote($var, '/') . '\s*=[^;]*/s', $source, $assignments)) {
                    continue;
                }
                foreach ($assignments[0] as $assignment) {
                    preg_match_all('/__t\(\'([a-z0-9_.]+)\'/', $assignment, $found);
                    foreach ($found[1] as $key) {
                        $keys[$key] = true;
                    }
                }
            }
        }

        self::assertNotSame([], $keys, 'the confirm scan matched nothing; the markup or the regex changed');

        return array_keys($keys);
    }

    private function catalogText(string $locale, string $key): string
    {
        [$module, $name] = explode('.', $key, 2);
        $file = dirname(__DIR__, 2) . '/lang/' . $locale . '/' . $module . '.php';
        self::assertFileExists($file, 'no ' . $locale . ' catalog for module ' . $module);
        /** @var array<string, string> $strings */
        $strings = require $file;
        self::assertArrayHasKey($name, $strings, $key . ' is missing from the ' . $locale . ' catalog');

        return (string) $strings[$name];
    }

    public function testEveryRenderedConfirmIsClassified(): void
    {
        $classified = array_merge(self::ROW_ACTIONS, array_keys(self::NO_TARGET));
        $unclassified = array_values(array_diff($this->renderedConfirmKeys(), $classified));
        sort($unclassified);

        self::assertSame(
            [],
            $unclassified,
            "A confirmation prompt is rendered whose key is in neither list. Decide which it is:\n"
            . "  - it acts on one identifiable row  -> add to ROW_ACTIONS and put :name in both catalogs\n"
            . "  - it has no single target          -> add to NO_TARGET with the reason\n"
            . 'unclassified: ' . implode(', ', $unclassified)
        );
    }

    public function testRowActionsNameTheirTargetInBothLocales(): void
    {
        $missing = [];
        foreach (self::ROW_ACTIONS as $key) {
            foreach (['de', 'en'] as $locale) {
                if (!str_contains($this->catalogText($locale, $key), ':name')) {
                    $missing[] = $locale . '/' . $key;
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            "A row action's confirmation does not name the row it is about, so it cannot be told apart from the\n"
            . "same prompt raised by a neighbouring row: " . implode(', ', $missing)
        );
    }

    /**
     * The other direction: a prompt with no single target must not carry a
     * placeholder nothing fills, which would render the literal ":name".
     */
    public function testNoTargetPromptsCarryNoNamePlaceholder(): void
    {
        $stray = [];
        foreach (array_keys(self::NO_TARGET) as $key) {
            foreach (['de', 'en'] as $locale) {
                if (str_contains($this->catalogText($locale, $key), ':name')) {
                    $stray[] = $locale . '/' . $key;
                }
            }
        }

        self::assertSame([], $stray, 'a NO_TARGET prompt carries :name, which nothing substitutes: ' . implode(', ', $stray));
    }

    /** A list entry for a prompt nobody renders any more is dead weight. */
    public function testTheListsHaveNoStaleEntries(): void
    {
        $rendered = $this->renderedConfirmKeys();
        $stale = array_values(array_diff(array_merge(self::ROW_ACTIONS, array_keys(self::NO_TARGET)), $rendered));
        sort($stale);

        self::assertSame([], $stale, 'the lists name a confirm prompt no portal page renders any more; delete the entry: ' . implode(', ', $stale));
    }
}
