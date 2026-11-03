# Etappe 10C: Audit-Producer-Migrationsmatrix

Stand der Vorabaufnahme: 2026-08-25, Basiscommit
`24808a8fa95c6db0856180525c14f3436c5a7dba`. Die Zeilennummern in der Spalte
"Alt-Aufrufer" bezeichnen bewusst den Stand **vor** der Migration; die Matrix
wurde vor der ersten Codeänderung an der Audit-SSoT angelegt und danach um den
Abschlussstand ergänzt, statt sie umzuschreiben. Was hier als Migrationsvorgabe
stand, ist jetzt der implementierte Vertrag.

## Suchumfang und Zählung

Durchsucht wurden alle first-party PHP-Dateien unter `Docker/WebAPI`, ohne
`Docker/WebAPI/tests` und ohne Drittanbieter-Code. Der vollständige Ausdrucksscan
ergab 104 produktive Aufrufe: 79 `audit()`, 17 `audit_auth()` und 8
`machine_api_audit_warning()`. Darin enthalten sind die drei damaligen Wrapper-
beziehungsweise Sink-Aufrufe in `lib/audit_events.php` und `lib/machine_api.php`;
sie werden in der Zielarchitektur durch den Registryowner ersetzt.

Der statische Zero-Caller-Nachweis für `addLog()` fand ausschließlich die
Definition in `lib/repo/log.php:14` und eine Kommentarstelle in
`lib/constants.php:162`. Der getrennte Test-/Toolscan fand keinen Aufruf.

**Abschlussstand:** `scripts/check-audit-contract.php` zählt 105 strukturierte
Producer-Aufrufe über 231 Dateien gegen 58 registrierte Ereignisse und ordnet
jeden Aufruf einem Ereignis zu; kein Ereignis bleibt ohne Producer und kein
Producer ohne Ereignis. `addLog()` und `audit_auth()` sind entfernt; ihre
Wiedereinführung ist ein Buildfehler (`audit-contract.dead-sink`,
`AuditProducerContractTest::testTheRemovedSinksAreNotReintroduced`). Die
Kommentarstelle in `lib/constants.php` ist berichtigt und beschreibt jetzt, dass
keine Aufrufstelle mehr eine Kategorie wählt. `audit_change_note()` fiel als
zweiter toter Helfer mit: seine Aussage ("ein Update ohne Feldänderung sagt das")
gehört in den Presenter, weil sie über die gerenderte Beschreibung spricht und
nicht über den Diff.

Die Zielcodes unten sind der implementierte Vertrag. `success`, `denied`,
`warning`, `failure` und `recovered` sind geschlossene Resultate. Kontextnamen
sind keine Freitextfreigabe: Die Registry typisiert und begrenzt jeden
aufgeführten Namen; nicht aufgeführte Felder sowie Passwort-, Secret-, Token-,
Payload-, Exception- und Suchwertfelder werden abgewiesen, und zwar unabhängig
davon, ob ein Ereignis sie deklariert.

## Portal-, Worker- und Systemproducer

