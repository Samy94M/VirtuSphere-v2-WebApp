# Portal-Require-Closure: Abschluss des offenen Guard-Befunds

## Umfang und Ursache

Nachpflege vom 2026-09-08 auf Basis von `7266966`. Der frühere Dashboardfehler
war bereits behoben; der bisherige Portaltest hatte jedoch keine eigene
syntaxbaumgestützte Analyse für dynamische Aufrufe, Callback-Verträge und
bedingte Includes. Ein erfolgreich gerenderter Standardzweig beweist diese
Abhängigkeiten nicht.

`PortalRequireClosureContractTest` leitet alle Portal-PHP-Einstiege aus dem
Dateisystem ab. `PortalClosureSource` indexiert Funktionsdeklarationen und
Require-Kanten; `PortalClosureCalls` prüft Aufrufe in allen syntaktischen
Zweigen. Beide sind reine Testhelfer unter `Docker/WebAPI/tests/Support/`.
Der bereits gesperrte Entwicklungsparser aus Composer wird wiederverwendet.
Portal-, Bootstrap- und Ownerdateien werden niemals ausgeführt.

## Prüfgrenzen

- Nur interne PHP-Funktionen gelten als Built-ins. Ein zufällig im
  PHPUnit-Prozess geladener Benutzerhelper erfüllt keine Abhängigkeit.
- Namespaces und Function-Imports werden aufgelöst; Methoden werden nicht
  als globale Funktionen fehlklassifiziert. Callback-Parameter werden aus
  Reflection beziehungsweise vorhandenen Owner-Signaturen/PHPDoc abgeleitet.
  Literale Callbacknamen, First-Class-Callables, Rückgabeverträge, benannte
  Argumente und Closure-Captures bleiben geprüft. Nicht auflösbare dynamische
  Aufrufe und Callback-Unpacking schlagen fehl.
- Unbedingte Modulkanten sind von bedingten und funktionslokalen Kanten
  getrennt. Ein Include in einer unaufgerufenen Funktion erfüllt keinen
  Aufruf außerhalb dieser Funktion. Mehrdeutige dynamische Include-Ziele
  werden zurückgewiesen.
- Die vorhandene Help-Panel-Registry bleibt SSoT und wird beidseitig mit dem
  Dateisystem verglichen. Dynamische Sprachkataloge müssen nichtleer und reine
  konstante Daten sein. Es gibt keine zweite handgepflegte Modulliste.
- Der Guard ist ein Abhängigkeitsnachweis, kein allgemeiner PHP-Typbeweis und
  kein Beweis beliebiger Ausführungsreihenfolgen. Composer-Autoloading bleibt
  ein eigener Lockfile-Vertrag. Der AST-Cache ist auf acht Dateien begrenzt;
  das normale PHPUnit-Speicherlimit wurde nicht erhöht.

Die einzige Produktänderung ist die bedarfsgesteuerte Require-Kante von
`deploy_parse_schedule()` in `lib/repo/deploy_job_input.php` zu
`lib/portal_time.php`, unmittelbar vor dem Aufruf bei fehlendem explizitem
Zeitzonenargument. Der Zeitparser besitzt damit seine tatsächliche
Abhängigkeit, statt im Test eine Bootstrap-Ausnahme zu benötigen. Ein
unbedingtes Require hatte im ersten Fast-Lauf den YAML-Roundtrip gerötet:
`portal_time.php` lädt `db.php` mit Log-Initialisierung. Reine Generatoren und
Aufrufer mit expliziter Zeitzone benötigen diese Nebenwirkung nicht.
Wire-, DB-, Session- und Portaltextverträge ändern sich nicht.

## Dauerhafte Gegenproben und QA

`PortalClosureAnalysisTest` prüft positive Closures, nicht ausgeführte Zweige,
fehlende Owner, Namespace-Importe, Callbacks, unsichere dynamische Includes,
lokale/bedingte Kanten, leere Einstiege und Registry-Drift. Die Dashboardprobe
entfernt in einer In-Memory-Mutation genau die tatsächliche Require-Kante zu
`system_status_service_panel.php` und verlangt den entsprechenden Missing-Owner-
Befund. Das Arbeitsverzeichnis wird dafür nicht verändert.

Die vorhandenen Gatefamilien `phpunit-unit` und `guard-harness` führen diese
Prüfung aus. Der Harnessfall `portal-require-closure.probes` verlangt zusätzlich
die Dashboard- und Zero-Match-Probe im Testreport und verweigert leere oder
geskippte PHPUnit-Suites. Der Fortschrittsvertrag ist in
`tests/powershell/VirtuSphere.ProgressReporting.Tests.ps1` erweitert; Gate-IDs,
Reihenfolge, Lane-Zuordnung und JSON bleiben unverändert.

Die erste gezielte synthetische Suite meldete **22 Tests, 33 Assertions, ohne Skips**
(`qa-artifacts/qa-local-rest-portal-probes-final.log`). Die Abschlussartefakte
dieses Pakets liegen unter
`qa-artifacts/qa-local-rest-portal-guards.json` und
`qa-artifacts/qa-local-rest-portal-docs.json` (fünf grüne Dokumentations-/SSoT-Gates).
Der erste vollständige Harnesslauf bewies 104 von 105 Fällen und meldete den
neuen Guard rot: Die nullable Callable-Standardzuweisung in `ssh.php` wurde
zu streng als unbekannter Aufruf behandelt. Der deklarierte Eingabevertrag
bleibt nun erhalten, während der Ersatzwert weiter geprüft wird; eine
zusätzliche Positiv-/Negativprobe pinnt dies. Die Wiederprüfung der gesamten
Portalfläche samt Gegenproben steht in
`qa-artifacts/qa-local-rest-portal-harness-final.log`, die abschließende
statische Analyse in `qa-artifacts/qa-local-rest-portal-stan-complete.json`.
`qa-artifacts/qa-local-rest-portal-fast.json` hält den ersten vollständigen Lauf
mit 30 grünen Gates und dem oben beschriebenen YAML-Roundtrip-Befund fest.
Die gezielte Wiederprüfung von PHP-Lint, PHPStan, Größenbudget und Roundtrip
nach dessen Behebung steht in `qa-artifacts/qa-local-rest-portal-lazy-time.json`.
Der Harness gibt seine gezählten Einzelfälle live an den kanonischen Runner
weiter. Frühere Entwicklungsversuche
mit zu großem AST-Cache und PHPStan-Typbefunden waren rot; sie werden nicht als
Abnahme gezählt. Der gemeinsame saubere Abschlusscommit wird anschließend
erneut durch alle drei vollständigen Lanes geprüft.
