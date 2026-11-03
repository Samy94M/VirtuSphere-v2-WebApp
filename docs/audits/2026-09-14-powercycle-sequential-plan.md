# Powercycle pro VM abschließen

## Auftrag und Ursache

Der Nutzer erwartet für jede VM: einschalten, konfigurierte Sekunden warten,
hart ausschalten, erst dann die nächste VM. Bisher schleift das Playbook über
alle Einschaltungen, pausiert einmal und schaltet anschließend alle eigenen
Starts aus. Früh gestartete VMs bleiben damit wesentlich länger an.

## Entscheidung und Begründung

Die geprüfte Zielauswahl bleibt vor allen Mutationen. Eine flache, mitgelieferte
Task-Datei führt den vollständigen Block mit `always` je Kandidat aus.
Die äußere `include_tasks`-Schleife erhält eine eigene Schleifenvariable.
Eigene Startnachweise werden je VM zurückgesetzt. Nur erfolgreiche Änderungen
dürfen ausgeschaltet werden; unklare Einschaltungen und Ausschaltfehler stoppen
den Lauf vor der nächsten VM. UUID-Auswahl, Export und Modusowner bleiben erhalten.
Fortschritt nennt Position, Anzahl und VM; Feldbezeichnung und DE/EN-Hilfe erklären
die Pause pro VM. Wartezeit ist keine Garantie für die gesamte Einschaltdauer:
API- und Tasklaufzeiten kommen hinzu.

## Quellenprüfung

