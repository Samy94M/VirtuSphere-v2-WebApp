<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_mission_busy_exception.php';

/**
 * R11 (docs/ai/reference/portal.md): a message that names another page or a
 * prerequisite carries the link to it. The gap search of 03.10.2026 found the
 * rule broken in two shapes, and this guard holds both:
 *
 *  - a catalog sentence that sends the operator to a portal page ("in the
 *    system status", "in the help") is rendered next to a link helper, or it
 *    is exempt below with its reason. The page names are derived from the
 *    navigation labels, never listed here, so a renamed or new page is
 *    covered without touching this file;
 *  - a guard translated by portal_error_message() either throws an exception
 *    that carries its action (PortalActionableError) or is exempt below with
 *    its reason. The map is read from the code, in both directions.
 */
final class MessageLinkContractTest extends TestCase
{
    /** Lines around a call site that may hold the link it renders. */
    private const WINDOW = 6;

    private const LINK_HELPERS = '/\b(help_url|system_status_url|settings_url|mission_details_url|vm_edit_url|deploy_job_log_url|deploy_mission_url|credentials_test_action)\s*\(|href=|[\'"]url[\'"]\s*=>/';

    /**
     * Catalog keys that name a page but need no link at their call site.
     *
     * @var array<string, string> key => reason
     */
    private const PAGE_EXEMPT = [
        'ansible_test.help' => 'Documentation in the credentials, settings and system-status help panels, explaining their current page rather than a standalone error.',
        'common.conn_ansible_host_identity' => 'Transport category mapping; credential and inventory presenters carry the host-identity remedy, not this mapping table.',
        'credentials.test_err_portal' => 'credentials_test_message() supplies text; credentials_test_action() supplies the settings link for the same result.',
        'credentials.test_warn_allowlist' => 'credentials_test_action() supplies the settings link for the same allowlist-warning predicate.',
        'credentials.test_warn_allowlist_noip' => 'credentials_test_action() supplies the settings link for the same allowlist-warning predicate, including unknown IP.',
        'credentials.test_esxi_queued' => 'credentials_actions.php supplies the credential-specific system-status link when it flashes this enqueue result.',
        'credentials.test_esxi_no_ansible' => 'The enqueue presenter supplies settings_url() for this result; this function returns only the message.',
        'credentials.test_esxi_ambiguous_ansible' => 'The enqueue presenter supplies settings_url() for this result; this function returns only the message.',
        'credentials.test_esxi_invalid_ansible' => 'The enqueue presenter supplies settings_url() for this result; this function returns only the message.',
        'deploy.err_datacenter_no_inventory' => 'Historical unused key; the queue decision uses the datacenter_name_* blockers and their inventory/mission actions. Removal is outside R11-03, which concerns the error map.',
    ];

    /**
     * portal_error_message() entries without an action, each with its reason.
     *
     * @var array<string, string> exact English guard message => reason
     */
    private const ERROR_EXEMPT = [
        'Mission has no VMs to deploy.' => 'The deploy form already shows the empty-mission blocker with its link; this guard only answers a race.',
        'Mission datastore is required before deployment.' => 'The deploy form already shows the datastore blocker with its link to the mission; this guard only answers a race.',
        'None of the selected VMs belong to this mission.' => 'The fix is the VM selection on the same form.',
        'Strict ESXi certificate verification requires HTTPS.' => 'The fix is a field of the same credential form.',
        'Strict ESXi certificate verification must pass a connection test before activation.' => 'The test button is on the same credential form.',
        'ESXi certificate is required.' => 'The fix is a field of the same credential form.',
        'VM was changed by another user. Reload before saving.' => 'The fix is reloading the same page.',
    ];

    public function testEveryMessageThatNamesAPageIsRenderedNextToALink(): void
    {
        self::assertSame([], $this->pageViolations($this->sources()));
    }

