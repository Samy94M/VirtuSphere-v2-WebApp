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
- Nächster direkter Schritt: `loop_control.loop_var` im Vertragsspiegel samt
  echt ungebundener Negativprobe korrigieren, den abweichenden-`hw_name`-Fall
  ergänzen und die unmittelbar betroffenen Gates wiederholen. Danach werden die
  unabhängigen Fast-Blocker bei ihren eigenen Paketen geschlossen und Fast auf
  einem neuen unveränderten Quellenmanifest vollständig wiederholt.


## Einordnung in das konsolidierte Ziel

Die frühere Begrenzung auf einen Planungsvorschlag erklärt, warum begonnene
Produktänderungen zunächst nicht weiterbearbeitet wurden. Der spätere QA-Auftrag
hat den vorhandenen Stand inzwischen geprüft. Am 14.09.2026 wurde PC01 bis PC04
ausdrücklich in den konsolidierten Zielplan aufgenommen. Daraus folgt die
Reihenfolge PC03-Direktkorrektur, getrennte Gatebereinigung, vollständige lokale
PC04-Abnahme und erst danach reale ESXi-/Releaseabnahme. Die Aufnahme in den Plan
ist für sich keine Freigabe für weitere Produktänderungen, Commit, Push,
Deployment oder Standortwirkung.
