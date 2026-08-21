# Mission-Import: Folgeplan fuer Shape-Sicherheit, Handoff, Uploadgrenzen und Diagnose

Stand: 2026-08-20. Dieser Plan ist fuer die Ausfuehrung in einer neuen Sitzung geschrieben und ersetzt fuer diesen Folgeumfang die widerspruechlichen Teile von `C:\Users\Samy\.claude\plans\logical-knitting-kay.md`. Der urspruengliche Fix aus Commit `3187391` bleibt die Basis: Namensprobleme sind Vorschau-Befunde, der GET-Dry-Run scheitert nicht mehr still, und `blocked_in_file` deaktiviert den Bestaetigen-Button nur fuer Befunde, die das Namensfeld nicht beheben kann.

Der Plan implementiert keine Deploy-, MECM-, PowerShell-, Worker-, Schema- oder Machine-API-Aenderung. Der bei der Planerstellung vorhandene fremde, uncommittete Diff in `docs/audits/2026-08-11-deploy-reliability-master-plan.md` bleibt unangetastet. In der ausfuehrenden Sitzung gilt trotzdem allein der dann aktuelle `git status`/Diff.

### Reproduzierter Ausgangsstand

Die folgenden Befunde wurden am 2026-08-20 gegen den aktuellen Code reproduziert und sind die roten Startfaelle der Umsetzung:

- `interfaces: "oops"` ergibt heute `blocked=false`, `counts.interfaces=1`, keine Feldfehler; der Write wuerde daraus ein leeres DHCP-Interface bauen.
- `disks: "oops"` ergibt heute `blocked=false`, `counts.disks=1`; `repo_replace_disks()` schreibt wegen des nicht-iterierbaren Strings dagegen 0 Disks.
- `packages: "oops"` wird ohne Befund verworfen.
- Ein zusaetzliches `interfaces[*].mac = "not-a-mac"` blockiert heute die Preview, obwohl der Write MAC bewusst aus der Transferprojektion entfernt.
- Ein alter Token A loescht heute durch den Mismatch-`unset()` den neueren Handoff B derselben Session.
- Das aktuelle Container-Image meldete trotz Repo-Ini `4M/6M` noch `upload_max_filesize=2M`, `post_max_size=8M`; die Ini-Aenderung ist image-basiert und war nicht neu gebaut/recreated.

Die bestehenden gezielten Tests waren dabei gruen (9 Mission-Transfer-Integrationstests, 5 Preview-Static-Tests), ebenso der relevante kanonische Acht-Gate-Ausschnitt. Die neuen Tests muessen deshalb genau diese bisher ungedeckten Negativrichtungen beweisen; ein erneut gruener Altbestand ist kein Abschlussnachweis.

## 0. Auftrag und Abnahmekriterium

Der Mission-Import gilt erst als abgeschlossen, wenn alle folgenden Aussagen zugleich wahr sind:

1. Vorschau und Schreibpfad arbeiten auf demselben kanonischen Transferdokument. Ein Feld, das der Import verwirft, kann die Vorschau nicht blockieren; ein Feld, das geschrieben wird, wird vor der Vorschau und erneut vor dem Write mit derselben Repo-Regel validiert.
2. Kaputte JSON-Formen werden weder per `(array)` in scheinbar gueltige Daten verwandelt noch still uebersprungen. Zaehlung, Befund und Write koennen fuer ein bestaetigbares Dokument nicht auseinanderlaufen.
3. Ein alter oder fremder Import-Token loescht nie die aktuell gueltige Vorschau eines anderen Uploads derselben Sitzung.
4. Erwartbare Datei-/Namens-/TTL-Fehler sind lokalisiert und erzeugen kein Audit-Rauschen. Unerwartete gefangene Fehler haben eine interne Referenz und einen sicheren Server-Logeintrag, ohne Payload, Token, Temp-Pfad oder Dateiinhalte zu protokollieren.
5. `VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES` bleibt die fachliche SSoT. HTML, Hilfetext und App-Pruefung leiten sich daraus ab; ein Guard pinnt die technisch notwendigen groesseren PHP-/nginx-Grenzen dagegen.
6. Hilfe, ADR, Runbook, QA-Nachweis und Code sagen dasselbe ueber Button-Gates, Abbrechen, TTL, Logkanaele und Uploadfehler.
7. Der Originalfall "Vorlage exportieren, wieder hochladen, Vorschau" ist im Browser bewiesen: Vorschau sichtbar, Praefixfehler am Namensfeld, Bestaetigen aktiv, Umbenennen fuehrt zum erfolgreichen Import.

## 1. Vor Beginn der Umsetzung

Die neue Sitzung liest vollstaendig:

- `AGENTS.md`, `GROK.md`, `.claude/rules/webapi.md`;
- `docs/adr/ADR-0014-portal-i18n-and-user-facing-errors.md`;
- `docs/adr/ADR-0021-backup-status-channel-and-mission-transfer.md`;
- `Docker/WebAPI/lib/mission_transfer.php`, `mission_transfer_import.php`, `mission_transfer_export.php`, `missions_import_panel.php`;
- `Docker/WebAPI/portal/missions.php`;
- `Docker/WebAPI/lib/repo/missions.php`, `repo/vms.php`, `repo/catalog.php`, `repo/esxi_inventory_cache.php`;
- die bestehenden Mission-Import-Integration-, Static- und E2E-Tests.

Danach:

1. `git status --short`, `git diff --name-only`, `git diff --check` ausfuehren.
2. Jeden bereits veraenderten Zielpfad hunkweise lesen; fremde Hunks erhalten.
3. Pruefen, ob 14A aus dem Deploy-Masterplan inzwischen `repo_validate_interfaces()` oder einen neuen `vm_network_contract.php` eingefuehrt hat. Falls ja, dessen Gesamtlisten-Pruefung verwenden und keine VLAN-/Mehrdeutigkeitsregel in diesem Plan duplizieren. Ebenso pruefen, ob die im fremden Masterplan geplante 14D-Rolloutnamen-/Snapshotlogik inzwischen umgesetzt ist; dann deren `vm_name`-/`vm_hostname`-/Provenienzvertrag unveraendert durch die Transferprojektion tragen und keine zweite Rolloutnamenregel erfinden.
4. Aktuelle Zeilenzahlen lesen. `mission_transfer_import.php` lag bei Planerstellung bei 339 Zeilen und `MissionTransferRoundTripTest.php` bei 364; neue Fachlogik bzw. neue Testgruppen deshalb in eigene Module/Dateien legen, nicht die 400-Zeilen-Grenze ausreizen.
5. Den historischen Plan `logical-knitting-kay.md` nicht als Code-SSoT verwenden. Dieser Folgeplan und der aktuelle Code sind massgeblich.

## 2. Nicht verhandelbare Zielvertraege

### 2.1 Transfer-Schema und Projektion

- `VIRTUSPHERE_MISSION_TRANSFER_MISSION_FIELDS`, `VIRTUSPHERE_MISSION_TRANSFER_INTERFACE_FIELDS`, `REPO_MISSION_COPYABLE_COLUMNS` und `REPO_VM_COLUMNS` bleiben die Feldlisten-SSoTs.
- MAC, MECM-ID, Primaerschluessel und Laufzeitstatus bleiben ausgeschlossen. Insbesondere wird ein im JSON zusaetzlich vorhandenes `interfaces[*].mac` vollstaendig ignoriert: nicht validieren, nicht anzeigen, nicht schreiben.
- Keine rohe Importstruktur wird mehr fuer Zaehlung, VLAN-Sammlung oder Repo-Write gecastet. Erst kanonisieren, danach denselben kanonischen Wert fuer Report und Write verwenden.
- Unbekannte Zusatzfelder werden fuer Vorwaertskompatibilitaet ignoriert. Ein bekanntes **schreibbares** Transferfeld mit falschem Typ wird dagegen als dateiinterner Feldfehler gemeldet und blockiert. `mission_status` bleibt trotz Exportfeld davon ausgenommen, weil der Import ihn immer durch `VIRTUSPHERE_MISSION_STATUS_DEFAULT` ersetzt; sein Dateiwert wird weder validiert noch geschrieben. `mission_name` ist nur der Vorschlag fuer das editierbare Zielfeld und wird bei falscher Form zu `''`, nicht zu einem dateiinternen Blocker.
- `exported_at` ist Anzeige-Metadatum, kein Schreibfeld. Leer, ungueltig oder nicht skalar blockiert den Import nicht und wird nicht roh als angeblicher Zeitstempel dargestellt; die Zeile wird dann weggelassen.

### 2.2 Report und Button

- `report['blocked']` bleibt das einzige Write-Verweigerungspraedikat.
- `report['blocked_in_file']` bleibt die Teilmenge, die den Button deaktiviert.
- `name_invalid` und `name_conflict` setzen `blocked`, aber nie `blocked_in_file`, weil das Namensfeld sie behebt.
- Fehlende VLANs, globale VM-Konflikte, dateiinterne VM-Duplikate, Strukturfehler und Mission-/VM-Feldfehler setzen beide Flags.
- Fehlende Pakete bleiben eine Warnung und werden beim Write uebersprungen. Eine syntaktisch kaputte Paketreferenz ist kein "fehlendes Paket", sondern ein Struktur-/VM-Feldfehler und blockiert, damit Datenverlust nicht still bestaetigt wird.
- Renderer leiten kein Praedikat neu her. Button, Erfolgszeile und Blockhinweis lesen ausschliesslich die Reportfelder.

### 2.3 Session-Handoff

- Es bleibt bewusst genau ein aktiver Mission-Import pro PHP-Sitzung; ein neuer Upload ersetzt den vorherigen. Mehrere 2-MB-Payloads parallel in der Session werden nicht eingefuehrt.
- Ein Token-Mismatch bedeutet nur: der angefragte Link besitzt nicht den aktuellen Handoff. Er darf den aktuellen Handoff nicht `unset()`ten.
- Nur der passende abgelaufene Token, ein korrupter aktueller Zustand, ein nicht analysierbares passendes Dokument oder ein erfolgreicher Import entfernt den aktuellen Zustand.
- Exakte TTL-Grenze bleibt: Alter `<= VIRTUSPHERE_MISSION_IMPORT_TTL_SECONDS` gueltig, groesser abgelaufen.
- Bei einem fehlgeschlagenen Confirm mit passendem Token bleibt der Handoff erhalten und `suggested_name` wird auf den gerade eingegebenen, getrimmten Namen aktualisiert. Der Redirect zeigt damit genau den Namen und Befund, den der Operator gerade abgeschickt hat.
- Die bestehende Nutzerentscheidung bleibt: Abbrechen ist ein Link und keine neue POST-Aktion. Der Zustand bleibt bis Ueberschreiben, passendem TTL-Aufruf oder Session-GC vorhanden. Browser-Zurueck innerhalb der TTL darf die Vorschau deshalb wieder zeigen. Doku und Kommentare behaupten nicht mehr, Abbrechen entferne den Zustand.
- Bei jedem Aufruf der Missionsseite darf ein nachweislich abgelaufener oder korrupter aktueller Handoff auch ohne `?import=` still bereinigt werden. Damit wird die Session bei weiterer Portalnutzung tatsaechlich aufgeraeumt; ein geschlossener Tab ohne weitere Requests bleibt naturgemaess Sache des Session-GC.

