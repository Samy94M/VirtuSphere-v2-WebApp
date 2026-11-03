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
    'group_direct_vlan' => 'Auf VM-Netzwerkkarten eingetragen',
    'group_direct_credential' => 'VMs mit nachgewiesener Bindung',
    'group_mission_vlan' => 'Als WDS-Portgruppe in Missionen eingetragen',
    'group_job_open' => 'Aktive oder geplante Aufträge',
    'group_job_history' => 'Frühere Aufträge mit Mission',
    'empty_package' => 'Keine VM verwendet dieses Paket.',
    'empty_os' => 'Keine VM verwendet dieses Betriebssystem.',
    'empty_vlan' => 'Keine VM-Netzwerkkarte und keine Mission verwendet dieses VLAN.',
    'empty_credential_esxi' => 'Kein Auftrag und keine VM mit nachgewiesener Bindung verwendet diesen Zugang.',
    'empty_credential_ansible' => 'Kein Auftrag verwendet diesen Zugang.',
    'empty_generic' => 'Keine belegte Verwendung.',
    'note_vm_binding' => 'Eine VM zählt nur, wenn ihre Bindung an diesen Zugang nachgewiesen ist. Fehlt der Nachweis, ist eine Verwendung nicht ausgeschlossen.',
    'note_ansible_jobs' => 'Ansible-Zugänge werden nur über Aufträge nachgewiesen.',
];
