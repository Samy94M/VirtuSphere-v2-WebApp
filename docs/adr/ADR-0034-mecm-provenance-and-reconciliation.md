# ADR-0034: MECM-Provenienz und sichere Reconciliation

## Amendment 6 (15.09.2026): Retirement wartet nicht auf 100 Prozent der DPs

Der von `removeOldVersion=true` angeforderte automatische Retirement-Plan verwendet dieselbe
Ersatzbereitschaft wie das Deployment: Der Contentauftrag muss bestätigt und an
eine eindeutige Deployment-Type-/Contentidentität gebunden sein, die bekannte
DP-Zielprojektion darf keinen Zielverlust oder Löschzustand enthalten und das
Ersatz-Deployment muss geprüft vorhanden sein. `failed` oder `in_progress` auf
einzelnen oder allen DPs bleibt dabei ein sichtbarer Verteilbefund, blockiert den
Retirement-Plan aber nicht. Ein erfolgreicher DP oder 100 Prozent
Verteilerfolg sind keine Voraussetzung.

Ein noch ungebundener Intent, ein unbestätigter Aufruf, unbekannte
Content-/Zielidentität, Zielverlust und Löschzustände bleiben fail-closed. Der
Plan behält außerdem die unabhängigen Schutzgrenzen: eindeutige numerische
Zielversion, exakte Ownership-Marker, vollständiger Referenzscan, referenzfreie
Kandidaten, erneute Prüfung und identischer Planhash unmittelbar vor der ersten
Löschung. Der Autoimporter erhebt den Plan unmittelbar zweimal und führt ihn nur
bei identischem Hash aus; Deployment, Application und Collection sind explizite
Einheiten in dieser Reihenfolge. Das Retirement-Plan-Schema steigt auf 3;
Schema-1/2-Pläne sind unter der automatischen Semantik ungültig.

## Amendment 5 (15.09.2026): Deploymentfreigabe nach bestätigtem Contentauftrag

Auftragsannahme, Deploymentfreigabe und vollständiger Verteilnachweis sind drei
getrennte Grenzen. Sobald `Start-CMContentDistribution` oder
`Update-CMDistributionPoint` erfolgreich zurückgekehrt ist und der Autoimporter
den Auftrag als bestätigt gespeichert hat, zieht er die eigene Collection, das
Required-Deployment und ein konfiguriertes Available-Deployment idempotent nach.
Dafür ist kein bereits erfolgreich kopierter DP erforderlich. Die Zuweisung
bleibt damit auch erhalten, wenn alle DPs vorübergehend offline sind; Geräte
können das Paket anwenden, sobald der ihnen zugeordnete DP den Inhalt anbietet.

Der Verteilnachweis wird dadurch nicht abgeschwächt. `failed` und `in_progress`
bleiben offene Punkte, verhindern den Manifest-Stamp und bleiben im Portal
sichtbar. Vollständig verteilt ist der Stand weiterhin erst nach dem neueren
erfolgreichen Kopiernachweis aller gebundenen Ziele. Eine fehlende DP-Gruppe,
ein nicht bestätigter MECM-Aufruf, unbekannte Application-/Deployment-Type- oder
Contentidentität, eine unvollständige beziehungsweise widersprüchliche
Zielprojektion, Zielverlust und DP-Löschzustände bleiben vor nachgelagerten
Mutationen fail-closed. Der Autoimporter entfernt nur die über
`removeOldVersion=true` angeforderten und vollständig belegten Altobjekte; für
ein unverändertes Manifest stößt er keine blinde Redistribution an.

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

## Amendment 4 (09.09.2026): dauerhafte Journalgrenze und Wiederherstellung

Vorhandene Quarantänedateien sperren auch weitere Prozessstarts und ein
ersetztes Hauptjournal. Das Journal verwendet explizites, verlustfreies UTF-8;
ungültige Bytes bleiben als ungeklärte Evidenz erhalten. Die Freigabe verlangt
eine dokumentierte manuelle Ownership-Entscheidung, keine zeitbasierte Löschung.

