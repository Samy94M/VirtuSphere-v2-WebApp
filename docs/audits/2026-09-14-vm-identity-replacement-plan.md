# VM-Identität nach externer Löschung: Umsetzungs- und Prüfplan

Stand: 14.09.2026. Status: Planung; keine Produktimplementierung und keine ausgeführten QA-Gates.

## 1. Auftrag, Geltungsbereich und Einstieg

Nutzerauftrag: Einen ausführlichen Plan mit Gründen erstellen und anschließend Dokumentation, Hilfe, Edge Cases, SSoT, Drifts, Logik und QoL gegenprüfen. Die vorangegangene Prüfung war ausdrücklich ohne Produktänderungen. Dieser Auftrag autorisiert die Planpflege, nicht Implementierung, Migration, Produktionsaktionen oder Veröffentlichung.

Dieser Detailplan ist im [bestehenden Aufgabenregister](2026-09-12-consolidated-session-backlog.md) verlinkt. Er ist der einzige Paket-/Befundowner für den Vorfall 563 und den daraus abgeleiteten Ersatzvertrag; andere Register sollen nur darauf verweisen. Bestehende Registereinträge und fremde Änderungen bleiben erhalten.

Prüfgrundlage ist der aktuelle lokale Arbeitsstand, der zahlreiche uncommittete Änderungen enthält. Der installierte VirtuSphere-Commit des betroffenen Hosts ist unbekannt. Bereits vorhandene Implementierung wird vor Arbeitsbeginn erneut zugeordnet; nichts wird allein aufgrund dieses Plans wiederholt. Für die Planung sind keine Rückfragen nötig. Standortfreigaben, Testsysteme und tatsächlich eingesetzte Versionen werden erst für die davon abhängigen Ausführungspakete benötigt.

## 2. Vorfall und belastbare Evidenz

Auftrag 563, Mission ATeP05, umfasste 15 VMs im Modus Full. VM-05111 und VM-05112 waren in einem früheren Versuch erstellt und an Portal-Einträge gebunden worden. Der Nutzer bestätigte, diese beiden VMs auf ESXi gelöscht und anschließend erneut bereitgestellt zu haben.

| Objekt | Portal-ID | Gespeicherte Bindung im Diagnosebericht | Ergebnis des neuen Create |
|---|---|---|---|
| VM-05111 | 53 | MOID 7, UUID 52677625-4986-ed5b-89b5-ee9597f0f4cb | MOID 9, UUID 52b3bff3-ed69-665f-faa3-fa8ea7a2b4bb |
| VM-05112 | 54 | MOID 8, UUID 526612f9-4dc4-a560-a26a-00df79a4576a | MOID 10, UUID 5250be43-7809-b952-d3dc-837185023655 |

Das Rohprotokoll enthält bei Sequenz 334 und 640 jeweils `event=succeeded`, `changed=true` und eine zurückgelesene Identität. Direkt danach folgen bei 335 und 641 die Workerfehler `identity_result_invalid`. Prepare meldete jeweils `existed_before=false`. Der Diagnosebericht enthält den Detailtext, dass die vom Modul erfolgreich gemeldete Identität nicht zur Portal-Bindung passt. 13 Einheiten wurden erfolgreich gespeichert, 2 als fehlgeschlagen; keine nachfolgenden Playbooks wurden gestartet.

Die aktuelle gespeicherte Bindung allein wäre kein Beweis ihres historischen Werts. Zusammen mit Fehlerdetail, Live-Markern und Nutzerbestätigung ist der Identitätswechsel hier belastbar. `changed=true` widerlegt für diesen Lauf den anfänglichen Verdacht eines verlorenen Async-Änderungsflags. Die Auswertung beweist nicht unabhängig sämtliche Hardwareeinstellungen oder eine abgeschlossene OS-Installation.

Originale, außerhalb des Repositorys und ohne Geheimnisse in den Plan zu kopieren:

- `C:/Users/Samy/Downloads/virtusphere-deploy-job-563.ndjson`: vollständiges Auftragsprotokoll.
- `C:/Users/Samy/.codex/attachments/1bdee4be-c407-4f35-a639-8d3c491d6dbf/pasted-text.txt`: lesender Diagnosebericht.
- `C:/Users/Samy/.codex/attachments/c8de8996-fb72-46bf-ae10-4afcca629f33/pasted-text.txt`: ursprüngliche Portalansicht.
- Die Sammlungsversion `vmware.vmware 2.9.0` wurde vom Nutzer genannt. Der untersuchte Create-Pfad benutzt jedoch `community.vmware.vmware_guest`; dessen tatsächlich installierte Version ist dadurch nicht belegt.

## 3. Warum das heutige Verhalten scheitert

| ID | Befund und Quelle, Pfade relativ zur Repositorywurzel | Auswirkung / Einordnung |
|---|---|---|
| IDR-F01 / hoch | `Ansible/create_identity_check_tasks.yml` prüft gespeicherte UUID nur bei vorhandenem namensgleichen Objekt; `lib/repo/vm_identity.php` unter `Docker/WebAPI` betrachtet nur den Inventar-Join auf den Namen. | Alte Bindung plus abwesende VM passiert die Vorprüfung und startet Create. Der verbindliche Commit in `lib/repo/deploy_create_identity.php` lehnt anschließend die neue UUID ab. Bestätigter Vorfallpfad. |
| IDR-F02 / hoch | Dieselbe Live-Abfrage bildet nur Namenskandidaten. | Umbenannte VM mit weiterhin existierender alter UUID wird nicht als solche eingeordnet. Vor Ersatz müssen Name und UUID unabhängig aufgelöst werden. Bestätigte Suchlücke; kein zusätzlich behaupteter Produktionsvorfall. |
| IDR-F03 / hoch | `lib/repo/vm_identity.php::repo_vm_identity_adopt_locked()` verlangt vollständige MOID/UUID, prüft aber keine Frische, Namenssemantik oder Multiplizität. | `lib/repo/esxi_inventory_cache.php` behält beim Deduplizieren den ersten Datensatz und setzt `source_count`. Ein solcher mehrdeutiger Kandidat kann derzeit übernommen werden. |
| IDR-F04 / hoch | `lib/deploy_blockers.php` und `lib/deploy_actions.php` übergeben für Übernahme Mission, VM und Zugangsdaten, keine erwartete Kandidatenidentität/-version. | Eine Bestätigung ist nicht an den zuvor gezeigten Kandidaten gebunden. Ein zwischenzeitlicher Inventarwechsel wird nicht als Änderung der bestätigten Entscheidung abgelehnt. |
| IDR-F05 / mittel | `lib/repo/deploy_create_identity.php` verwendet denselben Fehlercode für mehrere Ablehnungen; `lib/deploy_worker_create_poll.php` liefert einen pauschalen Bindungstext. | Fehlende VM-Zeile, widersprüchlicher Zustand, UUID-Konflikt und unzulässige Ergebniskombination sind diagnostisch nicht sauber getrennt. |
| IDR-F06 / mittel | Abgelehnter Erfolg wird als Fehler gespeichert, Async-Cleanup folgt. Schema verbietet bei Nicht-Erfolgen die Erfolgsfelder einschließlich UUID/MOID. | Die beobachtete neue Identität ist nicht in der dauerhaften Ergebniszeile verfügbar. Der Rohlog mit Retention ist dann zentral für die Diagnose. Kein Aufweichen der Erfolgsconstraints, sondern separate Beobachtungsevidenz nötig. |
| IDR-F07 / mittel | `lib/deploy_create_progress.php`, `lib/deploy_log_phases.php`, DE/EN-Deploytexte. | „Erstellte VMs / 15 von 15 abgeschlossen“ zählt auch Fehlschläge; die Create-Phase erscheint nicht im klassischen Step-Marker-Ablauf. Technisch unterschiedliche Zähler wirken wie ein Erfolgsversprechen. |
| IDR-F08 / mittel | DE/EN `help_deploy.deploy_identity_p2`, `docs/operations/esxi-inventory.md` und Inventarkommentare. | Hilfe beschränkt zurückbleibende VMs fälschlich auf Abbruch/Timeout; Inventar wird als nie blockierend bezeichnet, obwohl Identitätsblocker daraus entstehen. |

