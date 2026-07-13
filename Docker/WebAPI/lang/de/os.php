<?php

declare(strict_types=1);

return [
    'title' => 'Betriebssysteme',
    'readonly_hint' => 'Betriebssysteme werden aus MECM synchronisiert (als Task Sequences); sie lassen sich hier nur löschen, nicht anlegen oder bearbeiten.',
    'th_vm_usage' => 'VMs',
    'confirm_delete_unused' => 'Betriebssystem :name wird von keiner VM genutzt. Löschen? Existiert die Task Sequence noch in MECM, legt der nächste Sync den Eintrag neu an.',
    'confirm_delete_one' => 'Betriebssystem :name wird von einer VM genutzt. Wirklich löschen? Existiert die Task Sequence noch in MECM, legt der nächste Sync den Eintrag neu an.',
    'confirm_delete_many' => 'Betriebssystem :name wird von :count VMs genutzt. Wirklich löschen? Existiert die Task Sequence noch in MECM, legt der nächste Sync den Eintrag neu an.',
    'empty' => 'Keine Betriebssysteme gefunden.',
    'flash_deleted' => 'Betriebssystem gelöscht.',
    'th_retired_at' => 'Zurückgezogen am',
    'filter_label' => 'Status-Filter',
    'filter_active' => 'Aktiv',
    'filter_retired' => 'Zurückgezogen',
    'filter_all' => 'Alle',
    'filter_apply' => 'Filtern',
];
