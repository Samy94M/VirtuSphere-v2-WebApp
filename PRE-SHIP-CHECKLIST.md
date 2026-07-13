# Pre-Ship-Checkliste

Vor jedem Release-/Meilenstein-Abschluss abhaken. Reihenfolge ist Empfehlung; jeder Punkt nennt sein Prüfkommando. Historie gehört nach `docs/CHANGELOG.md`, nicht hierher.

## Automatische Checks (müssen grün sein)

- [x] PHPUnit: `docker exec virtusphere-v2-webapp-php-1 composer --working-dir=/var/www/html test` (2026-07-12: 458 Tests grün, 7 skipped)
- [x] Migrations-Preflight: `docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/migrate.php --check` (pending=0)
- [x] Lang-Parität DE/EN: `php scripts/lang-audit.php --ci` (21 Module)
- [x] CSP-/Pattern-Scan ohne `BLOCK:`: `sh scripts/lint-csp-patterns.sh --all-changed` (nur WARN-Zeilenbudget)
- [x] ENUM-Spiegel synchron: `sh scripts/check-enum-sync.sh`
- [x] PHP-Version konsistent: `sh scripts/check-php-version-sync.sh`
- [x] Doku-Hygiene: `sh scripts/check-doc-hygiene.sh`
- [x] Git-Whitespace: `git diff --check -- ':!Docker/WebAPI/vendor/**'`

## Manuelle Nachweise

- [x] Backup + Restore-Probe frisch gelaufen: `sh scripts/backup.sh && sh scripts/restore_test.sh` (2026-07-12: 884 KB Dump, 23 Tabellen wiederhergestellt)
- [x] Schema-Konvergenz bewiesen (frische `struktur.sql` == struktur + Migrationen, lädt auf leerem Volume): `sh scripts/check-schema-convergence.sh`
- [x] `portal/health.php` liefert HTTP 200 mit `ok`-Status, `/tests/bootstrap.php` liefert 403
- [x] Keine Secrets/Logs/Build-Artefakte neu getrackt: `git status --short` und `git ls-files | grep -iE '\.(env|pfx|pem|key|log)$'` leer
- [x] `docs/CHANGELOG.md` für den Meilenstein aktualisiert (Härtungskampagne 2026-07)

## Pre-Release-Härtungskampagne (2026-07)

Die systematische Härtung nach `docs/TESTPLAN.md` deckt die Sicherheits-, Nebenläufigkeits-, HTTPS- und Doku-Nachweise dieser Liste ab; die vollständige Befundtabelle steht dort. Kurzfassung der behobenen Release-Blocker: Fresh-Install-DB (`struktur.sql` FK-Guard), Deploy-YAML-Steuerzeichen, MAC-Kanonisierung (MECM-Lookup), Maschinen-API-JSON-Fehlerform, Security-Header auf nginx-Fehlerseiten, HTTPS-Quarantäne-Aussperrung, Deploy-Enqueue-Race. Offen bleiben nur die bewusst zurückgestellten Punkte unten und die in `docs/TESTPLAN.md` notierten Nicht-Blocker.

## Meilenstein-gebundene Punkte (offen bis zur jeweiligen Etappe)

- [x] WP7/HTTPS-Finalisierung: Admin-Config-Flow, HSTS-Entscheidung, nginx-Reload-Pfad (ADR-0012, ADR-0027; Runbook `docs/operations/https.md`)
- [ ] E3-Legacy-Retirement: Desktop-Client, `access.php`, `api/login.php`, `deploy_tokens` physisch entfernen (nur nach akzeptierter E3-Entscheidung)
- [ ] Clean-Checkout-Probelauf auf frischem Host (Ubuntu) als Release-Nachweis
- [ ] Visuelles Frontend-Design-Handoff abgeschlossen (ADR-0013)
