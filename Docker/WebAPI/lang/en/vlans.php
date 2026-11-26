<?php

declare(strict_types=1);

return [
    'title' => 'Port groups',
    'confirm_delete' => 'Delete retired catalog entry :name? If the port group still exists on ESXi, the next fetch re-creates it.',
    'flash_deleted' => 'Catalog entry removed.',
    'catalog_hint' => 'The port group catalog is ESXi-owned (display only): port groups are taken from the ESXi credentials registered on the "Credentials" page. There is no create or edit here; when a port group disappears the entry is retired (not deleted). Assignments in missions and VMs are kept as names. The "On ESXi" column shows on how many successfully fetched hosts the port group exists; the "VLAN ID" column warns when the same name carries different IDs on different hosts.',
    'link_credentials' => 'Open credentials',
    'status_active' => 'Active',
    'filter_label' => 'Status filter',
    'filter_apply' => 'Filter',
    'empty_filtered' => 'No port group matches this status filter.',
    'show_all' => 'Show all entries',
    'status_retired' => 'Retired',
    'status_all' => 'All',
    'th_hosts' => 'On ESXi (credentials)',
    'no_hosts' => 'Not currently on any host in the inventory',
    'hosts_all_one' => 'On the one host in the inventory',
    'hosts_all_many' => 'On all :total hosts in the inventory',
    'hosts_partial' => 'On :count of :total hosts in the inventory',
    'hosts_missing' => 'Missing on: :hosts',
    'th_vlan_id' => 'VLAN ID',
    'vlan_id_one' => 'ID :id',
    'vlan_id_one_hosts' => 'ID :id on: :hosts',
    'vlan_id_mismatch' => 'Different IDs',
    'vlan_id_trunk' => 'Trunk/range on: :hosts',
    'empty_catalog' => 'No port groups in the catalog yet. Once an ESXi credential has been fetched, they appear here.',
];