| Alt-Aufrufer (vor Migration) | Ereigniscode | Objekt | Ergebnis | erlaubter Kontext | Kategorie |
|---|---|---|---|---|---|
| `portal/login.php:27`, `lib/audit_events.php:75` | `auth.csrf_rejected` | `request` / Seitenname | `denied` | `page` | `auth` |
| `lib/audit_events.php:47` | `auth.access_denied` | `request` / `portal` | `denied` | `permission` | `auth` |
| `portal/logout.php:26` | `auth.logout` | `user` / Benutzer-ID (nullable) | `success` | `source` | `auth` |
| `portal/account.php:30,35` | `auth.password_change_attempt` | `user` / Benutzer-ID | `denied` oder `success` | `scope`, bei `denied` zwingend `reason` | `auth` |
| `lib/auth.php:153,193`, `lib/auth_directory_login.php:63` | `auth.login` | `user` / bekannte ID oder leer | `denied` | `source`, zwingend `reason`, optional begrenzter `username` | `auth` |
| `lib/auth.php:204`, `lib/auth_directory_login.php:39` | `auth.login` | `user` / Benutzer-ID | `success` | `source`, optional `controller_id` | `auth` |
| `lib/auth.php:182` | `auth.account_locked` | `user` / Benutzer-ID | `denied` | `duration_minutes`, `failure_count` | `auth` |
| `lib/auth_rate_limit.php:178,207` | `auth.ip_rate_limited` | `client_ip` / Audit-IP | `denied` | `duration_minutes`, `failure_count` | `auth` |
| `lib/auth.php:286,306,327` | `auth.session_ended` | `user` / Benutzer-ID | `success` oder `denied` | `reason`, optional `duration_seconds` | `auth` |
| `lib/audit_events.php:31` | bisheriger freier `audit_auth()`-Sink | kein eigener Producer | entfällt | ersetzt durch den Registryowner | `auth` |
| `lib/users_admin.php:89` | `user.created` | `user` / neue ID | `success` | `source`, optional begrenzter `name` | `users` |
| `lib/users_admin.php:123` | `user.active_state_changed` | `user` / Ziel-ID | `success` | `enabled` | `users` |
| `lib/users_admin.php:154` | `user.role_changed` | `user` / Ziel-ID | `success` | `role` | `users` |
| `lib/users_admin.php:186,198` | `user.security_state_changed` | `user` / Ziel-ID | `success` | `action` | `users` |
| `lib/directory_service.php:375` | `user.directory_imported` | `user` / Ziel-ID | `success` | kein Kontext | `users` |
| `lib/users_directory_admin.php:184` | `user.directory_synchronized` | `user` / Ziel-ID | `success` | kein Kontext | `users` |
| `lib/users_directory_admin.php:49,146` | `directory.configuration_changed` | `directory_config` / `active` | `success` | `action`, optional `revision` | `directory` |
| `lib/users_directory_admin.php:71,98,107,121` | `directory.controller_changed` | `directory_controller` / Controller-ID | `success` | `action`, optional `host`, `port`, `enabled`, `direction` | `directory` |
| `lib/users_directory_admin.php:137` | `directory.login_changed` | `directory_config` / `active` | `success` | `enabled` | `directory` |
| `lib/directory_service.php:65` | `directory.controller_state_changed` | `directory_controller` / Controller-ID | `warning` oder `recovered` | `outcome` | `directory` |
| `lib/directory_service.php:94,100,107` | `directory.controller_tested` | `directory_controller` / Controller-ID | `success` oder `failure` | `outcome` | `directory` |
| `lib/directory_service.php:162` | `directory.bind_rejected` | `directory_config` / `active` | `warning` | `action` | `directory` |
| `portal/credentials.php:115,143,148,152,159` | `credential.changed` | `credential` / Zugang-ID | `success` | `action`, optional `changes`, `trust_mode`, `selection_cleared` | `credentials` |
| `lib/credentials_actions.php` | `credential.tested` | `credential` / Zugang-ID | `success`, `warning` oder `failure` | `outcome`, boolesch `evidence_stored`; optional `component`, `ip`. Ein überholter Abschluss ist `warning` / `discarded` / `false`. | `credentials` |
| `portal/credentials.php:76`, `lib/deploy_worker_inventory.php:208` | `credential.inventory_automation_changed` | `credential` / Zugang-ID | `recovered` oder `warning` | `action`, `reason` | `credentials` |
| `portal/credentials.php:170` | `deploy.inventory_requested` | `credential` / Zugang-ID | `success` oder `warning` | `reason`, optional `job_id` | `deploy` |
| `portal/missions.php:50,59,142`, `portal/mission_details.php:91` | `mission.changed` | `mission` / Missions-ID | `success` | `action`, optional `name`, `changes`, `vm_count` | `missions` |
| `portal/mission_details.php:43,97,103` | `mission.transferred` | `mission` / Quell-ID | `success` | `action`, optional `name`, `target_mission_id` | `missions` |
| `portal/missions.php:204` | `mission.list_exported` | `mission_list` / `missions` oder `templates` | `success` | `row_count` | `missions` |
| `portal/vm_edit.php:100`, `portal/vms.php:72` | `vm.changed` | `vm` / VM-ID | `success` | `action`, `mission_id`, optional `changes` | `vms` |
| `portal/vms.php:44,59,66` | `vm.mecm_state_changed` | `vm` / VM-ID | `success` | `action`, `mission_id`, optional `progress_kind` | `vms` |
| `portal/vms.php:97` | `vm.bulk_changed` | `mission` / Missions-ID | `success` | `action`, `affected_count`, begrenzte `vm_ids` | `vms` |
| `portal/vms.php:175` | `vm.list_exported` | `mission` / Missions-ID | `success` | `row_count` | `vms` |
| `portal/deploy.php:106` | `vm.identity_adopted` | `vm` / VM-ID | `success` | `credential_id`, `mission_id`, `moid`, `instance_uuid` | `vms` |
| `portal/os.php:31` | `catalog.item_deleted` | `operating_system` / OS-ID | `success` | kein Kontext | `os` |
| `portal/vlans.php:26` | `catalog.item_deleted` | `vlan` / VLAN-ID | `success` | kein Kontext | `vlans` |
| `portal/settings.php:60,74,88,126,128,144,159,222,244,255,315` | `settings.changed` | `setting` / Setting-Key | `success` | `action`, optional `old_value`, `new_value`, `enabled`, `redirect_disabled` | `settings` |
| `portal/settings.php:192` | `settings.certificate_installed` | `setting` / `https_certificate` | `success` | `subject`, `valid_to` | `settings` |
| `portal/settings.php:266,274` | `settings.report_token_changed` | `setting` / `machine_report_token` | `success` | `action`; niemals der Tokenwert | `settings` |
| `portal/settings.php:297,327` | `settings.machine_ip_allowlist_changed` | `client_ip` / IP-Adresse | `success` | `action` | `settings` |
| `portal/deploy.php:85,90` | `deploy.queued` | `deploy_group` oder `deploy_job` / ID | `success` | `mission_id`, `scheduled`, optional `job_count` | `deploy` |
| `portal/deploy.php:130` | `deploy.cancel_requested` | `deploy_job` / Job-ID | `success` | kein Kontext | `deploy` |
| `portal/deploy.php:133,140` | `deploy.cancelled` | `deploy_job` oder `deploy_group` / ID | `success` | `action`, optional `job_count` | `deploy` |
| `portal/deploy.php:146` | `deploy.retried` | `deploy_job` / neue Job-ID | `success` | `retry_of_job_id` | `deploy` |
| `lib/deploy_worker_finish.php:256` | `deploy.outcome` | `deploy_job` / Job-ID | `success`, `warning` (Teil-Erfolg) oder `failure` | `mission_id`, `mode`, `status`, optional begrenzter redigierter `reason` | `deploy` |
| `lib/system_status_page.php:70` | `deploy.inventory_refresh_requested` | `system` / `esxi_inventory` | `success` oder `warning` | begrenzte `target_ids`, `job_ids`, `queued_count`, `open_count`, `paused_count`, `failed_count` | `deploy` |
| `lib/system_status_page.php:127` | `deploy.vlan_reassigned` | `vlan` / Quell-VLAN (Objekt-ID-Art `name`) | `success` oder `warning` | `target_vlan`, `mission_count`, `interface_count` | `missions` |
| `lib/maintenance_tasks.php:203` | `deploy.convergence_sweep` | `mission` / Missions-ID | `warning` | `vm_count`, begrenzte `vm_ids`, `reason` | `deploy` |
| `lib/maintenance_tasks.php:238` | `integration.state_changed` | `integration_source` / Source | `warning` oder `recovered` | `old_state`, `new_state` | Registry leitet `system` oder `mecm` aus der geschlossenen Sourcekarte ab |
| `lib/errors.php:295` | `system.unhandled_error` | `error` / Fehlerreferenz | `failure` | `error_class`; keine Exceptionmeldung und kein Stack | `system` |
| `portal/logs.php:64` (jetzt `lib/logs_export.php`) | `logs.csv_exported` | `log_view` / Tab | `success` | `filter_fingerprint`, `rows_exported`, `total_rows`, `limit`, `truncated` | `system` |

