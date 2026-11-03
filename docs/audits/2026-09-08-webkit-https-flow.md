# WebKit: HTTPS-Test und beobachtbare Zustandsgrenzen

## Reproduktion

Der Restauftrag nannte auf `7fe9b18` die Assertion „dismissing the dialog kept
HTTPS on“ in `tests/e2e/specs/https-flow.spec.js`. Nach den drei separaten
Arbeitspaketen wurde der unveränderte Test auf `db83817` isoliert gegen
WebKit und den synthetischen `virtusphere-qa`-Stack ausgeführt.

Eine erste Anfahrt scheiterte an der PowerShell-Behandlung einer Node-Warnung,
bevor ein Testergebnis vorlag. Der erneute Aufruf verwendet die vorhandene
`Invoke-Tool`-Funktion; das ist nur der lokale Diagnoseaufruf, keine Änderung
an Produkt, Testtimeout oder Runnervertrag.

Der erste vollständig beobachtete Test scheiterte am unveränderten
30-Sekunden-Limit, am zweiten Klick auf „Disable HTTPS“. Die genannte
Cancel-Assertion war dabei erfolgreich. Der Trace belegt, dass nach Beginn
der Timeout-Hooks noch die Hidden- und DB-Assertions des Testkörpers liefen:
Hookbeginn bei 39.571 ms der Trace-Uhr, Hidden-Assertion bei 40.392 ms,
DB-Assertion bei 41.340 ms. Synchrone Docker-/PHP-Abfragen und die laufende
Testfortsetzung machen Aussagen während dieses Abbaus diagnostisch unsicher.
Das beweist keinen fehlerhaften HTTPS-Write durch Cancel.

Drei anschließende, begrenzte Wiederholungen des unveränderten Tests waren
grün. Der ursprüngliche falsche DB-Zustand wurde **nicht reproduziert**.
Die Laufzeit lag nahe an der vorhandenen Grenze; wiederholte vollständige
GETs zwischen den Aktionen waren im Trace sichtbar, obwohl jeder POST
bereits auf die HTTPS-Einstellungsseite zurückführt.

Artefakte: `qa-artifacts/qa-local-rest-webkit-isolated.log` (Transportabbruch),
`qa-local-rest-webkit-isolated-repeat.log` (Timeout),
`qa-local-rest-webkit-original-second.log` (drei grüne Wiederholungen).
Der rote Trace ist unter `qa-artifacts/webkit-original-timeout/` erhalten.
Alle Daten und Zertifikate stammen aus der synthetischen QA-Umgebung.

## Änderung am Test

Der Submission-Helper wartet jetzt neben der POST-Antwort auf die Navigation
des Hauptframes und `DOMContentLoaded`. Erst danach prüft er das HTTPS-Panel
und dessen Flash. Eine alte sichtbare Flashmeldung kann damit nicht als
Nachweis des neu abgeschickten Vorgangs dienen.

Die Cancel-Probe wartet zusätzlich auf das vom gemeinsamen Close-Handler
geleerte `returnValue` des Dialogs und prüft, dass kein Settings-POST gesendet
wurde. Die bisherigen direkten DB-Assertions bleiben unverändert. Die
überflüssigen GETs zwischen den Aktionen sind entfernt; nach POST/Redirect
arbeitet der Test auf dem gerade verifizierten Dokument weiter.

Der Ablauf bleibt ein geordneter Test mit denselben fachlichen Fällen.
Timeout 30 Sekunden, Expect-Grenze und Retryzahl bleiben unverändert.
Produktcode, Modalverhalten und visuelle Sollbilder sind nicht geändert.
Die Korrektur behauptet nicht, den historischen DB-Befund als Produktfehler
bewiesen zu haben; sie schließt die beobachteten unsicheren Testgrenzen.

Die gezielte Wiederprüfung besteht dreimal ohne Skips
(`qa-artifacts/qa-local-rest-webkit-fixed.log`). Der bestehende
`E2eActionCoverageContractTest` besteht mit drei Tests und 15 Assertions
(`qa-artifacts/qa-local-rest-webkit-coverage.log`); JavaScript-Syntax und die
drei gezielten Dokumentations-/CSP-Gates sind ebenfalls grün.
Die abschließende vollständige Firefox-/WebKit-Matrix, Chromium und Edge
werden anschließend vom unveränderten kanonischen Release-Runner auf dem
sauberen Abschlusscommit geprüft. Dessen eigenes Ergebnisartefakt
`qa-artifacts/qa-local-rest-release.json` ist dafür maßgeblich.
