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
    'unsaved_heading' => 'Ungespeicherte Änderungen',
    'unsaved_p1' => 'Die Missionseinstellungen und der VM-Editor zeigen an, ob das aktuelle Formular vom zuletzt bestätigten Serverstand abweicht. Auch das Hinzufügen oder Entfernen einer Netzwerkkarte oder Festplatte zählt als Änderung.',
    'unsaved_p2' => 'Beim Folgen eines Links, bei Browser-Zurück, Neuladen oder Schließen des Tabs warnt das Portal vor dem Verlassen mit ungespeicherten Änderungen. Bestätigen verwirft nur die Änderungen im Browser; Abbrechen lässt den Editor mit seinen Werten geöffnet.',
    'unsaved_p3' => 'Ein Validierungs- oder Bearbeitungskonflikt macht das Formular nicht gespeichert. Inhalte von Passwort- und Dateifeldern werden nie in den Vergleichszustand kopiert. Ohne JavaScript funktionieren die serverseitigen Formulare weiter, eine solche frühe Warnung kann der Browser dann aber nicht geben.',
    'copy_heading' => 'Angezeigte Werte kopieren',
    'copy_p1' => 'Kopierbuttons stehen bei angezeigten VM-Namen, Windows-Hostnamen, aktiven Rolloutnamen, konfigurierten IP-Adressen, MAC-Adressen und Auftrags-IDs. Bei IP und MAC nennt der Button die zugehörige Netzwerkkarte.',
    'copy_p2' => 'In einem Editor wird beim Klick der aktuelle Feldinhalt kopiert. Leere Werte und eine bei DHCP inaktive IP-Adresse erhalten keine Kopieraktion. „Konfigurierte IP-Adresse" bezeichnet den Sollwert im Portal und nicht eine beobachtete Adresse des laufenden Systems.',
    'copy_p3' => 'Auf einem HTTP-Portal oder bei verweigerten Browserrechten kann die Zwischenablage nicht verfügbar sein. Dann meldet der Button den Fehler, ohne Erfolg vorzutäuschen; der angezeigte Wert bleibt markierbar und kann von Hand kopiert werden.',
];