## Machine-API- und MECM-Producer

| Alt-Aufrufer (vor Migration) | Ereigniscode | Objekt | Ergebnis | erlaubter Kontext | Kategorie |
|---|---|---|---|---|---|
| `lib/machine_api.php:85` | `machine_api.access_denied` | `machine_endpoint` / Endpoint | `denied` | optional `action`, Drosselzähler; IP bleibt in der eigenen Auditspalte | `machine_api` |
| `db_importMAC.php:227` | `machine_api.callback_rejected` | `deploy_job` / Job-ID | `denied` | `reason_code`; keine freie Exceptionmeldung | `machine_api` |
| Catch-Blöcke der Endpunkte | `machine_api.internal_failure` | `machine_endpoint` / Endpoint | `failure` | `error_class`; niemals Meldung oder Stack | `machine_api` |
| `mecm_updateid.php:59,98` | `mecm.unknown_vm_reported` | `vm` / unbekannte VM-ID | `warning` | `report_type`, optional begrenzte `resource_id` | `mecm` |
| `mecm_packages.php:68` | `mecm.catalog_sync_rejected` | `catalog` / `packages` oder `task_sequences` | `denied` | `retire_count`, `active_count`, `threshold_percent` | `mecm` |
| `mecm_packages.php:163` | `mecm.packages_relinked` | `catalog` / `packages` | `success` | begrenzte typisierte `items`, `item_count` | `mecm` |
| `mecm_packages.php:169` | `mecm.packages_relink_skipped` | `catalog` / `packages` | `warning` | begrenzte typisierte `items`, `item_count` | `mecm` |
| `mecm_report.php:47` | `mecm.report_token_rejected` | `machine_endpoint` / `mecm_report.php` | `denied` | `action`; niemals Header-/Tokenwert | `mecm` |
| `mecm_report.php:100` | `mecm.client_event_cap_reached` | `vm` / VM-ID | `warning` | kein MAC- oder Payload-Dump; Drosselung je VM statt je IP | `mecm` |
| `mecm_report.php:150` | `mecm.reporter_upgraded` | `integration_source` / Source | `recovered` | `report_version` (aus `VIRTUSPHERE_REPORT_CHANNEL_VERSION`) | `mecm` |
| `lib/machine_api.php:172` | bisheriger freier Throttle-Sink | kein eigener Producer | entfällt | Registryevent erzeugt Beschreibung, Kategorie und Throttle-Key | Registryowner |

