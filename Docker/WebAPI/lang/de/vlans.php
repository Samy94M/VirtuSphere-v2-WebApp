<?php

declare(strict_types=1);

return [
    'title' => 'VLANs',
    'confirm_delete' => 'Zurückgezogenen Katalog-Eintrag :name entfernen? Existiert die Portgruppe auf ESXi noch, legt der nächste Abruf sie neu an.',
    'empty' => 'Keine VLANs gefunden.',
    'flash_deleted' => 'Katalog-Eintrag entfernt.',
    'catalog_hint' => 'Der VLAN-Katalog ist ESXi-owned (nur Anzeige): Portgruppen werden aus den registrierten ESXi-Zugangsdaten übernommen. Anlegen und Bearbeiten entfällt; verschwindet eine Portgruppe, wird der Eintrag zurückgezogen (nicht gelöscht). Zuweisungen in Missionen und VMs bleiben als Name erhalten. Die Spalte "Auf ESXi" zeigt, auf wie vielen erfolgreich abgerufenen Hosts die Portgruppe existiert; die Spalte "VLAN-ID" warnt, wenn derselbe Name auf verschiedenen Hosts unterschiedliche IDs trägt.',
    'status_active' => 'Aktiv',
    'status_retired' => 'Zurückgezogen',
    'status_all' => 'Alle',
    'th_hosts' => 'Auf ESXi (Zugangsdaten)',
    'no_hosts' => 'derzeit auf keinem Host im Inventar',
    'hosts_all' => 'auf allen :total Hosts im Inventar',
    'hosts_partial' => 'auf :count von :total Hosts im Inventar',
    'hosts_missing' => 'fehlt auf: :hosts',
    'th_vlan_id' => 'VLAN-ID',
    'vlan_id_one' => 'ID :id',
    'vlan_id_one_hosts' => 'ID :id auf: :hosts',
    'vlan_id_mismatch' => 'Unterschiedliche IDs',
    'vlan_id_trunk' => 'Trunk/Bereich auf: :hosts',
    'empty_catalog' => 'Noch keine Portgruppen im Katalog. Sobald ein ESXi-Zugangsdatum abgerufen wurde, erscheinen sie hier.',
];
