<?php

declare(strict_types=1);

/**
 * Mission export/import as portable JSON (Paket A2 / ADR-0021).
 *
 * Export produces a self-contained JSON document of one mission and its VMs,
 * interfaces (WITHOUT MAC addresses), disks and package references (by name).
 * Import re-creates the mission under a new name in one transaction, reusing the
 * same validation and write helpers as the portal editor and the template clone
 * path. It never carries MAC addresses, MECM ids or workflow state across: VMs
 * come in fresh (Registered / not_ready), exactly like a template clone.
 *
 * This is a data-transfer feature, not a backup/restore path: it never touches
 * the dumps and never writes primary keys from the file.
 */

require_once __DIR__ . '/repo/missions.php';
require_once __DIR__ . '/repo/vms.php';
require_once __DIR__ . '/repo/catalog.php';
// esxi_inventory_name_key(): the project-wide SSoT for name equality, used by
// the import analysis to fold two spellings of one VLAN into one finding.
require_once __DIR__ . '/repo/esxi_inventory_cache.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/defaults.php';

const VIRTUSPHERE_MISSION_EXPORT_VERSION = 1;

// Upload/parse guards for import (A4). ~500 VMs is roughly 1 MB; 2 MB leaves
// headroom and can be raised centrally if a customer needs bigger missions.
const VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES = 2 * 1024 * 1024;
const VIRTUSPHERE_MISSION_IMPORT_JSON_DEPTH = 32;
// Server-side dry-run hand-off lifetime (A4): the parsed payload is kept in the
// session between preview and confirm so the file is not re-uploaded.
const VIRTUSPHERE_MISSION_IMPORT_TTL_SECONDS = 600;

/**
 * Mission columns carried by the transfer format (no id/timestamps).
 *
 * Adding a field here does NOT bump VIRTUSPHERE_MISSION_EXPORT_VERSION: the
 * import reads keys it finds and lets the validator default the rest, so an
 * export written before a field existed still imports. Bumping the version would
 * make every file already on disk unimportable, since the check is an equality.
 */
const VIRTUSPHERE_MISSION_TRANSFER_MISSION_FIELDS = [
    'mission_status',
    'mission_notes',
    'wds_vlan',
    'hypervisor_datastorage',
    'hypervisor_datacenter',
    'domain',
    ...REPO_MISSION_AUTOSTART_COLUMNS,
];

/** Interface columns carried by the transfer format (MAC deliberately excluded). */
const VIRTUSPHERE_MISSION_TRANSFER_INTERFACE_FIELDS = [
    'ip', 'subnet', 'gateway', 'dns1', 'dns2', 'vlan', 'mode', 'type',
];

/**
 * Disk columns carried by the transfer format.
 *
 * A list, like the two above, and read by BOTH directions: the export writes
 * exactly these keys and the import projects exactly these keys. Before this
 * constant existed the two sides each spelled the three names out, which is how
 * a `disks: "oops"` could be counted as one disk by the report and written as
 * zero disks by the repo.
 */
const VIRTUSPHERE_MISSION_TRANSFER_DISK_FIELDS = ['disk_name', 'disk_size', 'disk_type'];

/**
 * Package reference keys, mapped to the catalog columns they are resolved
 * against: transfer key => `deploy_packages` column. The transfer format names
 * them `name`/`version` because a reference is not a row.
 */
const VIRTUSPHERE_MISSION_TRANSFER_PACKAGE_FIELDS = [
    'name' => 'package_name',
    'version' => 'package_version',
];

// The canonical reading of an uploaded document, shared by the preview report
// and the write. Loaded after the field lists it projects.
require_once __DIR__ . '/mission_transfer_document.php';
require_once __DIR__ . '/mission_transfer_field_errors.php';
require_once __DIR__ . '/mission_transfer_export.php';
require_once __DIR__ . '/mission_transfer_import.php';
