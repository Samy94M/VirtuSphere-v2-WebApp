<?php

declare(strict_types=1);

const VIRTUSPHERE_VM_EDIT_ANCHORS = ['interfaces'];

function vm_edit_url(int $missionId, int $vmId, ?string $anchor = null): string
{
    if ($missionId <= 0 || $vmId <= 0) {
        throw new InvalidArgumentException('Mission and VM ids are required.');
    }
    if ($anchor !== null && !in_array($anchor, VIRTUSPHERE_VM_EDIT_ANCHORS, true)) {
        throw new InvalidArgumentException('Unknown VM editor anchor.');
    }

    return 'vm_edit.php?mission_id=' . $missionId . '&id=' . $vmId . ($anchor !== null ? '#' . $anchor : '');
}
