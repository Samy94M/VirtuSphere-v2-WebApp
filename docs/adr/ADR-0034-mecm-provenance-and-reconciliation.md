# ADR-0034: MECM-Provenienz und sichere Reconciliation

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