Dateinamen und Funktionsowner sind Suchanker, keine Behauptung einer stabilen Zeilennummer. Vor Implementierung Source-Manifest mit Dateihashes sichern; für aktuelle Planung wurden die genannten Aufrufketten lesend geprüft.

## 4. Ziel, Nichtziele und unveränderte Schutzregeln

Ziel: Vor einer VM-Mutation verständlich entscheiden, ob eine erste Erstellung, die Bearbeitung einer gebundenen VM, eine Übernahme oder ein bewusst bestätigter Ersatz zulässig ist. Fehlende oder widersprüchliche Evidenz darf keine neue Instanz erzeugen. Historische Aufträge bleiben nachvollziehbar.

Unverändert bleiben:

1. Der UUID-Konflikt beim atomaren Erfolgscommit ist eine Schutzgrenze. Er darf nicht global ignoriert werden.
2. Kein Löschen, automatisches Ersetzen oder Zurücksetzen auf `NULL` allein aufgrund eines Fehlers oder leeren Caches.
3. Kein zweiter Create-Pfad; Prepare/Launch/Status/Cleanup und persistente Async-/Remotehandles bleiben zuständig.
4. `uncertain` bleibt eine offene Ausführung. Eine Übernahme oder Ersatzfreigabe erledigt sie nicht nebenbei.
5. Erfolgreiche historische Ergebniszeilen werden nicht an neue UUIDs angepasst. Eine spätere Auflösung ist eine zusätzliche Tatsache.
6. Ein Retry behält die dafür vorgesehene Auswahl und Pipeline-Semantik; neue Missions-VMs werden nicht still aufgenommen.
7. DE/EN bleibt rein Darstellung. Maschinenstatus, Callback-Felder und Versionsverträge werden nicht als UI-Reparatur verändert.
8. ESXi bleibt Quelle des beobachteten Bestands; der Portal-Writer besitzt die autorisierte Bindung. Beobachtung allein ersetzt keine Bindungsentscheidung.

Nicht Teil dieses Pakets: automatisches Löschen verbliebener Datastoredateien, allgemeine vCenter-/Hostmigration, ein neuer Deploy-Controller, ein globaler Auto-Reconcile-Dienst oder automatische positive Auflösung sämtlicher historischer `uncertain`-Fälle.

## 5. Fachliche Entscheidungsmatrix

„Frisch und vollständig“ bedeutet eine passende, erfolgreich beantwortete VM-Abfrage des verifizierten Zielsystems mit belegter Beobachtungsversion. Seitenladezeit, globaler letzter Erfolg oder eine erfolgreiche Datastoreabfrage ersetzen das nicht. Ein API-Erfolg beweist zudem nicht automatisch vollständige Sichtberechtigung; diese Voraussetzung gehört zum Ziel-/Zugangsvertrag.

| ID | Bindung / Beobachtung | Entscheidung vor Mutation | Warum |
|---|---|---|---|
| IDR-S01 | Keine Bindung; erwarteter Name nachweislich frei | Erste Erstellung erlaubt | Es wird keine bestehende Identität ersetzt. |
| IDR-S02 | UUID und Name stimmen; genau ein Objekt | Bestehende VM über UUID bearbeiten | Name dient nur zur Plausibilisierung, die UUID wählt das Objekt. |
| IDR-S03 | UUID stimmt, MOID geändert | MOID auffrischen | Ein neuer Hostgriff ist allein keine neue Instanz. |
| IDR-S04 | Alte UUID unter anderem Namen gefunden | Umbenennung anzeigen und klären; kein Create | Namensabwesenheit ist keine Instanzabwesenheit. Automatisches Rückbenennen ist nicht mitbeauftragt. |
| IDR-S05 | Alte Bindung; weder UUID noch Name nachweisbar | Normalen Create sperren; Ersatzvorschau anbieten | Eine neue Instanz ist eine ausdrückliche Absichtsänderung. |
| IDR-S06 | Name vorhanden; fremde/fehlende Portal-UUID | Konflikt; eindeutigen Kandidaten zur Übernahme prüfen | Namensgleichheit allein autorisiert keine Übernahme. |
| IDR-S07 | Alter UUID-Treffer und weiterer Namenskandidat | Beide zeigen, sperren | Die alte Instanz könnte umbenannt worden sein; keine willkürliche Wahl. |
| IDR-S08 | Mehrdeutig, UUID/MOID fehlen, unbekannte Semantik | Sperren und Diagnose anbieten | Ein erster Treffer oder Legacywert ist keine belastbare Identität. |
| IDR-S09 | Abfrage fehlt/fehlschlägt/ist zu alt oder Zielwechsel unklar | „Nicht feststellbar“, lesenden Abruf anbieten | Ein Beobachtungsfehler bedeutet nicht „gelöscht“. |
| IDR-S10 | Offene oder fremde historische Ausführung | Recovery-/Fencing-Sperre hat Vorrang | Ein zweiter Create könnte einen noch laufenden ersten überholen. |

Create/Full dürfen IDR-S01 ausführen. Start, Autostart, Export und Powercycle erhalten dadurch keinerlei Erstellungsrecht. Der bestehende Modusowner entscheidet über benötigte Operationen; keine zweite Modusliste in UI oder Hilfe einführen.

## 6. Empfohlenes Daten- und Konkurrenzmodell

### 6.1 Drei unterschiedliche Aktionen

