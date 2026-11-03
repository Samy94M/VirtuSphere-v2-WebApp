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
    'mecm_deploy_failed' => 'MECM-Abgleich offen nach Fehler',
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
    'mode_then_autostart' => 'danach Autostart',

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
    // Variante ohne Zeit oder ohne Herkunft. `mecm_sync_deploy_failed` nutzt `mecm_deploy_failed`.
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
    // Kurzform je Befund für die Tabellen (SP4, F7): ohne Zeit, Zahl und Namen;
    // der volle Satz steht im Tooltip und im VM-Editor.
    'finding_short_create_uncertain' => 'Create ungeklärt',
    'finding_short_job_failed' => 'Auftrag fehlgeschlagen',
    'finding_short_create_failed' => 'Nicht angelegt',
    'finding_short_job_outcome_unknown' => 'Ausgang unbekannt',
    'finding_short_missing_from_inventory' => 'Nicht im Inventar',
    'finding_short_job_running' => 'Auftrag läuft',
    'finding_short_old_mecm_device' => 'Altes MECM-Gerät',
    'finding_short_mecm_pending_overdue' => 'MECM überfällig',
    'finding_short_mecm_pending' => 'Wartet auf MECM',
    'finding_short_powered_off' => 'Ausgeschaltet',
    'finding_short_os_install_overdue' => 'OS-Installation überfällig',
    'finding_short_pxe_unwatched' => 'PXE-Beleg fehlt',
    'finding_short_os_install_watching' => 'Beobachtung läuft',
    'finding_short_client_phase_failed' => 'Clientphase fehlgeschlagen',
    'finding_short_client_phase_unconfirmed' => 'Clientphase unbestätigt',
    'finding_short_package_run_failed' => 'Paketbericht fehlgeschlagen',
    'finding_short_mecm_sync_deploy_failed' => 'MECM-Abgleich offen',
    // Grund des Devices Sync am Befund „Wartet auf MECM“ (MX1). Er steht im vollen
    // Satz (VM-Editor und Tooltip der Tabellenzelle), nie in der Kurzform; ab
    // einem gewissen Alter mit „Stand“ und Beobachtungszeit.
    'finding_mecm_cause' => ':finding; Grund: :cause',
    'finding_mecm_cause_as_of' => ':finding; Grund (Stand :time): :cause',
    // Je Code der Gerätecodes (VIRTUSPHERE_DEVICE_SYNC_CAUSES) ein Satzteil, was
    // die VM aufhält. Die Maßnahme nennt die Hilfe zum Systemstatus.
    'sync_cause_mission_missing' => 'VM ohne Mission',
    'sync_cause_mac_missing' => 'keine MAC auf der WDS-Portgruppe',
    'sync_cause_pxe_nic_ambiguous' => 'mehrere Netzwerkkarten auf der WDS-Portgruppe',
    'sync_cause_mac_conflict' => 'Name und MAC zeigen auf verschiedene MECM-Geräte',
    'sync_cause_device_name_invalid' => 'Windows-Hostname taugt nicht als Gerätename',
    'sync_cause_device_identity_ambiguous' => 'mehrere MECM-Datensätze passen auf die VM',
    'sync_cause_previous_resource_present' => 'altes MECM-Gerät besteht noch',
    'sync_cause_resource_id_missing' => 'gebundenes MECM-Gerät wurde gelöscht',
    'sync_cause_resource_mac_conflict' => 'gebundenes MECM-Gerät trägt eine andere MAC',
    'sync_cause_device_import_failed' => 'Import in MECM fehlgeschlagen',
    'sync_cause_resource_id_pending' => 'MECM hat noch keine ResourceID vergeben',
    'sync_cause_stale_rollout_revision' => 'Scan mit veraltetem Stand, der nächste läuft neu',
    'sync_cause_collection_missing' => 'eine Collection der VM fehlt in MECM',
    'sync_cause_collection_folder_failed' => 'Ordner einer Collection nicht angelegt oder nicht verschoben',
    'sync_cause_collection_assign_failed' => 'Zuordnung zu einer Collection fehlgeschlagen',
    'sync_cause_collection_remove_failed' => 'eigene Collection-Zuordnung nicht entfernt',
    'sync_cause_membership_provenance_invalid' => 'Herkunft einer Collection-Zuordnung unvollständig',
    'sync_cause_membership_identity_ambiguous' => 'Collection nicht eindeutig',
    'sync_cause_membership_query_failed' => 'Collection-Mitgliedschaften nicht sicher lesbar',
    'sync_cause_membership_operation_uncertain' => 'Collection-Zuordnung nach Abbruch unbestätigt',
    'sync_cause_membership_report_pending' => 'Collection-Zuordnung bestätigt, Meldung folgt',
    'sync_cause_membership_report_failed' => 'Meldung der Collection-Zuordnung ans Portal fehlgeschlagen',
    'sync_cause_resource_update_failed' => 'Meldung der ResourceID ans Portal fehlgeschlagen',
];