`heartbeat` und jeder normale `reportRun` bleiben ausdrücklich ohne Auditproducer.
Nur die einmalige Legacy-zu-V2-Ratsche und gedrosselte Ablehnungen schreiben
eine Zeile. Die Wartungsübergänge schreiben nur bei einem Zustandswechsel; ein
Recovery entsteht höchstens einmal beim Verlassen desselben Störungsfensters.
`AuditProducerContractTest::testNormalHeartbeatsAndRunReportsProduceNoAuditRow`
liest genau diese beiden Zweige und fällt, sobald einer einen Producer bekommt.

## Entscheidungen, die erst beim Umbau entstanden sind

1. **Objekt-ID-Art.** Fast jedes Auditobjekt trägt eine Maschinen-ID, einen
   Settingschlüssel, einen Endpunktdateinamen oder eine IP; alle passen in einen
   geschlossenen Zeichensatz. Ein VLAN hat gar keine ID, es wird über seinen vom
   Operator getippten Namen adressiert, und der darf ein Leerzeichen enthalten.
   Statt den Zeichensatz für alle zu öffnen, deklariert die Registry die Art pro
   Ereignis (`objectIdKind`), und `deploy.vlan_reassigned` ist das einzige
   `name`-Objekt. Ein `name` bleibt begrenzt, redigiert und ohne
   Steuerzeichen: eine ID, die einen Zeilenumbruch tragen kann, kann eine
   gefälschte Zeile in einen Export einschleusen.