- **Wiederholen:** Neuer Auftrag mit dem durch den bestehenden Retry-Owner bestimmten Scope. Bestätigte Vorgänger werden überprüft, offene Ausführungen bleiben gesperrt.
- **Vorhandene VM übernehmen:** Geprüften Kandidaten ausdrücklich binden, ohne Hardwareänderung oder automatischen Folgelauf.
- **Ersatz-VM vorbereiten:** Eine neue Instanz anstelle einer nachweislich fehlenden alten Bindung autorisieren. Der Titel ist ein Entwurf; endgültiger DE/EN-Wortlaut folgt dem Portalreview.

### 6.2 Versionierte Bindung und Ersatzabsicht

Empfehlung: Eine Bindungsversion beziehungsweise Bindungsgeneration mit unveränderbarer Historie einführen. Nicht mit der bereits existierenden Runtime-/Execution-Generation gleichsetzen. Der Portal-Eintrag bleibt stabil, seine konkrete ESXi-Instanz kann durch eine autorisierte Entscheidung wechseln.

Mindestens modellieren: Portal-VM, Bindungsversion, bisherige UUID/MOID, verifizierter Zielkontext, neue UUID/MOID nach Erfolg, Aktion/Grund, handelnde Person, Zeitpunkt, zugehöriger Job/Einheit und Beobachtungsreferenz. Die aktuelle Bindung hat genau einen Schreibowner. Historie darf kein zweiter unabhängig veränderbarer aktueller Zustand sein.

Ein Ersatzauftrag erhält eine dauerhafte Operations-ID und die erwartete alte Bindungsversion. Er ersetzt die alte Bindung erst beim atomar bestätigten Übergang auf die neue Instanz. Währenddessen ist der Zustand „Ersatz ausstehend“ eindeutig; andere Mutationen dürfen weder die alte Instanz versehentlich weiterverwenden noch einen zweiten Ersatz starten. Ein bloßer Freigabe-Boolean ohne Scope, Version und Auftragsbindung ist unzureichend.

Bestätigen und Einreihen sollen atomar erfolgen oder über eine einmalig konsumierbare persistente Freigabe verbunden sein. Für einen gemischten Full-Auftrag wird der Ersatz pro VM im bestehenden Auftrag materialisiert; kein zweiter separater Create-Job, der mit dessen übriger Auswahl konkurriert. Standardempfehlung: Bestätigung, Freigabeverbrauch und Jobmaterialisierung in einer Transaktion. Ein gespeicherter Entwurf autorisiert noch nichts.

### 6.3 Vorschau und Compare-and-swap

Die Vorschau bindet die Entscheidung an VM-ID, bisherige Bindungsversion, Zielsystem/-kontext, exakten Namen, Kandidaten-UUID und Beobachtungsversion. Felder vom Browser sind erwartete Werte, keine autoritative Bestandsquelle. Ein serverseitiger Vergleich muss jeden relevanten Wechsel ablehnen und eine neue Vorschau verlangen.

Remoteabfragen laufen außerhalb langer DB-Transaktionen. Anschließend wird die gesicherte Beobachtung unter den notwendigen Sperren erneut gegen aktuelle Bindung, Jobs und Freigabe geprüft. Vor Launch folgt die Live-Prüfung. Netzwerk-I/O unter einer Missionssperre wäre ein neues Blockierungs-/Deadlockrisiko.

Die bestehende Missionssperre schützt eine Mission. Doppelte Portalbindungen über mehrere Missionen oder Zugangsdaten erfordern zusätzlich einen klaren Ziel-/Objektbesitzvertrag. Kein globaler UUID-Unique-Index ohne Prüfung des tatsächlichen VMware-Scopes. Eine Zugangsdaten-ID ist nicht automatisch eine unveränderliche Zielsystemidentität: Host-/Endpointänderung und zwei Logins zum selben Host müssen in IDR-D02 entschieden werden.

Lockreihenfolge vor Implementierung gegen Queue, Retry, VM-Edit, Adoption, Runtime und Callbacks festlegen. `edit_version` und neue Bindungsversion dürfen sich nicht widersprechen: eine Übernahme während eines offenen Editors muss beim späteren Write erkannt werden. Keine Zeitstempel als Konkurrenzversion.

### 6.4 Beobachtung und Ergebnis getrennt speichern

Bei ESXi-Erfolg und abgelehntem Bindungscommit zusätzlich dauerhaft festhalten: erwartete und beobachtete UUID/MOID, Ziel, Job/Einheit/Async-ID, Modulresultat, Beobachtungszeit und geschlossener Ablehnungsgrund. Keine freien vollständigen Modulobjekte mit Zugangsdaten speichern.

Die bestehenden Erfolgsconstraints nicht lockern, um die Beobachtung in Erfolgsfelder zu quetschen. Vorzugsweise versionierte additive Evidenz über einen einzigen Writer persistieren. Cleanup erst nach dauerhaft gesicherter erforderlicher Evidenz; bei DB-Ausfall vorhandenen DbChannel-/Ownership-Vertrag nutzen. Historische Logs nicht nachträglich als neuen verbindlichen Erfolgswriter auswerten.

Audit: alter und neuer Bindungsbezug sowie Operations-ID müssen nachvollziehbar sein. Die aktuelle Adoption schreibt Audit erst nach der Bindungstransaktion; für den neuen Vertrag Commit-/Audit-Ausfälle ausdrücklich behandeln. Bestehenden Auditowner verwenden, keine zweite Audittabelle nur für dieses Feature erfinden.

### 6.5 Historie und Folgezustände

Ein Ersatz hat keine Berechtigung, alte MACs, MECM-ResourceIDs, erfolgreiche OS-/Paketphasen oder Heartbeats als Beleg für die neue Instanz zu übernehmen. Pro Feld festlegen: Sollkonfiguration behalten, aktuelle Beobachtung invalidieren, historische Evidenz erhalten. Keine pauschale Löschung aller VM-Zustände.

Besonders prüfen: `needs_mac` kann Powercycle-Auswahl beeinflussen; eine alte MAC darf die Vorbereitung einer neuen Instanz nicht überspringen lassen. Bewusst konfigurierte statische MACs sind anders zu behandeln als importierte Beobachtungen. Späte Callbacks müssen zur gültigen Ausführung und Bindung passen. Ob bestehendes Fencing hierfür genügt, ist ein Prüfauftrag, kein bereits bestätigter Defekt und keine vorweggenommene Wire-Änderung.

Die Nachprüfung zeigt einen konkreten Grund für diese Trennung: `lib/repo/vm_rollout.php` koppelt die vorhandene Rolloutrevision im untersuchten Editpfad an die normalisierte Hostnamenidentität und friert sie bei bestehender MECM-Übergabe ein. Ein Austausch der ESXi-UUID bei gleichem Hostnamen ist damit nicht automatisch eine neue Rolloutrevision. P06 muss die realen Aufrufketten untersuchen; die vorhandene Revision darf nicht ungeprüft zur neuen Bindungsgeneration erklärt werden.

