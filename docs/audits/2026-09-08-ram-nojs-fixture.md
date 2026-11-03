# RAM-QoL: Schreibfixture ohne JavaScript

## Erneut geöffneter Abnahmebefund

Der erste gemeinsame Abschlusslauf auf `c8a20fb` bestand Fast mit 31/31 Gates.
Integration endete mit 38/39: 261 Chromiumtests waren grün, allein
`RAM is normalized on the server with JavaScript disabled` in
`tests/e2e/specs/form-accessibility.spec.js` scheiterte. Die vollständige
PHPUnit-Suite, Schema-/Health-Verträge und alle 105 Guard-Gegenproben waren
grün. Der Sequenzrunner startete danach keinen Release-Lauf.

Der Befund betrifft die RAM-QoL-Abnahme aus `e1c57d3`. Die gemeinsame
`seedMatrixFixtures()`-Fixture erzeugt VMs mit `vm_os='Win11'`, legt jedoch
keinen OS-Katalogeintrag an. Für ihre ursprünglichen Lesetests ist das
ausreichend; der zusätzliche RAM-Test sendet dagegen das gesamte VM-Formular.

Im Fehlerkontext enthält das erforderliche OS-Select nur „Select OS“.
Der Trace enthält **keinen POST an `vm_edit.php`**. Die native Browservalidierung
verhindert somit das Absenden, bevor der RAM-Parser ausgeführt werden kann.
Der ausbleibende Redirect ist keine Aussage über die RAM-Normalisierung.

Der rote Lauf bleibt unter
`qa-artifacts/qa-local-rest-integration-before-ram-fixture.json` und dem
gleichnamigen `.log` erhalten; Trace und Fehlerkontext liegen unter
`qa-artifacts/ram-nojs-before-fix/`. Der grüne erste Fast-Lauf ist als
`qa-local-rest-fast-before-ram-fixture.json` archiviert. Diese Ergebnisse
werden nicht nachträglich auf den reparierten Commit umgeschrieben.

## Korrektur und Prüfung

Die betroffene Formular-Spec besitzt jetzt einen eigenen aktiven OS-Eintrag
mit ihrem festen Testpräfix und bindet ihre beiden VMs daran. Ihr Cleanup
löscht zuerst die Matrixobjekte und danach genau diesen OS-Namen per
Prepared Statement, auch wenn das Matrix-Cleanup fehlschlägt. Die gemeinsame
Lesefixture und damit andere Specs und visuelle Sollbilder werden nicht
verändert.

Die No-JavaScript-Probe verlangt vor dem Speichern den ausgewählten eigenen
OS-Eintrag und wartet ausdrücklich auf den echten POST. JavaScript bleibt
deaktiviert; der Preset bleibt deaktiviert; der direkte DB-Nachweis für
`6 GB -> 6144 MB` bleibt erhalten. Es gibt keine Umgehung der nativen
Validierung und keine Änderung von Timeouts oder Produktcode.

Die gesamte betroffene Formular-Spec besteht auf Chromium, Firefox, WebKit
und Edge mit jeweils sieben Tests einschließlich Auth-Setup, ohne Skips
(`qa-artifacts/qa-local-rest-ram-fixture.log`). JavaScript-Syntax und die drei
gezielten Dokumentations-/CSP-Gates sind grün
(`qa-artifacts/qa-local-rest-ram-docs.json`). Anschließend
beginnen alle drei vollständigen Abschlusslanes erneut auf dem sauberen,
gepushten Reparaturcommit. Maßgeblich sind deren eigene Artefakte
`qa-local-rest-fast.json`, `qa-local-rest-integration.json` und
`qa-local-rest-release.json` unter `qa-artifacts/`.
