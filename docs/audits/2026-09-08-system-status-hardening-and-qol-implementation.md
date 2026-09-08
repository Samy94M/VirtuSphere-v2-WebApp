# Umsetzung Systemstatus-Härtung und QoL, 2026-09-08

## Rahmen und Ausgangsbasis

Die Umsetzung folgt `2026-09-08-system-status-hardening-and-qol-plan.md` und umfasst E0 bis E8. Die optionale Live-Aktualisierung ist nicht enthalten. Ausgangspunkt des Plans war `2635b271c4fab3d46bbac633ac72af3c76d0e643`. Das Arbeitsverzeichnis enthielt bereits umfangreiche fremde Änderungen, insbesondere an Ansible-, Create-, CI-, QA- und Dokumentationsdateien. Diese Änderungen wurden nicht zurückgesetzt, gestaget oder übernommen.

Auf dem Host waren weder PHP noch eine erreichbare Docker Engine verfügbar; WSL-PHP war ebenfalls nicht zugänglich. Vorhandene Daten wurden deshalb nicht als Integrationsbeleg verwendet. Laufzeitabnahmen mit Datenbank, MECM, ESXi oder AD werden in diesem Dokument nicht behauptet.

## E0: Befunde und gezielte Nachweise

- SS-01 wurde im Quellpfad bestätigt: `request_trimmed()` veränderte exakte VLAN-Bytes vor der Scope-Ermittlung.
- SS-02 wurde bestätigt: ein fehlerhaftes Einzelziel konnte in die Bulk-Semantik fallen.
- SS-10 wurde für die fehlende Attempt-/Generationseingrenzung und die Handle- statt Jobzählung bestätigt. Die im Plan geforderte Laufzeitreproduktion alter erreichbarer Handles bleibt mangels isolierter Datenbanklaufzeit offen.
- SS-16 wurde durch den bisherigen read-external-write-Ablauf bestätigt. Der kontrollierte Race-Test wurde als Integrationstest ergänzt, konnte auf diesem Host aber nicht ausgeführt werden.
- SS-21 wurde im Code für verschiedene Zeitquellen und die Nutzung von SQL-`NOW()` trotz injizierter Uhr bestätigt. Das im Plan bereits als unbelegt bezeichnete sichtbare Flackern wurde nicht nachträglich als Laufzeitbefund ausgegeben.
- Keine geprüfte Kernhypothese wurde widerlegt. Für nicht reproduzierbare Laufzeitanteile erfolgte keine spekulative Zusatzreparatur.

## E1: VLAN-Schreibscope

Die VLAN-Massenkorrektur besitzt nun einen fokussierten Repository-Owner mit gemeinsamem Vorschau- und Schreibscope. Rohwerte bleiben bytegenau erhalten; nur die Leerprüfung ist trim-bewusst. Einzelzielparameter werden strikt als positive Dezimalwerte validiert und können bei Fehlform nicht in den Bulkzweig wechseln. Vorschau und Bestätigung sind über einen versionierten Fingerprint an Katalogziel, Missionen, Vorlagen, VMs, Interfaces, MAC-bezogene Netzwerkfingerprints und aktive Konfliktjobs gebunden. Der Writer prüft Zielaktivität und Scope im bestehenden Mission -> Job -> VM -> Interface-Lockpfad erneut und delegiert Änderungen weiterhin an den zentralen Netzwerk-Writer.

Das Vertragsreview fand zusätzlich eine WDS-Mission mit VM ohne Interfacezeilen, die im ersten Scopeentwurf fehlen konnte. Der Scope materialisiert deshalb Missionen, VMs und Interfaces getrennt und unioniert WDS-Missionen ausdrücklich in die Jobprüfung. Ein gezielter Integrationstest deckt diesen Fall ab.

Damit sind SS-01 und SS-02 geschlossen. SS-18 ist für leere Zielkataloge und fehlende Einstellungsberechtigung geschlossen; der relevante Teil von SS-24 wird durch die begrenzte Vorschau adressiert.

## E2: Deploy-Service, Recovery und Audit

Aktive Jobs werden vollständig gelesen und als normal besessen, legitime Recovery oder inkonsistent klassifiziert. Recoveryfälle werden pro Job dedupliziert und an aktuellen Versuch sowie aktuelle Runtime-Generation gebunden; ausdrücklich historische Legacygründe und terminale ungewisse Create-Einheiten bleiben sichtbar. Queue- und Servicebewertung verwenden dieselbe injizierte Auswertungszeit. Die Pause-Transition liefert ihren tatsächlichen atomaren Änderungs- und Jobkontext; nur eine echte Transition erzeugt ein Auditereignis.