### 2.4 Uploadgrenzen

- Fachlimit: `VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES` (derzeit 2 MiB).
- Das Uploadformular rendert vor dem File-Input `<input type="hidden" name="MAX_FILE_SIZE" ...>` aus genau dieser Konstante.
- `MAX_FILE_SIZE` ist nur die PHP-/UX-Frueherkennung und keine Sicherheitsgrenze: der Server vergleicht `$_FILES['size']` weiterhin selbst mit der Konstante, weil ein Client das Hidden-Feld aendern oder weglassen kann.
- `UPLOAD_ERR_INI_SIZE` und `UPLOAD_ERR_FORM_SIZE` ergeben die lokalisierte Zu-gross-Meldung, nicht "keine Datei".
- `UPLOAD_ERR_NO_FILE`, `UPLOAD_ERR_PARTIAL`, `UPLOAD_ERR_NO_TMP_DIR`, `UPLOAD_ERR_CANT_WRITE`, `UPLOAD_ERR_EXTENSION` und unbekannte Codes werden getrennt klassifiziert. Infrastrukturfehler bekommen eine Referenz und Serverdiagnose; erwartbare Auswahl-/Groessenfehler nicht.
- `upload_max_filesize` bleibt groesser als das App-Limit und `post_max_size` groesser als `upload_max_filesize`. nginx bleibt groesser als `post_max_size`. Diese unvermeidbaren abgeleiteten Zahlen werden durch einen automatischen Guard gegen die PHP-Konstante gepinnt.
- Der Plan verspricht keine lokalisierte App-Antwort fuer Bodies oberhalb `post_max_size` oder nginx `client_max_body_size`, weil PHP dann Action/CSRF/Payload nicht mehr sieht. Dieser Infrastruktur-Cutoff wird im Runbook ehrlich benannt.

### 2.5 Protokollierung

| Ereignis | Portal | `deploy_logs` Audit | Server-/PHP-Log | Job-/Machine-Kanaele |
|---|---|---|---|---|
| Erfolgreicher Import | Erfolgs-Flash + Redirect | genau eine bestehende `missions`-Zeile mit Zielname und VM-Anzahl | keine neue Zeile | unveraendert |
| Name, Datei-Feld, Struktur, fehlendes VLAN/Paket, TTL, alter Token, zu grosse/ungueltige Datei | lokalisierter konkreter Befund | keine Zeile | keine Zeile fuer erwartbare Bedien-/Dateifehler | unveraendert |
| Unerwarteter gefangener Preview-/Confirm-/Upload-Infrastrukturfehler | generische lokalisierte Meldung mit Referenz | keine neue Feature-Auditkonvention | genau eine begrenzte Zeile: Scope/Phase, Referenz, Exceptionklasse bzw. Uploadcode; keine JSON-Daten, Token, Dateinamen, Temp-Pfade oder Sessioninhalte | unveraendert |

Das ist absichtlich kein Audit-on-Failure-Umbau. `deploy_logs` beantwortet weiterhin erfolgreiche fachliche Aenderungen und bestehende Security-/Systemereignisse; die technische Diagnose eines gefangenen Importfehlers gehoert ueber das konfigurierte PHP-`error_log()` nach `Docker/WebAPI/logs/php-error.log`. Nicht zusaetzlich `repo_log_failure()`/`fail.log` verwenden, sonst entstehen fuer denselben Fehler zwei Diagnosekanaele. Keine neue Auditkategorie, DB-Spalte, Migration, Joblogquelle oder Machine-API-Antwort.

## 3. Gemeinsame Dokumentanalyse als neue SSoT

### 3.1 Neues Modul `Docker/WebAPI/lib/mission_transfer_document.php`

Das Modul wird von `mission_transfer.php` nach den Transferkonstanten geladen und bleibt frei von Portal-/Sessionlogik. Es besitzt genau eine Analyse-/Kanonisierungsfunktion, beispielsweise:

```php
mission_transfer_document_analyze(array $payload): array
```

Rueckgabeform (Namen duerfen bei der Umsetzung praezisiert, die Verantwortungen nicht verschoben werden):

```text
format_version
exported_at                 gueltiger kanonischer String oder ''
mission                     kanonische, schreibbare Missionswerte
suggested_name              skalar/getrimmt oder ''
vms                         kanonische VM-Liste
mission_shape_errors        lokalisierte Meldungen
vm_shape_errors             lokalisierte Meldungen mit VM-/Unterpositionslabel
counts                      Anzahl der kanonisch erkannten VMs/Interfaces/Disks/Pakete
```

Regeln:

