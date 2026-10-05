# Arbeitsauftrag für Codex: Befunde aus S7 und S8 umsetzen (04.10.2026)

## Ziel

Die belegten und entschiedenen Befunde aus dem [Ablaufprüfplan](2026-09-28-deploy-flows-review-plan.md) (S7) und der [Lückensuche](2026-10-03-gap-matrices.md) (S8) im Code, in Doku, Hilfe und Diagrammen beheben, Paket für Paket in der Reihenfolge unten. Jedes Paket endet geprüft, committet und im [Register](2026-09-12-consolidated-session-backlog.md) nachgewiesen.

Diese Datei legt nur fest, **was** in welcher Reihenfolge geschieht und wann ein Paket fertig ist. **Wie** gearbeitet wird, regeln `AGENTS.md` und die dort gerouteten Verträge; sie werden hier nicht wiederholt. Befunde, Entscheidungen und Belege stehen in S7, S8 und im Register; hier nur ihre IDs.

## Rahmen

- **Modell:** GPT-6.1 Sol in der Rolle Sol, Effort High. Das Register nennt im Abschnitt „Modell- und Ausführungsvertrag“ noch `gpt-5.6-sol`; für diesen Auftrag gilt GPT-6.1 Sol als Sol. Rollen Terra und Astra führt dieser Lauf nicht selbst aus. Die Astra-Prüfung (Stoppregel 5) übernimmt Claude Opus als vom Nutzer freigegebener Ersatz (05.10.2026); ihr Ergebnis liegt als `astra-result.md` im Artefaktordner des Pakets.
- **Vorrang bei Widerspruch:** `AGENTS.md` und die dort gerouteten Verträge gehen vor; danach dieser Auftrag; danach die Pläne. Widerspricht eine Entscheidung in S7, S8 oder im Register diesem Auftrag, gilt die Stoppregel 3.
- **Quellstand:** Die Befunde beziehen sich auf `3b37025`. Seitdem hat sich am Code nur das geändert, was `git log 3b37025..origin/main` zeigt. Vor jedem Paket den Befund am aktuellen Code nachmessen.
- **Git:** Trunk-basiert. Vor jedem Paket `git fetch` und auf dem aktuellen `origin/main` aufsetzen; parallel arbeitende Agenten können `main` inzwischen weitergeschrieben haben. Nur die eigenen Dateien stagen, nie den ganzen Baum. Commit und Push auf `main` sind je Paket freigegeben, sobald seine Abnahme erfüllt ist.
- **Umgebung:** Gates laufen über `scripts/check.ps1` aus Windows PowerShell 5.1 oder PowerShell 7 mit Docker. Bekannte Störungen: Meldet ein Gate „Projekt-Image virtusphere-php:8.4-tooling fehlt“, obwohl `docker image ls` es zeigt, das Image per ID neu taggen (`docker tag <id> virtusphere-php:8.4-tooling`) und das Gate wiederholen. Ein frischer Worktree braucht die LF-Konfiguration, eine `.env` und `composer install`, sonst schlagen Gates ohne Codeursache fehl.

## Pakete in dieser Reihenfolge

Spalte „Prüfung“: **Sol** heißt getrennter, begrenzter Selbstreview vor der QA. **Astra** heißt: Nach Umsetzung und Selbstreview, vor der abschließenden QA, gilt Stoppregel 5 (Register, „Modell- und Ausführungsvertrag“: neue kritische Verträge prüft Astra vor der Abnahme).

