<?php

declare(strict_types=1);

return [
    'heading' => 'Verwendet in',
    'link' => 'Verwendet in',
    'link_label' => 'Verwendet in: :name',
    'shown' => 'Angezeigt: :shown von :total.',
    'job_label' => 'Auftrag #:id',
    'job_system' => 'Systemauftrag',
    'object_template' => 'Vorlage',
    'object_mission' => 'Mission',
    'group_direct_package' => 'Auf VMs zugewiesen',
    'group_direct_os' => 'Auf VMs eingetragen',
    'group_direct_vlan' => 'An Netzwerkkarten von VMs eingetragen',
    'group_direct_credential' => 'VMs, die über diesen Zugang angelegt oder erkannt wurden',
    'group_mission_vlan' => 'Als WDS-Portgruppe in Missionen eingetragen',
    'group_job_open' => 'Aktive oder geplante Aufträge',
    'group_job_history' => 'Frühere Aufträge mit Mission',
    'empty_package' => 'Keine VM verwendet dieses Paket.',
    'empty_os' => 'Keine VM verwendet dieses Betriebssystem.',
    'empty_vlan' => 'Keine Netzwerkkarte und keine Mission verwendet diese Portgruppe.',
    'empty_credential_esxi' => 'Kein Auftrag und keine VM verwendet diesen Zugang.',
    'empty_credential_ansible' => 'Kein Auftrag verwendet diesen Zugang.',
    'empty_generic' => 'Keine Verwendung gefunden.',
    'note_vm_binding' => 'Eine VM zählt, sobald sie über diesen Zugang angelegt oder ihre MAC-Adresse über ihn exportiert wurde. Ältere VMs ohne diese Angabe fehlen hier, auch wenn sie den Zugang nutzen.',
    'note_ansible_jobs' => 'Für Ansible-Zugänge zählen nur Aufträge.',
];