1. Falsche Version, fehlender/nicht-arrayfoermiger Missionsblock und fehlende/nicht-listenfoermige `vms` sind Dokumentfehler mit lokalisiertem sicheren Text. Keine rohe englische `RuntimeException` darf ins Portal gelangen.
2. `array_is_list()` entscheidet Listenform fuer `vms`, `interfaces`, `disks`, `packages`. Ein JSON-Objekt wird nicht still als Liste interpretiert.
3. Ein nicht-arrayfoermiger VM-Eintrag wird als `VM #n`-Strukturfehler erfasst und nicht kanonisiert. Die Vorschau bleibt renderbar und blockiert.
4. Nicht-listenfoermige Untercontainer werden als Fehler der betreffenden VM erfasst und zu `[]` kanonisiert. Dadurch zaehlt ein String nicht mehr als eine Disk/ein Interface.
5. Nicht-arrayfoermige Untereintraege werden mit VM und Position gemeldet und nicht in die kanonische Liste uebernommen.
6. Nur bekannte Feldlisten werden projiziert. Bekannte nicht-skalare Werte erzeugen einen Fehler und einen sicheren kanonischen Ersatz; unbekannte Felder werden ignoriert.
7. `interfaces[*].mac`, `id` und sonstige nicht uebertragene Felder sind unbekannt fuer die Transferprojektion und koennen deshalb weder einen Fehler noch einen Write erzeugen.
8. Paketname leer oder Name/Version nicht skalar ist ein positionierter Datei-Fehler. Eine korrekt geformte, aber im Zielkatalog nicht aufloesbare Referenz bleibt die bestehende nichtblockierende Warnung.
9. Counts werden ausschliesslich aus kanonisch erkannten Eintraegen gebildet. Ein verworfener skalarer Listenwert erhoeht keinen Zaehler; seine Originalposition bleibt in der Fehlermeldung sichtbar. Fuer jeden unblocked Report entsprechen die Counts dadurch exakt den Einheiten, die der Write verarbeitet.
10. Meldungstexte entstehen ueber `validator_text()` und neue DE/EN-Keys, nicht als hartcodierte Portalprosa. Technische Pfade wie `interfaces[2].vlan` duerfen als Daten im lokalisierten Satz erscheinen.
11. Die Funktion fuehrt keine DB-Abfrage und keinen Write aus. Damit sind alle Shape-Kanten als schnelle Unit-Tests beweisbar.

### 3.2 `mission_transfer_import.php` auf die Analyse umstellen

Am Anfang von `mission_import()` einmal `mission_transfer_document_analyze()` aufrufen. Danach ausschliesslich deren kanonische Rueckgabe verwenden:

- Missionsfeldvalidator auf kanonischen `repo_mission_copyable_values()`;
- Mission-VLAN aus genau den kanonischen Missionswerten, nicht aus rohem `$missionSrc`;
- VM-Validator auf der kanonischen VM-Projektion;
- Interface-/Diskvalidator auf kanonischen Listen;
- Counts aus der Analyse, nicht aus `(array)`-Casts;
- Paketaufloesung aus kanonischen Referenzen;
- Transaction-Closure erhaelt kanonische Mission/VMs und baut Interfaces nicht ein zweites Mal anders um.

Die vorhandenen `mission_shape_errors`/`vm_shape_errors` werden mit den Repo-Validatorfehlern in `mission_field_errors`/`vm_field_errors` zusammengefuehrt und setzen `blocked_in_file`.

Fuer Interfaces ist die Reihenfolge lasttragend:

1. Transferprojektion schliesst MAC und Fremdfelder aus.
2. Bestehende `repo_validate_interfaces()`-Regel prueft die kanonische Gesamtliste. Wenn die aktuelle Repo-Funktion beim ersten kaputten Eintrag abbricht, darf die Vorschau zusaetzlich je kanonischem Eintrag dieselbe Repo-Funktion auf einer Ein-Eintragsliste aufrufen, um mehrere Meldungen zu sammeln; keine Regel wird kopiert.
3. Falls 14A eine listenweite VLAN-Regel besitzt, muss die Gesamtliste weiterhin einmal durch deren Owner laufen. Einzelpruefungen ersetzen die listenweite Pruefung nie.
4. Write uebergibt genau diese kanonische Liste an `repo_replace_interfaces()`.

Analog fuer Disks. Der Write bleibt in einer `repo_transaction()` und fuehrt keine neue Vorab-Transaktion ein.

### 3.3 Deduplizierung und Rennen

- VM-Namen innerhalb der Datei weiter ueber `esxi_inventory_name_key()` vergleichen; kein neues `mb_strtolower(trim())`.
- Globale Konfliktmeldungen nach demselben Key deduplizieren. Zwei dateiinterne Duplikate duerfen denselben globalen Konflikt nicht zweimal anzeigen; die separate Duplikatmeldung nennt die wiederholten Positionen.
- VLAN-Referenzen weiter ueber `esxi_inventory_name_key()` deduplizieren und die erste Schreibweise anzeigen.
- Confirm analysiert live erneut. Eine zwischen Preview und Confirm entstandene Mission-/VM-Namenskollision blockiert serverseitig; Client-Disable ist nie Sicherheitsgrenze.
- Die bestehende DB-Unique-Constraint fuer Missionsnamen bleibt letzte Rennsicherung. Die globale VM-Eindeutigkeit bleibt beim bestehenden Repo-Owner; dieser Plan fuehrt keinen unvollstaendigen zweiten Lockvertrag ein. Ein echter Parallelimport-Race ausserhalb der vorhandenen Repo-Garantie wird als separater Architekturbefund dokumentiert, nicht beiläufig mit einem falschen Lock "behoben".

## 4. Portal-Helfer fuer Upload, Handoff und Diagnose

### 4.1 Neues Modul `Docker/WebAPI/lib/mission_import_portal.php`

Dieses Modul ist portalnah und wird nur von `portal/missions.php`/seinen Tests geladen. Es enthaelt:

1. Eine reine Handoff-Statusfunktion mit geschlossener Rueckgabe `valid | expired | mismatch | missing | invalid`.
2. Eine reine Uploadfehler-Klassifikation fuer alle PHP-`UPLOAD_ERR_*`-Codes.
3. Eine kleine Diagnosefunktion fuer unerwartete Fehler, die eine `virtusphere_error_reference()` erzeugt, genau eine begrenzte `error_log()`-Zeile schreibt und die Referenz zurueckgibt. Die Signatur nimmt keinen Payload, Token oder Dateipfad entgegen.

Statusfunktion und Uploadklassifikation bekommen Unit-Tests; keine Sessionglobale wird innerhalb der reinen Funktionen mutiert.

### 4.2 Upload-POST in `portal/missions.php`

