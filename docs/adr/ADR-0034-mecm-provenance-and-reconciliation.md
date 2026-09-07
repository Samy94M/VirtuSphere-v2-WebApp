# ADR-0034: MECM-Provenienz und sichere Reconciliation

## Amendment 2 (07.09.2026): schemagetreue Verteilung und konservative Membership-Leser

Der Device-Sync behandelt eine Direct-Membership-Abfrage nun als dreiwertig:
`present`, `absent` oder `unknown`. Ein Providerfehler ist `unknown` und blockiert
alle Membership-Mutationen sowie den Provenienzrückzug der betroffenen VM. Ein
leeres, erfolgreich gelesenes Ergebnis bleibt dagegen `absent`. Collectionnamen
werden ordinal und nur bei eindeutiger Auflösung verwendet; mehrere exakte
Treffer oder derselbe gewünschte Name mit widersprüchlichen Typen blockieren.

Ein Remove übernimmt ID, Namen und Typ aus der autoritativen Provenienzzeile,
nicht aus der typfreien Live-Beobachtung. Der vollständige Add-/Remove-Plan wird
vor dem ersten MECM-Write auf meldbare Namen, IDs und Typen validiert. Der
Transport- und Crashvertrag zwischen MECM-Write und Portal-ACK bleibt davon
unberührt und wird erst mit dem separaten Journal-Amendment aus A04 ersetzt.

`Get-VsContentDistributionState` adressiert den eindeutig gelesenen
Application-Datensatz über `Get-CMDistributionStatus -InputObject`. Es wertet
die tatsächlichen Zähler `Targeted`, `NumberSuccess`, `NumberErrors`,
`NumberInProgress`, `NumberUnknown` und `SourceVersion` aus. Fehlende oder
ungültige Identität beziehungsweise Schemainformation ist `unknown`; nur ein
vollständig klassifizierter, fehlerfreier Stand ist `succeeded`. Die bestehende
Grenze bleibt: diese Abfrage ist ein globales Application-Aggregat und kein
Beweis für eine einzelne DP-Gruppe.

## Amendment 3 (07.09.2026): lokales Membership-Journal

Jede vom Device-Sync beabsichtigte Add-/Remove-Mutation erhält vor dem
MECM-Write einen dauerhaften `intent`-Eintrag. Sein stabiler Schlüssel bindet
VM, Rolloutrevision, ResourceID, CollectionID, Typ und Operation. Erst nach
erfolgreicher Rückkehr des MECM-Cmdlets wechselt er zu `remote_confirmed`.
Dieser Zustand darf idempotent an `reportMembership` replayt werden und wird
erst nach einer 200-Antwort entfernt. Der bestehende Endpoint bleibt dabei
unverändert: Fence und Provenienzwrites sind bereits transaktional, identische
Adds/Removes idempotent.

Ein beim Neustart verbliebener `intent` ist ausdrücklich `uncertain`. Der
aktuelle Live-Bestand beweist nicht, ob genau dieser Prozess die Regel schrieb;
deshalb erfolgen weder Adoption noch erneuter Remote-Write noch ResourceID-
Registrierung. Alte Revisionen, andere ResourceIDs sowie 404/409 beim Replay
werden ebenfalls ungeklärt gehalten. Unlesbare Dateien werden unter eindeutigem
Quarantänenamen erhalten und blockieren den mutierenden Lauf. Kapazitäts- oder
Schreibfehler blockieren vor dem Remote-Write. Ungeklärte Evidenz besitzt keine
zeitbasierte automatische Löschung.

Das versionierte JSON liegt neben den installierten Servermodulen, enthält
keine Secrets und erbt deren geschützte Program-Files-ACL. Eine exklusive,
prozesslang gehaltene Dateisperre verhindert zwei mutierende Device-Sync-
Instanzen. `VirtuSphere-MembershipJournal.ps1` besitzt Schema, Bounds,
Validierung, atomare Ersetzung und Quarantäne; Caller leiten diese Regeln nicht
neu ab.

Status: accepted (2026-07-27). Entscheidungen 1-3 der Härtungskampagne 2026-07.

## Kontext