2. **Überlänge wird abgewiesen, nie gekürzt.** Eine gekürzte Objekt-ID zeigt auf
   eine andere Zeile als die, zu der das Ereignis gehört, und niemand
   nachgelagert kann erkennen, dass gekürzt wurde.
3. **Geschlossene Objekt-ID-Listen.** Wo die Menge wirklich geschlossen ist
   (Machine-API-Endpunkte, Katalogarten, Logtabs, Directory-Config,
   Zertifikats- und Tokenschlüssel, `esxi_inventory`, Integration-Sources),
   führt die Registry sie. `machine_api_forbidden()` bildet einen unbekannten
   Skriptnamen auf `machine-api-unknown` ab, statt die Zeile fallen zu lassen:
   eine abgewiesene Maschinenanfrage ist ein Sicherheitsereignis, und es wegen
   einer umbenannten Datei zu verlieren wäre schlechter als eine unspezifische
   Objekt-ID. Der Rohname geht redigiert ins Containerlog.
4. **Keine Wildcard-MECM-Kategorie.** Die Vorgängerlogik löste "nicht
   maintenance" nach `mecm` auf. Die erste neue Nicht-MECM-Quelle hätte ihren
   Ausfall damit in dem Tab abgelegt, den ein Operator öffnet, um
   MECM-Synchronisation zu lesen. Die Karte ist jetzt geschlossen, eine
   unbekannte Source wird abgewiesen.
5. **Ereigniscode-Semantik.** Eine abgelehnte Passwortänderung heißt
   `auth.password_change_attempt` und trägt das Ergebnis; ein Code namens
   `auth.password_changed` würde für den abgelehnten Fall behaupten, die
   Änderung sei erfolgt. Ebenso getrennt: `deploy.cancel_requested` für die
   angenommene Anforderung an einen laufenden Job und `deploy.cancelled` für den
   tatsächlich beendeten Job beziehungsweise die Gruppe. Nach ADR-0033 verändert
   der laufende Schritt ESXi weiter, nachdem die Anforderung angenommen wurde;
   ein gemeinsamer Code würde das Protokoll behaupten lassen, ein Job sei zu
   einem Zeitpunkt gestoppt gewesen, zu dem er nachweislich lief.
6. **Zählung der Machine-Denials nach Ereigniscode.** Die Kategorie
   `machine_api` enthält auch abgewiesene MAC-Callbacks und interne Fehler.
   `repo_recent_machine_api_denials()` zählt jetzt exakt
   `machine_api.access_denied`; die Kategoriezählung schickte einen Operator,
   dessen Ansible-Callback mit einem abgebrochenen Job kollidiert war, zur
   Allowlist, auf der sein Host längst stand.

## Anzeige- und Exportfolgen

Die englische Beschreibung bleibt die Kompatibilitätsanzeige, wird aber aus
denselben strukturierten Daten gerendert (`lib/audit_presenter.php`), statt eine
zweite, ältere Meinung zu sein. Wo bestehende Suchen und E2E-Zusicherungen an
einem Wortlaut hängen, ist der Wortlaut erhalten geblieben: ein Deploy-Job heißt
weiter `deploy job id N`, eine Gruppe weiter `deploy group N`, und ein Update
ohne Feldänderung sagt weiter `(no field changes)`. Was ein Row **speichert**,
darf sich ändern; was ein jahrealter gespeicherter Filter liest, ist die Daten
von jemand anderem.

Historische Zeilen behalten alle fünf neuen Spalten `NULL` und ihren
unveränderten Freitext. Es gibt keinen Backfill und keine Heuristik, die einen
Code aus alter Prosa errät. Die CHECK-Bedingung lässt genau diese Form zu, und
`StructuredAuditSchemaTest` beweist sie gegen ein echtes MySQL.

## Test- und Dokumentationsfundstellen