- Uploadcode zuerst klassifizieren; nicht mehr alle Nicht-OK-Zustaende zu `import_err_no_file` falten.
- `size <= 0` getrennt von `size > MAX` behandeln.
- JSON-Decode mit bestehender Tiefengrenze; `json_last_error()`/`JsonException` sicher auf lokalisierte JSON-/Tiefe-Meldung abbilden.
- Nach Decode sofort die gemeinsame Dokumentanalyse ausfuehren. Nur ein auf Top-Level analysierbares Dokument in die Session legen; verschachtelte Shape-Befunde gehoeren gerade in die Preview und duerfen den Handoff deshalb nicht verhindern.
- Nach erfolgreicher Top-Level-Analyse den urspruenglichen decodierten Payload speichern, nicht nur dessen bereinigte Projektion. Sonst verschwinden die Shape-Befunde bei der erneuten GET-/Confirm-Analyse. Canonical Values werden nie als zweite Session-SSoT persistiert.
- Die kanonische Analyse wird beim GET/Confirm defensiv erneut aus diesem gespeicherten Payload erzeugt; die Regel bleibt an einer Stelle.
- `suggested_name` aus der gemeinsamen Analyse, nicht aus einem rohen Array-Cast.
- Tempname, Client-Dateiname und JSON-Inhalt nie loggen.

### 4.3 GET-Vorschau

Die bisher duplizierten Token-/TTL-Bedingungen durch die Handoff-Statusfunktion ersetzen:

- `valid`: live analysieren und rendern;
- `expired`: nur den passenden Zustand loeschen, `import_err_expired`;
- `mismatch`: aktuellen Zustand erhalten, `import_err_gone`;
- `missing`: nichts loeschen, `import_err_gone`;
- `invalid`: korrupten aktuellen Zustand loeschen, neutrale lokalisierte Meldung.

Erwartete Dokument-/Validierungsfehler liefern konkrete lokalisierte Meldungen. Ein unerwarteter Throwable entfernt nur den passenden defekten Handoff, wird einmal sicher protokolliert und zeigt `missions.import_err_unexpected` mit `:reference`. Kein `portal_error_message()`-Fallback darf dabei rohe PHP-Prosa anzeigen.

### 4.4 Confirm-POST

- Dieselbe Handoff-Statusfunktion und dieselben Unset-Regeln wie GET.
- Vor dem Import den getrimmten Namen im passenden Sessionzustand als `suggested_name` ablegen, damit jeder Fehlerredirect ihn behaelt.
- Erwartete Block-/Validation-/Constraint-Befunde lokalisiert anzeigen und Handoff erhalten.
- Unerwartete Fehler einmal sicher protokollieren, generische Referenzmeldung anzeigen und Handoff fuer einen moeglichen Retry erhalten, solange er strukturell gueltig und noch nicht abgelaufen ist.
- Nur nach erfolgreicher Transaction: Handoff entfernen, genau einmal `audit(... missions, 'imported mission ...')`, Erfolgs-Flash, Redirect zur neuen Mission.

### 4.5 Renderer

`missions_import_panel.php` bleibt Renderer:

- `blocked_in_file` direkt lesen;
- kein `$structuralBlock`/manuell zusammengesetztes Ersatzpraedikat;
- invalides/fehlendes `exported_at` nicht rendern;
- Datei-Shapefehler mit VM/Interface/Disk/Paket-Position anzeigen;
- Namensprobleme weiter direkt am Namensfeld;
- kein neuer Modal, kein JavaScript-Fallbacktext, keine neue POST-Aktion;
- Hidden `MAX_FILE_SIZE` vor dem File-Input aus der PHP-Konstante;
- bestehende Busy-Button-Invariante (Action als Hidden Input, genau ein Submit je Formular) erhalten.

## 5. Sprachkatalog, Hilfe und Benutzerfuehrung

### 5.1 DE/EN-Kataloge

Neue/geaenderte Keys in beiden Locales mit identischen Platzhaltern:

- `missions.import_err_partial_upload`;
- `missions.import_err_upload_infrastructure` bzw. `missions.import_err_unexpected` mit `:reference`;
- `missions.import_err_structure` fuer nicht analysierbare Top-Level-Form;
- optional ein neutraler Text fuer korrupten Sessionzustand, falls `import_err_gone` fachlich nicht reicht;
- `validate.mission_import_list_required` (`:field`);
- `validate.mission_import_entry_invalid` (`:field`, `:position`);
- `validate.mission_import_scalar_required` (`:field`);
- `validate.mission_import_package_name_required` (`:position`);
- erforderliche lokalisierte Labels fuer Interface-/Disk-/Paketpositionen.

Bestehende Keys nicht duplizieren. Vor dem Anlegen per `rg` pruefen, ob `validate.required`, `validate.*entry_invalid`, Upload- oder Referenztexte wiederverwendbar sind. Reale deutsche Umlaute, keine Em-Dashes in Portalprosa.

### 5.2 `lang/{de,en}/help_missions.php`

Die Transferhilfe sagt explizit:

- Zielnamensprobleme werden im Feld behoben und deaktivieren Bestaetigen nicht;
- nur Befunde in der Datei deaktivieren den Button;
- fremde Felder und MAC werden nie uebernommen;
- strukturell kaputte bekannte Felder werden mit Position gemeldet;
- fehlende Pakete warnen/werden uebersprungen, kaputte Paketreferenzen blockieren;
- ein neuer Upload ersetzt die eine aktive Vorschau der Sitzung;
- Abbrechen verlaesst die Ansicht, loescht den Handoff aber nicht sofort; Browser-Zurueck kann ihn innerhalb der TTL erneut zeigen.

Keine internen PHP-Begriffe oder Ini-Grenzen in die Bedienhilfe schreiben.

## 6. Betriebsdoku, ADR und dauerhafte Driftabwehr

