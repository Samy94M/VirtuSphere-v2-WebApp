<?php

declare(strict_types=1);

/**
 * The one scan that defines "every action a portal form can POST".
 *
 * Two contracts consume it and must not drift apart: PortalConfirmContractTest
 * decides whether each action is confirmed or declared safe, and
 * E2eActionCoverageContractTest decides whether each action is proven in the
 * browser (E6). If the scan lived in both files, a markup pattern added to one
 * copy would silently shrink the other contract's world.
 */
final class PortalActionInventory
{
    /**
     * Every action a portal form can POST, and whether it is confirmed.
     *
     * Where the action rides on the submit button itself (`<button name="action"
     * value="delete">`) the prompt must sit on that button: one form can hold two
     * such buttons (the VM bulk actions) and a form-wide check would let the
     * second one through unconfirmed. Where the action rides on a hidden input,
     * the form has a single submit and the form-wide check is the right scope.
     *
     * A form belongs to the page its action names (see actionTarget()); only a
     * form without a literal target belongs to the page its file is named after.
     * The page that renders a form is not the page that receives its POST: a
     * shared renderer such as lib/system_status_remote_secret_notice.php posts to
     * portal/credentials.php, and its action belongs to credentials.php.
     *
     * @param array<string, string> $pages relative path => contents
     * @return array<string, bool> "page.php:action" => confirmed
     */
    public static function postableActions(array $pages): array
    {
        $actions = [];
        foreach ($pages as $path => $contents) {
            $owner = basename($path);
            preg_match_all('/<form\b.*?<\/form>/s', $contents, $forms);
            foreach ($forms[0] as $block) {
                $page = self::actionTarget($block) ?? $owner;
                preg_match_all('/<button\b[^>]*>/', $block, $buttons);
                foreach ($buttons[0] as $tag) {
                    if (preg_match('/name="action"\s+value="([a-z_]+)"/', $tag, $m) === 1) {
                        $actions[$page . ':' . $m[1]] = str_contains($tag, 'data-confirm=');
                    }
                }

                preg_match_all('/<input\b[^>]*name="action"[^>]*value="([a-z_]+)"[^>]*>/', $block, $hidden);
                foreach ($hidden[1] as $action) {
                    $key = $page . ':' . $action;
                    // A page may render the same action twice (a per-row form and a
                    // bulk form); a single unconfirmed rendering is a finding.
                    $confirmed = str_contains($block, 'data-confirm=');
                    $actions[$key] = ($actions[$key] ?? true) && $confirmed;
                }
            }
        }

        return $actions;
    }

    /** @return array<string, string> relative path => contents */
    public static function portalPages(string $webApiRoot): array
    {
        $root = self::normalize($webApiRoot);
        $pages = [];
        foreach (glob($root . '/portal/*.php') ?: [] as $path) {
            $pages['portal/' . basename($path)] = (string) file_get_contents($path);
        }

        // A page that outgrew the ADR-0006 line budget keeps its forms in
        // lib/<page>_*.php renderers, and those forms still post to the page, so
        // they belong to its one closed action inventory. The rule is DERIVED
        // from the page names, never a list of renderers: a per-page glob written
        // by hand is the same defect one level up, and it had already bitten
        // twice - the ESXi refresh and the VLAN reassign vanished the moment they
        // moved into a second system_status module, and the mission import
        // vanished the moment its panel moved out of missions.php. A new split
        // now needs no edit here; a renderer named after no page is not a page
        // renderer and stays out.
        foreach (array_keys($pages) as $page) {
            $prefix = basename($page, '.php');
            foreach (glob($root . '/lib/' . $prefix . '_*.php') ?: [] as $renderer) {
                $pages[$page] .= "\n" . (string) file_get_contents($renderer);
            }
            foreach (glob($root . '/lib/' . $prefix . '/*.php') ?: [] as $renderer) {
                $pages[$page] .= "\n" . (string) file_get_contents($renderer);
            }
        }

        // A lib form whose file is named after no page cannot be reached by the
        // name rule, so it joins the page its action names, and only its own
        // <form> block: a whole shared layout file would put its dialog into
        // every page. A form with no literal target stays out and is listed by
        // unattributedForms() instead of being guessed onto a page.
        foreach (self::formSites($root) as $site) {
            if ($site['owners'] === [] && $site['target'] !== null) {
                $key = 'portal/' . $site['target'];
                $pages[$key] = ($pages[$key] ?? '') . "\n" . $site['block'];
            }
        }

        return $pages;
    }

