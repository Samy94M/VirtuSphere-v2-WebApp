# CHANGELOG-Fragmente

Jedes Paket schreibt seine CHANGELOG-Einträge in eine eigene Datei in diesem Ordner, nicht mehr direkt in [`docs/CHANGELOG.md`](../CHANGELOG.md). Zwei Pakete berühren so nie dieselbe Datei, und ein Merge hat hier keinen Konflikt.

## Form

- Dateiname ist die Paketkennung in Großbuchstaben: `SEC2.md`, `RL1-D7.md`, `B1.md`. Ein Paket, das später weiterarbeitet, ergänzt seine Datei.
- Jeder Eintrag ist eine Zeile `- **Titel (PAKET, TT.MM.JJJJ).** Text`, wie bisher im CHANGELOG. Eingerückte Fortsetzungszeilen sind erlaubt, Überschriften und freie Absätze nicht.
- UTF-8 ohne BOM, echte Umlaute. Keine Servernamen, FQDNs, Prod-IPs, DP-Namen, Site-Code, echte MACs oder Konten.
- Der Update-Absatz („Update von …, Migration …, neue Images …“) gehört nicht in ein Fragment; ihn schreibt der Release-Commit.

## Release-Commit

Nach dem Anheben beider `VERSION`-Kopien:

```sh
sh scripts/merge-changelog-fragments.sh --dry-run
sh scripts/merge-changelog-fragments.sh --intro <datei-mit-update-absatz>
```

Das Skript setzt `## JJJJ.MM.N (TT.MM.JJJJ)` über die bisher oberste Release-Überschrift, darunter den Update-Absatz und alle Einträge in der Reihenfolge der Dateinamen, und löscht die Fragmente. Ein leeres oder kaputtes Fragment bricht vor dem Schreiben ab. Danach `git add -A docs/CHANGELOG.md docs/changelog.d` und committen.

Das Gate `doc-fragments` (`scripts/check-doc-fragments.sh`) prüft Namen und Form dieser Dateien und lehnt neue Einträge direkt in `docs/CHANGELOG.md` zwischen zwei Releases ab.
