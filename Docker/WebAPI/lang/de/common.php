<?php

declare(strict_types=1);

/**
 * Wiederverwendete Portal-Begriffe (Buttons, Tabellenköpfe, Zustände).
 */
return [
    'name' => 'Name',
    'status' => 'Status',
    'vms' => 'VMs',
    'updated' => 'Aktualisiert',
    'actions' => 'Aktionen',
    'details' => 'Details',
    'mission' => 'Mission',
    'back' => 'Zurück',
    'refresh' => 'Aktualisieren',
    'cancel' => 'Abbrechen',
    'confirm' => 'Bestätigen',
    'save' => 'Speichern',
    'delete' => 'Löschen',
    'edit' => 'Bearbeiten',
    'create' => 'Erstellen',
    'remove' => 'Entfernen',
    'yes' => 'Ja',
    'no' => 'Nein',
    'export_csv' => 'CSV-Export',
    'sort_by' => 'Sortieren',
    'free_suffix' => ':free frei',
    'maintenance_suffix' => 'Wartung',
    'inventory_bucket_all' => 'Auf allen ESXi-Zugängen vorhanden',
    'inventory_bucket_some' => 'Nur auf einigen Zugängen: :hosts',
    'inventory_bucket_only' => 'Nur auf :host',
    'creator_unknown' => 'Unbekannt',
    'technical_details' => 'Technische Details',
    'help' => 'Hilfe',
    'unknown_action' => 'Unbekannte Aktion.',
    'duration_ms' => ':count ms',
    'duration_seconds' => ':count Sekunden',
    'duration_minutes' => ':count Minuten',
    'duration_hours' => ':count Stunden',

    // Verbindungsfehler (VIRTUSPHERE_INVENTORY_ERROR_*): Klartext für das Portal.
    'conn_dns' => 'Der Host ":host" konnte nicht aufgelöst werden. Prüfen Sie den Hostnamen.',
    'conn_unreachable' => 'Der Host ":host" ist nicht erreichbar. Prüfen Sie Port, Netzwerk und Firewall.',
    'conn_certificate' => 'Das Zertifikat von ":host" konnte nicht verifiziert werden. Gespeichertes CA-Bundle bzw. Serverzertifikat und Hostnamen prüfen.',
    'conn_tls' => 'Die TLS-Verbindung zu ":host" wurde abgelehnt. TLS-Version, Port und Gegenstelle prüfen.',
    'conn_auth' => 'Die Anmeldung wurde abgelehnt. Benutzername oder Secret stimmt nicht.',
    'conn_authz' => 'Die Anmeldung hat funktioniert, aber dem Konto fehlen die nötigen Rechte.',
    'conn_http' => 'Der Host hat mit HTTP :status geantwortet.',
    'conn_ssh' => 'Die SSH-Verbindung zu ":host" ist fehlgeschlagen.',
    'conn_worker' => 'Der Inventarabruf wurde beendet, weil der Deploy-Worker kein Lebenszeichen mehr gesendet hat.',
    'conn_parse' => 'Der Abruf ist mit einem unbekannten Fehler gescheitert.',
    'conn_config' => 'Konfigurationsproblem vor dem Verbindungsaufbau: Zugangsdaten, Toolchain oder Playbooks sind unvollständig. Der Host wurde dabei nicht kontaktiert.',
    'conn_unknown' => 'Die Verbindung ist fehlgeschlagen.',
];