| Nr. | Paket | Befunde und Entscheidungen | Kern der Änderung | Rot vor Fix (mindestens) | Prüfung | Stand |
|---|---|---|---|---|---|---|
| K1 | Host-Schlüssel des Ubuntu-Hosts anheften | AB-01; AB-E1, AB-E1a; S7 „Paketskizze AB-01“ | Ein Verbindungshelfer prüft den Host-Schlüssel vor jedem `login()` für alle SSH- und SFTP-Wege; Fingerprint je Ansible-Zugang mit Bestätigung; vorläufiges Anheften bei der ersten Verbindung nach dem Update; Abweichung bricht vor der Anmeldung mit geschlossener Ursache ab. Doku laut Skizze, `trust-flows.md` mit den lokalen Regeln DS-02 bis DS-07 und DS-09 anlegen, falls es fehlt; DS-01 und der globale DS-08-Wächter bleiben bei AB-02/FC4 laut Astra-Ergebnis. | Fremder Schlüssel führt zu keinem `login()`; vorläufig angeheftet, danach abweichend: gesperrt; Bestätigung hebt „bitte bestätigen“ auf | Astra | fertig, Commit „Pin the Ansible host key before every SSH and SFTP login“; lokal geprüft, extern offen (LP-09); visuelle Abnahme offen, kein K1-Befund (Abweichung des Nutzers, siehe Register) |
| K2 | Host-Preflight vor `deploying`, IP-Freigabe sperrt MAC-Modi | DF-L8 nach (a), MR-02; Paket DF-P5 | Host-Preflight samt Freigabeprobe läuft vor `deploy_worker_mark_vms_deploying()`. Scheitert der Auftrag vor dieser Markierung, ändert der Fehlerweg keinen VM-Zustand; heute konvergiert `deploy_worker_handle_failure()` auch dann alle VMs des Umfangs. Bei abgewiesener Freigabe enden Modi mit MAC-Ergebnis als `failed` mit Abschlussgrund `configuration_blocked`, ohne Upload und ohne VM-Zustandsänderung; die Meldung verlinkt die Freigabeliste in den Einstellungen. Kommentar „The portal/allowlist probes gate exactly the modes …“ korrigieren, Worker-Diagramm nachziehen. | Abgewiesene Freigabe im Modus `full`: kein Upload, VM-Zustände unverändert, Abschlussgrund `configuration_blocked`. Fehlende Komponente im Host-Preflight: VM-Zustände unverändert (heute alle `failed`) | Astra | fertig, Commit „Run the host preflight before marking VMs and block MAC modes on a denied allowlist“; Entscheidung (b), K2-R1 im Register |
| K3 | Export ändert registrierte VMs nicht | WM-01; WM-E1 | `db_importMAC.php`: Bei VMs mit gespeicherter ResourceID Zustand nicht anfassen, nur die MAC vergleichen. Gleiche MAC: unverändert im Auftragsergebnis. Andere MAC: VM fehlgeschlagen mit dem Hinweis aus WM-E1 und Link zur VM. VMs ohne ResourceID wie bisher. Worker-Markierung und Abschluss für registrierte/installierte VMs anpassen: Ein unveränderter Export bewahrt Lebenszyklus, Pickup-Merker und Pending-Zeitstempel über Worker, Callback und Abschluss. Der allgemeine Fehlervertrag FC2-E1 bleibt ausgeschlossen. Hilfe zum Export und `deploy-chain.md` nachziehen. | Erneuter Export registrierter/installierter VMs über Worker, Callback und Abschluss: Lebenszyklus, Pickup-Merker und Pending-Zeitstempel unverändert, keine erneute Verarbeitung im Devices Sync; geänderte MAC: VM fehlgeschlagen mit Hinweis | Astra | fertig, Commit „Keep MECM-bound VMs unchanged on a MAC export“; Entscheidung (b) mit Hinweis über das Auftragslog (Option 2), P2-1/P2-2 dokumentiert; visuelle Abnahme offen, kein K3-Befund; FC2-E1, K2-R1, WM-02, LP-01 im Register |
| K4 | Client-ACK setzt nur den Lebenszyklus | VT-02; VT-E1 Variante C; FM-09; S8 „Paketskizze C“ | Laut Paketskizze C: MECM-Zustand im ACK unverändert lassen, Wiederholungsprüfung ohne MECM-Zustand, Überwachung von `pending` unabhängig vom Lebenszyklus, Nachtrag in ADR-0019. | Die beiden Fälle aus Paketskizze C; Wire-Test für den ACK mit veralteter Rollout-Revision (409, FM-09) | Sol | fertig, Commit „Write only the lifecycle on the client ACK and watch MECM pending at any lifecycle“; visuelle Abnahme offen, kein K4-Befund |
| K5 | „Identität übernehmen“ zurückbauen | WM-E2a, WM-03, DF-L5 (Übernahme-Teil), DF-D1; S8 „Rückbau-Skizze zu WM-E2a“ | Laut Rückbau-Skizze. Danach WM-07 neu bewerten und das Ergebnis im Register festhalten. | Identitätssperre bietet keine Übernahme mehr, sondern den Hinweis mit Links; ein POST `adopt_vm` wird abgewiesen; alte Audit-Einträge der Übernahme bleiben lesbar | Sol | offen |
| K6 | FC3: Diagramme und Doku | Register-Reihenfolge FC3: Flowchart-Abgleich aus S6 für `mecm-scheduled-tasks.md`, doku-seitige Teile von D-01 bis D-09, DF-P0 (DF-D2 bis DF-D7, DF-D9 mit dem Wortlaut aus MR-01, DF-S1, DF-S2); dazu FC2-10, FC2-15 (Kommentare), FC2-16 und FC2-17 (Diagrammteil), DF-D4, VT-07, KM-02, VT-06 (nur Runbook) | Doku, Kommentare und Diagramme an den Code angleichen; zum Schluss die Ablaufseite mit `scripts/build-flow-viewer.ps1` neu erzeugen. Veröffentlichen tut sie der Nutzer. | entfällt bei reiner Doku; die Wächter `DeployFlowsDocContractTest`, `SystemStatusChecksDocContractTest` und `MecmScheduledTasksDocContractTest` bleiben grün | Sol | offen |
| K7 | Meldungen mit Link (R11) | R11-01 bis R11-09, FC2-06, R11-Teil von FC2-03, Link-Teil von ZB-06, Link-Teil von FM-05 | Der Übersetzungsweg `portal_error_message()` bekommt je Schlüssel eine Aktion; Hinweise mit den vorhandenen Helfern verlinken. Wächter nach dem Muster von PI-01: Meldungen mit Seitennamen nur mit Aktion, Link oder begründeter Ausnahme. | Je Meldung: Link wird gerendert, mit der Berechtigung des Ziels; Wächter mit Negativfall | Sol | offen |
| K8 | DF-P1: veralteter Site-Befund | DF-L4; DF-E4, DF-E5 | laut S7 | laut S7 | Sol | offen |
| K9 | DF-P2: Alter der ESXi-Nachweise | DF-L3 (= ZB-06), DF-E3, DF-L5 (Sperre beim Einreihen), DF-L6 (= FC2-03), DF-L7, DF-D8 | laut S7 | laut S7 | Sol | offen |
| K10 | DF-P3: freie Lizenz sperrt schreibende Modi | DF-L2; DF-E2 | laut S7, erst nach K9 | laut S7 | Sol | offen |
| K11 | DF-P4: Create-Vertrag | DF-L1 (Kern von FC2-15), DF-E1, DF-D3, DF-L9, FC2-02 | laut S7; Create-Marker-Vertrag und seine Vertragstests gemeinsam ändern | laut S7; für FC2-02: abgelehnter Commit behält MOID und UUID in `error_detail` und im Protokoll | Astra | offen |
| K12 | Worker-Ausgabe und Belege | FC2-05, FC2-16 (Endgrund mit Zahl geschriebener VMs), FC2-08, FC2-17 (ein Budget für beide Preflights), ZB-03, ZB-05 | laut S7 und S8 | je Befund ein Fall; ZB-03 im Vertragstest des Upload-Skripts | Sol | offen |
| K13 | Status und Bedienung | FC2-07, FM-07, FM-08, KM-03 (Veraltung max. aus sieben Tagen und 2 × Intervall), KM-04, VT-03 | laut S7 und S8 | je Befund ein Fall | Sol | offen |
| K14 | Konsistenz und Wächter | WM-04, WM-05, WM-06, FC2-12, FC2-13, FC2-18 (Logik: Deploy-Warnungen, Missionswarnung und Missionsbadge nach `esxi_inventory_deviation_report()`) | laut S7 und S8 | je Befund ein Fall; Wächter mit Negativfall | Sol | offen |

