# U13-Messplan für SC-008, SC-021 und SC-025

Aktualisiert am 12.09.2026. U01–U17 sind implementiert und die gemeinsame QA
vom 10.09.2026 ist im freigegebenen Umfang abgeschlossen. Deren historischer
37/39-Integrationslauf und gezielte Nachläufe sind keine Performanceevidenz.
AP09 enthält historische S/T/L-Leseläufe und Querydiagnose sowie
einen korrigierten Session-A/B-Nachlauf mit belegten Identitäten. Die damaligen
VM-Latenzen beruhten jedoch nicht auf der nun validierten Zielseite und sind
kein Before-Wert für diesen Aufbau. Die hier geplante exklusive U13-
Vorher-/Nachherabnahme mit validierter Zielseite, getrenntem Cold/Warm,
Relationsgleichheit, Queryvergleich und Session-A/B wurde noch nicht
ausgeführt; es werden keine neuen Laufzeitwerte oder Freigaben vorweggenommen.
Der [konsolidierte QA-Plan](2026-09-10-u13-u14-qa-plan.md) ordnet Q01 vor dieser
getrennten, exklusiven Performancephase und Q02 nach allen wirksamen Änderungen
ein. Die k6-Dateien unter `tests/load/` sind separate Messwerkzeuge und kein
zusätzlicher öffentlicher QA-Runner.

## Modell- und Messübergabe

