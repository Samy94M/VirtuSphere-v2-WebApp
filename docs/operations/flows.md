# Abläufe: Einstieg

VirtuSphere rendert das Portal in PHP, speichert Aufträge in MySQL und lässt den Deploy-Worker über den Ubuntu-Ansible-Host mit ESXi arbeiten. MAC-Ergebnisse und MECM-Berichte gehen über die Maschinen-API zurück. Die [Bereitstellungskette](deploy-chain.md) beschreibt die Gesamtkette und ihre Übergaben; jeder Detailablauf hat einen Ort in der folgenden Landkarte.

| Thema | Dokument |
|---|---|
| Einreihen, Worker und Playbooks | [Bereitstellung: Abläufe](deploy-flows.md) |
| Statusprüfungen | [Systemstatus: Prüfungen](system-status-checks.md) |
| Serveraufgaben in MECM | [MECM-Serveraufgaben: Abläufe](mecm-scheduled-tasks.md) |
| SSH-/SFTP-Vertrauensanker zum Ubuntu-Host | [Verbindungen und Vertrauensanker](trust-flows.md) |
| ESXi-Inventar und Zertifikatsvertrauen | [ESXi-Inventar](esxi-inventory.md) |

## Gemeinsame Legende

<!-- flow-node-limit: 12 -->
Neue Detaildiagramme enthalten höchstens zwölf Knoten; größere Abläufe werden in verlinkte Teilabläufe aufgeteilt. Der Vertrauenswächter liest die Grenze hier und prüft den neuen SSH-/SFTP-Ablauf. Die bestehenden Diagramme werden mit ihrem jeweiligen Paket abgeglichen.

Für neue Diagramme: Rechteck `step` = Arbeit/Beobachtung; Raute `decision` = Prüfung; Rechteck `blocked` = Abbruch/Sperre. Farben: `step` Hintergrund `#eef2ff`, Rand `#475569`; `decision` Hintergrund `#fff7ed`, Rand `#9a3412`; `blocked` Hintergrund `#fef2f2`, Rand `#b91c1c`; Text jeweils `#0f172a`. Die Vertrauensprüfung zeichnet nur diesen Teilablauf. Andere Dokumente verlinken ihn, statt eine zweite SSH-Anmeldelogik zu zeichnen.

Die Klassendefinitionen sind die Quelle für neue Flowcharts; der Wächter gleicht den SSH-/SFTP-Ablauf damit ab:

```text
classDef step fill:#eef2ff,stroke:#475569,color:#0f172a;
classDef decision fill:#fff7ed,stroke:#9a3412,color:#0f172a;
classDef blocked fill:#fef2f2,stroke:#b91c1c,color:#0f172a;
```

## Begriffe und Auswahl der Darstellung

**Pin** ist der gespeicherte Vertrauensanker, **Beobachtung** der zuletzt gelesene Schlüssel und **Bestätigung** die bewusste Entscheidung des Administrators nach unabhängigem Vergleich. Eine Beobachtung ist keine Bestätigung. Ein erfolgreiches Login ist kein vollständiger Ansible-Volltest.

| Frage | Darstellung |
|---|---|
| In welcher Reihenfolge wird geprüft? | Kurzes Flowchart |
| Welche Voraussetzung erlaubt welche Aktion? | Entscheidungstabelle |
| Wo liegt die Übergabe zwischen Systemen? | Übergabetabelle und Link auf den Fachablauf |

Reine Verwaltungsseiten, Dashboard, Hilfe, einmalige Skripte und nummerierte Runbooks erhalten hier kein zusätzliches Diagramm. Die weitere Dokumentkarte und noch nicht gelieferte Darstellungsarten bleiben im [Ablaufprüfplan](../audits/2026-09-28-deploy-flows-review-plan.md#struktur-gegen-einen-doku-dschungel-übernommen-mit-ds-e1-03102026); diese Übersicht zeichnet keine geplanten Funktionen.