**Ergänzung 05.10.2026** aus den Entscheidungen vom 05.10.2026 (Register, Abschnitt „Entscheidungen 05.10.2026: offene Entscheide FC2, AB, VT, FM“, dazu die Freigaben VT-01, VT-04 und VT-06 im selben Abschnitt). Pakete, deren Werte erst die Laborproben liefern (FC2-E2/LP-04, FC2-E3/LP-03, FC2-E6/LP-05), sind bewusst nicht dabei.

| Nr. | Paket | Befunde und Entscheidungen | Kern der Änderung | Rot vor Fix (mindestens) | Prüfung | Stand |
|---|---|---|---|---|---|---|
| K15 | Gescheiterte Aufträge färben nur angefasste VMs | FC2-01, WM-02, K2-R1, FC2-E1; offener K3-Rest „gebundene, nie markierte VM bei Fehler vor dem Rückruf“ | Vorzustand (Lifecycle und MECM-Zustand) je Auftrag und VM dauerhaft speichern (Migration, Schema-Doku). `start` und `autostart` schreiben nie Lifecycle oder MECM-Zustand. Scheitert ein Modus, bevor ein Playbook ESXi berührt hat, gilt wieder der gespeicherte Vorzustand. `failed/failed` nur für VMs, die ein Playbook dieses Auftrags angefasst hat; „angefasst“ im Paket präzisieren und im Register festhalten. Gleiche Regel für Abbruch, Reaper und Konvergenz-Sweep. Selektiver Abschluss aus K3 (`successful_vm_ids`) bleibt. Keine Reparatur von Altschäden (eigener Auftrag nach LP-01). | Je Modus und Fehlerstelle (vor Markierung, nach Markierung vor dem ersten Playbook, nach dem Rückruf), dazu Abbruch, Reaper nach Workerausfall und Sweep: unberührte VMs behalten Lifecycle und MECM-Zustand; registrierte VM bleibt `registered` | Astra | offen |
| K16 | Nie angelegte VMs mit Hinweis ausnehmen | FM-02, FM-E2 | Start, Export, Power-Cycle und Autostart nehmen VMs ohne gebundene Instance-UUID aus dem Lauf, statt am Playbook zu scheitern; auch bei leerer Auswahl und bei geplanten Aufträgen. Ergebnis und Protokoll melden „N VMs nicht angelegt, übersprungen“ mit Link auf Create (Berechtigung des Ziels). Übersprungene VMs behalten ihren Zustand (K15). Erst nach K15. | Neue, nie angelegte VM in ausgerollter Mission, Modus `start` mit leerer Auswahl: übrige VMs laufen, Hinweis mit Link, die neue VM bleibt unverändert | Sol | offen |
| K17 | Warnung bei Create auf anderem Host | FM-01, FM-E1 | Keine Sperre und kein Umzugsablauf. Die Bindung merkt sich zusätzlich, über welchen ESXi-Zugang und Host die VM angelegt wurde. Create warnt vor dem Neuanlegen, wenn gebundene VMs auf dem gewählten Zugang oder Host fehlen würden; die Warnung nennt den bisherigen Host und erklärt, dass die alten VMs und MECM-Geräte weiterlaufen. Im Paket festlegen, ob die Warnung beim Einreihen (aus der gespeicherten Bindung) oder als Bestätigung vor dem Start kommt; sie muss vor jedem Neuanlegen sichtbar sein. | Create für eine gebundene VM mit einem zweiten Zugang: Warnung erscheint vor dem Neuanlegen; Create mit dem bisherigen Zugang: keine Warnung | Sol | offen |
| K18 | Verspätete geplante Aufträge kennzeichnen | FM-04, FM-E3 | Keine Verfallszeit. Liste und Protokoll zeigen den tatsächlichen Start und „startete N Minuten nach Plan“, ab einer im Paket festgelegten Schwelle. | Geplanter Auftrag, der nach der Schwelle übernommen wird: Hinweis in Liste und Protokoll; pünktlicher Auftrag: kein Hinweis | Sol | offen |
| K19 | Backups asymmetrisch verschlüsseln | VT-05, VT-E2 | Öffentlicher Schlüssel (Zertifikat) in den Einstellungen; der private Schlüssel liegt nie auf dem App-Host. `Docker/backups/backup.sh` verschlüsselt Config-Archiv und Dump mit `openssl` und legt sie getrennt ab. Ohne hinterlegten Schlüssel meldet der Systemstatus „Backups unverschlüsselt“. Restore-Test und `scripts/restore_test.sh` mit Testschlüssel; `docs/operations/backup.md` (Schlüssel erzeugen, aufbewahren, Restore) und ADR-0017 nachziehen. | Backup mit hinterlegtem Schlüssel enthält keinen lesbaren APP_KEY und kein lesbares Dump; Restore mit privatem Testschlüssel gelingt; ohne Schlüssel Statushinweis | Astra | offen |
| K20 | IP-Freigabe je Rolle | VT-01 (Freigabe 05.10.2026) | Die Freigabeliste der Maschinen-API bekommt eine Rolle je Eintrag (MECM-Server, Ansible-Host, Client); jeder Endpunkt erlaubt nur seine Rollen. Bestehende Einträge ohne Rolle gelten bis zur Zuordnung wie bisher für alle Endpunkte, der Systemstatus weist darauf hin. Migration, Einstellungsseite, Hilfe DE/EN, `machine.md`. | Ansible-Host-IP ruft `updateDevice` auf: abgewiesen mit geschlossener Ursache; MAC-Rückruf derselben IP: angenommen; Eintrag ohne Rolle: wie bisher plus Hinweis | Astra | offen |
| K21 | ESXi-Passwort nie auf dem Ubuntu-Host zurücklassen | VT-04 (Freigabe 05.10.2026) | `accounts.yml` am Auftragsende immer löschen, auch bei ungeklärter Create-Einheit und gescheitertem Aufräumen (dann beim nächsten Kontakt nachholen und protokollieren). Nur der Async-Status bleibt als Beleg; eine spätere Statusabfrage lädt die Zugangsdatei neu hoch und löscht sie danach. Create-Vertrag (ADR-0041) und seine Vertragstests gemeinsam ändern. Laborprobe LP-06 bleibt beim Nutzer. | Ungeklärte Create-Einheit am Auftragsende: Zugangsdatei gelöscht, Statusabfrage danach gelingt mit neu hochgeladener Datei; Vertragstest des Upload-/Aufräumskripts | Astra | offen |
| K22 | APP_KEY wechseln | VT-06 (Freigabe 05.10.2026: Anleitung und Werkzeug) | CLI-Werkzeug, das alle mit dem APP_KEY verschlüsselten Zugangsdaten mit einem neuen Schlüssel neu verschlüsselt, in einer Transaktion, mit Trockenlauf und Abbruch ohne Teilzustand. Runbook „APP_KEY wechseln“ (in K6 angelegt) um das Werkzeug ergänzen; Backup vor dem Wechsel verlangen. | Neuverschlüsselung: alle Zugangsdaten danach mit dem neuen Schlüssel lesbar, mit dem alten nicht; Fehler mitten im Lauf hinterlässt den alten Stand | Sol | offen |
| K23 | Ablaufdiagramme in der Portalhilfe | FC2-E5, KM-01 | Mermaid-Blöcke unter `docs/operations` bleiben die einzige Quelle. Beim Build mit dem im Repo vorhandenen Chromium zu SVG rendern, hell und dunkel, eingecheckt, in der Portalhilfe als Bild eingebunden; keine Lockerung der CSP, kein Mermaid zur Laufzeit. Wächter: Quelle und SVG passen zusammen, sonst rot. KM-01: Hinweis an der API-Basis-URL nennt alle drei Stellen der Portal-Adresse mit Link auf die Anleitung. | Wächter mit Negativfall (geänderte Quelle ohne neues SVG); Hilfeseite zeigt die Diagramme ohne CSP-Verstoß in beiden Themes | Sol | offen |
| K24 | Reste aus K3 | K3-P3-2, K3-P3-3 (Register, Abschnitt K3) | Retry ohne Befund nicht sofort anbieten, wenn er nachweislich erneut scheitert (im Paket präzisieren). Fehlende Tests nachziehen: Binder-Lauf zwischen Markierung und Rückruf sowie Fehler nach dem Rückruf und Abbruch, jeweils mit gebundener VM. Erst nach K15. | Die beiden Testfälle; Retry-Fall | Sol | offen |

