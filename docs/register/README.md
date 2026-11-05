# Register je Paket

Seit 05.11.2026 schreibt jedes Paket seinen Registereintrag in eine eigene Datei in diesem Ordner. Das bisherige Register [`docs/audits/2026-09-12-consolidated-session-backlog.md`](../audits/2026-09-12-consolidated-session-backlog.md) bleibt als Archiv unverändert lesbar und bekommt keine Einträge mehr dazu.

## Form

- Dateiname `JJJJ-MM-TT-<PAKET>.md`: Startdatum des Pakets und Paketkennung in Großbuchstaben, zum Beispiel `2026-11-05-B1.md`. Das Datum ordnet den Ordner zeitlich und hält eine später wiederverwendete Kennung getrennt.
- Erste Zeile `# <PAKET> (TT.MM.JJJJ): Titel`. Darunter der Inhalt wie bisher im Register: Auftrag, Umsetzung, Quellen, Gates mit Artefaktpfad, Offenes, nächster Schritt.
- Ein Paket, das später weiterarbeitet, ergänzt seine Datei. Die Datei bleibt nach dem Release liegen; welches Release sie enthält, nennt der CHANGELOG-Eintrag des Pakets.
- UTF-8 ohne BOM, echte Umlaute. Keine Servernamen, FQDNs, Prod-IPs, DP-Namen, Site-Code, echte MACs oder Konten.

Das Gate `doc-fragments` (`scripts/check-doc-fragments.sh`) prüft Namen und erste Zeile dieser Dateien und lehnt neue Zeilen im Archivregister ab.