Weiterer Pflichtpunkt: Serverlist und andere Jobartefakte können vor Create erzeugte Identitäten enthalten. Nach bestätigtem Ersatz müssen sämtliche Folgeschritte dieselbe autorisierte neue Bindung verwenden. Den vorhandenen Mechanismus für neue/ungebundene VMs und Artefaktaktualisierung zuerst untersuchen, statt pauschal erneut hochzuladen oder `identity_unbound_allowed` auf Ersatzfälle auszuweiten.

`verify_skip` bestätigt die UUID des historischen Erfolgs. Eine autorisierte neue Bindung darf diesen Beweis nicht umschreiben. Der Retry-Plan muss Ersatz explizit als neue Arbeit materialisieren oder nachvollziehbar eine neue Ausgangsplanung verlangen; keine endlose Folge „übernehmen → verify_skip scheitert“.

## 7. Arbeitspakete mit Gründen und Abnahme

Alle Pakete sind offen. Die Reihenfolge ist fachlich; Dokumentation und Gegenbeispiele werden jeweils im selben Paket gepflegt, nicht erst am Ende.

| Paket | Inhalt und Warum | Owner / Abhängigkeiten | Abnahme |
|---|---|---|---|
| IDR-P00 | Installierten Stand, betroffene lokale Änderungen und Versions-/Collectionnachweise erfassen; Quellmanifest und synthetische Vorfallfixture anlegen. Verhindert Prüfung des falschen Stands. | Koordination; keine produktiven Writes. | Vorfall, lokaler Stand und Installationsstand getrennt; Fixture enthält keine Secrets. |
| IDR-P01 | Entscheidungsmatrix und kritische Vertragsfragen festlegen. Verhindert verschiedene Antworten in Portal, Ansible und Commit. | Fachanalyse; IDR-D01 bis D06. | Jeder Zustand aus Abschnitt 5 besitzt Ergebnis, Aktion, Ursache und Gegenbeispiel. |
| IDR-P02 | Vorläufige Schutzkorrektur: alte Bindung plus fehlendes Objekt vor Create stoppen; UUID auch unter anderem Namen suchen; genauer Grund. Verhindert Wiederholung des Vorfalls ohne bereits einen Ersatzworkflow auszuliefern. | Gemeinsame Ansible-Prüfung; Queue-/Retry-/Workerowner. Nach P01. | Vorfallfixture erzeugt keine neue VM; Erstcreate, gleiche UUID und andere MOID funktionieren weiter. Kein UI-only-Guard. |
| IDR-P03 | Adoption mit Frische, Multiplizität, Kandidaten-/Bindungsversion und Audit absichern. Verhindert falsche oder überholte Übernahme. | `repo/vm_identity.php`, `repo/vms_legacy.php`, Actions/Blockers/Audit. Nach P01; verwendet die in P04 festgelegte Versionierung oder ein kompatibles Teilstück. | Geänderter Kandidat/Editor, zwei Bestätigungen, alter Cache und doppelte Namen werden korrekt behandelt; No-op wiederholbar. |
| IDR-P04 | Additives Schema für Bindungshistorie, Ersatzabsicht und Beobachtungsevidenz; Constraints und atomare Writes. Schafft dauerhafte, prüfbare Entscheidungen. | Ein Schema-/Repoowner; Migrationsnummer erst bei Ausführung vergeben. Nach P01. | Fresh/Upgrade-Konvergenz, Legacyzustände ohne erfundene Historie, atomarer Commit und gesicherte Evidenz bei Fehlern. |
| IDR-P05 | Ersatzfreigabe in bestehende Queue-/Create- und Retry-Pfade integrieren; Doppelstartschutz und Reattach. Trennt Ersatzabsicht von blindem Retry. | Worker-/Repoowner; nach P02/P04, gemeinsame Versionierung mit P03. | Eine bestätigte Ersatzabsicht erzeugt höchstens eine gestartete Create-Einheit; Antwortverlust führt zum selben Auftrag; Full behält Scope. |
| IDR-P06 | MAC, Powercycle, MECM, Heartbeats und Callback-Fencing gegen Bindungswechsel prüfen und notwendige Anpassungen umsetzen. Verhindert veraltete Erfolgsbelege. | Betroffene Machine-/PowerShellowner nach P04/P05; nur bei nachgewiesenem Bedarf Wire erweitern. | Neue Instanz wird nicht durch alte Beobachtungen als fertig behandelt; Sollwerte und Historie bleiben erhalten. |
| IDR-P07 | Vorschau, Bestätigung, Ergebnis-/Retryansicht, Diagnoseexport und DE/EN-Texte integrieren. Macht Schutzregeln handhabbar. | Portalowner; nach stabilen P01/P03–P06-Verträgen. | Abschnitte 8/9 erfüllt, JS-freier POST sicher, Rechteprüfung und Entwurfserhalt nach Fehler. |
| IDR-P08 | Unabhängige Vertrags-/Driftprüfung, kanonische QA, Standortnachweise und Auslieferungsplan. Belegt den Gesamtfluss. | Rollen gemäß Workflow; nach implementierten Paketen. | Abschnitt 11 mit gültigem Source-Manifest; externe Lücken offen benannt. Keine Veröffentlichung aus diesem Plan abgeleitet. |

P03 und P04 sind nicht als zirkuläre Abhängigkeit auszuführen: P01 entscheidet das Datenmodell zuerst; P04 liefert dessen benötigte Versionierungsbasis, darauf P03. Unabhängige Vorbereitungsanalyse ist möglich. Vorläufige P02-Auslieferung darf klar sperren und manuelle Prüfung anbieten; sie darf keinen bereits verfügbaren sicheren Ersatzablauf vortäuschen.

Für eine später autorisierte Terra/Sol/Astra-Ausführung: Sol High für Logik, Implementierung und Testdesign; Sol Medium für QA und Git-/Publikationsprüfung; Terra für entschiedene Dokument-/UI-Arbeit. Eine begrenzte Astra-High-Prüfung nur für die kritischen Ersatz-/Fencing-/Atomaritätsfragen vor QA. Keine routinemäßige Wiederholung des gesamten Audits. Ein Schreibowner pro Datei und ein Owner am QA-Stack. Dieser Plan startet keine Agenten oder neuen Benutzertasks.

## 8. QoL-Anforderungen und konkrete Bedienabläufe

