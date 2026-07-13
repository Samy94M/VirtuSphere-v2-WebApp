<?php

declare(strict_types=1);

/**
 * Reused portal terms (buttons, table headers, states).
 */
return [
    'name' => 'Name',
    'status' => 'Status',
    'vms' => 'VMs',
    'updated' => 'Updated',
    'actions' => 'Actions',
    'details' => 'Details',
    'mission' => 'Mission',
    'back' => 'Back',
    'refresh' => 'Refresh',
    'cancel' => 'Cancel',
    'confirm' => 'Confirm',
    'save' => 'Save',
    'delete' => 'Delete',
    'edit' => 'Edit',
    'create' => 'Create',
    'remove' => 'Remove',
    'type' => 'Type',
    'yes' => 'Yes',
    'no' => 'No',
    'export_csv' => 'CSV export',
    'sort_by' => 'Sort',
    'free_suffix' => ':free free',
    'creator_unknown' => 'Unknown',
    'technical_details' => 'Technical details',

    // Connection failures (VIRTUSPHERE_INVENTORY_ERROR_*): portal wording.
    'conn_dns' => 'The host ":host" could not be resolved. Check the host name.',
    'conn_unreachable' => 'The host ":host" is unreachable. Check port, network and firewall.',
    'conn_tls' => 'The TLS connection to ":host" was rejected. Check the host certificate.',
    'conn_auth' => 'The login was rejected. User name or secret is wrong.',
    'conn_authz' => 'The login succeeded, but the account lacks the required permissions.',
    'conn_http' => 'The host answered with HTTP :status.',
    'conn_ssh' => 'The SSH connection to ":host" failed.',
    'conn_parse' => 'The host answered unexpectedly.',
    'conn_config' => 'The credential is incomplete or its type is not supported.',
    'conn_unknown' => 'The connection failed.',
];
