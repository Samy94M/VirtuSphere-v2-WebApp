<?php

declare(strict_types=1);

// Der gemeinsame Statuskatalog des Portals. Er benennt nur, was ein Mensch
// liest; die technischen Werte bleiben in lib/constants.php und
// lib/deploy_constants.php und wandern unverändert durch Datenbank, Worker,
// dauerhafte Joblogs und jedes Maschinenfeld (ADR-0014).
//
// Jede Menge hat ihren eigenen Unbekannt-Eintrag, damit ein unbekannter Wert
// neutral benannt statt roh ausgegeben wird: bisher stand der Rohwert als
// Beschriftung im Badge, und `os_installing` neben `pending` liest sich für
// niemanden ausser dem Schema, das ihn geschrieben hat.
//
// Phase und Befund einer VM (lib/vm_phase.php) sind Lesezeit-Ableitungen aus
// gespeicherten Belegen: keine Spalte, kein Schreiber. Ihre Texte stehen am Ende
// dieses Katalogs. Die Phase nennt den zuletzt angekommenen Beleg, nie einen
// Fortschritt; der Befund nennt den nächsten Schritt.
return [
    // Lebenszyklus einer VM (VIRTUSPHERE_LIFECYCLE_STATES).
    'lifecycle_initializing' => 'In Vorbereitung',
    'lifecycle_ready' => 'Bereit',
    'lifecycle_deploying' => 'Wird bereitgestellt',
    'lifecycle_deployed' => 'Bereitgestellt',
    'lifecycle_os_installing' => 'Betriebssystem wird installiert',
    'lifecycle_os_installed' => 'Betriebssystem installiert',
    'lifecycle_failed' => 'Fehlgeschlagen',
    'lifecycle_unknown' => 'Unbekannter Zustand',

    // MECM-Abgleich einer VM (VIRTUSPHERE_MECM_SYNC_STATES).
    'mecm_not_ready' => 'Noch nicht bereit',
    'mecm_pending' => 'Wartet auf MECM',
    'mecm_registered' => 'In MECM registriert',
    'mecm_failed' => 'MECM-Abgleich offen nach Fehler',
    'mecm_unknown' => 'Unbekannter Zustand',

    // Auftragsstatus (aktive und terminale Menge aus lib/deploy_constants.php).
    'job_queued' => 'In Warteschlange',
    'job_running' => 'Läuft',
    'job_cancelling' => 'Abbruch angefordert',
    'job_succeeded' => 'Erfolgreich',
    'job_failed' => 'Fehlgeschlagen',
    'job_cancelled' => 'Abgebrochen',
    'job_partial' => 'Teilweise erfolgreich',
    'job_outcome_unknown' => 'Ausgang unbekannt',
    'job_unknown' => 'Unbekannter Status',

    // Bereitstellungsmodi. Die sechs postbaren Modi stehen technisch in
    // virtusphere_deploy_mode_labels(); `inventory` kann das Portal zeigen,
    // aber nicht über das Bereitstellungsformular einreihen. Erzeugt wird er
    // vom Zeitplan, von „Alle aktualisieren“ und dem Einzelabruf im
    // Systemstatus, beim Speichern und Testen eines ESXi-Zugangs und nach
    // jedem Endzustand von `create` oder `full`, sobald eine Einheit eine
    // Ansible-Job-ID dauerhaft gespeichert hat.
    'mode_full' => 'Vollständige Kette',
    'mode_create' => 'VMs anlegen',
    'mode_powercycle' => 'Ein- und ausschalten mit MAC-Export',
    'mode_export' => 'MAC-Adressen exportieren',
    'mode_start' => 'VMs starten',
    'mode_autostart' => 'ESXi-Autostart anwenden',
    'mode_inventory' => 'Inventar abrufen',
    'mode_unknown' => 'Unbekannter Modus',
    'mode_invalid_payload' => 'Auftragsdaten nicht lesbar',
    'mode_scope_one' => ':count VM',
    'mode_scope_many' => ':count VMs',

    // Missionsstatus im Dashboard. Die Spalte ist ein freies VARCHAR, kein Enum:
    // nur `active` und leer haben eine feste Bedeutung, jeder andere Altwert
    // wird mit seinem eigenen Text gezeigt statt als etwas gedeutet, was er nie
    // war.
    'mission_active' => 'Aktiv',

    // VM-Phase (VIRTUSPHERE_VM_PHASES, lib/vm_phase.php). Die Namen stehen in
    // derselben Reihenfolge wie die Phasen; `phase_template` gilt für Vorlagen.
    'phase_created' => 'Angelegt',
    'phase_in_progress' => 'In Arbeit',
    'phase_on_esxi' => 'Auf ESXi',
    'phase_mac_known' => 'MAC bekannt',
    'phase_handed_to_mecm' => 'An MECM übergeben',
    'phase_windows_reports' => 'Windows meldet sich',
    'phase_client_done' => 'Client fertig',
    'phase_template' => 'Vorlage',
    'phase_unknown' => 'Unbekannte Phase',
    'phase_technical' => 'Technisch: :status',

    // Befunde (VIRTUSPHERE_VM_FINDINGS, lib/vm_phase.php). Der Zeitbestandteil
    // fällt nur weg, wenn kein gültiger Serverzeitpunkt vorliegt; dann greift die
    // Variante ohne Zeit oder ohne Herkunft. `mecm_sync_failed` nutzt `mecm_failed`.
    'finding_create_uncertain' => 'Create ungeklärt (Auftrag :job_id, Einheit :position)',
    'finding_job_failed' => 'Auftrag fehlgeschlagen: :mode, :time',
    'finding_create_failed' => 'Auf ESXi nicht angelegt: :mode, :time',
    'finding_job_failed_unknown' => 'Auftrag fehlgeschlagen',
    'finding_job_outcome_unknown' => 'Ausgang des Auftrags unbekannt: :mode, :time',
    'finding_missing_from_inventory' => 'Nicht mehr im Inventar (:host, Stand :time)',
    'finding_missing_from_inventory_no_host' => 'Nicht mehr im Inventar (Stand :time)',
    'finding_job_running' => 'Auftrag läuft seit :since (:mode)',
    'finding_job_running_unknown' => 'Auftrag läuft',
    'finding_old_mecm_device' => 'Altes MECM-Gerät löschen (ResourceID :id)',
    'finding_mecm_pending_overdue' => 'Wartet auf MECM seit :since, länger als :hours h',
    'finding_mecm_pending_since' => 'Wartet auf MECM seit :since',
    'finding_mecm_pending' => 'Wartet auf MECM',
    'finding_powered_off' => 'Laut Inventar ausgeschaltet (Stand :time)',
    'finding_os_install_overdue' => 'OS-Installation länger als :hours h beobachtet',
    'finding_pxe_unwatched' => 'PXE-Beleg fehlt',
    'finding_os_install_watching' => 'Beobachtung läuft seit :since',
    'finding_client_phase_failed' => 'Clientphase fehlgeschlagen: :phase',
    'finding_client_phase_unconfirmed' => 'Clientphase :phase seit :minutes min unbestätigt',
    'finding_package_run_failed' => 'Paketbericht fehlgeschlagen: :package',
    'finding_unknown' => 'Unbekannter Befund',
    'finding_more' => '+:count',
    'finding_more_title' => ':count weitere Befunde, alle im VM-Editor',
];