| ID | Anforderung | Begründung und Gegenprobe |
|---|---|---|
| IDR-Q01 | Fehler nach „Was geschah / Auswirkung / nächster Schritt“ erklären. Beispiel: ESXi meldete Erstellung, Portal lehnte neue Bindung ab, Folgeschritte nicht gestartet. | Kein Schluss „fehlgeschlagen = VM existiert nicht“. Gegenprobe mit echtem Modulfehler und unklarem Ausgang. |
| IDR-Q02 | Vorhandene, erwartete und beobachtete Identität mit Ziel und Beobachtungszeit zeigen; UUIDs vollständig kopierbar. | Kürzel allein reichen bei ähnlichen Werten nicht; fehlender Wert darf nicht als Übereinstimmung erscheinen. |
| IDR-Q03 | Erstcreate, Übernahme, Ersatz und Retry sichtbar unterscheiden. Bestätigung nennt betroffene VM, alte/neue Bindung und Wirkung. | Kein universeller „Reparieren“-Button mit wechselnder Wirkung. |
| IDR-Q04 | Auswahl, Zeitplanung und Formularentwurf bei Aktualisierung, Konflikt und Navigation bewahren. | Nach Reparatur keine überraschende Rückkehr zu allen Missions-VMs. Entfernte Ziele bleiben sichtbar ausgeschlossen. |
| IDR-Q05 | Vorschau für Full zeigt ursprünglichen Scope, verify_skip, bestehende VMs zur Konvergenz, Ersatz und noch nicht gestartete Arbeit. | Für 563: 13 frühere Erfolge prüfen, 2 korrigierte Bindungen bearbeiten; Folgekette erst nach bestätigter Create-Phase. |
| IDR-Q06 | Fortschritt nennt bearbeitete Einheiten und fachliche Ergebnisse getrennt. Create-Phase erscheint trotz fehlender klassischer Step-Marker. | Kein „15 erstellt“ bei 13 übernommenen Erfolgen. Timeline aus bestehenden strukturierten Quellen zusammensetzen, keine Logprosa als Ergebnis-SSoT. |
| IDR-Q07 | Lesender Diagnoseexport ohne Shellpflicht: Job, Bindung, Beobachtung, Ursache, aktuelle Auflösung und Versionen. | Support darf nicht von manueller Base64-Decodierung oder noch vorhandenen Async-Dateien abhängen. Redaction/RBAC erhalten. |
| IDR-Q08 | Sammelprüfung möglich, aber jeder Kandidat versionsgebunden. Atomare oder partielle Sammelbestätigung vorab definieren. | Empfehlung für erste Lieferung: gemeinsame Vorschau, Einzelübernahme; keine unklare Halbtransaktion. |
| IDR-Q09 | Doppelklick/Antwortverlust führt zu vorhandener Operations-ID und Ergebnislink. | Kein zweiter Auftrag wegen fehlender Browserantwort. |
| IDR-Q10 | Keine neue modale/JS-Infrastruktur; gemeinsame Confirm-, Form-, Copy-, URL- und Statusowner nutzen. | Tastatur, Fokus, schmale Viewports, lange UUIDs, DE/EN und POST ohne JS bleiben konsistent. |

## 9. Dokumentations-, Help- und SSoT-Matrix

Diese Dateien wurden nach dem ersten Audit erneut anhand ihrer Owner/Schlüssel zugeordnet. „Anpassen“ bedeutet zukünftige Produktarbeit, keine Änderung durch die Planerstellung. Pfade unter `Docker/WebAPI` sind ausdrücklich gekennzeichnet; DE und EN müssen gemeinsam geändert werden.

| ID | Quelle | Erforderliche Anpassung / Prüfkriterium |
|---|---|---|
| IDR-DOC01 | `docs/operations/deploy-chain.md` | Ablauf extern gelöscht → Bindung fehlt am Host → Ersatzentscheidung; Übernahme/Retry/Ersatz trennen; historische und aktuelle Evidenz erklären; Diagnose vor/nach Retention. |
| IDR-DOC02 | `docs/operations/esxi-inventory.md` | Pauschale Behauptung „blockiert nie“ korrigieren. Anzeige-/Kapazitätswarnungen von identitätsrelevanten Gate- und Freigabeentscheidungen unterscheiden. Frische, Multiplizität und Zielscope erklären. |
| IDR-DOC03 | `docs/operations/troubleshooting.md` | Symptom „VM existiert, Auftrag meldet Fehler“, konkrete Ursachen und lesenden Prüfweg ergänzen. Keine Lösch-/SQL-Reset-Anleitung als Standardreparatur. |
| IDR-DOC04 | `Docker/WebAPI/lang/{de,en}/help_deploy.php` | `deploy_identity_p1/p2`, `deploy_mode_create`, `deploy_retry_p2/p3`, `create_progress_p3/p4/p5`, `deploy_warn_p3`, `action_outcomes_*` fachlich abgleichen. „Nur Abbruch/Timeout“ streichen; Übernahme löst `uncertain` nicht. Vor Veröffentlichung Ersatzfunktion nur als vorhanden beschreiben, wenn ausgeliefert. |
| IDR-DOC05 | `Docker/WebAPI/lang/{de,en}/deploy.php` und ggf. `deploy_history.php` | `identity_*`, neue geschlossene Ursachen, Bestätigung und Folgewirkung, `create_progress_*`, `phases_empty`, Retrytexte. Einheitlicher Aktionsname und echte Umlaute. Grenzen interpolieren. |
| IDR-DOC06 | `Docker/WebAPI/lib/help/deploy.php`, `lib/help_page.php` | Neue Helpabschnitte nur über Registry/Renderer integrieren; existierende Links erhalten. Kein manuell erfundener Hashlink. |
| IDR-DOC07 | `Docker/WebAPI/lang/{de,en}/help_missions.php`, `help_system_status.php`, `help_credentials.php` | Querverweise prüfen: externe Löschung, Zielwechsel, Inventaralter, aktive Mission. Nur tatsächlich betroffene Texte ändern, keine flächige Neuformulierung. |
| IDR-DOC08 | `docs/adr/ADR-0041-worker-driven-per-vm-create.md`, `docs/adr/README.md` | Ersatz als neue Absicht, Bindungsversion und historischer verify_skip-Vertrag begründet ergänzen oder verlinkte Folge-ADR anlegen; ADR-0041 nicht rückwirkend als bereits implementiert umschreiben. Nummer nach aktuellem Index vergeben. |
| IDR-DOC09 | `docs/ai/contracts/deploy.md`, `docs/ai/reference/ansible.md`, `docs/ai/reference/webapi.md` | Vor-/Nachbedingungen, ein Klassifikationsowner, Ersatzfreigabe und Beobachtungsevidenz konsistent aufnehmen. Zuordnung zu bestehenden A46/R2/R22 prüfen. |
| IDR-DOC10 | `docs/ai/reference/database.md`, ggf. `docs/ai/contracts/machine.md` und `docs/ai/reference/machine-api.md` | Bindungsversion, Sperrreihenfolge, Migration und Legacyzustände; Machine-Referenzen nur bei tatsächlicher Betroffenheit, keine ungeprüfte Wire-Neufassung. |
| IDR-DOC11 | `GROK.md` 1.3, `AGENTS.md`, `.claude/rules/{ansible,webapi,database}.md` | Dauerhafte Verbote nur im zentralen Katalog; Adapter bleiben Verweise. Neue Dopplungen vermeiden. AGENTS-Routing nur ändern, wenn ein neuer Owner das erfordert. |
| IDR-DOC12 | `Docker/WebAPI/lib/repo/esxi_inventory_cache.php`, `Docker/mysql/mysql-init/struktur.sql` | Veraltete Kommentare „nur Warncache / niemals blockieren“ korrigieren. Schema-SSoT und Migration inhaltlich deckungsgleich halten. |
| IDR-DOC13 | `docs/QA.md`, `docs/QUALITY-GATES.md`, vorhandene Test-/Runnerowner | Neue Fälle an bestehende Gates anbinden. Dokumentation nur ändern, falls sich Setup oder Gatevertrag ändert; keine zweite Gate-Liste als ausführbare Wahrheit. |
| IDR-DOC14 | `docs/CHANGELOG.md`, Release-/Upgradehinweise | Erst tatsächlich gelieferte Änderungen beschreiben; konservative Legacybehandlung und Altworker-Kompatibilität erklären. Keine erfundene Fehlerheilung alter Aufträge. |

