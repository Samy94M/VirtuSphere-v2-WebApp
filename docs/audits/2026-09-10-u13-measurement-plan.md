# U13-Messplan für SC-008, SC-021 und SC-025

Vorbereitet am 10.09.2026 auf HEAD `aa3daac` plus dem uncommitteten
Gesamtarbeitsbaum. In dieser Session wurden keine Profile, Tests, Container,
Browser oder Messungen ausgeführt. Der [konsolidierte QA-Plan](2026-09-10-u13-u14-qa-plan.md)
ordnet die späteren Quality-Gates vor dieser getrennten, exklusiven
Performancephase ein. Die k6-Dateien unter `tests/load/` sind separate
Messwerkzeuge und kein zusätzlicher öffentlicher QA-Runner.

## Messgegenstand und unveränderte Verträge

Die Korrektur für SC-021 liegt in `tests/load/portal-read.js`; ihre Laufzeitabnahme ist offen. Das Profil verlangt
eine positive Zielmissions-ID sowie Missionsname, Ziel-VM-Zahl und einen
bekannten VM-Namen aus demselben Fixture. Der gemessene Request verwendet
`redirects: 0`. Nur Status 200, exakte URL, HTML-Content-Type, Authmarker,
Missionsname im Titel, exakte Tabellen- und Auswahlzeilenzahl sowie der
VM-Marker nehmen die Dauer in `target_vms_duration` auf. Login, Fehler,
Redirect und falscher Inhalt bleiben Kandidaten ohne akzeptierten VM-Sample und
brechen die Iteration ab. Der zusätzliche Existenzvertrag
`accepted_vm_samples > 0` verhindert einen grünen Lauf mit null VM-Samples,
etwa wenn jeder Login bereits vor einem Targetcheck scheitert.

Die bestehenden Schwellen bleiben erhalten: `checks > 99 %`,
`http_req_failed < 1 %`, Listen-p95 unter 800 ms, Health-p95 unter 300 ms und
null ausgelassene Monitoriterationen. Für Session-A/B und den einzelnen kalten
Zielrequest wird kein neuer Produktgrenzwert erfunden. p99 bleibt bei weniger
als 1000 erfolgreichen Endpunktsamples explorativ.

## Dauerhafte synthetische Datenprofile

`tests/load/fixtures/profile.sql` und `cleanup.sql` sind die dauerhaft
versionierten Quellen. Ein späterer, bereits zuständiger QA-Owner setzt
`@vs_profile` nach eigener S/T/L-Validierung. Die SQL-Dateien sind keine
Migrationen und werden nicht automatisch durch einen neuen Runner gestartet.
Sie dürfen ausschließlich gegen die verifizierte synthetische
`virtusphere-qa`-Datenbank laufen.

| Profil | Missionen | VMs gesamt | Ziel-VMs | NICs/Disks je Ziel-VM | Pakete je VM | Joblogzeilen |
|---|---:|---:|---:|---:|---:|---:|
| S | 1 | 10 | 10 | 1 / 1 | 3 | 100 |
| T | 10 | 202 | 40 | 2 / 2 | 3 | 1000 |
| L | 100 | 1099 | 1000 | 2 / 2 | 3 | 10000 |

Das T-Ziel bleibt am Job-Scope-Limit 40. L ist ein Leseprofil und darf nicht
als zulässiger Queue-Scope interpretiert werden. Die Seed-Ausgabe nennt Profil,
alle Relationscounts, Zielmissions-ID und -name, Zielzahl, VM-Marker und Job-ID.
Diese Ausgabe muss gegen die Tabelle geprüft werden, bevor k6 startet.

Die Fixture besitzt ausschließlich die binär verglichenen Präfixe
`loadu13_s_`, `loadu13_t_` und `loadu13_l_`. Mission-Fremdschlüssel entfernen
beim engen Cleanup die zugehörigen VMs, Interfaces, Disks, VM-Paketrelationen,
den synthetischen Job und seine Logs; Pakete entfernt ein zweites enges
Prädikat. Ein ungültiges Profil ist schreibfrei. Es gibt kein Backup, Restore
oder Cleanup fremder Daten. Vor jedem Profil werden alle drei U13-Präfixe
kontrolliert bereinigt, danach wird genau ein Profil neu erzeugt.
Seed und Cleanup umschließen ihre eigenen Zeilen jeweils mit einer Transaktion;
der ausführende Client muss bei einem SQL-Fehler abbrechen und darf ihn nicht
mit `--force` überspringen.

Jedes löschende und einfügende Statement trägt `@vs_valid` in seinem eigenen
Prädikat, einschließlich VM-Relationen, Job und Joblogs. Bei einem ungültigen
Profil bleibt die Zielmissions-ID `NULL`; die exakte Zielauflösung vergleicht
den vollständigen Namen binär. Der finale Ausgabesatz meldet `valid = 0` und
`NULL` für Counts und Zielwerte, statt gegebenenfalls bereits vorhandene
`loadu13_x_`-Zeilen als gültiges Fixture auszugeben.

