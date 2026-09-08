# Restarbeit: Umsetzung und verbleibende Abnahmen

Stand: 08.09.2026. Ausgangspunkt ist die
[Bestandsaufnahme gegen cf75676](2026-09-07-open-work-inventory.md).
Die Bestandsaufnahme bleibt als damaliger Befund erhalten. Dieser Nachtrag
beschreibt den aktuellen Arbeitsbaum; er ist keine Betriebsfreigabe.

## Implementierte Korrekturen

| Punkt | Änderung | Prüfgrenze |
|---|---|---|
| R01 | Remote-Cleanup liest dauerhafte Create-Ergebnisse; prepared/running/uncertain, verlorene Ownership oder unlesbare Evidenz verhindern das Löschen. Cleanup liegt im registrierten Helper `deploy_worker_cleanup.php`. Auch ein expliziter Cleanup-Fehlercode wird protokolliert. | Echte SSH-/Create-Abnahme offen. |
| R02 | Reconnect prüft Ownership ohne Cancel-Bestätigung. Die Bestätigung bleibt an einer sicheren Schrittgrenze. | Neuer DB-Regressionstest vorhanden, Docker-Abnahme offen. |
| R03 | Claim prüft den Pausenzustand erneut unter der Runtime-Sperre, nach dem Joblock. | Konkurrierende DB-Abnahme offen. |
| R04 | Ein fehlender SSH-Exitstatus ist ein Transportfehler. Nur eine beobachtete 0 ist Erfolg. | Native Regression bestanden. |
| R05 | Zeilenpuffer ist begrenzt; überlange Remote-Zeilen werden vollständig verworfen, ohne Secretfragmente oder gekürzte Kontrollmarker zu verarbeiten. Preflight hält nur den letzten gültigen Stagemarker. | Native Pufferregression bestanden. |
| R06 | Spool entfernt erst bestätigte Einzelzeilen. Ein zweiter Ausfall erhält den unbestätigten Rest. Bei ungewissem Commit ist Wiederholung möglich und ausdrücklich benannt. | Fehler vor und nach simuliertem Commit getestet; keine Exactly-once-Behauptung. |
| R07 | Worker prüft aktuelle Scope-Bounds vor Remote-Arbeit und meldet den Block pro VM. | DB-Test für nach Queue gewachsene Interfaces vorhanden, noch nicht ausgeführt. |
| R08 | Bei gestaffelter Auswahl gilt die Scope-Grenze je entstehendem Ein-VM-Auftrag. | DB-/Live-Endpunkt-Abnahme offen. |
| R09/R10 | Terminales Live-Ende sperrt die Historie nicht. Prepend/Trim erhält die sichtbaren Zeilen und ihren Scrollanker. | Browserregression ergänzt, Ausführung offen. |
| R11 | CSV verwendet eine feste obere ID-Grenze und einen absteigenden ID-Cursor statt verschiebbarem OFFSET. | DB-Test mit zwischenzeitlichen Inserts ergänzt, Ausführung offen. |
| R12 | Die gemeinsame Recovery-Anzeige berücksichtigt uncertain-Create-Einheiten auch terminaler Jobs. Remote- und Create-Nachweise desselben Jobs werden im manuellen Zähler nicht doppelt gezählt. | DB-Abnahme offen. |
| R13/D01 | Deutsche Modusnamen, Reporttoken, SYSTEM-/Admin-ACL und completed-only Site-Health fachlich korrigiert. | Sprach- und Vertragsprüfungen; menschliche Verständlichkeitsprobe offen. |
| O03 | CLI und Portal teilen einen Closure-Analysator. Portalrouten werden aus dem Dateisystem abgeleitet. Der Guard fand einen zusätzlichen fehlenden Layout-Require im Live-Blocker-Endpunkt; behoben. | Konservative statische Analyse; dynamische Aufrufe werden nicht bewiesen. Der unbenutzte Schedule-Helfer der Health-Route ist begründet ausgenommen. |

## RAM und Deep Links

Beide Teile des [RAM-/Deep-Link-Plans](2026-08-27-ram-unit-and-deep-link-target-plan.md)
sind implementiert. Die bestehenden Credential-Artikel erhalten eine gemeinsame
Zielmarkierung. Der Abstand zur Kopfzeile bleibt unverändert. RAM-Parser und
Renderer liegen in `lib/vm_ram.php` und `lib/vm_edit_ram.php`; der Renderer ist
im VM-Modulvertrag und im Formularvertrag registriert. `controls.css` und
`status.css` sind die heutigen CSS-Owner, nicht die historischen Planpfade.

MB bleibt der Daten-, Audit-, Import- und Ansible-Vertrag. Die Einheit ist nur
Formularzustand. Der CSV-Header nennt MB ausdrücklich. Der Browser übernimmt
Faktoren und Grenzen aus dem Markup. Die Preset-Auswahl ist ohne JavaScript
deaktiviert; Zahl und Einheit bleiben serverseitig nutzbar. Ungültige Altwerte
werden sichtbar erhalten. Genau halbe MB sind mit der freigegebenen maximalen
Zehnstellen-Grammatik nicht exakt darstellbar; Tests prüfen beide Seiten dieser
Rundungsgrenze. Der Algorithmus ist dennoch ganzzahliges half-up.

## Help und Betriebsübergabe

