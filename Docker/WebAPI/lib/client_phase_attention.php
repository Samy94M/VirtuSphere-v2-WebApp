<?php

declare(strict_types=1);

require_once __DIR__ . '/repo/client_events.php';
require_once __DIR__ . '/vm_urls.php';

/** @param list<array<string,mixed>> $failures */
function client_phase_attention_render(array $failures): void
{
    if ($failures === []) {
        return;
    }
    echo '<div class="alert alert-warning"><p>' . portal_badge('warning', __t('missions.client_phase_failed', ['count' => count($failures)])) . '</p><ul>';
    foreach ($failures as $vm) {
        echo '<li><a href="' . h(vm_edit_url((int) $vm['mission_id'], (int) $vm['id'])) . '">' . h((string) $vm['vm_name']) . '</a></li>';
    }
    echo '</ul></div>';
}