Der verbindliche Effort- und Modellvertrag steht zentral im
[konsolidierten Backlog](2026-09-12-consolidated-session-backlog.md#modell--und-ausführungsvertrag)
und wird hier nicht wiederholt.

| Phase | Lead | Eingang | Ergebnis und Abnahme | Übergabe |
|---|---|---|---|---|
| Messinventar | Terra low | festgehaltener Quellstand, Originalkopien, Quellhashes, vorhandene Logs und Links | Vollständigkeitsliste für S/T/L, Zielseite, Harness, Images und frühere Evidenz; keine Messdeutung | an Sol high für das Messdesign |
| Messdesign und Fehleranalyse | Sol high | Inventar, konkrete Optimierungshypothese und fachliche Gleichheitskriterien | kontrollierte Variablen, Paarungsfolge, Abbruch-/Invalidierungsregeln und enge Gegenproben | ausführbarer Auftrag an Sol medium |
| Messausführung | Sol medium | autorisierter Auftrag, exklusiver Stack, identische Daten und validierte Zielseite | beobachtbare Cold-/Warm-, Query-, Relations- und Session-A/B-Rohbelege; keine Commit-/Pushaktion ohne separate Autorisierung | Abweichungen an Sol high; nur eine danach konkrete ungeklärte Hypothese bei Bedarf an Astra high |
| Kausalitätsgegenprüfung bei Bedarf | Astra high | konkrete, nach Sol-high-Analyse ungeklärte Hypothese samt verdichteter Gleichheits- und Nutzenbelege | kurze Gegenprüfung vor der finalen Übergabe, ob die behauptete Änderung den gemessenen Effekt tragen kann; kein Pflichtschritt nach jedem Lauf, keine Läufe, kein Logpollen und keine Commit-/Push- oder Publikationsprüfung | Ergebnis an Sol high und den QA-Owner |

Der Plan legt keine neuen Tasks an.

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

Die vorhandene Image-ID-Deduplizierung wird im Manifest berücksichtigt:
Bytegleiche Builds dürfen dieselbe Image-ID besitzen; das ist kein fehlender
Stacknachweis. Sobald sich eine in das Image eingehende Before-/After-Quelle
unterscheidet, müssen Buildkontext, Quellmanifest und resultierender Digest die
beabsichtigte Zuordnung beweisen. Ein wiederverwendetes altes Image oder ein
mehrdeutiges Tag invalidiert das betroffene Paar. Bei Bind-Mounts dürfen die
Image-IDs gleich bleiben; dann beweisen getrennte Mount- und Quellhashmanifeste
den tatsächlichen Before-/After-Appstand.

Nach Beginn einer Messreihe invalidiert eine Änderung an Produktquelle,
Schema/Migration, Fixture, Harness, validierter Zielseite, Auth-/Sessionpfad,
Composekonfiguration, Image, Ressourcenlimit oder Toolversion alle davon
abhängigen Paare. Eine reine Dokumentationsänderung invalidiert nur deren
Dokumentationsnachweis. Änderungen an einem einzelnen Endpunkt verlangen die
fachliche Gleichheits- und Performancenachmessung dieses Endpunkts und seiner
gemeinsam genutzten Owner; sie erzwingen keine zweite vollständige S/T/L- oder
QA-Lane ohne Wirkungsbezug. Q01 wird einmal als Ausgangsabnahme geführt,
gezielte Nachmessungen folgen nur für betroffene Pfade, und Q02 bewertet den
finalen Lieferstand.

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

## Gleichheit, Nutzen und vollständiger Messablauf

Eine Optimierung gilt nur dann als belegt, wenn Before und After fachlich
gleich sind und der behauptete Nutzen in den gepaarten Wiederholungen sichtbar
ist. Gleichheit umfasst Status und Zielseite, Auth-/Rollenentscheidung,
Missions- und VM-Auswahl, vollständige Relationsmengen einschließlich
Multiplizität, Sortierung, Zähler, Filter, Locale, Sessionwirkung und
Fehlersemantik. Queryreduktion allein ist kein Nutzenbeleg; eine bessere Dauer
bei falscher oder unvollständiger Antwort ist ungültig. Umgekehrt wird bei
fachlicher Gleichheit auch ein ausbleibender oder streuungsabhängiger Nutzen
als Ergebnis berichtet. Es werden keine neuen Schwellenwerte erfunden.

Queryzahlen werden mit derselben vorab festgelegten Instrumentierung und
Aktivierung in beiden Kopien pro validiertem Zielrequest erfasst. Instrument-
und Debug-I/O darf nicht nur eine Seite belasten. Die fachliche
Relationsgleichheit wird vor der Zeitwertung über eine stabil normalisierte
Projektion mit exakten IDs, Werten, Typen, Reihenfolge und Multiplizität
verglichen; ein bloß gleicher Zeilenzähler reicht nicht.

| Ablauf | Fachliche Gegenprobe | Mess- und Nachweisstatus |
|---|---|---|
| Profil und Seed | unbekanntes Profil, falsche Counts, nicht eindeutige Zielmission, enger Cleanup ohne Fremddaten | Fixturequelle `umgesetzt` und manuell quergelesen; echte MySQL-Ausführung `lokal geprüft` offen |
| Login und Zielvalidierung | Redirect, falsche Rolle, abgelaufene Session, falscher Content-Type/Marker und null akzeptierte Samples | Harness `umgesetzt`; Laufzeitbeleg offen |
| Portalread S/T/L | leere, typische und große Menge; exakt gleiche Zeilen, Relationen, Reihenfolge und Filter vor Zeitvergleich | historische AP09-Läufe bleiben Kontext; validierter U13-Before-/After-Laufzeit-, Query- und Relationsbeleg offen |
| Cold/Warm | ungeprüfter Reset, fehlender Cold-Sample, instabiler Warm-up und getrennte Wiederholungen | kontrollierter Zustands- und Rohsamplebeleg offen |
| Session A/B | fälschlich gleiche oder fälschlich verschiedene Identität, verlorener Authkontext, keine exportierten Geheimnisse | korrigierter AP09-Nachlauf historisch belegt; gepaarte U13-Before-/After-Wirkung offen |
| QoL-Pfade QL01–QL05 | Fehler erhält Eingaben/Filter; Bulkresultat bleibt vollständig; Zeit/Herkunft/Frische bleiben getrennt; Konfliktfelder bleiben konkret und geheimnisfrei; unklarer Write führt nur zum lesenden Prüfweg | vor einer Performanceaussage funktional je Rolle, No-JS und Teilquellenausfall prüfen; Laufzeit nicht aus Fehlerantworten ableiten |
| Upgrade X01 | unterstützter Altstand mit offenen Seiten, alten Callbacks, Drafts, Workerzustand und altem Assetcache; Rollback gegen Forward Repair | Portal-/Schema-/Worker-/PowerShell-/Assetkompatibilität separat belegen; erst danach betroffene Messpaare als vergleichbar markieren |

Die Statuswerte `umgesetzt`, `lokal geprüft`, `publiziert`, `installiert` und
`extern geprüft` bleiben auch im Messbericht getrennt. Eine lokale Messung
belegt weder Veröffentlichung noch Installation oder externe Wirkung.

Bei Upgrade-Messungen muss derselbe fachlich gültige Datenstand vor und nach
Migration erreichbar sein. Offene Seiten, zulässige Drafts und alte Callbacks
werden lesend auf Erhalt beziehungsweise auf die dokumentierte Ablehnung
geprüft. Scheitert eine Stufe, wird die Messung abgebrochen und der für X01
festgelegte transaktionale Rollback oder Forward Repair ausgeführt; ein
gemischter Portal-/Schema-/Worker-/PowerShell-/Assetstand liefert keine
Performanceevidenz.

## Laufartefakt und Geheimnisgrenze

Jeder spätere Lauf erfasst Lauf-ID, UTC-Start/-Ende, before/after, Profil,
Thermalzustand, Wiederholung, Datenhash und Counts, Quell-/Harness-/Fixturehash,
Tool-/Imageversionen, Ressourcenlimits, Reihenfolge, Samplezahl,
p50/p95/p99/max, Fehler, Durchsatz, Drops, Responsebytes und besondere
Ereignisse. Fehlende Werte heißen `not_collected` mit Grund, nie `0`.

Credentials werden über eine Datei außerhalb des Laufartefaktbaums als
Umgebungsvariablen eingereicht. Weder Environment-Datei noch Befehlszeile,
Cookies, IDs, CSRF-Tokens oder `.env` werden kopiert. Falls eine spätere
Veröffentlichung separat autorisiert ist, wird das Artefakt vorher auf diese
Werte geprüft. Die Fixture-Namen und synthetischen IDs sind keine Geheimnisse.

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
- Die Image-ID-/Digestzuordnung ist eindeutig; eine zulässige Deduplizierung
  bytegleicher Images und ein unerlaubt wiederverwendetes altes Image werden
  unterschieden.
- Für QL01–QL05 sind die betroffenen Rollen-, No-JS- und
  Teilquellenfehlerpfade funktional geprüft, bevor ihre Antworten als
  Performanceziel gelten.
- Das Quellmanifest weist aus, welche spätere Produkt-, Schema-, Worker-,
  PowerShell-, Asset-, Harness- oder Fixtureänderung welche Evidenz
  invalidiert. Es ist keine wirkungslose vollständige Vorher-/Nachher-Lane als
  scheinbare Sicherheit eingeplant.

Offen bleiben sämtliche neuen U13-Vorher-/Nachher-Laufzeitwerte, die
tatsächliche Fixtureausführung mit validierter Zielseite, der gepaarte
Session-A/B-Vergleich, Queryvergleich und Relationsgleichheit sowie reale
MECM-/ESXi-/AD-/Windows-Zeiten. Die historischen AP09-S/T/L-/Query- und
Session-A/B-Belege bleiben mit den oben genannten Grenzen erhalten. Der
aktuelle Stand lautet: Mess- und Fixturecode `umgesetzt` und anhand der Quellen
manuell geprüft; die vollständige neue lokale Laufzeitabnahme,
Veröffentlichung, Installation und externe Prüfung sind nicht belegt. Keine
Aussage dieses Plans autorisiert Commit, Push, Veröffentlichung oder
Installation.