Sprachparität allein ist keine Abnahme: DE und EN können dieselbe falsche Behauptung enthalten. Für jeden Hilfesatz über Freigabe, Erstellung, Wirkung oder Erfolg muss eine passende ausführbare Regel beziehungsweise ausdrücklich benannte Betriebsgrenze existieren.

## 10. Edge-Case- und Gegenbeispielkatalog

| ID | Fall | Erwarteter Beleg / Ergebnis |
|---|---|---|
| IDR-E01 | Exakter Vorfall 563 | Vor Schutzkorrektur reproduzierbar; danach keine Neuanlage ohne Ersatzentscheidung. Nach autorisiertem Ersatz/Übernahme kein falscher UUID-Erfolg. |
| IDR-E02 | Keine alte Bindung | Erstcreate unverändert möglich. |
| IDR-E03 | UUID gleich, MOID neu | Kein Ersatz erforderlich; UUID bleibt gleich. |
| IDR-E04 | Alte UUID unter anderem Namen | Kein Name-basiertes Create; Umbenennung sichtbar. |
| IDR-E05 | Fremder Namenskandidat und alte UUID zugleich vorhanden | Mehrdeutigkeit sperrt; beide Bezüge diagnostizierbar. |
| IDR-E06 | Mehrfachname / `source_count > 1` | Weder Queuefreigabe noch Adoption auf Basis des ersten Cacheeintrags. |
| IDR-E07 | Case-, Unicode-, Whitespacevarianten | Exakte Namenssemantik erhalten, keine Trim-/Case-basierte Objektwahl. |
| IDR-E08 | Fehlende UUID/MOID, Legacysemantik | Diagnose statt automatische Bindung oder Ersatz. |
| IDR-E09 | Fehlgeschlagene/partielle/leere VM-Abfrage | Nur autoritativ leere erfolgreiche Sicht kann Abwesenheit beitragen. Kein Rückschluss aus globalem Erfolg. |
| IDR-E10 | Alter Cache, Uhrgrenze, Abfrage startet vor Änderung und endet danach | Zeitstempel allein nicht als hinreichender neuer Beweis verwenden; Beobachtungsversion/Live-Nachprüfung testen. |
| IDR-E11 | Rechteverlust oder eingeschränkte Inventarsicht | „Nicht feststellbar“, keine Löschungsannahme. |
| IDR-E12 | Änderung zwischen Formular und POST | Kandidaten-/Bindungsversion lehnt überholte Bestätigung ab, Entwurf bleibt erhalten. |
| IDR-E13 | Änderung zwischen Prepare und Launch | Strukturierte Ablehnung vor Modulaufruf. |
| IDR-E14 | Externer Konkurrent zwischen letzter Prüfung und Modul | Restliches Race dokumentiert und konservativ behandelt; kein unbelegtes Exactly-once-Versprechen gegenüber fremden Akteuren. |
| IDR-E15 | Doppelklick, Retry des HTTP-POST nach Antwortverlust | Gleiche Operation zurückgeben, keine zweite Ersatzfreigabe/Queue. |
| IDR-E16 | DB-Ausfall vor/nach Commit, Auditfehler | Kein erfundener Erfolg; Operationsstatus lesend auflösbar; Evidenz/Cleanup korrekt. |
| IDR-E17 | Worker-Neustart, SSH-Verlust, abgelaufene Lease | Derselbe Remote-/Async-Handle wird beobachtet; kein zweiter Create. |
| IDR-E18 | Job cancelled/partial, Remotehandle weiter offen | Remote-/Historienfence schlägt Ersatzfreigabe und neue Queue. |
| IDR-E19 | Historischer Erfolg wurde später gelöscht/ersetzt | verify_skip darf neue UUID nicht als alten Erfolg bestätigen; Ersatzentscheidung explizit planen. |
| IDR-E20 | Wiederholung einer Wiederholung | Ursprünglicher Erfolgsbezug und neue Bindungsversion bleiben nachvollziehbar. |
| IDR-E21 | Vollkette mit 13 Erfolgen/2 Konflikten | Ursprüngliche 15er-Auswahl; Folgekette erst nach gültiger Create-Phase. |
| IDR-E22 | Nachträglich neue/gelöschte/verschobene Portal-VM | Kein stilles Erweitern/Verkleinern des Auftrags; fehlende Ziele erklären. |
| IDR-E23 | Geplanter/gestaffelter Auftrag, späterer Ziel-/Konfigurationswechsel | Freigabe am Ausführungszeitpunkt revalidieren; erwartete Version bindet alle relevanten Werte. |
| IDR-E24 | Zwei Zugangsdaten zum selben Ziel oder zwei Missionen zum selben Objekt | Besitz-/Scopekonflikt trotz unterschiedlicher Missionslocks erkennen. |
| IDR-E25 | Endpoint der Zugangsdaten geändert, Restore auf anderem Host | Zielherkunft unbekannt; keine UUID-Übertragung aus bloß gleicher Credential-ID. |
| IDR-E26 | Unregister statt Delete; alte Dateien bleiben | Kein automatisches Datastore-Cleanup; Bediener erkennt Beweisgrenze. |
| IDR-E27 | Alte VM erscheint nach Ersatz wieder | Keine automatische Rückbindung; beide Instanzen als Konflikt sichtbar. |
| IDR-E28 | Alte MAC/MECM-/Heartbeatdaten | Kein Überspringen erforderlicher neuer Schritte, keine Fertigmeldung aus alter Ausführung. |
| IDR-E29 | Absichtlich statische MAC / mehrere NICs / VLANänderung | Sollkonfiguration und importierte Beobachtung getrennt behandeln; keine pauschale MAC-Löschung. |
| IDR-E30 | Retention entfernt Logs/Asyncdatei | Dauerhafte Beobachtung und Bindungshistorie bleiben hinreichend zur Erklärung; keine erfundene Hardwarekonvergenz. |
| IDR-E31 | Rechteentzug, CSRF, POST ohne JS, offene Editorversion | Gleiche Serversperren, keine Umgehung durch direkte Action; keine Secrets in Entwurfs-/Diagnoseausgabe. |
| IDR-E32 | Migration mit laufendem Altworker / Downgrade | Keine gemischte Ausführung über inkompatible Bindungsverträge; Startfence oder koordinierter Workerwechsel. |
| IDR-E33 | Backup/Restore nach Ersatzfreigabe oder Launch | Historie, Operations-ID und Remote-/Runtimefence konsistent; kein erneuter Launch aus altem Backup. |
| IDR-E34 | Neue Bindung, alter Hostname und bestehende MECM-Übergabe | Vorhandene Rolloutrevision nicht als automatische Ersatzgeneration interpretieren; explizite Consumerprüfung. |
| IDR-E35 | Neue Bindung nach Create, vorab erzeugte Serverlist mit alter UUID | Powercycle/Export/Start nutzen konsistent die autorisierte neue Identität; kein später erneuter Namens-Fallback. |