Auditentscheidung D-01 präzisiert verfallene Provenienz: Eine weiterhin
ausdrücklich im Portal gewünschte eigene Membership wird unter aktueller
Rolloutrevision wiederhergestellt. Der pure Plan darf `add` und `stale_owned`
für dasselbe Ziel enthalten, weil beide den Zustand vor dem Apply beschreiben.
Nach einem bestätigten Add unter derselben exakten CollectionID bleibt die
Provenienz erhalten. Eine neue CollectionID unter gleichem Namen ersetzt
dagegen nicht die alte Identität; deren verfallene Provenienz wird separat
zurückgezogen. Ein nicht bestätigtes Add erlaubt keinen solchen Rückzug.
Fremde/manuelle Regeln werden weiterhin weder adoptiert noch entfernt.

Eine externe Entfernung soll dauerhaft gelten, indem die Zuweisung im Portal
ausdrücklich aufgehoben beziehungsweise die Ownership freigegeben wird.
Die Reihenfolge widersprüchlicher Add-/Remove-Berichte ist keine fachliche
Entscheidung über den Portalwunsch. Die bestehenden Revisions-, ResourceID-
und CollectionID-Grenzen bleiben verbindlich.

Beim Replay eines alten bestätigten Add-/Remove-Paars für dieselbe VM,
Rolloutrevision, ResourceID und CollectionID wird das Add gemeldet und nach
dessen ACK das gesamte Paar in
einer atomaren Journalersetzung quittiert. So erzeugt ein Abbruch zwischen
lokalen Löschungen keinen einzelnen veralteten Remove-Eintrag. Ein isolierter
Remove wird nur bei erfolgreich gelesener aktueller Abwesenheit replayt;
`present` oder `unknown` hält ihn dauerhaft als `uncertain` zur Klärung fest.
Live-Präsenz begründet dabei keine Adoption oder neue Ownership.

Status: accepted (2026-07-27). Entscheidungen 1-3 der Härtungskampagne 2026-07,
präzisiert durch Amendment 4 / Auditentscheidung D-01.

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
- **Verfallene Provenienz** (Regel von Hand in MECM entfernt) wird gemäß
  Amendment 4 behandelt: Ein weiterhin gewünschtes eigenes Ziel wird
  wiederhergestellt; ein nicht mehr gewünschtes Ziel wird zurückgezogen.
  MECM bleibt die Wahrheit über den aktuellen Live-Bestand.
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

## Amendment 1 (2026-09-07, durch Amendment 6 ersetzt): kein namensbasierter Autoimporter-Cleanup

Diese frühere Entscheidung ist für die Autorisierungsfrage ersetzt:
`removeOldVersion=true` ist jetzt die ausdrückliche Löschanforderung. Unverändert
gültig bleibt, dass ein Name oder Paketordner allein weder Eigentum noch
Ersatzbereitschaft beweist. Der Autoimporter benötigt einen unmittelbar vor der
Ausführung erneut validierten Plan mit stabilen IDs, Ownership, vollständigen
Referenzabfragen und Ersatznachweis. Portal-Retirement und MECM-Löschung bleiben
getrennte Vorgänge.

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
Deployment Type, einen bestätigten und gebundenen Contentauftrag, sichere
Zielevidenz und ein geprüftes Deployment, aber keinen vollständigen
DP-Verteilerfolg. Kandidaten brauchen stabile CI- bzw.
Collection-IDs, den passenden Marker und dürfen keine Referenz besitzen.

Der Plan besitzt einen SHA-256-Fingerabdruck über seine kanonische Form und
führt alte Application-Deployments vor Applications und Collections.
`Invoke-VsPackageRetirementPlan` akzeptiert nur einen unmittelbar erneut
erhobenen Plan mit identischem Fingerabdruck und bricht beim ersten
Einzelfehler mit einem expliziten Ergebnis ab. Der Autoimporter ruft diesen
Executor automatisch auf, wenn mindestens eine Paketquelle des Produkts
`removeOldVersion=true` verlangt und der eindeutige höchste Quellstand als
Ersatz belegt ist. Portal-Retirement löst diesen MECM-Vorgang nicht aus.