Die manuelle Schemaprüfung bezog sich auf
`Docker/mysql/mysql-init/struktur.sql`: die verwendeten Spalten sind vorhanden,
Missionen kaskadieren zu VMs und Jobs, VMs zu Interfaces, Disks und
VM-Paketrelationen, Jobs zu Joblogs; Pakete bleiben eigener Cleanup-Owner. Die
Fixture wurde nicht gegen MySQL ausgeführt. Eine spätere Migration kann diese
Quellprüfung veralten lassen und blockiert dann die Messung bis zum erneuten
Abgleich.

## Gleicher vollständiger Vorher-/Nachherstand

Ein belastbarer Vergleich entsteht nicht aus historischen AP09-Werten oder
einem Checkout von HEAD. Nach Abschluss aller U13/U14-Dateiänderungen wird der
vollständige Arbeitsbaum einschließlich aller fremden Änderungen und
ungetrackten Quellen genau einmal in zwei isolierte Quellkopien übernommen.
Dateiliste und SHA-256-Manifest beider Kopien werden gesichert.

1. Die Nachherkopie bleibt dieser vollständige Snapshot.
2. In der Vorherkopie ersetzt der QA-Owner ausschließlich die im U13-Codebericht
   aufgelisteten Produktdateien durch die Originale unter
   `qa-artifacts/system-chain-audit/20260910-u13-u14/before/<relativer Pfad>`.
   Neue U13-Produktdateien dürfen in der Vorherkopie nicht in einem Requirepfad
   verbleiben. Fehlt eine Originaldatei oder ihr vor dem Edit gesicherter Hash,
   ist die Vorhermessung blockiert.
3. Der korrigierte Harness aus `tests/load/`, einschließlich Fixture und
   Inhaltsvalidierung, wird aus der Nachherkopie bytegleich in beiden
   Messkopien verwendet. Die dort ebenfalls gesicherten alten Harnessdateien
   dienen nur der Revisionsspur und niemals als Vorher-Harness.
4. Kein Checkout, Reset oder Stash verändert den gemeinsamen Arbeitsbaum. Ein
   einfacher Git-Worktree reicht nicht, weil er fremde uncommittete Änderungen
   auslassen würde.

Vorher und Nachher erhalten getrennte, gleich konfigurierte synthetische
Compose-Stacks und identische Datenbankvolumes aus demselben nach dem U13-Seed
erzeugten QA-Datensnapshot. Dieser Snapshot enthält ausschließlich synthetische
Daten. Image-Digests, Composekonfiguration, CPU-/RAM-Limits, PHP-/MySQL-
Konfiguration, Host, Toolversion, k6-Image-Digest und Fixture-/Harnesshashes
müssen übereinstimmen. Fremde Tests, Builds, Browser und Lastprofile laufen in
diesem exklusiven Fenster nicht.

## Kalt und warm getrennt

`portal-read.js` akzeptiert nur `U13_THERMAL_STATE=warmup` oder `warm`; ein
60-Sekunden-Rampenlauf darf nicht als kalt bezeichnet werden. Für S/T/L gilt je
Quellkopie:

1. Eine ausdrücklich dokumentierte Reset-/Startfolge stellt bei beiden Stacks
   denselben PHP-FPM-/OPcache- und Datenbankzustand her. Was nicht kontrolliert
   wurde, etwa Host- oder OS-Page-Cache, wird als `not_collected` mit Grund
   geführt und nicht als kalt behauptet.
2. `portal-read-cold.js` liefert genau einen explorativen
   `target_vms_cold_duration`-Sample. Der Run-Owner bestätigt den extern
   protokollierten Zustand mit
   `U13_COLD_PRECONDITION=verified-target-route-reset`. Der erforderliche Login
   wärmt Bootstrap und Auth bereits; der Sample heißt deshalb nur
"erster VM-Zielrequest nach geprüftem Reset", niemals kalter Host oder
   vollständig kalte Anwendung. `accepted_cold_vm_samples > 0` macht einen
   fehlenden Cold-Sample zum Messfehler.
3. Ein vollständiger 60-Sekunden-Warm-up mit `portal-read.js` wird nicht
   ausgewertet. Falls seine zwei Hälften weiterhin klar auseinanderlaufen,
   folgt genau ein zweiter dokumentierter Warm-up.
4. Danach folgen drei 60-Sekunden-Wiederholungen mit `warm`. Vorher/Nachher
   werden in wechselnder Reihenfolge ausgeführt, damit zeitliche Hostdrift nicht
   stets dieselbe Seite begünstigt. Profil, Daten und Bedingungen bleiben je
   Paar identisch.

