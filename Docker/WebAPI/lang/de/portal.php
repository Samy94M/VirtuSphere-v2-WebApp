<?php

declare(strict_types=1);

/**
 * Portal-weite Guard- und Fehlermeldungen (ADR-0014).
 * Nur user-facing Portal-Text. Maschinen-/API-Wire-Felder werden nicht lokalisiert.
 */
return [
    'invalid_request' => 'Ungültige Abmeldeanfrage.',
    'invalid_csrf' => 'Ungültiges CSRF-Token.',
    'forbidden' => 'Kein Zugriff.',
    'mission_not_found' => 'Mission nicht gefunden.',
    'vm_not_found' => 'VM nicht gefunden.',
    'deploy_not_found' => 'Bereitstellungsauftrag nicht gefunden.',
    'vm_guest_os_label' => 'Guest OS',
    'vm_guest_os_windows_server_2019' => 'Windows Server 2019',
    'vm_guest_os_windows_11' => 'Windows 11',
    'vm_guest_os_windows_server_2022' => 'Windows Server 2022',
    'vm_guest_os_windows_server_2025' => 'Windows Server 2025',
    'vm_guest_os_unknown' => 'Unbekanntes Guest OS',
    'vm_guest_os_legacy' => 'Legacy Guest ID: :guest_id',
    'vm_mecm_reset_button' => 'Reset MECM ID',
    'vm_mecm_reset_confirm' => 'MECM ID für VM :name zurücksetzen und erneut für MECM einreihen?',
    'vm_mecm_reset_success' => 'MECM ID wurde zurückgesetzt; die VM ist wieder für MECM eingereiht.',
    'vm_mecm_reset_template_blocked' => 'Templates können nicht für MECM eingereiht werden.',
    'vm_mecm_reset_no_mac' => 'Reset nicht möglich: Die VM hat noch keine importierte MAC-Adresse.',
    // Ausdrückliche Aktion statt stillem Zustandswechsel: das Portal ist die
    // Absicht vor dem Rollout, MECM die Wahrheit danach.
    'vm_mecm_transfer_button' => 'Zuweisungen an MECM übertragen',
    'vm_mecm_transfer_confirm' => 'Betriebssystem- und Paketzuweisungen von VM :name jetzt an MECM übertragen? Die VM wird dafür erneut in die Warteschlange des Device-Sync gestellt; ihr Installationsstand bleibt unverändert. Eine Installation startet dadurch nicht.',
    'vm_mecm_transfer_success' => 'Die VM ist für die Übertragung eingereiht. Der Device-Sync gleicht die Mitgliedschaften beim nächsten Durchlauf ab: fehlende werden hinzugefügt, überholte eigene Regeln entfernt. Von Hand in MECM angelegte Regeln bleiben immer unangetastet.',
    'vm_mecm_transfer_stale' => 'Die Zuweisungen haben sich geändert, seit diese Seite geladen wurde. Bitte die Seite neu laden und die Vorschau erneut prüfen.',
    'vm_mecm_preview_add' => 'Bei der nächsten Übertragung wird die VM hinzugefügt zu: :names',
    'vm_mecm_preview_remove' => 'Eigene, nicht mehr zugewiesene Regeln werden entfernt: :names. Von Hand in MECM angelegte Regeln bleiben unangetastet.',
    'vm_mecm_preview_none' => 'Aus Portalsicht stehen keine Änderungen an den eigenen Mitgliedschaften an; die Übertragung prüft trotzdem den Stand in MECM.',
];