## 11. Test- und Abnahmeplan

Aktueller Status: ausschließlich Quellen- und Planprüfung. Keine ausgeführten Tests und keine behauptete grüne QA. Der aktuelle Planungsauftrag benötigt keine produktive Reproduktion.

Vorhandene Abdeckung wiederverwenden: `VmIdentityCollisionTest`, `AnsibleVmIdentityContractTest`, `DeployCreateWorkerFlowTest`, `DeployCreateResultsIntegrationTest`, `DeployCreateReleaseTest`, `DeployCreateHistoricalFenceTest`, `DeployCreateTerminalRetryTest`, `DeployCreateRetryEvidenceTest`, `DeployCreateProgressTest` und Create-Playbook-Contracttests. Vor neuer Arbeit prüfen, ob zwischenzeitliche Änderungen Fälle bereits abdecken. Die bestehende Prüfung des abschließenden UUID-Konflikts ersetzt nicht den E01-Nachweis vor Mutation.

Neue Tests sollen Verhalten beweisen: Modulaufrufzähler bei abgelehntem Create gleich null; konkurrierende Bestätigungen erzeugen genau eine Operation; geänderte UUID im Cache wird nicht übernommen; alte MACs liefern keinen neuen Erfolg. Reine Stringsuche im Playbook kann die Produktionsmatrix ergänzen, nicht ersetzen. Gemeinsame synthetische Zustandsvektoren gegen PHP und tatsächliche Ansible-Auswertung prüfen.

Neue Diagnosecodes brauchen einen gemeinsamen Kompatibilitätsnachweis für `deploy_create_constants.php`, `ansible_create_protocol.php`, Zustandsvalidierung, Schema, Worker, Retry und DE/EN-Presenter. Das aktuelle Ergebnisfeld ist auf 32 Zeichen begrenzt. Konkrete Codes erst gegen diese Registry festlegen; kein unbekannter Marker, der eine fachliche Ablehnung erneut zum Protokollfehler macht. Historische Codes bleiben lesbar. Neue Beobachtungsklassen dürfen die bestehende Partial-/Failed-Matrix nicht still ändern: Auswirkung auf dem Host und bestätigter Portal-Erfolg müssen getrennt erklärbar bleiben.

Alle ausführbaren Gates werden bei späterer Testfreigabe ausschließlich aus `scripts/check.ps1` gewählt. `docs/QA.md` besitzt Setup, `docs/QUALITY-GATES.md` die Interpretation. Betroffene Prüfbereiche: PHP-Unit/Static/Integration, Schema-Konvergenz und Migration, Ansible-Syntax/Lint/Modulvertrag/Async, Sprachparität, Dokumenthygiene/-semantik, Portal-E2E und passende visuelle Nachweise. Exakte aktuelle Gate-IDs und notwendige finale Lanes vor Ausführung aus dem Runner ermitteln; keine Kopie der Registry in diesem Plan pflegen.

Ein autorisierter synthetischer `virtusphere-qa`-Stack, ein QA-Owner. Kein Test gegen die Installation des Nutzers. Visuelle Baselines nur über den menschlich ausgeführten Baselinewriter, nicht durch Agenten aktualisieren. Fehlende Voraussetzungen als `infrastructure_error` dokumentieren. Bestehende Evidenz nur bei gültigen Quellen, Abhängigkeiten, Umgebung und Scope wiederverwenden.

Vor langen Läufen pollbares Log anlegen, echte `[n/total]`-Fortschritte melden. Ein neuer canonical runner path muss den bestehenden Fortschrittsvertrag und dessen Pester-Test erweitern. Nach jedem Paket Ergebnis, Source-Manifest, Artefakte und nächste offene Arbeit hier verlinken.

Reale isolierte Laborabnahme vor Betriebsfreigabe: externe Löschung, Umbenennung, Unregister, eingeschränkte Sicht, gleichzeitige externe Aktion, tatsächliche Collectionversion und Legacy-Transport des Standortes. MAC-/MECM-Kette nur auf dafür autorisierten synthetischen Zielsystemen. Ohne Laborbeleg keine Behauptung, alle ESXi-Races oder Maschinenfolgen seien geprüft.

## 12. Migration, Rollout und Wiederanlauf

Additive Migration und frisches Schema gemeinsam planen; vorhandene Erfolgsconstraints und historische Ergebniszeilen erhalten. Alte Bindungen haben gegebenenfalls keine gesicherte Zielherkunft: als Legacy/ungeprüft behandeln, keine Herkunft aus dem zuletzt sichtbaren Inventar erfinden. Historische `identity_result_invalid`-Zeilen sind Verdachtskandidaten und werden nicht per Massenupdate korrigiert.

Vor Aktivierung der Ersatzfunktion müssen alle betroffenen Worker den Bindungsvertrag verstehen. Lange aktive Jobs nicht für eine Migration blind abbrechen; kompatible Schemaergänzung und Funktionsaktivierung getrennt planen. Inkompatibler Altworker darf keine freigegebene Ersatzoperation konsumieren. Downgrade auf Code ohne neue Fences ist nach Nutzung der Funktion nicht automatisch zulässig.

P02 kann als konservative Sperre separat geliefert werden. P03–P07 erst aktivieren, wenn Historie, Konkurrenzkontrolle, Folgezustände und UI zusammen passen. Ein Rollback deaktiviert neue Ersatzfreigaben und erhält laufende/unklare Operationen samt Evidenz; er setzt keine UUIDs zurück und löscht keine VMs. Konkrete Freigabe/Veröffentlichung ist ein späterer Nutzerauftrag.

Für Auftrag 563: Historisches Teilergebnis erhalten. Eine später erfolgreich übernommene aktuelle Bindung ist eine gesonderte Tatsache. Nach geprüftem Bestand den bestehenden Retry-Owner verwenden, nicht nur die zwei Fehler-VMs als vermeintlich vollständige Full-Kette starten. Vorher aktuelle Hardware-/Netzwerk- und Maschinenfolgen prüfen.

