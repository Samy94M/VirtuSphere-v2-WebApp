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
];