- [Ansible include_tasks](https://docs.ansible.com/projects/ansible/latest/collections/ansible/builtin/include_tasks_module.html): Task-Dateien unterstützen Schleifen.
- [Ansible Blocks](https://docs.ansible.com/projects/ansible/latest/playbook_guide/playbooks_blocks.html): Blocks selbst unterstützen keine Schleifen; `always` behandelt gewöhnliche Taskfehler, keine ungültigen Tasks oder unerreichbaren Hosts.
- [Ansible pause](https://docs.ansible.com/projects/ansible/latest/collections/ansible/builtin/pause_module.html): Sekundenpause an ihrer Position im Ablauf; Null/negative Werte bedeuten nicht zuverlässig keine Pause.
- [VMware powerstate](https://docs.ansible.com/projects/ansible/latest/collections/community/vmware/vmware_guest_powerstate_module.html): Instance-UUID und harte Powerzustände. Lokaler Collection-Pin bleibt maßgeblich; keine Modulmigration in diesem Fix.

## Arbeitspakete

1. PC01: Ablauf, Uploadabhängigkeit und Fehlergrenzen korrigieren.
2. PC02: Produktionsablauf offline mit synthetischen VMware-Antworten testen:
   mehrere VMs, leere Auswahl, bereits an/suspendiert/MAC vorhanden, externe
   Einschaltung, Fehler beim Ein-/Ausschalten, fehlende UUID/Powerzustände,
   Pausefehler und Wartezeit. Regression muss den bisherigen Gruppenablauf erkennen.
3. PC03: Upload-/Identitätstests, Auswahlfixtures, DE/EN-Hilfe, Feldlabel,
   Betriebsdoku und QA-Beschreibung auf denselben Vertrag bringen.
4. PC04: passende Gates aus `scripts/check.ps1`, danach Fast-Lane als Abschluss;
   Integration/Release vor Merge/Auslieferung bleiben separate Pflichtabnahmen.

## Grenzen und Abnahme

Keine echten VMs in dieser Prüfung. Ein harter Prozessabbruch kann Cleanup
verhindern; externe Bedienung ist durch die API-Aufrufe nicht atomar gesperrt.
Ein ESXi-Lab muss die reale Folge mit mehreren VMs und Teilfehlern bestätigen.
Vorhandene uncommittete Änderungen werden erhalten. Quellenmanifest und
Prüfergebnisse liegen unter `qa-artifacts/powercycle-sequential/`.

## Stand

- PC01 umgesetzt: vollständiger Zyklus in der äußeren Include-Schleife,
  UUID-/Zustandsprüfung, Cleanup je VM, Abbruch vor der nächsten VM und Upload.
- PC02 lokal umgesetzt und nachgewiesen: Das kanonische Gate bestand 14/14
  Offline-Fälle, darunter 15 sequenzielle Zyklen, eine echte Wartezeit von
  5 Sekunden, externe Einschaltung sowie Pause-, Start- und Ausschaltfehler.
  Zusätzlich offen ist ein Gegenfall mit vorhandenem, aber abweichendem
  `hw_name`; der bisher so bezeichnete Fall entfernt das Feld nur.
- PC03 teilweise fehlerhaft: Upload-, Identitäts-, Hygiene-, DE/EN-, Doku- und
  Fortschrittsprüfungen sind grün. Der allgemeine PHP-Vertragsspiegel erkennt
  die durch `loop_control.loop_var` lokal bereitgestellte Variable
  `powercycle_vm` noch nicht und macht `phpunit-unit` rot.
- PC04 nicht abgeschlossen: Alle 31 Fast-Gates wurden abgedeckt, 26 bestanden
  und 5 schlugen fehl. Ein PHPUnit-Befund gehört direkt zu PC03; die übrigen
  roten Gategruppen sind getrennte bestehende Ownerprobleme. Integration,
  Release und das reale ESXi-Lab wurden nicht ausgeführt.
- Maßgeblicher Bericht und historisches Quellenmanifest des QA-Stands:
  `qa-artifacts/powercycle-sequential/sol-medium/report.md`, `result.json` und
  `source-manifest.json`. Zum Laufabschluss stimmten 37/37 Hashes. Spätere
  reine Planfortschreibungen ändern diesen historischen Beleg nicht rückwirkend;
  sie benötigen nur ihre passenden Dokumentprüfungen.
- Nächster direkter Schritt (Stand 14.09., überholt, siehe Fortschreibung):
  `loop_control.loop_var` im Vertragsspiegel samt echt ungebundener
  Negativprobe korrigieren, den abweichenden-`hw_name`-Fall ergänzen und die
  unmittelbar betroffenen Gates wiederholen.

### Fortschreibung 27.09.2026

- PC02 und PC03 sind mit Commit `ae55493` (20.09.) lokal geschlossen:
  `mismatched-instance-identity` ist der 15. Offlinefall in
  `Docker/qa-ansible/powercycle-sequence-contract.py`, und
  `AnsiblePlaybookVariableContractTest` erkennt `loop_var` nur innerhalb von
  `loop_control`, mit echt ungebundener Gegenprobe.
- PC04 bleibt offen. Die Fast-Läufe vom 20.09. und 21.09.
  (`qa-artifacts/merge-readiness/20260920-fast-full.json`,
  `20260921-fast-post-rebase.json`) stehen bei 30/31; rot ist allein
  `powershell-tests`, weil der Visual-Vertrag 18 Referenzbilder verlangt und
  das Manifest 12 enthält. Das ist kein Powercycle-Befund. Die Bilder darf nur
  der persönlich gestartete `scripts/update-visual-baselines.ps1` nach
  Sichtprüfung schreiben; danach Fast auf neuem Quellenmanifest wiederholen.
- Review-Befunde vom 27.09. (Folgearbeit, keine PC04-Voraussetzung):
  - Die Zielauswahl liest den Ausgangszustand per `vmware_guest_info` über den
    Namen. Das Modul wählt bei Namensdubletten still den ersten Treffer
    (`name_match: first`). Außerhalb von Full fängt der UUID-Vergleich das ab;
    im Full-Modus überspringt `identity_unbound_allowed` ihn. Die Auflösung
    gehört zur gemeinsamen Identitätsprüfung im
    [VM-Identitätsplan](2026-09-14-vm-identity-replacement-plan.md), Abschnitt 16.
    **Erledigt am 28.09.2026 mit IDR-P02 (`9a33073`):** Die Serverliste wird
    nach dem Create-Abschnitt neu geschrieben, `identity_unbound_allowed` ist
    entfernt; eine Namensdublette scheitert jetzt am UUID-Vergleich. Offen
    bleibt nur die einheitliche UUID-Suche in allen Playbooks (Identitätsplan
    16.2), damit statt des Abbruchs die richtige VM gefunden wird.
  - Die geschlossene 8R-O-Registry in `lib/remote_step_policy.php` beschreibt
    Powercycle als `stop_requested → stopped_verified → start_requested →
    started_verified` mit Nachweis `uuid_or_moid`. Tatsächlich läuft
    Einschalten, Warten, Ausschalten mit Namenssuche plus UUID-Assertion. Die
    Registry ist inaktiv; vor ihrer Aktivierung an PC01 angleichen.
  - Laufzeit wächst linear: Anzahl VMs mal (Wartezeit plus Befehlslaufzeit),
    bei 300 s Obergrenze und 50 VMs über vier Stunden. QoL: erwartete
    Mindestdauer neben dem Wartezeitfeld anzeigen.
  - Hilfe und Texte: Der Modusname „Aus- und einschalten mit MAC-Export“ nennt
    die Reihenfolge verkehrt herum (tatsächlich ein, warten, aus). Veraltete
    Modusnamen stehen in `lang/de/mission_details.php` („Full Pipeline“,
    „Power-Cycle + Export MACs“), `validate.php` und `deploy.php`
    (`stagger_hint`); EN-Hilfe und EN-Modusname weichen voneinander ab.
    **Erledigt am 28.09.2026:** Ursache der Abweichung war die Modusauswahl im
    Deploy-Formular, die die unlokalisierten technischen Texte aus
    `virtusphere_deploy_mode_labels()` zeigte („Power-Cycle + Export MACs“
    auch im deutschen Portal). Sie zeigt jetzt dieselben Namen wie Jobliste und
    Status (`deploy_mode_label()`). Der DE-Name lautet „Ein- und ausschalten mit
    MAC-Export“. Moduslisten in Hinweis-, Validierungs- und Hilfetexten
    (Staffelung, WDS-Portgruppe, beide Wartezeiten, Netzwerkvertrag) sind
    `:modes`-Platzhalter aus denselben Prädikaten, die das Verhalten
    entscheiden. `DeployModeTextTest` leitet alle Listen daraus ab, verbietet
    von Hand genannte Modusnamen außerhalb dieser Listen und jeden technischen
    Namen, der vom lokalisierten abweicht; er fand zusätzlich den englischen
    Autostart-Namen in der deutschen Missionshilfe. 8R-O-Registry und
    Mindestdauer-Hinweis bleiben unbeauftragt offen.
- Nächster direkter Schritt: Baseline-Writer durch den Nutzer, danach Fast
  vollständig; Integration, Release und ESXi-Lab bleiben getrennte Abnahmen.


## Einordnung in das konsolidierte Ziel

Die frühere Begrenzung auf einen Planungsvorschlag erklärt, warum begonnene
Produktänderungen zunächst nicht weiterbearbeitet wurden. Der spätere QA-Auftrag
hat den vorhandenen Stand inzwischen geprüft. Am 14.09.2026 wurde PC01 bis PC04
ausdrücklich in den konsolidierten Zielplan aufgenommen. Daraus folgt die
Reihenfolge PC03-Direktkorrektur, getrennte Gatebereinigung, vollständige lokale
PC04-Abnahme und erst danach reale ESXi-/Releaseabnahme. Die Aufnahme in den Plan
ist für sich keine Freigabe für weitere Produktänderungen, Commit, Push,
Deployment oder Standortwirkung.