Der Device-Sync legt Direct-Membership-Regeln an (OS-, Paket-, Missions-
Collections) und war rein additiv. Nichts hielt fest, welche Regeln VirtuSphere
gehören: ein OS-Wechsel A→B ließ die Regel in A stehen (die VM wäre in beiden
Task-Sequence-Collections gelandet), ein abgewähltes Paket blieb zugewiesen,
und ein Entfernen war nie beweisbar sicher, weil es eine von Hand in der
MECM-Konsole angelegte Regel hätte treffen können. Der alte Pester-Vertrag
verbot deshalb jedes Remove; die Übertragen-Aktion versprach im Portaltext
sogar „bestehende Mitgliedschaften werden nie entfernt".

## Entscheidung

- **Provenienz je Regel** (`deploy_vm_mecm_rules`, Migration 0033): vm_id,
  CollectionID, Name, Typ (os|package|mission), Herkunft (`created` |
  `explicitly_adopted`), Akteur, Zeit. Nur created/adopted gilt als
  VirtuSphere-owned. Bestehendes wird **nie still adoptiert**; Adoption ist
  eine ausdrückliche Portal-Aktion mit menschlichem Akteur (Etappe 9), das
  Skript kann sie nicht melden.
- **Der Plan ist eine pure Funktion** über desired (Portalwunsch), owned
  (Provenienz) und present (MECM-Stand): add / preserve / preserve_manual /
  remove / stale_owned / foreign. PHP (`lib/mecm_plan.php`) und PowerShell
  (`Get-VsMembershipPlan`) implementieren die identische Abbildung und laufen
  dieselben Vektoren (`tests/fixtures/mecm-plan-vectors.json`). Die eine
  Sicherheitsregel: **remove enthält nur owned ∧ present ∧ ¬desired**. Eine
  Hand-Regel hat keine Provenienzzeile und ist konstruktionsbedingt
  unantastbar (preserve_manual/foreign werden nie angefasst).
- **OS-Wechsel A→B entfernt die eigene Regel in A und fügt B hinzu; abgewählte
  Pakete werden abgeglichen** (Entscheidung 2). **Ein OS-Wechsel startet keine
  Installation** (Entscheidung 3): die Deployments bleiben Available/PXE,
  installiert wird nur beim separat ausgelösten PXE-Boot.
- **VM-Löschen bleibt rein lokal** (Entscheidung 1): die Provenienz stirbt mit
  der VM (CASCADE), die MECM-Regeln bleiben stehen, es gibt keine
  MECM-Bereinigung und keinen Nachlauf. Die Hilfe sagt das ausdrücklich.
- **Verfallene Provenienz** (Regel von Hand in MECM entfernt) wird
  zurückgezogen, nie zurückgekämpft: MECM ist die Wahrheit über das, was
  existiert.
- **Wire additiv:** `getDeviceList` führt je Gerät `owned_collections`;
  `mecm_updateid.php?action=reportMembership` nimmt die angewandten
  added/removed-Änderungen idempotent und atomar entgegen (404 für unbekannte
  VMs, ganzer Report abgelehnt bei einem fehlerhaften Eintrag). Der Sync
  meldet **vor** der ResourceID, die das Gerät aus der Warteschlange nimmt;
  ein verlorener Report hinterlässt höchstens eine überzählige eigene
  Provenienzzeile, die der nächste Lauf als stale zurückzieht - nie eine
  entfernte Hand-Regel.
- **Preview mit Revision:** Der VM-Editor zeigt vor der Übertragung die
  Portalsicht des Plans (Adds, eigene Removes), und der POST trägt eine
  Assignment-Revision (Hash über desired+owned, reihenfolgeunabhängig). Ändern
  sich die Zuweisungen zwischen Vorschau und Bestätigung, wird abgelehnt.
- **Verteilwahrheit (B7):** `Get-VsContentDistributionState` ist mehrwertig
  (not_started|in_progress|succeeded|failed|unknown), liest `NumberErrors`,
  adressiert per CI_ID, wo das Objekt vorliegt, und nur `succeeded` erlaubt
  dem Autoimporter, den Stamp zu merken. Der Stamp umfasst das
  Vorlagen-install.ps1; der DeployTo-Fehlschlag zählt als offener Punkt.
  Benannte Grenze: eine `failed`-Verteilung wird nicht blind neu angestoßen
  (Redistribution je DP ist ohne MECM-Testumgebung nicht prüfbar); der Punkt
  bleibt sichtbar, bis die Konsole neu verteilt.