Nach K24 endet dieser Auftrag. PS1 bis PS3, AV-, MECM-Client- und UX-Pakete aus dem Register gehören nicht dazu.

## K1: Nutzerentscheidungen vom 04.10.2026

Diese ausdrücklich nachgereichten Entscheidungen gelten für K1:

1. Die Obergrenze für `Docker/WebAPI/lib/migrate.php` in `scripts/check-file-size.php` auf die aktuelle Zeilenzahl senken. Der bestehende Mutant `file-size.grown` muss dadurch wieder oberhalb der Grenze liegen. Keine künstliche Vergrößerung der Produktdatei.
2. Vor dem K1-Commit ein eigenes kleines Dependency-Paket für phpseclib 3.0.57 vorbereiten: Composer-Anforderung, Lock und Vendor gemeinsam aktualisieren. SFTP-Budgeterkennung und Tests wegen der in 3.0.56 eingeführten Timeout-Ausnahme ausdrücklich prüfen. Am neuen Vendor-Stand Astra-Prüffrage 1 bestätigen: `reconnect()` meldet über `$this->login()` an, `ping()` erreicht diesen Weg und `reset_connection()` bewahrt `signature_validated`. Bei einer Abweichung stoppen.
3. A3-Zeilenimport ist optionaler QoL-Befund im Register, kein K1-Hindernis.
4. LP-09 bleibt die externe Laborprobe des Nutzers und blockiert den K1-Commit nicht. K1 erhält den Nachweisstatus „lokal geprüft, extern offen“; keine externe Verifikation behaupten.
5. Nach Punkt 1 und 2 stoppen und auf die Rückmeldung des Nutzers zu den von ihm erzeugten und geprüften Bildreferenzen warten. Danach `e2e-portal`, `visual-contract`, `guard-harness` und `composer-audit` gezielt über `scripts/check.ps1` wiederholen und K1 nach S9 abschließen. Gültige unveränderte Nachweise wiederverwenden; das Dependency-Paket vor K1 committen.