Die Oberfläche trennt Verfügbarkeit, Arbeitsfähigkeit und Klärungsbedarf, zeigt begrenzte konkrete Jobfälle sowie getrennte Dienst- und Auftragszeitpunkte. Snapshotfehler werden lokal abgefangen und als fehlende Diagnose statt als Erfolg dargestellt. Damit sind SS-06 bis SS-11 sowie die Codeanteile von SS-21 und SS-22 geschlossen. Die Laufzeitabnahme des SS-10-Historienfalls bleibt offen.

## E3: MECM- und Meldungsevidenz

Ein gemeinsamer Evidenzowner klassifiziert fehlende, unlesbare und auffällig zukünftige Zeitwerte neutral. Laufender Versuch und letzter abgeschlossener Lauf werden getrennt benannt. Site-Evidenz altert unabhängig von Sync-Läufen: nur ein frischer MECM-bestätigter Status 2 ist rot; historische kritische Ergebnisse und Providerfehler bleiben diagnostisch sichtbar, ohne aktuelle Kritikalität zu behaupten. Setup wird aus tatsächlichem Meldungsvorhandensein statt aus der Farbe abgeleitet.

Abweisungen liefern eine deterministische, begrenzte IP-Liste mit vollständiger Zahl, Zeitfenster und Auslassungszahl. Sie werden als historische API-Ereignisse und nicht als erfundene MECM-Zuordnung bezeichnet. Damit sind SS-13 bis SS-15, SS-20, der Evidenzteil von SS-21 und SS-25 geschlossen.

## E4: ESXi-Inventarevidenz und Takt

Der Abweichungsbericht aggregiert pro Objektart die bestehenden Semantik- und Frischeowner. Er unterscheidet aktuellen qualifizierten Nachweis, historischen Nachweis, bestätigte Leere und fehlende Auswertbarkeit. Eine fehlgeschlagene Folgebeobachtung löscht den älteren Nachweis nicht, macht ihn aber nicht aktuell. Nicht unterstützte Namen werden nicht als Beweis genutzt. Liste und Überblickszahl stammen aus demselben Bericht.

Die Taktanzeige trennt Wartungsplanung, Queue, Claimannahme, Worker, Ansible-Zuordnung und individuelle Auth-Pause. Nur die Auth-Pause wird als automatischer Abrufstopp beschrieben. Damit sind SS-04, SS-05, SS-12 sowie die betreffenden Teile von SS-18, SS-19, SS-21 und SS-24 geschlossen.

## E5: Ansible-Testfencing und AD

Eine additive Migration führt monotone Konfigurationsrevision und Testgeneration ein. Beginn und Abschluss eines Tests sperren nur kurz; SSH/SFTP laufen ohne Datenbanksperre. Ein Ergebnis wird ausschließlich gespeichert, wenn Zugangstyp, Konfigurationsrevision und Testgeneration noch zum getesteten Snapshot passen. Legacyzeilen ohne Bindung gelten nicht als aktueller Nachweis. Secretwerte oder Secretfingerprints werden nicht ausgegeben.

Das Vertragsreview fand außerdem ein Interleaving zwischen dem zunächst getrennten Credential-Update und dem anschließenden Löschen alter Evidenz. Revisionserhöhung und Evidenzlöschung liegen nun atomar in derselben Repository-Transaktion; der tatsächliche Löschfakt wird für das Audit zurückgegeben. Ein nach Commit gestarteter Test der neuen Revision kann dadurch nicht mehr vom älteren Update-Request gelöscht werden.

Die AD-Karte leitet Zulassung, aktuellen Erfolg, veralteten Nachweis und Störung ausschließlich aus `directory_health_snapshot()` ab. Abgelaufene Zertifikate sind kritisch, bald ablaufende warnend. Damit sind SS-16, SS-17 und der Ansible-Anteil von SS-20 geschlossen. Der neue Race-Integrationstest und die Migration benötigen noch die isolierte Laufzeitabnahme.

## E6: Navigation, Rechte und Fehlerpfade

Die bestehende Tabimplementierung löst Fragmente nun bei Initialaufruf und jedem Hashwechsel auf, aktiviert zuerst das besitzende Panel und fokussiert das konkrete sichtbare Ziel. Wiederholte Links auf dasselbe Fragment stellen Fokus und Scrollposition erneut her; fehlerhaft percent-kodierte Fragmente werden sicher verworfen. Missionslinks verwenden den registrierten URL-Owner. Der Systemstatus-Linkowner akzeptiert nur registrierte feste Ziele oder `credential-<positive ID>`. Bedingte Korrektur- und Einstellungslinks werden nur bei tatsächlich vorhandenem Ziel und passender Berechtigung gerendert.

