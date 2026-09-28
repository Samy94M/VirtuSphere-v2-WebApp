<?php

declare(strict_types=1);

/**
 * AV-P0 (AV-F02): every queueing of a VM for the device-sync (operator
 * transfer, MECM reset) increments a counter. getDeviceList exports it and
 * updateDevice reports it back, so the portal clears `updated` only for the
 * transfer the sync actually applied and a newer one queued meanwhile stays.
 * Existing rows start at 0; a caller that reports no generation keeps the
 * previous behaviour.
 */
function migrate_0058_mecm_transfer_generation(mysqli $db): void
{
    migrator_add_column($db, 'deploy_vms', 'mecm_transfer_generation',
        'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER updated');

    migrator_out('0058: deploy_vms counts device-sync transfers so a newer transfer survives an older callback');
}
