# Automatischer Ansible-Volltest

Unter **Einstellungen → Kataloge und Inventar → Automatischer Ansible-Volltest**
ändern Benutzer mit `system.config` das Intervall für alle registrierten
Ansible-Zugänge, auch einen als „Ansible auf Ubuntu“ benannten Zugang.
Vorgabe und Grenzen gehören `lib/ansible_test_config.php`: standardmäßig
24 Stunden, ganze Stunden bis 168; 0 schaltet die Automatik aus.
Das ESXi-Inventarintervall bleibt eine unabhängige Einstellung.

Der Bereitstellungsdienst prüft die Fälligkeit in freien Durchläufen.
Deploy-Aufträge haben Vorrang. Pausierte oder gestoppte Dienste starten keine
neue automatische Prüfung. Eine gestartete Prüfung darf fertig werden, auch
wenn danach die Automatik ausgeschaltet oder die Annahme pausiert wird.
Ein gesonderter Ubuntu-Cronjob oder systemd-Timer ist nicht erforderlich.

Die Diagnose läuft als eigener PHP-Kindprozess im Deploy-Worker-Container mit
eigener Datenbankverbindung. Jobannahme und Prozess-Lebenszeichen bleiben
unabhängig von langsamen SSH-/SFTP-Antworten. Pro Worker existiert höchstens ein
Diagnosekind; ein Ersatz startet erst nach dessen Ende und Reaping.
Beim regulären Worker-Ende wird ein verbliebenes Diagnosekind beendet.
`VIRTUSPHERE_ANSIBLE_TEST_TOTAL_TIMEOUT_SECONDS` begrenzt die Gesamtlaufzeit.
Ein Zeitlimit bestätigt keinen Erfolg. Ein abgebrochener SFTP-Probeversuch
kann seine temporäre Datei unter `/tmp/.virtusphere-preflight-*` zurücklassen.

## Fälligkeit und Konkurrenz

`deploy_credentials.ansible_test_started_at` speichert den letzten Prüfstart
dauerhaft. Fehlgeschlagene und unterbrochene Versuche zählen ebenfalls, damit
ein Neustart keine Wiederholungsschleife auslöst. Ein manueller Prüfstart
verschiebt den nächsten automatischen Termin. Für vorhandene Installationen
ohne diesen Zeitstempel gilt zunächst der gespeicherte Ergebniszeitpunkt.
Ohne bisherigen Test ist der Zugang beim nächsten freien Durchlauf fällig.
Geänderte Zugangsdaten verwerfen die alte Fälligkeit. Ein kleineres Intervall
kann eine Prüfung sofort fällig machen.

Fälligkeitsprüfung und Startbuchung werden unter derselben Zugangsdaten-Sperre
erneut geprüft. Zwei Worker können nicht denselben fälligen Versuch buchen.
`config_revision` und `ansible_test_generation` sichern weiterhin das Ergebnis:
Eine ältere Prüfung überschreibt weder eine neuere Prüfung noch den Nachweis
einer neueren Konfiguration. Datenbanksperren enden vor SSH/SFTP.

## Prüfumfang und Diagnose

Beide Startarten verwenden `ansible_full_test_execute()` und
`credential_test_connection()`: lokale Ansible-Quellen/Collection-Pins,
SSH-Anmeldung, Werkzeuge und Python-Module, SFTP sowie bei konfigurierter
API-Rückadresse Portal-Erreichbarkeit und Allowlist. Eine fehlende Rückadresse
erlaubt die übrigen Prüfungen; eine ungültige Adresse ist ein Konfigurationsfehler.
Ein Datenbankfehler gilt nicht als fehlende Rückadresse.
Der Test legt keine VM an und schaltet keine VM um.

Ergebnis und Audit werden gemeinsam durch `ansible_full_test_store_result()`
gespeichert. **Systemstatus → Ansible-Host** zeigt Volltest und Intervall.
Automatische Prüfungen sind im bestehenden Audit der Kategorie `credentials`
durch `scheduled=true` und ihre Beschreibung gekennzeichnet; der Auslöser ist
kein erfundener Benutzer. Prozess-/Infrastrukturfehler stehen im Worker-Containerlog.

Bei überfälligen Prüfungen Intervall, Dienstzustand und Auftragsannahme prüfen.
Bei Fehlern die benannte Komponente beheben. Nach einer Unterbrechung oder bei
ungültiger Zuordnung kann **Volltest jetzt starten** aktuellen Nachweis erzeugen.
Missionsaufträge erneuern diesen Volltest nicht. Das Gültigkeitsfenster der
Ampel bleibt unabhängig vom Intervall; eine ausgeschaltete Automatik
konserviert kein grünes Ergebnis.

## Aktualisierung einer Installation

Migration `0055_ansible_test_schedule` muss vor dem neuen Worker-Code angewendet
sein. Danach den Deploy-Worker regulär neu starten, damit er die neue Schleife
lädt. Das bestehende Image benötigt weiterhin PHP mit `pcntl` und `proc_open`;
zusätzliche Ubuntu-Pakete, Internetzugriff oder neue Dienste sind nicht nötig.
Ohne gespeicherten Intervallwert gilt danach die Standardautomatik.
Vor dem Neustart bei Bedarf das Intervall auf 0 setzen.
