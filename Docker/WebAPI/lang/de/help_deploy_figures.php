<?php

declare(strict_types=1);

// Texte der beiden Bilder in der Bereitstellungshilfe (lib/help_deploy_modes.php,
// lib/help_deploy_job_states.php). Modus- und Statusnamen kommen aus status.php.
return [
    'modes_heading' => 'Was jeder Bereitstellungsmodus ausführt',
    'modes_intro' => 'Jede Zeile zeigt einen Modus und die Schritte, die er auf ESXi startet, in ihrer Reihenfolge. Die Nummern stehen in der Legende oben im Bild.',
    'modes_legend' => 'Schritte',
    // Je Schritt ein Name; Schlüssel nach VIRTUSPHERE_PLAYBOOKS.
    'step_create' => 'VMs anlegen',
    'step_powercycle' => 'Kurz ein- und ausschalten',
    'step_export' => 'MAC-Adressen auslesen',
    'step_start' => 'VMs starten',
    'step_autostart' => 'Autostart-Richtlinie schreiben',
    'modes_desc' => 'Die Schritte in ihrer Reihenfolge: :steps. :modes',
    'modes_desc_mode' => ':mode: :steps.',
    'modes_desc_conditional' => ':step nur mit Autostart der Mission',
    'modes_note' => 'Ein gestrichelter Schritt läuft nur, wenn die Mission Autostart aktiviert hat.',
    'job_states_heading' => 'Zustände eines Auftrags',
    'job_states_intro' => 'Ein Auftrag hat immer genau einen Status. Das Bild zeigt, in welchen Status er von welchem aus wechseln kann und wann er beendet ist.',
    'group_active' => 'Aktiv',
    'group_terminal' => 'Beendet',
    'state_cancellable' => 'Abbrechen möglich',
    'job_states_desc' => 'Aktive Status: :active. Abbrechen lässt sich: :cancellable. Endzustände: :terminal. Übergänge: :transitions.',
    'job_states_transition' => 'von :from nach :to',
    'job_states_note' => 'Aus einem Endzustand führt kein Weg zurück; „Erneut ausführen“ legt einen neuen Auftrag an. „Ausgang unbekannt“ setzt nur die Aufsicht, wenn ein laufender Auftrag keinen Herzschlag mehr meldet.',
];
