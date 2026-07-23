<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/system_status.php';

final class FlashActionTest extends TestCase
{
    public function testRelativePortalActionIsAccepted(): void
    {
        $url = system_status_url('credential-12', ['inventory' => 12]);
        self::assertSame(
            ['url' => 'system_status.php?inventory=12#credential-12', 'label' => 'Status ansehen'],
            flash_action_normalize([
                'url' => $url,
                'label' => 'Status ansehen',
            ])
        );
        self::assertSame('system_status.php?inventory=12#credential-12', $url);
    }

    public function testExternalAndTraversalActionsAreRejected(): void
    {
        foreach ([
            'https://example.invalid/',
            '//example.invalid/',
            '/portal/system_status.php',
            '../system_status.php',
            'system_status.php bad',
            'folder\\system_status.php',
        ] as $url) {
            self::assertNull(flash_action_normalize(['url' => $url, 'label' => 'Go']), $url);
        }
    }

    public function testActionMarkupEscapesUrlAndLabel(): void
    {
        $html = flash_alert_html([
            'type' => 'success',
            'message' => 'Saved',
            'action' => ['url' => 'system_status.php?x=1&y=2', 'label' => '<Status>'],
        ]);
        self::assertStringContainsString('system_status.php?x=1&amp;y=2', $html);
        self::assertStringContainsString('&lt;Status&gt;', $html);
        self::assertStringNotContainsString('<Status>', $html);
    }
}