Zusätzlicher R05-Fund: Auch die vollständige Inventarausgabe wurde unbegrenzt
akkumuliert. Sie hat jetzt eine eigene Capture-Grenze im Inventory-Parser-Owner.
Bei Überschreitung wird weiter vom SSH-Kanal gelesen, aber kein Präfix als
vollständige Beobachtung übernommen. Der Abruf endet mit Diagnose, der bisherige
Cache bleibt erhalten. Das ist von reinen Kürzungen des gespeicherten Logs
getrennt. Grenztest und Modulvertrag wurden ergänzt.

Die geänderten Hilfen erklären Zustand, nächste Prüfung und tatsächliche
Portalbezeichnung. Zusätzlich korrigiert: Portgruppennamen werden für operative
Gleichheit nicht getrimmt. Grenzen bei Staffelung, ausgelassene Ausgabe und
mögliche Logwiederholungen sind erklärt. Die RAM-Diagnose und die Schritte bei
ungeklärtem Create stehen im [Runbook](../operations/troubleshooting.md).
Auditcodes, Machine-Felder, Statusstrings und Retention wurden nicht geändert.

Die Planköpfe von Create, 14A, PowerShell, Mission-Import und konsolidiertem Plan
unterscheiden jetzt lokale Umsetzung, historischen Ausgangsstand und offene
Abnahme. Historische Testnachweise wurden nicht nachträglich als neue Abnahme
ausgegeben. Das Juli-Archiv bleibt unverändert.

## Prüfungen und Infrastruktur

Docker Desktop startet auf diesem Rechner nicht: Das Backend meldet eine
unzugängliche Laufzeitdatei `dockerInference`. Ein gezielter Umbenennungsversuch
scheiterte; Images, Volumes und Container wurden nicht zurückgesetzt. Der bei
`[1/13] RUN php-lint` hängende Containerlauf wurde beendet.

Für native Diagnose wurde die offizielle PHP-8.4-CLI ausschließlich unter dem
ignorierten `qa-artifacts` bereitgestellt und per SHA-256 geprüft. Native
Prüfungen ersetzen keine Docker-/Linux-/DB-/Browser-Abnahme. Die Artefakte
`open-work-native.json`, `open-work-unit.log`, `open-work-focused-final.log`
und `open-work-phpstan.log` halten die jeweiligen Ergebnisse fest. Ein Windows-
Fehler bei POSIX-CA-Dateirechten ist nicht durch eine Lockerung des Produktcodes
behoben worden.

Abschließender lokaler Nachweis: alle elf ausgewählten nativen Gates bestanden
(684 PHP-Dateien gelintet; Sprachparität, ENUM-/Versions-/Bounds-Synchronität,
Dateigröße, Auditvertrag, beide Dokumentationsprüfungen, CSP und JS-Syntax).
PHPStan: keine Fehler, eine erledigte SSH-Baseline-Ausnahme entfernt.
Native Unit/Static-Diagnose: 1575 Tests, 38571 Assertions, kein Assertion-Fehler,
ein Fehler in `DirectoryFoundationTest::testMaterializedCaFileIsContentAddressedAndRejectsModeDrift`
und vier Skips. Der Fehler entsteht an der geforderten POSIX-Verzeichnismode
0700 unter Windows. Deshalb ist die gesamte Suite ausdrücklich nicht grün.
Die Linux-/Docker-Abnahme bleibt ausstehend.

## Übergabe: noch nicht umgesetzt oder nicht abgenommen

1. Docker-Startproblem am Host beheben; danach den kanonischen Integration-
   und Release-Lauf ausführen. Neue Browser-/DB-Tests sind noch keine Abnahme.
   Die Visual-Baselines wurden nicht verändert.
2. 8R benötigt weiterhin produktive Consumer-/Recovery-Anbindung, einen
   vollständigen evidenzgebundenen Aktivierungsweg und Standortnachweise.
   Deaktivierte Grundlagen sind keine fertige Integration. Keine Freigabe
   wurde gesetzt; Supervisor-Aktivierung bleibt ebenfalls separat.
3. Reale ESXi-, Netzwerk/WDS-, AD/LDAPS-, MECM/Cutover-, Windows/SYSTEM-,
   Datenträger- und Screenreader-Abnahmen bleiben bei den vorhandenen Protokollen.
4. U01–U07 sind weiterhin eigene Featurearbeit. U01 kann auf dem jetzt
   vollständigeren Recovery-Snapshot aufbauen. U02 braucht einen echten
   Queuezeitpunkt, U03 Revision-/Versuchszuordnung, U04 strukturierte
   Istbeobachtungen, U05 Content-/Verteilnachweise, U06 eine belastbare Vorschau
   und U07 Manifest-/Diagnoseverträge. Diese Daten dürfen nicht aus Freitext
   oder einem allgemeinen Änderungszeitpunkt erfunden werden.
5. Die fünf zurückgestellten UX-Pakete, vollständige DoD-Einzelzuordnung des
   konsolidierten Plans und Mission-Imports sowie der unreproduzierte
   Queue-/Cancel-Altfall bleiben offen.
   `identity_unbound_allowed` wurde nicht entfernt: Die Artefakt-/Identitätsfolge
   von Full braucht dafür eine eigene Änderung mit ESXi-Nachweis.

Offene Entscheidung für die nächste Etappe: Zielumgebung und Standortnachweise
für 8R bereitstellen und danach den abgegrenzten U01-Umfang festlegen. Aus
fehlenden Nachweisen wurde hier keine Aktivierung abgeleitet.
