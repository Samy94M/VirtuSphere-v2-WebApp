<?php

declare(strict_types=1);

require_once __DIR__ . '/portal_work_context.php';

const VIRTUSPHERE_VM_EDIT_ANCHORS = ['interfaces'];

function vm_edit_url(int $missionId, int $vmId, ?string $anchor = null, array $workContext = []): string
{
    if ($missionId <= 0 || $vmId <= 0) {
        throw new InvalidArgumentException('Mission and VM ids are required.');
    }
    if ($anchor !== null && !in_array($anchor, VIRTUSPHERE_VM_EDIT_ANCHORS, true)) {
        throw new InvalidArgumentException('Unknown VM editor anchor.');
    }

    return portal_work_context_append_url(
        'vm_edit.php?mission_id=' . $missionId . '&vm_id=' . $vmId . ($anchor !== null ? '#' . $anchor : ''),
        $workContext
    );
}
