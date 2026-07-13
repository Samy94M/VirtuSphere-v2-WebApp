<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * settings.php spreads its forms over four tabs and re-opens the posting tab
 * after the redirect via a URL fragment ($actionTabs => #panel-<tab>). php -l
 * cannot see a form whose action is missing from that map: the save itself
 * works, but the redirect falls back to the first tab, a sticky validation
 * error renders inside a hidden panel and the one-time report token can go
 * unseen. This pins map and markup to each other in both directions.
 */
final class SettingsTabRedirectContractTest extends TestCase
{
    private const PAGE = 'portal/settings.php';

    private function source(): string
    {
        $path = str_replace('\\', '/', dirname(__DIR__, 2)) . '/' . self::PAGE;
        self::assertFileExists($path, self::PAGE . ' must exist');

        return (string) file_get_contents($path);
    }

    /** @return array<string, string> action => tab key, parsed from the $actionTabs literal */
    private function actionTabs(): array
    {
        $source = $this->source();
        self::assertSame(
            1,
            preg_match('/\$actionTabs\s*=\s*\[(.*?)\];/s', $source, $match),
            'settings.php must define the $actionTabs action-to-tab map exactly once'
        );

        preg_match_all("/'([a-z_]+)'\s*=>\s*'([a-z-]+)'/", $match[1], $pairs, PREG_SET_ORDER);
        $map = [];
        foreach ($pairs as $pair) {
            $map[$pair[1]] = $pair[2];
        }

        self::assertNotSame([], $map, 'the $actionTabs map parsed empty; the literal or the regex changed');

        return $map;
    }

    /** @return list<string> actions the page's forms can POST */
    private function postedActions(): array
    {
        $source = $this->source();
        $actions = [];

        preg_match_all('/<input\b[^>]*name="action"[^>]*value="([a-z_]+)"[^>]*>/', $source, $hidden);
        preg_match_all('/<button\b[^>]*name="action"\s+value="([a-z_]+)"[^>]*>/', $source, $buttons);
        foreach (array_merge($hidden[1], $buttons[1]) as $action) {
            $actions[$action] = true;
        }

        self::assertNotSame([], $actions, 'the action scan matched nothing; the markup or the regex changed');

        return array_keys($actions);
    }

    /** @return list<string> tab keys that render as a tabpanel */
    private function renderedTabs(): array
    {
        preg_match_all('/id="panel-([a-z-]+)"[^>]*role="tabpanel"/', $this->source(), $panels);
        self::assertNotSame([], $panels[1], 'no tabpanels found; the tab markup or the regex changed');

        return $panels[1];
    }

    public function testEveryPostedActionReturnsToItsTab(): void
    {
        $missing = array_diff($this->postedActions(), array_keys($this->actionTabs()));
        sort($missing);
        self::assertSame(
            [],
            $missing,
            "settings.php posts an action without an \$actionTabs entry; its redirect would\n"
            . 'fall back to the first tab and hide the form it came from. Map it to its tab.'
        );
    }

    public function testMapNamesNoActionThePageCannotPost(): void
    {
        $stale = array_diff(array_keys($this->actionTabs()), $this->postedActions());
        sort($stale);
        self::assertSame([], $stale, '$actionTabs names an action no settings form posts any more; delete the entry');
    }

    public function testEveryMappedTabIsRendered(): void
    {
        $unknown = array_diff(array_unique(array_values($this->actionTabs())), $this->renderedTabs());
        sort($unknown);
        self::assertSame([], $unknown, '$actionTabs points at a tab key without a rendered tabpanel; the anchor would go nowhere');
    }
}