## Nicht beginnen

Diese Befunde warten auf eine Laborprobe, eine Entscheidung oder den MECM-Cutover. Sie werden in keinem Paket oben mitgenommen. Stand 05.10.2026: Die Entscheide FC2-E1 bis FC2-E6, VT-E2 und FM-E1 bis FM-E3 sind gefallen, VT-01, VT-04 und VT-06 freigegeben (Register, Abschnitt „Entscheidungen 05.10.2026“). Was davon ohne Laborprobe umsetzbar ist, steht als K15 bis K24 oben.

| Befund | Wartet auf |
|---|---|
| FC2-04 | Laborprobe LP-04 (Entscheid FC2-E2 gefallen: kürzen) |
| FC2-11 (Logik), ZB-02 | Laborprobe LP-03 (Entscheid FC2-E3 gefallen: Laufschonfrist je Aufgabe) |
| `full` wartet auf `registered` statt auf eine feste Zeit | Laborprobe LP-05 (Entscheid FC2-E6 gefallen: auf `registered` warten, Obergrenze, sonst scheitern) |
| Reparatur bereits falsch gefärbter VMs (FC2-01, WM-01) | Laborprobe LP-01, danach eigener Reparaturauftrag |
| FM-06 | Entscheid zum Rückabgleich gebundener VMs (nicht Teil von FM-E1) |
| FM-03 | Laborprobe LP-08 |
| ZB-01 | Laborprobe LP-02 |
| FM-05 (Ursache je VM), WM-E3, VT-E1 Variante B, ZB-04 | MECM-Cutover MC-R4, Laborprobe LP-13 |
| WK-01 (parallele Aufträge) | Entscheid WK-E1, später durch den Nutzer |
| PI-01 (Rest), PI-06, PI-07, PI-08 | eigener Auftrag |