    public function testAMessageWhoseLinkWasRemovedIsReported(): void
    {
        $sources = $this->sources();
        $key = 'deploy.verbose_hint';
        $file = $this->fileContaining($sources, "'" . $key . "'");
        // Negative case: drop every link helper from the file that renders the
        // key. The same derivation must then name exactly this key.
        $sources[$file] = (string) preg_replace(self::LINK_HELPERS, 'no_link(', $sources[$file]);

        self::assertContains($key, array_keys($this->pageViolations($sources)));
    }

    public function testEveryTranslatedGuardCarriesAnActionOrAReason(): void
    {
        self::assertSame([], $this->errorViolations($this->sources()));
    }

    public function testAMappedGuardWhoseActionWasRemovedIsReported(): void
    {
        $sources = $this->sources();
        $message = 'Mission has an active deploy job.';
        $file = $this->fileContaining($sources, "new DeployMissionBusyException('" . $message . "'");
        $sources[$file] = str_replace('new DeployMissionBusyException(', 'new RuntimeException(', $sources[$file]);
        self::assertContains('"' . $message . '" has no action and no reason', $this->errorViolations($sources));
    }

    public function testAnActionableGuardMissingFromTheMapIsReported(): void
    {
        $sources = $this->sources();
        $sources['lib/new_guard.php'] = "<?php throw new DeployMissionBusyException('New operator guard.', 42);";
        self::assertContains('actionable guard "New operator guard." is not in the map', $this->errorViolations($sources));
    }

