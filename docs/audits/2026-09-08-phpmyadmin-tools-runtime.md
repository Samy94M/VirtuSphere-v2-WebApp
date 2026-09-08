# phpMyAdmin tools-Profil: lokale Ursachenprüfung

Ausgangscommit: `e1c57d33ea463731f02b310e614e9bc779e82209`, lokal und
`origin/main` vor Änderungen hashgleich, Arbeitsbaum sauber. Ausschließlich
Compose-Projekt `virtusphere-qa`, `Docker/qa/qa.env` und `tools`-Profil.
Der Entwicklungsstack wurde nicht verändert.

## Messung und Gegenproben

Das ursprüngliche Image
`sha256:0025217851d083f2ad619eed2edff9ac9108a0c1894be082b4032b4d8f07f26c`
startet `/docker-entrypoint.sh` als root. `/etc/phpmyadmin` gehört
`www-data:www-data`, Modus 0755; keine Konfigurationsvolumes überlagern es.
Der Entrypoint erzeugt dort `config.secret.inc.php` und `config.user.inc.php`.
Die ausgelieferte `config.inc.php` lädt die Secretdatei vor der Verarbeitung
von `PMA_HOST` und `PMA_PORT`.

| Variante | Beobachtung | Aussage |
|---|---|---|
| Original, root, `cap_drop: ALL` | `CapEff=0`; beide Konfigurationswrites `Permission denied`; Apache `AH02156: setgid`; Login HTTP 200 mit `mysqli::real_connect(): No such file or directory` | Oberfläche erreichbar, Anmeldung defekt; die TCP-Konfiguration wird nicht geladen |
| Nur `DAC_OVERRIDE` in reversibler QA-Override ergänzt | `CapEff=2`; Secretdatei erzeugt, HTTP-200-Anmeldung erfolgreich; Apache meldet weiterhin `setgid` und läuft als root | DAC-Verweigerung verursacht den Konfigurationsfehler; zusätzliche Capability löst den Apache-Vertrag nicht |
| Nur Benutzer `www-data:www-data`, weiterhin alle Capabilities entfernt | `CapEff=0`; generierte Dateien gehören 33:33, Modus 0644; sämtliche Apache-Prozesse 33:33; keine Permission-/setgid-Fehler; authentifizierte Seite mit `mysql via TCP/IP` | Bestehende Eigentümerschaft passt bereits; unprivilegierter Start behebt beide Ursachen |

Die temporäre DAC-Capability wurde unmittelbar nach der Gegenprobe entfernt.
Kein Secretinhalt, Passwort, Cookie oder CSRF-Token wurde ausgegeben.
Der Fix ergänzt nur `USER www-data:www-data` im finalen Imagestage. Ein
Root-Ownership-Umbau wurde verworfen, weil er den unnötigen root-Apache und
dessen fehlgeschlagenen Gruppenwechsel erhalten würde. `cap_drop: ALL`,
`no-new-privileges`, Loopbackbindung und unveränderte Vendor-Modi bleiben.

## Automatischer Nachweis

Owner: `scripts/lib/check/phpmyadmin.ps1`, eingebunden in die bestehende
Gatefamilie `health-contract`, sowie `Docker/qa/phpmyadmin-contract.sh`.
`tests/powershell/VirtuSphere.PhpMyAdmin.Tests.ps1` beweist Erfolgsantwort,
ursprüngliche HTTP-200-Fehlerantwort, fehlende TCP-Evidenz und leere Antwort.
Es entsteht keine neue Gate-ID oder zweite Runnerliste.

- QA-Aufbau: `qa-artifacts/qa-local-rest-setup.json`, 1 pass.
- Erstes Zielgate: `qa-local-rest-pma-target.json`, 3 pass / 1 fail.
  Der neue Rechteprobe-Shellstring überstand PowerShell 5.1 nicht; die Probe
  wird nun als LF-Datei kopiert und ausgeführt, ohne Shellstring-Escaping.
- Wiederholung: `qa-local-rest-pma-smoke.json`, 1 pass, authentifizierter TCP-Login.
- Negativkontrolle: `qa-local-rest-pma-negative.json`, ursprüngliches Image
  wird vom selben Gate abgelehnt; korrigierter Imagetag in `finally` restauriert.
- Antworttests: 3 pass, 0 fail/skip. Ein erster Sandboxlauf konnte Pesters
  temporären Registrybereich nicht anlegen; der Lauf außerhalb bestand.

- Ergänzende Gates: `qa-local-rest-pma-gates.json`, 8 pass, 0 fail,
  infrastructure_error, not_applicable oder skip: Compose-Härtung, File-Size,
  Doc-Hygiene, Doc-Semantics, PowerShell-Tests, ShellCheck, Hadolint, Image-CVE.

Offline-Bundle und gemeinsamer finaler Release-Nachweis stehen noch aus.
Diese Messung ist keine MECM-/AD-/ESXi-/Standortabnahme.
