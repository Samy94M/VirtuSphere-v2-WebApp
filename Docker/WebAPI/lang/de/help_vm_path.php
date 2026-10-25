<?php

declare(strict_types=1);

return [
    'heading' => 'Der Weg einer VM',
    'intro' => 'Jede VM durchläuft dieselben Phasen. Das Bild zeigt sie in ihrer Reihenfolge und nennt zu jeder den Beleg, der sie setzt. Was eine Phase genau verlangt und welche Befunde dazukommen können, steht im Abschnitt „:section“.',
    'desc' => 'Die Phasen in ihrer Reihenfolge: :phases.',
    // Je Phase ein Satz zum Beleg, der sie setzt; Schlüssel nach VIRTUSPHERE_VM_PHASES.
    'caption_created' => 'Anlegen, Import oder Kopie im Portal',
    'caption_in_progress' => 'Ein Auftrag hat die VM markiert',
    'caption_on_esxi' => 'Create oder MAC-Export bindet die VM',
    'caption_mac_known' => 'Der Ansible-Host meldet die MAC zurück',
    'caption_handed_to_mecm' => 'Der Device-Sync meldet die ResourceID',
    'caption_windows_reports' => 'Der Client bestätigt, dass er bereit ist',
    'caption_client_done' => 'Alle Clientphasen melden „abgeschlossen“',
    'note' => 'Es gilt die höchste Phase, deren Beleg angekommen ist; eine VM kann Phasen überspringen. Ein fehlgeschlagener Auftrag führt nicht zurück, er erscheint als Befund.',
];
