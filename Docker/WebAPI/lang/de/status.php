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
    'mecm_failed' => 'MECM-Abgleich fehlgeschlagen',
    'mecm_unknown' => 'Unbekannter Zustand',

    // Auftragsstatus (aktive und terminale Menge aus lib/deploy_constants.php).
    'job_queued' => 'In Warteschlange',
    'job_running' => 'Läuft',
    'job_cancelling' => 'Abbruch angefordert',
    'job_succeeded' => 'Erfolgreich',
    'job_failed' => 'Fehlgeschlagen',
    'job_cancelled' => 'Abgebrochen',
    'job_partial' => 'Teilweise erfolgreich',
    'job_unknown' => 'Unbekannter Status',

    // Bereitstellungsmodi. Die sechs postbaren Modi stehen technisch in
    // virtusphere_deploy_mode_labels(); `inventory` kann das Portal zeigen,
    // aber niemand einreihen, weil nur der Scheduler ihn erzeugt.
    'mode_full' => 'Vollständige Kette',
    'mode_create' => 'VMs anlegen',
    'mode_powercycle' => 'Aus- und einschalten mit MAC-Export',
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
];