    /**
     * Every form of portal/*.php and of the .php files under lib, in file order.
     *
     * `owners` is the list of portal pages the file is named after (empty for a
     * lib file that matches no page). `target` is the page the action names, or
     * null. Tests assert on this list: a form whose target is not a portal page
     * must fail there, not vanish from the inventory.
     *
     * @return list<array{file: string, line: int, block: string, owners: list<string>, target: ?string}>
     */
    public static function formSites(string $webApiRoot): array
    {
        $root = self::normalize($webApiRoot);
        $owners = self::ownerMap($root);

        $files = [];
        foreach (glob($root . '/portal/*.php') ?: [] as $path) {
            $files['portal/' . basename($path)] = [self::normalize($path), [basename($path)]];
        }
        foreach (self::libFiles($root) as $path) {
            $files[substr($path, strlen($root) + 1)] = [$path, $owners[$path] ?? []];
        }

        $sites = [];
        foreach ($files as $file => [$path, $fileOwners]) {
            $source = (string) file_get_contents($path);
            if (preg_match_all('/<form\b.*?<\/form>/s', $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($matches[0] as [$block, $offset]) {
                $sites[] = [
                    'file' => $file,
                    'line' => substr_count($source, "\n", 0, $offset) + 1,
                    'block' => $block,
                    'owners' => $fileOwners,
                    'target' => self::actionTarget($block),
                ];
            }
        }

        return $sites;
    }

    /**
     * Lib forms that name no page by file and no page by action. They are not
     * attributed to any page yet; the list is the open question, not a silent gap.
     *
     * @return list<string> "lib/file.php:line"
     */
    public static function unattributedForms(string $webApiRoot): array
    {
        $report = [];
        foreach (self::formSites($webApiRoot) as $site) {
            if (str_starts_with($site['file'], 'lib/') && $site['owners'] === [] && $site['target'] === null) {
                $report[] = $site['file'] . ':' . $site['line'];
            }
        }

        return $report;
    }

    /**
     * The page a form names in its action attribute. `x.php`, `x.php?query` and
     * `x.php<?php echo ... ?>` all name x.php: the PHP part only adds a query and
     * never changes the page. Null when the action is absent, empty or only a PHP
     * expression (`action="<?php echo h($url); ?>"`); nothing in the source says
     * which page such a URL is, so those forms keep the page of their file.
     */
    private static function actionTarget(string $block): ?string
    {
        // The PHP parts are removed first, so an arrow or a PHP closing tag inside
        // them cannot end the opening tag early.
        $markup = (string) preg_replace('/<\?php.*?\?>/s', '', $block);
        if (preg_match('/^<form\b[^>]*>/', $markup, $open) !== 1) {
            return null;
        }
        if (preg_match('/\baction\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+))/', $open[0], $action) !== 1) {
            return null;
        }

        $value = $action[1];
        if ($value === '' && isset($action[2])) {
            $value = $action[2];
        }
        if ($value === '' && isset($action[3])) {
            $value = $action[3];
        }
        if (preg_match('/^([^?\s]*\.php)(?:\?|$)/', $value, $target) !== 1) {
            return null;
        }

        return $target[1];
    }

    /**
     * Normalised lib path => the portal pages whose renderers it is, by the name
     * rule that portalPages() applies to whole files.
     *
     * @return array<string, list<string>>
     */
    private static function ownerMap(string $root): array
    {
        $owners = [];
        foreach (glob($root . '/portal/*.php') ?: [] as $page) {
            $prefix = basename($page, '.php');
            $renderers = array_merge(
                glob($root . '/lib/' . $prefix . '_*.php') ?: [],
                glob($root . '/lib/' . $prefix . '/*.php') ?: [],
            );
            foreach ($renderers as $renderer) {
                $owners[self::normalize($renderer)][] = basename($page);
            }
        }

        return $owners;
    }

    /**
     * Normalised paths of every .php file under lib, recursively, in sorted order.
     *
     * @return list<string>
     */
    private static function libFiles(string $root): array
    {
        $files = [];
        $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/lib', FilesystemIterator::SKIP_DOTS));
        foreach ($tree as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = self::normalize($file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    /** Forward slashes, no `.` or `..` segments; a drive letter or a leading slash is kept. */
    private static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return (str_starts_with($path, '/') ? '/' : '') . implode('/', $segments);
    }
}
