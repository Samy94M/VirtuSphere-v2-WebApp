<?php

declare(strict_types=1);

return [
    'title' => 'VLANs',
    'confirm_delete' => 'Remove retired catalog entry :name? If the portgroup still exists on ESXi, the next fetch re-creates it.',
    'empty' => 'No VLANs found.',
    'flash_deleted' => 'Catalog entry removed.',
    'catalog_hint' => 'The VLAN catalog is ESXi-owned (display only): portgroups are taken from the registered ESXi credentials. Create and edit are gone; when a portgroup disappears the entry is retired (not deleted). Assignments in missions and VMs are kept as names. The "On ESXi" column shows on how many successfully fetched hosts the portgroup exists; the "VLAN ID" column warns when the same name carries different IDs on different hosts.',
    'status_active' => 'Active',
    'status_retired' => 'Retired',
    'status_all' => 'All',
    'th_hosts' => 'On ESXi (credentials)',
    'no_hosts' => 'not currently on any host in the inventory',
    'hosts_all' => 'on all :total hosts in the inventory',
    'hosts_partial' => 'on :count of :total hosts in the inventory',
    'hosts_missing' => 'missing on: :hosts',
    'th_vlan_id' => 'VLAN ID',
    'vlan_id_one' => 'ID :id',
    'vlan_id_one_hosts' => 'ID :id on: :hosts',
    'vlan_id_mismatch' => 'Different IDs',
    'vlan_id_trunk' => 'Trunk/range on: :hosts',
    'empty_catalog' => 'No portgroups in the catalog yet. Once an ESXi credential has been fetched, they appear here.',
];