Der Produktionsscan schließt Tests absichtlich aus. Testcode mit echten
Auditaufrufen wurde mitmigriert:
`tests/e2e/specs/system-status-ampel.spec.js` erzeugt seine synthetische
Denial-Zeile jetzt über `audit_event()` mit
`VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_DENIED`, und
`tests/e2e/specs/mission-transfer.spec.js` findet den Import über seine
strukturierten Spalten statt über einen `LIKE` auf der Prosa: Die Prosa wird jetzt
aus diesen Spalten gerendert, ein `LIKE` darauf würde also den Wortlaut des
Presenters prüfen und behaupten, er prüfe, dass der Import auditiert wurde.
Direkte SQL-Inserts in Retention-/Legacyanzeige-Tests bleiben zulässig, wenn und
nur wenn sie eine historische Zeile mit sämtlichen neuen strukturierten Spalten
`NULL` beweisen; `LogCsvExportTest` nutzt sie zusätzlich als Massenfixture für
die Kappungsgrenzen, weil dort der Leser das Prüfobjekt ist.

`MachineApiAuditThrottleTest` fuhr den alten Freitextsink; er fährt jetzt den
strukturierten und prüft zusätzlich den Kontrast zwischen einer
IP-Allowlist-Ablehnung und einem abgewiesenen MAC-Callback.
`PhaseCContractTest` verlangte den direkten `machine_api_log_warning()`-Aufruf
namentlich, was `mecm_updateid.php` gezwungen hätte, neben seiner strukturierten
Zeile eine zweite, redundante zu behalten; er verlangt jetzt eine der beiden
Eintrittsstellen und pinnt getrennt, dass die indirekte wirklich eine Zeile
schreibt.

## Abschlussnachweis 2026-08-26

Der Produktionsscan des endgültigen Stands umfasst 231 PHP-Dateien, 105
Producer-Aufrufe und 58 Registryereignisse. Der Audit-Contract ist
nicht-vakuos: jeder Producer ist registriert, jedes Ereignis besitzt mindestens
einen Producer, nur `lib/repo/log.php` schreibt strukturierte Auditereignisse,
und die Mutationsprobe überschreibt den echten Migrationseinstieg. Der
vollständige Guard-Harness beweist 102 positive, negative und Zero-Match-Fälle
bei 0 unproven und 0 Infrastrukturfehlern.

Die reale MySQL-Integration prüft den CSV-Vertrag mit 0, 1, 9.999, 10.000 und
10.001 Treffern. In allen fünf Fällen entsteht genau ein Ereignis
`logs.csv_exported`; `total_rows`, `rows_exported`, `limit` und `truncated`
beschreiben dieselbe Filtermenge und die feste Obergrenze 10.000. Die
Exportzeile wird vor dem ersten Streambyte persistiert. Migration 0044 und
Frischschema verwenden dieselbe statische CHECK-Bedingung mit
`JSON_TYPE(context_json) = _utf8mb4''OBJECT''`; der Frischlauf bestätigt die
Schema-Konvergenz.

Kanonischer QA-Abschluss: Fast `29/29 pass`; Integration `36/36 pass`, jeweils
0 Failures, 0 Infrastrukturfehler, 0 Not-applicable und 0 Skips. Darin:
Unit/Static 1.198 Tests und 25.545 Assertions, Voll-PHPUnit 1.530 Tests und
28.883 Assertions, Chromium 221 Tests, `migrate --check` mit `pending=0`,
Schema-Konvergenz, Health-/Exposure-Vertrag und Guard-Harness grün. Artefakte:
`qa-artifacts/qa-e10c-fast-final.json` und
`qa-artifacts/qa-e10c-integration-final.json`; sie bleiben ignorierte
Arbeitsartefakte und gehören nicht in den Commit.

Vor dem Teardown liefen `deploy-worker` und `maintenance-worker` beide mit
`status=running`, `health=healthy`, `restarts=0` und `exit=0`. Ihre Logs
enthielten ausschließlich den erwarteten Reaper-Holdoff nach dem initialen
Verbindungsfenster. Contract-, Drift- und i18n-Review meldeten keinen Blocker;
der Machine-API-Wirevertrag, die fünf Statusstrings, `updated`, `mecm_id`, der
POST-only Client-ACK und der display-only Reportkanal bleiben unverändert.
