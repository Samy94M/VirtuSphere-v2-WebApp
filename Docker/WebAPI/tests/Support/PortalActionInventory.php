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
     * @param array<string, string> $pages relative path => contents
     * @return array<string, bool> "page.php:action" => confirmed
     */
    public static function postableActions(array $pages): array
    {
        $actions = [];
        foreach ($pages as $path => $contents) {
            $page = basename($path);
            preg_match_all('/<form\b.*?<\/form>/s', $contents, $forms);
            foreach ($forms[0] as $block) {
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
        $pages = [];
        foreach (glob(str_replace('\\', '/', $webApiRoot) . '/portal/*.php') ?: [] as $path) {
            $pages['portal/' . basename($path)] = (string) file_get_contents($path);
        }

        return $pages;
    }
}
