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
    'vm_mecm_reset_button' => 'MECM-ID zurücksetzen',
    // Zwei Bestätigungen für dieselbe Aktion, und das ist Absicht.
    //
    // In der VM-Liste ist der Windows-Hostname nirgends zu sehen, also nennt die
    // Frage ihn: sie aktiviert ihn. Im VM-Editor steht daneben ein EDITIERBARES
    // Hostnamenfeld, und ein serverseitig gerenderter Name würde dort einen Wert
    // behaupten, den der Bearbeiter gerade überschrieben, aber noch nicht
    // gespeichert hat. Dort sagt die Frage deshalb, was gilt, statt welchen Wert.
    'vm_mecm_reset_confirm' => 'MECM-ID für VM :name zurücksetzen? Der nächste Rollout meldet sie als „:hostname" bei MECM an. Das alte MECM-Gerät wird nicht automatisch gelöscht.',
    'vm_mecm_reset_confirm_editing' => 'MECM-ID für VM :name zurücksetzen? Aktiviert wird der zuletzt GESPEICHERTE Windows-Hostname, nicht eine ungespeicherte Änderung im Feld. Das alte MECM-Gerät wird nicht automatisch gelöscht.',
    'vm_mecm_reset_template_blocked' => 'Templates können nicht für MECM eingereiht werden.',
    'vm_mecm_reset_no_mac' => 'MECM-ID zurücksetzen nicht möglich: Die VM hat noch keine importierte MAC-Adresse.',
    // Etappe 14D: Der Reset ist der einzige Punkt, an dem ein neuer Rolloutname
    // wirksam wird. Jeder Erfolgstext nennt den Namen, der jetzt scharf ist, und
    // wiederholt die unveraenderte Betreiberpflicht: VirtuSphere loescht in MECM
    // nichts.
    'vm_mecm_reset_success' => 'MECM-ID zurückgesetzt. Der nächste Rollout meldet die VM als „:hostname" an MECM an. Das alte MECM-Gerät wird nicht automatisch gelöscht; bitte in der MECM-Konsole entfernen.',
    'vm_mecm_reset_already_pending' => 'Es war nichts zu tun: „:hostname" wartet bereits als nächster Rolloutname und die VM ist für MECM eingereiht.',
    'vm_mecm_reset_already_pending_reason' => 'bereits eingereiht',
    'vm_mecm_reset_active_job' => 'MECM-ID zurücksetzen nicht möglich: Für diese Mission läuft gerade ein Deploy-Job. Warten Sie, bis er beendet ist.',
    'vm_mecm_reset_invalid_hostname' => 'MECM-ID zurücksetzen nicht möglich: Der Windows-Hostname taugt nicht als Gerätename in MECM. Zulässig sind höchstens :max Zeichen, nur Buchstaben, Ziffern und innenliegende Bindestriche, kein Punkt. Bitte zuerst im VM-Editor korrigieren.',
    // Zustand, keine Ablehnung (Entscheidung 2026-09-03): VirtuSphere loescht in
    // MECM nichts, also bleibt nach einem Reset genau ein Schritt offen, und der
    // gehoert einem Menschen. Der Satz steht deshalb an der VM und nicht in einer
    // Fehlermeldung, die nur sieht, wer ein zweites Mal klickt.
    'vm_mecm_reset_previous_device' => 'Noch offen: Das MECM-Gerät des vorherigen Rollouts (ResourceID :resource_id) ist nicht gelöscht. Bitte in der MECM-Konsole entfernen; danach importiert der nächste Device-Sync den neuen Namen von selbst.',
    'vm_mecm_reset_error' => 'MECM-ID zurücksetzen nicht möglich: unerwarteter Fehler.',
    // Read-only Gegenueberstellung im VM-Editor. Sie erscheint NUR, wenn der
    // Sollwert und der eingefrorene Snapshot auseinanderlaufen; solange beide
    // dieselbe Maschine benennen, ist die kompakte Anzeige die Wahrheit.
    'vm_rollout_current_label' => 'Aktueller Rolloutname',
    'vm_rollout_next_label' => 'Nächster Rolloutname',
    'vm_rollout_frozen_hint' => 'Diese VM wurde bereits als „:current" an MECM übergeben. Der geänderte Name gilt erst für den nächsten Rollout: altes Gerät in MECM löschen, dann „MECM-ID zurücksetzen".',
];
