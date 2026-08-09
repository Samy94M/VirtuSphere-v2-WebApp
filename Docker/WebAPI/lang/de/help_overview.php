<?php

declare(strict_types=1);

return [
    'intro_heading' => 'Was ist VirtuSphere?',
    'intro_p1' => 'VirtuSphere plant und stellt virtuelle Maschinen für Übungs- und Testmissionen bereit. Es hat zwei Zugänge: das Portal, also diese Oberfläche hier für Menschen, und die Maschinen-API, über die MECM, Ansible und die Windows-Clients Daten austauschen. Die Maschinen-API hat keine Oberfläche und wird nie von Hand bedient. „Portal" meint in dieser Hilfe immer diese Oberfläche. Eine Mission bündelt eine Gruppe von VMs, die gemeinsam über ESXi angelegt und per Ansible konfiguriert werden.',
    'intro_p2' => 'Vorlagen sind wiederverwendbare Missionsvorlagen (Name beginnt mit „_"). Aus einer Vorlage lässt sich jederzeit eine neue, eigenständige Mission mit frischen VM-IDs und leeren MAC-Adressen anlegen.',
    'workflow_heading' => 'Ablauf in Kürze',
    'workflow_step1' => '1. Mission oder Vorlage unter „Missionen" bzw. „Vorlagen" anlegen und die benötigten VMs definieren.',
    'workflow_step2' => '2. Unter „Zugangsdaten" einen ESXi-Zugang und einen Ansible-Zugang hinterlegen (falls noch nicht vorhanden).',
    'workflow_step3' => '3. Unter „Bereitstellung" die Mission, den ESXi- und Ansible-Zugang sowie den Modus wählen und den Auftrag einreihen.',
    'workflow_step4' => '4. Fortschritt im Bereitstellungsprotokoll live verfolgen; MECM übernimmt registrierte VMs automatisch für die Betriebssysteminstallation.',
];