Cold-Sample, Warm-up und Warm-Wiederholungen erhalten getrennte Verzeichnisse
und werden nie zu einem gemeinsamen Perzentil aggregiert.

## Session A/B

`tests/load/session-pair.js` vergleicht den Liveblocker-Request mit einer
Dashboardanfrage. Beide gemessenen Requests setzen `redirects: 0` und gelangen
erst nach positiver JSON-/HTML-, Status-, URL- und Authprüfung in die drei
Custom Trends. Vor dem ersten Paar beweisen beide Sessions zusätzlich dieselbe
gültige Zielmission, Tabellenzahl und den VM-Marker.
`accepted_session_pairs > 0` verhindert einen grünen A/B-Lauf ohne gültiges
Paar.

- A, `MODE=same`: blocker und peer erhalten exakt dasselbe frisch
  authentifizierte `CookieJar`-Objekt; der boolesche Identitätsbeweis verlangt
  `first.id === second.id`.
- B, `MODE=independent`: beide Jars entstehen durch getrennte vollständige
  Logins; der Beweis verlangt `first.id !== second.id`.

Die Evidenzzeile enthält Lauf-ID, verlangten Modus, Ergebnisrelation und
`identity_valid`, aber keine rohe PHPSESSID, keinen Hash der ID, kein Passwort
und keinen CSRF-Wert. Sessiondaten bleiben im VU-Speicher und werden nicht über
`setup()` in `setup_data` oder eine Summary exportiert. Fehlermeldungen nennen
nur Status und URL; die URLs enthalten keine Authentisierungswerte.

Beide Modi laufen nacheinander gegen denselben bereits warmen Datenstand und
dieselbe Quellkopie. Danach wird die Reihenfolge für das nächste Paar gedreht.
Dauer, ein VU, Timeout, Zielmission, Profil, Containerlimits und konkurrierende
Last sind identisch. Same und independent werden nie in ein gemeinsames
Perzentil gemischt. Pro Modus werden erfolgreiche Paarzahl,
`paired_blocker_duration`, `paired_peer_duration`, `paired_pair_duration`,
Fehler und Streuung berichtet.

## Laufartefakt und Geheimnisgrenze

Jeder spätere Lauf erfasst Lauf-ID, UTC-Start/-Ende, before/after, Profil,
Thermalzustand, Wiederholung, Datenhash und Counts, Quell-/Harness-/Fixturehash,
Tool-/Imageversionen, Ressourcenlimits, Reihenfolge, Samplezahl,
p50/p95/p99/max, Fehler, Durchsatz, Drops, Responsebytes und besondere
Ereignisse. Fehlende Werte heißen `not_collected` mit Grund, nie `0`.

Credentials werden über eine Datei außerhalb des Laufartefaktbaums als
Umgebungsvariablen eingereicht. Weder Environment-Datei noch Befehlszeile,
Cookies, IDs, CSRF-Tokens oder `.env` werden kopiert. Vor Veröffentlichung wird
das Artefakt auf diese Werte geprüft. Die Fixture-Namen und synthetischen IDs
sind keine Geheimnisse.

## Manuelle Abnahme vor Freigabe

Vor dem ersten späteren Lauf sind folgende Punkte von einer Person im Code und
in der Seed-Ausgabe zu bestätigen:

- `portal-read.js` verweigert unbekanntes Profil, Cold-Rampe, falsche Zielzahl
  und fehlende Zielnachweise; der Targettrend wird erst nach Validierung
  befüllt und der positive Samplezähler schließt einen Leerfolg aus.
- VM-, Blocker- und Peer-Messrequests verwenden `redirects: 0`; Login-POST darf
  dem erwarteten Dashboardredirect folgen.
- S/T/L-Counts, Zielmissionsname, Ziel-VM-Zahl und Marker stimmen zwischen SQL,
  Seed-Ausgabe und k6-Umgebung überein.
- `session-pair.js` benutzt in A dasselbe Jar und in B zwei frisch erzeugte
  Jars; die Evidenz enthält keinerlei Session- oder Authmaterial.
- Before und After besitzen denselben vollständigen Basissnapshot, denselben
  korrigierten Harness und denselben synthetischen DB-Snapshot; nur die
  dokumentierten U13-Produktdateien unterscheiden sich.

Offen bleiben sämtliche Laufzeitwerte, die tatsächliche Fixtureausführung,
die Session-A/B-Wirkung nach der Produktänderung, Queryzahl und
Relationsgleichheit sowie reale MECM-/ESXi-/AD-/Windows-Zeiten. Der aktuelle
Stand lautet: Mess- und Fixturecode vorbereitet und anhand der Quellen manuell
geprüft, Laufzeitabnahme offen.