## Konsequenzen

- Der Pester-Vertrag „kein Skript entfernt Mitgliedschaften" ist bewusst neu
  geschnitten: drei Skripte bleiben remove-frei, der Device-Sync hat genau
  eine Remove-Stelle, und die muss den Plan-Bucket konsumieren (Positiv-,
  Negativ- und Zero-Match-Pins).
- Zwei neue Ursachen-Codes im geschlossenen Vokabular für die Reconciliation
  (`collection_remove_failed`, `membership_report_failed`) und zwei für die
  Verteilwahrheit (`package_content_in_progress`, `package_content_unknown`).
- B11-Rest und B12 sind Teil der Etappe: der Statusverlauf hat Leser und
  Retention (Migration 0032), Update-Hinweis und Relink teilen die
  Versionswahl (`catalog_pick_highest_version`).
- Das MECM-Hardware-Gate bleibt ausdrücklich offen: OS-Wechsel A→B mit
  überlebender Hand-Regel, wiederholter Device-Sync idempotent, Verteilung
  erfolgreich/in Arbeit/fehlgeschlagen korrekt gemeldet (Air-Gap-Checkliste).

## Amendment 1 (2026-09-07): kein namensbasierter Autoimporter-Cleanup

`removeOldVersion` autorisiert im normalen Importlauf keine MECM-Loeschung mehr.
Ein passender Name oder Paketordner beweist weder VirtuSphere-Eigentum noch, dass
die neue Application samt Deployment Type, aktuellem Content, Verteilung und
Referenzen als Ersatz bereitsteht. Der Autoimporter erkennt exakte Kandidaten,
laesst Deployment, Collection und Application unveraendert und meldet den
offenen Bereinigungsbedarf. Ein spaeterer A14b-Executor benoetigt einen vor der
Ausfuehrung erneut validierten Plan mit stabilen IDs, Ownership, Referenzen und
Ersatznachweis. Portal-Retirement und MECM-Loeschung bleiben getrennte Vorgaenge.

## Amendment 4 (2026-09-07): versionierter, revalidierter Bereinigungsplan

Die Bereinigungsplanung liest zuerst alle Paketquellen und gruppiert sie mit
ordinalem Produktnamen. Für Löschentscheidungen gilt absichtlich eine engere
Versionssprache als im Portal: nur kanonische, nichtnegative, punktgetrennte
Dezimalfolgen werden segmentweise geordnet. Dadurch gilt `1.10 > 1.9` und
`10 > 2`, ohne Integerüberlauf. Freie, doppelte oder nicht interpretierbare
Versionen blockieren. Das Portal darf solche Altwerte weiterhin über seine
breitere `version_compare`-Semantik anzeigen; Anzeige und destruktive Freigabe
haben nicht dieselbe Fachbedeutung.

Neue Autoimporter-Applications und -Collections tragen je einen exakten,
versionierten Ownership-Marker. Ein Name, ein Ordner oder ein Altbestand ohne
Marker wird niemals nachträglich als Eigentum angenommen. Weiterhin in der
Quelle liegende Versionen und vorhandene höhere Versionen bleiben erhalten.
`Get-VsPackageRetirementPlan` verlangt für den eindeutigen Ersatz genau einen
Deployment Type, bestätigtes Content-Tracking, vollständig erfolgreiche
Verteilung und ein geprüftes Deployment. Kandidaten brauchen stabile CI- bzw.
Collection-IDs, den passenden Marker und dürfen keine Referenz besitzen.

Der Plan besitzt einen SHA-256-Fingerabdruck über seine kanonische Form.
`Invoke-VsPackageRetirementPlan` akzeptiert nur einen unmittelbar erneut
erhobenen Plan mit identischem Fingerabdruck und bricht beim ersten
Einzelfehler mit einem expliziten Ergebnis ab. Der normale Autoimporter ruft
diesen Executor nicht auf. Ein späterer operativer Aufruf bleibt eine gesondert
freizugebende MECM-Handlung; Portal-Retirement löst ihn nicht aus.