## Abnahme je Paket

Ein Paket ist fertig, wenn alles hier gilt. Was „fertig“ im Übrigen heißt, regelt das Register im Abschnitt „Arbeitsweise und Definition „fertig““.

1. Jeder Befund des Pakets ist am aktuellen Code nachgemessen. Ist er dort nicht mehr vorhanden, steht das mit Beleg im Register, und der Befund entfällt.
2. Der Rot-vor-Fix-Test war vor der Änderung rot und ist danach grün. Er prüft Verhalten, nicht die Umsetzung.
3. Doku, Hilfe DE/EN und Diagramme sind im selben Commit nachgezogen; die Diagramme in `docs/operations` beschreiben danach den Code.
4. Die Gates für die berührten Bereiche sind über `scripts/check.ps1` gelaufen, ausgewählt nach `docs/QA.md`, mit ihrem tatsächlichen Ergebnis. `infrastructure_error` ist kein Pass.
5. Das Register hat einen Abschnitt zum Paket: Befund-IDs, Änderung, Quellstand, Gates mit Ergebnis, offene Punkte, nächster Schritt. In der Paketliste oben steht unter „Stand“ `fertig` und der Commit.
6. Commit und Push auf `main`.

**Ab K5 (Nutzer, 05.10.2026):** Punkt 4 und 6 gelten blockweise. Je Paket laufen Rot-vor-Fix, die schnellen Gates und bei Integrationstests `phpunit-full`, dazu ein eigener Commit. `e2e-portal`, die Prüfung im sauberen LF-Worktree und der Push laufen einmal am Ende eines Blocks; ein Block reicht bis zum nächsten Astra-Halt. Bis dahin steht ein Paket als „lokal geprüft, gemeinsame Schlussabnahme offen“. Einzelheiten im Register, Abschnitt „Entscheidung 05.10.2026: Abnahme in Blöcken ab K5“.