### 6.1 ADR-0021

Den Mission-Transfer-Abschnitt praezisieren:

- gemeinsame kanonische Dokumentanalyse vor Report/Write;
- MAC/Fremdfelder ausgeschlossen, falsche Typen bekannter Felder blockierend;
- `blocked` gegen `blocked_in_file`;
- ein aktiver Handoff pro Session, Mismatch loescht den anderen nicht;
- Abbrechen-Verhalten und tatsaechliche TTL-/Session-GC-Semantik;
- Upload-Layer: App-Limit, PHP-Puffer, Infrastruktur-Cutoff;
- Erfolgsaudit versus erwartbare Fehler versus unerwartetes Serverlog.

Keine neue ADR anlegen: Dies ist eine Korrektur/Praezisierung des akzeptierten ADR-0021-Vertrags, keine neue Architekturentscheidung.

### 6.2 `docs/operations/backup.md`

Die Troubleshooting-Tabelle korrigieren:

- Zeile "Bestaetigen ist grau" nennt nur `blocked_in_file`-Ursachen, nicht Zielnamen;
- "keine Vorschau mehr" nennt nicht mehr Abbrechen als Ursache;
- eigene Zeilen fuer partiellen Upload, App-Groessenlimit, Infrastruktur-Cutoff, kaputte Listen-/Eintragsform, alter Token bei parallel neuem Upload und unerwartete Referenzmeldung;
- Diagnoseweg bei Referenz: PHP-/Serverfehlerlog, nicht Deploy-Joblog;
- keine Anweisung, JSON-Inhalte oder Sessiontoken in Tickets/Logs zu kopieren.

### 6.3 Weitere Doku

- `docs/QA.md`: gezielte Testbefehle, Runtime-`ini_get`-Nachweis und E2E-Spec ergaenzen.
- `docs/security/asvs-wstg-matrix.md`: Uploadvalidierung um Shape-, MAX_FILE_SIZE-, Token-Isolation- und Safe-Error-Nachweise erweitern.
- `docs/CHANGELOG.md`: ein kompakter Eintrag mit Ursache/Wirkung, ohne Testanzahlen festzuschreiben.
- `GROK.md`, `AGENTS.md` und `.claude/rules/webapi.md` nur aendern, wenn bei der Umsetzung eine wirklich allgemeine neue Regel entsteht. Featuredetails bleiben in ADR, Modul und Tests; keine dreifache Prosa-SSoT erzeugen.
- Den alten externen Plan nicht nachtraeglich zu einer zweiten Wahrheit umschreiben. Dieser neue Repo-Plan dokumentiert dessen Ablösung.

### 6.4 Neuer Upload-Grenzguard

Neuer Static-Test `Docker/WebAPI/tests/Static/MissionImportUploadLimitContractTest.php`:

1. liest `VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES` aus dem geladenen Code;
2. parst `upload_max_filesize`/`post_max_size` aus `Docker/php/conf.d/zz-virtusphere.ini` inklusive `K/M/G`-Suffix;
3. parst `client_max_body_size` aus `Docker/nginx/default.conf` und der generierten Vorlage in `lib/https_config.php`;
4. beweist `app < upload < post < nginx` und Gleichheit der beiden nginx-Quellen;
5. pinnt den `MAX_FILE_SIZE`-Hidden-Input vor dem File-Input und dessen Wert an die Konstante;
6. besitzt klare Zero-Match-Fehler; keine leere gruen laufende Suche.

Falls diese Beziehungen besser in `scripts/check-bounds-sync.php` passen, dort nur dann erweitern, wenn der Guard-Harness fuer positive, negative und Zero-Match-Faelle im selben Commit ergaenzt wird. Der eng begrenzte Static-Test ist fuer diesen Featurevertrag voraussichtlich die kleinere, sicherere Loesung.

## 7. Tests

### 7.1 Neue Unit-Tests fuer Dokumentanalyse

Neue Datei, damit `MissionTransferRoundTripTest.php` unter dem Dateibudget bleibt. Mindestens:

1. gueltiger Export bleibt zaehler- und wertgleich;
2. zusaetzliche gueltige/ungueltige MAC wird ignoriert und nie blockierend;
3. `interfaces: "oops"` ergibt 0 kanonische Interfaces plus positionierten Blocker;
4. `disks: "oops"` ergibt 0 kanonische Disks plus Blocker;
5. `packages: "oops"` bzw. leerer/nicht-skalarer Paketname blockiert;
6. JSON-Objekt statt Liste wird nicht als Liste akzeptiert;
7. skalarer VM-Eintrag wird als `VM #n` gemeldet, ohne Throwable/Array-Warnung;
8. nicht-skalares bekanntes Missions-/VM-/Interface-/Diskfeld wird gemeldet, nicht zu `"Array"` gecastet;
9. unbekannte Felder bleiben wirkungslos;
10. ungueltiges `exported_at` blockiert nicht und wird zu leer normalisiert;
11. alle Shape-Meldungen sind in DE/EN vorhanden und Platzhaltergleich.

Tests aktivieren den Projekt-Errorhandler, damit jede verbleibende "Array to string conversion" rot wird.

### 7.2 Integrationstests gegen MySQL

Neue Datei oder fachlich kleiner Ausbau bestehender Tests:

- Dry-Run und Real-Write verwenden dieselbe kanonische Interfaceprojektion; invalides Fremd-`mac` blockiert nicht und wird nicht gespeichert.
- Ein unblocked Report schreibt exakt seine Counts fuer Interfaces/Disks/VMs; keine Vorschau-Write-Differenz.
- Strukturfehler setzen `blocked` und `blocked_in_file`; Non-Dry-Run schreibt keinerlei Mission/VM (Transaction-/Vorabnachweis).
- Zwei case-verschiedene VM-Duplikate plus ein globaler Konflikt ergeben eine Duplikatmeldung und nur einen globalen Konfliktlink.
- Name-/VM-Konflikt, der erst nach der Preview entsteht, wird beim Confirm/Non-Dry-Run erneut abgelehnt.
- Erfolgreicher Import schreibt genau eine passende `missions`-Auditzeile; jeder erwartbare Fehlfall schreibt keine Import-Auditzeile.

Jeder Test raeumt nur seine Prefix-Zeilen auf und bewahrt Setup-Ausfallursachen wie die aktuellen Integrationstests.

### 7.3 Unit-Tests fuer Handoff und Uploadcodes

Tabellarisch:

- valid bei Alter 0 und exakt TTL;
- expired bei TTL+1;
- missing, invalid und mismatch getrennt;
- Mismatch mutiert den Zustand nicht (die Pure Function mutiert grundsaetzlich nichts);
- leerer Requesttoken kann nie matchen;
- alle PHP-Uploadcodes inklusive unbekanntem Integer;
- Infrastrukturdiagnose enthaelt Scope/Referenz/Code bzw. Klasse, aber Test-Sentinel fuer Token/Payload/Dateiname nie.

### 7.4 Static-Vertrag

`MissionImportPreviewErrorContractTest.php` erweitern oder einen fokussierten zweiten Test anlegen:

- GET und Confirm verwenden dieselbe Handoff-Funktion;
- Mismatch-Zweig enthaelt kein `unset($_SESSION['mission_import'])`;
- unerwartete `catch (Throwable)`-Zweige rufen Diagnose + generische Referenzmeldung auf;
- erwartete Shape-/Validation-Zweige rendern lokalisiert und loggen nicht;
- `suggested_name` wird vor dem Fehlerredirect aktualisiert;
- Button liest nur `report['blocked_in_file']`;
- kein neuer `import_cancel`-POST, kein `formnovalidate`-Phantomtest.

Der Branch-Test darf nicht nur eine Mindestzahl von `flash_set()` zaehlen: jeder terminale Handoff-Status wird einzeln an einen sichtbaren Ausgang gepinnt, damit ein neuer stiller Zweig nicht hinter drei alten Flashs gruen bleibt.

### 7.5 E2E `tests/e2e/specs/mission-transfer.spec.js`

Die bestehende Spec gezielt erweitern, ohne fixe globale Testanzahl:

1. Vorlage exportieren, JSON wieder hochladen: Preview sichtbar, Praefixfehler am Feld, Confirm nicht disabled; neuen Namen eingeben und erfolgreich importieren.
2. Datei mit `VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES + 1` Bytes: lokalisierte Zu-gross-Meldung. Zusaetzlicher Fall oberhalb `upload_max_filesize`, aber unter `post_max_size`, beweist `UPLOAD_ERR_INI_SIZE`-Mapping.
3. Datei mit scalar `interfaces`/`disks`: Preview zeigt positionsbezogenen Blocker, Counts sind kanonisch, Confirm disabled.
4. Zwei Seiten im selben Browserkontext: Upload A, dann B, alten A-Link oeffnen, danach ist B weiter gueltig.
5. Abbrechen und Browser-Zurueck innerhalb TTL zeigt denselben Handoff wieder; damit bleibt die bewusst beibehaltene Semantik dokumentiert und getestet.
6. Nicht-JSON- und normale Roundtrip-Faelle bleiben gruen.

Die Spec liest Grenzwerte ueber den vorhandenen PHP-Testhelper statt `2 MB` hart zu codieren.

## 8. Edge-Case-Abnahmematrix

| Fall | Erwartung |
|---|---|
| leerer/Whitespace/zu langer/Zielname mit `_` | Preview bleibt, Feldfehler, `blocked=true`, `blocked_in_file=false`, Confirm aktiv |
| Zielname bereits vorhanden | wie oben; nach neuem Namen erneut pruefbar |
| Name beim Confirm erneut falsch | Handoff und gerade eingegebener Name bleiben erhalten |
| falsche Version/nicht JSON/Depth-Fehler | lokalisierter Uploadfehler, kein Handoff, kein Audit |
| `mission` oder `vms` falsche Top-Level-Form | lokalisierter Strukturfehler, kein rohes Englisch |
| VM-/Unterlisten-/Eintragsform kaputt | renderbare Preview, positionsbezogener Blocker, kein Cast-Warning |
| zusaetzliches `mac`, `id`, Runtimefeld | ignoriert, nie geschrieben, nie allein blockierend |
| bekanntes Feld als Array/Objekt | Datei-Befund; nie String `Array` |
| ungueltiges `exported_at` | keine Timestamp-Zeile, kein Blocker |
| zwei VLAN-Schreibweisen | ein Befund mit erster Schreibweise |
| doppelte VM-Namen plus globaler Konflikt | Duplikatpositionen, ein Konfliktlink |
| korrektes fehlendes Paket | Warnung, Import moeglich, Paket uebersprungen |
| kaputte Paketreferenz | positionsbezogener Blocker |
| Tokenalter exakt TTL / TTL+1 | gueltig / abgelaufen |
| alter Token A nach neuem Upload B | A meldet gone, B bleibt erhalten |
| Confirm A nach Upload B | B bleibt erhalten; A schreibt nichts |
| Abbrechen + Zurueck innerhalb TTL | Preview wieder sichtbar |
| Erfolg + Zurueck | gone, keine falsche Ablaufbehauptung |
| `NO_FILE`, `FORM_SIZE`, `INI_SIZE`, `PARTIAL`, Infrastrukturcodes | jeweils korrekte lokalisierte Klasse; nur Infrastruktur mit Referenzlog |
| DB-Ausfall im Preview/Confirm | keine rohe DB-Prosa; Referenz + genau eine sichere Serverdiagnose; kein Importaudit |
| Konflikt entsteht zwischen Preview/Confirm | serverseitig erneut blockiert, keine Teilmission |
| kuenftige 14A-Netzwerkregel | kanonische Gesamtliste erreicht deren einen Owner in Preview und Write |