Damit sind SS-03, SS-18, SS-22 und SS-23 geschlossen. Ein Browsertest für Direktaufruf, Hashwechsel und Wiederholung wurde ergänzt, aber ohne QA-Stack nicht ausgeführt.

## E7: Informationsdichte und große Bestände

MECM-Leerzustände und technische Servicevertragsdetails sind einklappbar, während konkrete Fehler sichtbar bleiben. Der Überblick enthält den Deploy-Service und AD nur dann, wenn das AD-Ziel für den Benutzer tatsächlich sichtbar ist. Recoveryfälle sind begrenzt und verlinkt.

Globale Abweichungen werden aus einer einmal materialisierten Ergebnismenge gefiltert, stabil bytegenau und mit ID-Tiebreaker sortiert sowie mit 50 Einträgen pro Seite paginiert. Filter für Mission, Vorlage, VM und Art sowie die Inventarauswahl bleiben in der URL erhalten; es entstehen keine Zeilenqueries. Damit sind SS-07 und SS-24 auf Codeebene geschlossen. Synthetische Mengen-, Layout- und Screenshotabnahme bleibt offen.

## E8: Hilfe und Betriebsdokumentation

DE/EN-Texte und Hilfe wurden an die Ownersemantik angepasst: Retry-Streak gegenüber Auth-Pause, autoritativ leeres Inventar gegenüber abgelehnter Beobachtung, fehlender Clientabschluss, aktuelle gegenüber historischer Evidenz, AD-Zulassung gegenüber Betriebsnachweis und konkrete Recoveryfälle. Der Systemstatus-Hilfereiter besitzt registrierte Sprungziele. Die Betriebshilfen für Deploy-Kette, ESXi-Inventar und MECM dokumentieren Fencing, Evidenz und Grenzen. Veraltete Kommentare zur Ansible-Frische wurden korrigiert.

## Tatsächlich ausgeführte Prüfungen

- `node --check Docker/WebAPI/portal/assets/core.js`
- `node --check tests/e2e/specs/system-status-ampel.spec.js`
- `node --check tests/e2e/specs/system-status-actions.spec.js`
- `node --check tests/e2e/specs/system-status.spec.js`
- `git diff --check` (ohne Whitespacefehler; lediglich bestehende Zeilenendewarnungen)
- Statische DE/EN-Schlüssel- und Platzhalterparität, `__t()`-Existenz, Umlaute und Em-Dash-Prüfung durch den i18n-Reviewer: sauber
- ENUM-Sync, PHP-Version-Sync, Dokumenthygiene und Dokumentsemantik durch den Drift-Reviewer: sauber
- Read-only Vertragsreview der Migration, Lockpfade, Recovery-Eingrenzung und Maschinenflächen; zwei gefundene Interleavings wurden behoben und gezielt erneut als geschlossen geprüft
- Quellinspektion der betroffenen Owner, Lockreihenfolgen, Wiregrenzen und Dateigrößen; gezielte Prüfung des zuvor beanstandeten SQL-Interpolationsmusters ohne Treffer
- exakter lokaler Abgleich der registrierten Dateigrößen für `constants.php` und `migrate.php`

## Bewusst ausstehend

- PHP-Syntax, PHPUnit, Migrationscheck, Sprachkatalogaudit und die fokussierten statischen Verträge, weil weder PHP noch Docker/WSL-PHP verfügbar waren
- erneuter ausführbarer CSP-Skriptlauf nach der SQL-Korrektur, weil Git Bash/WSL im Hostkontext mit Zugriffsfehler abbrach; das identische harte Interpolationsmuster wurde gezielt statisch geprüft
- isolierte Datenbanktests für SS-10 und SS-16 sowie Migration 0052
- Playwright-Geometrie, Tastatur-, Responsive-, Skalierungs- und visuelle QA ausschließlich im synthetischen `virtusphere-qa`-Stack
- externe Abnahme mit echtem MECM, ESXi und AD; Altdaten gelten ausdrücklich nicht als Nachweis
- breite Fast-/Integration-/Release-Lanes und Baselineupdates gemäß Auftrag

Die Änderungen wurden nach Abschlussprüfung gemeinsam mit den parallel entstandenen YAML-/Runtime-Korrekturen versioniert.
