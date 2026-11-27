<?php

declare(strict_types=1);

return [
    'title' => 'Portgruppen',
    'confirm_delete' => 'Zurückgezogenen Katalog-Eintrag :name löschen? Existiert die Portgruppe auf ESXi noch, legt der nächste Abruf sie neu an.',
    'flash_deleted' => 'Katalog-Eintrag entfernt.',
    'catalog_hint' => 'Die Portgruppen kommen aus dem Inventar der ESXi-Zugänge und werden hier nur angezeigt. Verschwindet eine Portgruppe, wird der Eintrag zurückgezogen, nicht gelöscht; Zuweisungen in Missionen und VMs bleiben als Name erhalten.',
    'link_credentials' => 'Zugangsdaten öffnen',
    'status_active' => 'Aktiv',
    'filter_label' => 'Statusfilter',
    'filter_apply' => 'Filtern',
    'empty_filtered' => 'Keine Portgruppe passt zu diesem Statusfilter.',
    'show_all' => 'Alle Einträge anzeigen',
    'status_retired' => 'Zurückgezogen',
    'status_all' => 'Alle',
    'th_hosts' => 'Auf ESXi (Zugangsdaten)',
    'no_hosts' => 'Derzeit auf keinem Host im Inventar',
    'hosts_all_one' => 'Auf dem einen Host im Inventar',
    'hosts_all_many' => 'Auf allen :total Hosts im Inventar',
    'hosts_partial' => 'Auf :count von :total Hosts im Inventar',
    'hosts_missing' => 'Fehlt auf: :hosts',
    'th_vlan_id' => 'VLAN-ID',
    'vlan_id_one' => 'ID :id',
    'vlan_id_one_hosts' => 'ID :id auf: :hosts',
    'vlan_id_mismatch' => 'Unterschiedliche IDs',
    'vlan_id_trunk' => 'Trunk/Bereich auf: :hosts',
    'empty_catalog' => 'Noch keine Portgruppen im Katalog. Sobald ein ESXi-Zugang abgerufen wurde, erscheinen sie hier.',
];