## 9. Verifikation und Ausfuehrungsreihenfolge

1. Pure Dokument-/Handoff-/Uploadtests zuerst schreiben und mit den reproduzierten roten Kanten starten.
2. Dokumentanalyse und Portalhelper implementieren.
3. Importer/Portal/Renderer auf die neuen SSoTs umstellen.
4. Integration-, Static- und E2E-Vertraege ergaenzen.
5. DE/EN, Help, ADR, Runbook, QA, Security-Matrix, Changelog synchronisieren.
6. `php -l` auf jeder geaenderten PHP-Datei; keine unvollstaendige Dreierliste.
7. Gezielte PHPUnit-Dateien und `php scripts/lang-audit.php --ci` beziehungsweise kanonischer Gate ausfuehren.
8. Die drei auf `Docker/php/Dockerfile` basierenden Services (`php`, `deploy-worker`, `maintenance-worker`) neu bauen/recreaten, weil `zz-virtusphere.ini` per Dockerfile kopiert und nicht bind-gemountet wird. Danach im **neuen** PHP-Container `ini_get('upload_max_filesize')` und `ini_get('post_max_size')` gegen den Repo-Stand pruefen. Nur ein `docker compose restart` reicht nicht, wenn das Image noch alt ist.
9. Gezielte Playwright-Spec ausfuehren.
10. Kanonische Fast-Lane vollstaendig; wegen DB-/Browser-/Runtimeaenderungen danach die relevante Integration-Lane inklusive E2E und `--fail-on-skipped`.
11. `git diff --check`, `git status --short`, geaenderte Dateien gegen Eigentum pruefen. Fremden Masterplan-Hunk nicht stagen.

Lange Suiten nur ueber einen live pollbaren Logpfad starten und die echten `[n/total]`-Zeilen mindestens minuetlich berichten, gemaess Repositoryvertrag.

## 10. Erwartete Dateiliste

Voraussichtlich neu:

- `Docker/WebAPI/lib/mission_transfer_document.php`
- `Docker/WebAPI/lib/mission_import_portal.php`
- `Docker/WebAPI/tests/Unit/MissionTransferDocumentTest.php`
- `Docker/WebAPI/tests/Unit/MissionImportPortalTest.php`
- `Docker/WebAPI/tests/Static/MissionImportUploadLimitContractTest.php`
- optional fokussierte neue Integrationstestdatei statt Wachstum des Roundtrip-Tests

Voraussichtlich geaendert:

- `Docker/WebAPI/lib/mission_transfer.php`
- `Docker/WebAPI/lib/mission_transfer_import.php`
- `Docker/WebAPI/lib/missions_import_panel.php`
- `Docker/WebAPI/portal/missions.php`
- `Docker/WebAPI/tests/Static/MissionImportPreviewErrorContractTest.php`
- `Docker/WebAPI/tests/Integration/MissionTransferRoundTripTest.php` nur wenn unter Budget; sonst neue Datei
- `tests/e2e/specs/mission-transfer.spec.js`
- `Docker/WebAPI/lang/{de,en}/missions.php`
- `Docker/WebAPI/lang/{de,en}/validate.php`
- `Docker/WebAPI/lang/{de,en}/help_missions.php`
- `Docker/php/conf.d/zz-virtusphere.ini` nur falls die Guardentscheidung eine Zahlenkorrektur verlangt
- `docs/adr/ADR-0021-backup-status-channel-and-mission-transfer.md`
- `docs/operations/backup.md`
- `docs/QA.md`
- `docs/security/asvs-wstg-matrix.md`
- `docs/CHANGELOG.md`

Explizit nicht erwartet:

- Migration/`struktur.sql`;
- Machine-API-Dateien und PowerShell-MECM;
- Deploy-/Maintenance-Worker und Joblogs;
- neue Berechtigung, Auditkategorie oder POST-Aktion;
- CSS/Modal/JavaScript-Aenderung;
- Aenderung des fremden Deploy-Masterplans.

Jede Abweichung von dieser Liste wird vor der Aenderung fachlich begruendet und gegen die oben stehenden Zielvertraege geprueft.

## 11. Definition of Done

- Alle Matrixfaelle besitzen einen automatisierten Nachweis oder einen begruendeten, dokumentierten manuellen Runtime-Nachweis.
- Kein `(array)`-Cast im Importanalyse-/Writepfad kann eine kaputte Containerform in eine Einheit verwandeln.
- Kein rohes Importfeld wird anders validiert als es geschrieben wird.
- Keine Importfehlermeldung rendert rohe PHP-/DB-Prosa.
- Alter Token loescht neuen Handoff nicht; Confirm behaelt den eingegebenen Namen.
- Help/Runbook/ADR stimmen mit Abbrechen, TTL, Button und Logging ueberein.
- Uploadgrenzguard ist positiv, negativ und gegen Zero-Match wirksam.
- Aktives Container-Ini entspricht dem neu gebauten Repo-Image.
- Erfolg erzeugt genau einen Mission-Audit; erwartbare Fehler keinen; unerwartete gefangene Fehler genau eine sichere Serverdiagnose.
- Fast- und relevante Integration-Lane gruen ohne Skips; E2E beweist den Vorlagen-Reimport.
- Arbeitsbaum enthaelt nur eigene Hunks; fremde Aenderungen bleiben unangetastet.