    /** @param array<string,string> $sources @return list<string> */
    private function errorViolations(array $sources): array
    {
        $map = $this->operatorErrorMap($sources);
        self::assertNotSame([], $map, 'the portal_error_message() map scan matched nothing; it cannot be trusted');

        $violations = [];
        foreach (array_keys($map) as $message) {
            $throwers = $this->throwingClasses($message, $sources);
            $actionable = $throwers !== [] && array_filter(
                $throwers,
                static fn (string $class): bool => !is_subclass_of($class, PortalActionableError::class)
            ) === [];
            $exempt = isset(self::ERROR_EXEMPT[$message]);
            if ($throwers === []) {
                $violations[] = 'nothing throws "' . $message . '"; the map entry is dead';
            } elseif ($actionable === $exempt) {
                $violations[] = '"' . $message . '" ' . ($actionable ? 'carries an action and is still exempt' : 'has no action and no reason');
            }
        }
        foreach (array_keys(self::ERROR_EXEMPT) as $message) {
            if (!isset($map[$message])) {
                $violations[] = 'exempt "' . $message . '" is not in the map any more';
            }
        }

        foreach ($sources as $source) {
            preg_match_all('/throw new ([A-Za-z_\\\\]+)\(\s*\'((?:[^\'\\\\]|\\\\.)+)\'/s', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $message = stripslashes($match[2]);
                if (is_subclass_of(ltrim($match[1], '\\'), PortalActionableError::class) && !isset($map[$message])) {
                    $violations[] = 'actionable guard "' . $message . '" is not in the map';
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * @param array<string,string> $sources relative path => source
     * @return array<string,string> key => violation
     */
    private function pageViolations(array $sources): array
    {
        $violations = [];
        $keys = $this->keysNamingAPage();
        foreach (self::PAGE_EXEMPT as $key => $reason) {
            if (!in_array($key, $keys, true) || trim($reason) === '') {
                $violations[$key] = 'stale or empty page exemption';
            }
        }
        foreach ($keys as $key) {
            if (isset(self::PAGE_EXEMPT[$key])) {
                continue;
            }
            $sites = 0;
            $linked = 0;
            foreach ($sources as $file => $source) {
                $lines = preg_split('/\R/', $source) ?: [];
                foreach ($lines as $number => $line) {
                    if (!str_contains($line, "'" . $key . "'")) {
                        continue;
                    }
                    $sites++;
                    $window = implode("\n", array_slice($lines, max(0, $number - self::WINDOW), 2 * self::WINDOW + 1));
                    if (preg_match(self::LINK_HELPERS, $window) === 1) {
                        $linked++;
                    }
                }
            }
            if ($sites === 0) {
                $violations[$key] = 'no literal call site; render it next to a link or exempt it with a reason';
            } elseif ($linked < $sites) {
                $violations[$key] = ($sites - $linked) . ' of ' . $sites . ' call sites render it without a link';
            }
        }

        return $violations;
    }

    /** @return list<string> catalog keys whose German text sends the reader to a portal page */
    private function keysNamingAPage(): array
    {
        $base = dirname(__DIR__, 2) . '/lang/de/';
        $pages = [];
        foreach (require $base . 'layout.php' as $key => $label) {
            if (str_starts_with($key, 'nav_') && !str_starts_with($key, 'nav_group_') && is_string($label)
                && !in_array($key, ['nav_primary_label', 'nav_toggle'], true)
            ) {
                $pages[] = preg_quote($label, '/');
            }
        }
        self::assertGreaterThan(5, count($pages), 'the navigation scan found almost no pages');
        $pattern = '/\b(im|in der|in den|unter|auf der Seite|auf die Seite|zur Seite|über die Seite)\s+„?(' . implode('|', $pages) . ')\b/u';

        $keys = [];
        foreach (glob($base . '*.php') ?: [] as $file) {
            $module = basename($file, '.php');
            if (str_starts_with($module, 'help')) {
                continue;
            }
            foreach (require $file as $key => $text) {
                if (is_string($text) && preg_match($pattern, $text) === 1) {
                    $keys[] = $module . '.' . $key;
                }
            }
        }
        self::assertNotSame([], $keys, 'no catalog sentence names a page; the scan cannot be trusted');
        sort($keys);

        return $keys;
    }

    /** @return array<string,string> exact English guard message => catalog key */
    private function operatorErrorMap(array $sources): array
    {
        $source = $sources['lib/layout_response.php'] ?? '';
        $start = strpos($source, '$operatorReachableErrors = [');
        self::assertNotFalse($start, 'portal_error_message() no longer holds its map in $operatorReachableErrors');
        $end = strpos($source, '];', (int) $start);
        preg_match_all("/'((?:[^'\\\\]|\\\\.)+)'\s*=>\s*'([a-z_]+\.[a-z0-9_]+)'/", substr($source, (int) $start, (int) $end - (int) $start), $matches, PREG_SET_ORDER);

        $map = [];
        foreach ($matches as $match) {
            $map[stripslashes($match[1])] = $match[2];
        }

        return $map;
    }

    /** @return list<string> exception classes thrown with exactly this message */
    private function throwingClasses(string $message, array $sources): array
    {
        $classes = [];
        $quoted = preg_quote(addslashes($message), '/');
        foreach ($sources as $file => $source) {
            if ($file === 'lib/layout_response.php') {
                continue;
            }
            preg_match_all('/throw new ([A-Za-z_\\\\]+)\(\s*\'' . $quoted . '\'/', $source, $matches);
            foreach ($matches[1] as $class) {
                $classes[] = ltrim($class, '\\');
            }
        }

        return array_values(array_unique($classes));
    }

    /** @param array<string,string> $sources */
    private function fileContaining(array $sources, string $needle): string
    {
        foreach ($sources as $file => $source) {
            if (str_contains($source, $needle)) {
                return $file;
            }
        }
        self::fail('no source renders ' . $needle);
    }

    /** @return array<string,string> relative path => source, first-party PHP only */
    private function sources(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $root = dirname(__DIR__, 2);
        $sources = [];
        foreach (['lib', 'portal'] as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    $sources[$relative] = (string) file_get_contents($file->getPathname());
                }
            }
        }
        self::assertArrayHasKey('lib/layout_response.php', $sources);

        return $cached = $sources;
    }
}