## 13. Offene technische Entscheidungen

Diese Punkte sind durch Quellen-/Implementierungsanalyse zu entscheiden, nicht pauschal als Rückfragen an den Nutzer abzugeben.

| ID | Frage | Empfehlung / notwendiger Nachweis |
|---|---|---|
| IDR-D01 | Bindungshistorie als eigene Tabelle oder Erweiterung vorhandener Strukturen? | Ein aktueller Writer, immutable Historie, additive Beobachtung. Entscheidung nach Query-/FK-/Retention-/Restoreanalyse; noch kein finales Schema. |
| IDR-D02 | Was identifiziert den Zielhost dauerhaft bei mehreren Logins/Endpointänderungen? | Vertrauenswürdig beobachtete Zielidentität mit Scope; nicht Credential-ID allein. Standalone-ESXi-Felder im unterstützten API-/Collectionstand nachweisen. |
| IDR-D03 | Welche Beobachtungsfrische und Sichtvollständigkeit sind für Adoption/Ersatz nötig? | Vorhandene per-kind Evidence-Owner wiederverwenden; passende Quelle/Grenze definieren, keine willkürliche neue Minutenzahl. Kritischen Moment live nachprüfen. |
| IDR-D04 | Wie werden MAC-/Installationsbelege an Bindungswechsel gekoppelt? | Feld-/Consumerliste erstellen, vorhandenes Fencing nachweisen; zusätzliche Version nur an nötigen Grenzen. Keine spekulative Maschinenprotokollmigration. |
| IDR-D05 | Wie wird Ersatz im Retry eines früheren Erfolgs materialisiert? | Explizite Ersatzoperation für neue Bindung, historischen verify_skip-Beweis erhalten. Gegenprobe E19/E20. |
| IDR-D06 | Kann Start einer neuen Instanz gegenüber externen gleichzeitigen Akteuren eindeutig attribuiert werden? | Verfügbare modul-/taskgebundene Evidenz untersuchen; keine erfundene Atomarität. Fehlender Beweis hält den betroffenen Ausgang offen und verhindert blinde Wiederholung. |

## 14. Herstellerquellen und Begründung der Architektur

Die Quellen wurden für die vorausgehende Prüfung am 14.09.2026 geöffnet. Sie erklären Prinzipien und API-Grenzen, ersetzen aber keinen Test des installierten Standes. Es handelt sich um Herstellerdokumentation und etablierte Architekturpraxis, nicht um eine zwingende ISO-Norm für dieses Portal.

- [Ansible vmware_guest](https://docs.ansible.com/projects/ansible/latest/collections/community/vmware/vmware_guest_module.html): Namen sind nicht zwingend eindeutig; UUID wird bei Neuanlage ignoriert. Deshalb weder Name noch übergebene alte UUID als Ersatzbeweis verwenden.
- [Broadcom VirtualMachineConfigInfo](https://developer.broadcom.com/xapis/vsphere-web-services-api/latest/vim.vm.ConfigInfo.html): `instanceUuid` ist eine VirtualCenter-spezifische Instanzidentität, getrennt von SMBIOS-UUID. Deshalb Zielscope und konkrete unterstützte Standalone-Semantik prüfen.
- [Kubernetes Object Names and IDs](https://kubernetes.io/docs/concepts/overview/working-with-objects/names/): Wiederverwendbarer Name und konkrete Objekt-UID sind unterschiedliche Identitäten. Das unterstützt die Trennung Portalobjekt/VM-Instanz, bedeutet aber keine Übernahme des Kubernetes-Controllers.
- [AWS: Making retries safe with idempotent APIs](https://aws.amazon.com/builders-library/making-retries-safe-with-idempotent-APIs/): Anfrageidentität und Absicht binden; gleiche Operations-ID mit anderer Absicht nicht still wiederverwenden. Deshalb Ersatzabsicht separat persistieren.
- [Terraform plan](https://developer.hashicorp.com/terraform/cli/commands/plan): Beobachtungsabgleich und geplanter Ersatz sind getrennte Vorgänge. Deshalb Vorschau und explizite Entscheidung statt automatischem State-Reset.

## 15. Nachprüfung und aktueller Abschlussstand

Planstatus: erstellt; Produktpakete IDR-P00 bis P08 nicht ausgeführt. Abschnitt 2 enthält bereits lesend vorhandene Vorfallevidenz, erfüllt aber nicht sämtliche P00-Installations-/Fixtureanforderungen.

Nachprüfung gegen die zugeordneten Quellen:

- SSoT: Queue/Retry, gemeinsame Ansible-Prüfung, aktueller Bindungswriter, Ergebnis-/Auditwriter und Registry-Gates bleiben benannte Owner. Keine zweite Create-Pipeline geplant.
- Logik: Erstcreate, Fortsetzen, Übernahme, Ersatz und `uncertain` sind getrennt. Nachträgliche Bindung heilt historische Ergebnisse nicht. P03/P04-Abhängigkeit explizit aufgelöst.
- Dokumentation/Help: IDR-DOC01 bis DOC14 nennen konkrete Stellen, Schlüssel und Änderungsgründe. Bestehende falsche Aussagen sind von erst nach Implementierung gültigen Beschreibungen getrennt.
- Edge Cases: IDR-E01 bis E35 umfassen Vorfall, Race, Zielscope, Historie, MAC, Retention, Upgrade, Restore sowie Rolloutrevision und Artefaktidentität. Standort-/Maschinennachweise werden nicht als erledigt ausgegeben.
- QoL: Q01 bis Q10 enthalten Wirkung, nächste Schritte, Versionsbindung, Entwurfserhalt und Gesamtumfang. Sammelaktionen sind für die erste Lieferung bewusst begrenzt.
- Drift: Freigabezustand, aktuelle Bindung und beobachtete Realität bleiben getrennte Quellen; DE/EN-Hilfe wird semantisch geprüft. Die pauschale Cachebehauptung und die bisherige Timeout-Erklärung werden als konkrete Korrekturen geführt.

Die Nachprüfung ist eine statische Quellen-/Planprüfung, keine unabhängige Vertragsabnahme und kein QA-Pass. Nächster Schritt bei Implementierungsauftrag: P00 und P01, dann konservative P02-Korrektur. Bis dahin keine Produktänderung, automatische Übernahme, Ersatzfreigabe oder Wiederholung des Auftrags 563.

Bei der Nachprüfung ergänzte Lücken: bestehende MECM-Rolloutrevision ist kein nachgewiesener Ersatz für Bindungsversion; Jobartefakte müssen neue Bindungen konsistent weitertragen; neue Fehlercodes müssen den geschlossenen Marker-/Schema-/Presentervertrag erfüllen. Diese Ergänzungen sind als P06-/P05-/QA-Abnahme aufgenommen, ohne einen ungeprüften Produktdefekt zu behaupten.