## Stoppregeln

1. **Nicht beginnen:** Ein Befund aus „Nicht beginnen“ oder einer, der in keinem Paket steht, wird nicht umgesetzt.
2. **Neuer Befund:** Ein Fehler außerhalb des laufenden Pakets wird im Register festgehalten, nicht behoben.
3. **Widerspruch oder fehlende Entscheidung:** Braucht ein Paket eine fachliche Entscheidung, die nirgends festgehalten ist, oder widersprechen sich zwei Vorgaben: die Frage in die Tabelle „Offene Entscheidungen des Nutzers“ im Register eintragen, das Paket anhalten und mit dem nächsten Paket weitermachen, das nicht davon abhängt.
4. **Infrastruktur:** Lässt sich ein `infrastructure_error` mit den bekannten Abhilfen unter „Rahmen“ nicht beheben, stoppen und melden.
5. **Astra-Prüfung:** Bei Paketen mit Prüfung „Astra“ nach der Umsetzung stoppen und einen Prüfauftrag melden: Paket-ID, Diff, berührte Verträge, Gegenbeispiele und offene Fragen. Weiter erst nach dem Prüfergebnis.
6. **Fremde Systeme:** Keine Aktion gegen echtes ESXi, MECM, den Ubuntu-Host oder die Produktion. Laborproben führt der Nutzer aus.

## Meldung nach jedem Paket

Kurz und in dieser Reihenfolge: Paket, behandelte Befunde, Rot-vor-Fix-Test, Gates mit Ergebnis, Commit, offene Punkte und nächstes Paket.

## Prompt zum Start

Für eine neue Codex-Aufgabe mit GPT-6.1 Sol, Effort High:

```text
Arbeite im Repository VirtuSphere-v2-WebApp. Lies AGENTS.md und danach docs/audits/2026-10-04-codex-work-order.md.
Setze die Pakete aus diesem Arbeitsauftrag in der dort angegebenen Reihenfolge um. Beginne mit dem ersten Paket, dessen Stand nicht „fertig“ ist.
Halte dich an Abnahme und Stoppregeln des Arbeitsauftrags. Melde nach jedem Paket im dort beschriebenen Format.
```
