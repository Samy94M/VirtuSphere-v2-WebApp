# Etappe 14: Formular-Migrationsmatrix

Erfasst am 2026-08-31 vor der ARIA-/Hint-Migration, abgeschlossen am selben
Tag. Diese Matrix erfasst Controls,
denen der Server einen Feldfehler zuordnet, sowie Feld- und Gruppenhinweise.
Reine Seiten-, Status-, Retention-, Tabellen- und Aktionsprosa ist ausdrücklich
nicht Teil der Matrix. `keine` in den ID-Spalten bezeichnet den Ist-Zustand vor
Etappe 14, nicht das Migrationsziel.

Zielkonvention:

- Control-ID, Hint-ID und Error-ID kommen ausschließlich aus `lib/forms.php`.
- Feldhints hängen am Control, Gruppenhints an `fieldset` oder `role="group"`.
- `aria-describedby` nennt nur wirklich gerenderte Hint-/Error-IDs.
- `aria-invalid` sitzt ausschließlich am fehlerhaften Control.
- Zeilenkennungen (`row-<id>`, Interface-/Diskindex und `__INDEX__`) sind Teil
  der generierten ID; das JS ersetzt einen Templateindex in Name und ID.

Abschlussstand: Sämtliche unten mit dem historischen Erfassungsstatus `offen`
geführten Zeilen sind migriert. Die Spalte bleibt bewusst als Vorher-Nachweis
stehen; die Abnahme und ihre Negativbeweise stehen in Abschnitt E.

## A. Fehlerfähige Controls

| Owner/Seite | Formular | Feld | Ist-ID/Label | Ist-Error-ID | Zeile/dynamisch | Migration | Status bei Erfassung |
|---|---|---|---|---|---|---|---|
| `lib/credentials_panels.php` / Credentials | `create` | `type` | Wrap-Label | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `create` | `name` | Wrap-Label Name | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `create` | `host` | Wrap-Label Host | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `create` | `port` | Wrap-Label Port | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `create` | `username` | Wrap-Label Benutzername | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `create` | `secret` | Wrap-Label Kennwort | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `create` | `esxi_cert_kind` | Wrap-Label Zertifikatsart | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `create` | `esxi_certificate_pem` | Wrap-Label Zertifikat | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `row-<id>` | `type` | Wrap-Label | keine | Serverzeile | ID enthält Formularzeile | offen |
| gleich | `row-<id>` | `name` | Wrap-Label Name | keine | Serverzeile | ID enthält Formularzeile | offen |
| gleich | `row-<id>` | `host` | Wrap-Label Host | keine | Serverzeile | ID enthält Formularzeile | offen |
| gleich | `row-<id>` | `port` | Wrap-Label Port | keine | Serverzeile | ID enthält Formularzeile | offen |
| gleich | `row-<id>` | `username` | Wrap-Label Benutzername | keine | Serverzeile | ID enthält Formularzeile | offen |
| gleich | `row-<id>` | `secret` | Wrap-Label Kennwort | keine | Serverzeile | ID enthält Formularzeile | offen |
| gleich | `row-<id>` | `esxi_cert_kind` | Wrap-Label Zertifikatsart | keine | Serverzeile | ID enthält Formularzeile | offen |
| gleich | `row-<id>` | `esxi_certificate_pem` | Wrap-Label Zertifikat | keine | Serverzeile | ID enthält Formularzeile | offen |
| `portal/mission_details.php` | `update` | `mission_name` | Wrap-Label Name | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `update` | `hypervisor_datastorage` | Inventory-Control/Wrap-Label | keine | nein | Inventory-Control erhält API-Attribute | offen |
| gleich | `update` | `hypervisor_datacenter` | Inventory-Control/Wrap-Label | keine | bedingt sichtbar | Inventory-Control erhält API-Attribute | offen |
| gleich | `update` | `domain` | Wrap-Label Domäne | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `update` | `mission_notes` | Wrap-Label Notizen | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `update` | `autostart_start_delay` | Wrap-Label Startverzögerung | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `update` | `autostart_stop_delay` | Wrap-Label Stoppverzögerung | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `update` | `autostart_stop_action` | Wrap-Label Stoppaktion | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `clone` | `target_mission_name` | Wrap-Label Zielmission | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `save_template` | `target_template_name` | Wrap-Label Template | keine | nein | Controlattribute + stabile Error-ID | offen |
| `portal/missions.php` | `create` | `mission_name` | Wrap-Label Name | keine | nein | Controlattribute + stabile Error-ID | offen |
| `lib/deploy_queue_panel.php` / Deploy | `schedule` | `credential_esxi_id` | Wrap-Label ESXi-Zugang | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `schedule` | `scheduled_at` | Wrap-Label Zeitpunkt | keine | bedingt sichtbar | Controlattribute + stabile Error-ID | offen |
| gleich | `schedule` | `stagger_minutes` | Wrap-Label Staffelung | keine | dynamischer Lock | Hint und Fehler gemeinsam beschreiben | offen |
| `lib/settings/catalog_panel.php` / Settings | `retire` | `retire_threshold` | Wrap-Label Schwelle | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `esxi` | `esxi_inventory_interval_hours` | Wrap-Label Intervall | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `esxi` | `esxi_inventory_ansible_credential_id` | Wrap-Label Ansible-Zugang | keine | nein | Controlattribute + stabile Error-ID | offen |
| `lib/settings/deploy_panel.php` / Settings | `settings` | `api_base_url` | `api-base-url`/explizites Label | keine | nein | generierte ID, Hint und Fehler | offen |
| `lib/settings/system_panel.php` / Settings | `timezone` | `timezone` | Wrap-Label Zeitzone | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `session` | `session_lifetime_minutes` | Wrap-Label Sitzungsdauer | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `password_policy` | `password_min_length` | Wrap-Label Mindestlänge | keine | nein | Controlattribute + stabile Error-ID | offen |
| `lib/settings/https_panel.php` / Settings | `https_upload` | `cert_file` | Wrap-Label Zertifikat | keine | nein | Upload-Gruppenhint + Error-ID | offen |
| gleich | `https_upload` | `key_file` | Wrap-Label Schlüssel | keine | nein | Upload-Gruppenhint + Error-ID | offen |
| gleich | `https_upload` | `pfx_password` | Wrap-Label PFX-Kennwort | keine | nein | Upload-Gruppenhint + Error-ID | offen |
| `lib/settings/machine_api_panel.php` / Settings | `allowlist` | `ip_address` | Wrap-Label IP-Adresse | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `allowlist` | `description` | Wrap-Label Beschreibung | keine | nein | Controlattribute + stabile Error-ID | offen |
| `lib/system_status_esxi_panels.php` / Systemstatus | `vlan_reassign` | `vlan_from` | Wrap-Label Quelle | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `vlan_reassign` | `vlan_to` | Wrap-Label Ziel | keine | nein | Controlattribute + stabile Error-ID | offen |
| `lib/users_accounts_panels.php` / Benutzer | `create` | `name` | Wrap-Label Name | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `create` | `email` | Wrap-Label E-Mail | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `create` | `password` | `create-password`/explizites Label | keine | nein | generierte ID, Hint und Fehler | offen |
| gleich | `row-<id>` | `password` | `reset-password-<id>`/explizites Label | keine | Serverzeile | generierte Zeilen-ID, Hint und Fehler | offen |
| `lib/users_directory_panels.php` / Benutzer | `directory` | `bind_upn` | Wrap-Label Bind-UPN | keine | nein | Controlattribute + stabile Error-ID | offen |
| gleich | `directory` | `bind_password` | Wrap-Label Bind-Kennwort | keine | nein | Feldhint + Error-ID | offen |
| gleich | `directory` | `ca_certificate_pem` | Wrap-Label CA-Zertifikat | keine | nein | Feldhint + Error-ID | offen |
| gleich | `directory` | `user_search_base_dn` | Wrap-Label Suchbasis | keine | nein | Feldhint + Error-ID | offen |
| gleich | `directory` | `controller_id` | Wrap-Label Testcontroller | keine | nein | Controlattribute + stabile Error-ID | offen |
| `lib/vm_edit_panels.php` / VM-Editor | `vm_edit` | `vm_name` | Wrap-Label Name | keine | nein | API mit direktem Validationfehler | offen |
| gleich | `vm_edit` | `vm_hostname` | Wrap-Label Hostname | keine | Legacywarnung bedingt | Fehler und Warnhinweis getrennt referenzieren | offen |
| gleich | `vm_edit` | `vm_domain` | Wrap-Label Domäne | keine | nein | API mit direktem Validationfehler | offen |
| gleich | `vm_edit` | `vm_os` | Wrap-Label Betriebssystem | keine | nein | API mit direktem Validationfehler | offen |
| gleich | `vm_edit` | `vm_ram` | Compound-Control/Wrap-Label | keine | Preset-JS | Texteingabe ist Fehlerziel, Picker bleibt beschriftet | offen |
| gleich | `vm_edit` | `vm_cpu` | Wrap-Label CPU | keine | nein | API mit direktem Validationfehler | offen |
| gleich | `vm_edit` | `vm_guest_id` | Wrap-Label Gasttyp | keine | nein | API mit direktem Validationfehler | offen |
| gleich | `vm_edit` | `autostart_start_delay` | Wrap-Label Startverzögerung | keine | Mission-Lock | Gruppenhint plus Error-ID | offen |
| gleich | `vm_edit` | `autostart_stop_delay` | Wrap-Label Stoppverzögerung | keine | Mission-Lock | Gruppenhint plus Error-ID | offen |

## B. Feld- und Gruppenhinweise

| Owner/Seite | Control oder Gruppe | Art | Ist-Hint-ID | Dynamik | Migration | Status bei Erfassung |
|---|---|---|---|---|---|---|
| `portal/account.php` | `new_password`, `confirm_password` | gemeinsamer Feldgruppenhint | `account-password-hint` | nein | beide Controls referenzieren API-Hint-ID der Gruppe | offen |
| `lib/users_accounts_panels.php` | `create/password` | Feldhint | `create-password-hint` | nein | API-Hint-ID | offen |
| gleich | `row-<id>/password` | Feldhint | `reset-password-<id>-hint` | Serverzeile | API-Zeilen-/Hint-ID | offen |
| `lib/users_directory_panels.php` | `bind_password` | Feldhint | keine | Inhalt hängt von Bestand ab | API-Hint-ID | offen |
| gleich | `ca_certificate_pem` | Feldhint | keine | Inhalt hängt von Bestand ab | API-Hint-ID | offen |
| gleich | `user_search_base_dn` | Feldhint | keine | nein | API-Hint-ID | offen |
| `lib/settings/catalog_panel.php` | `retire_threshold` | Feldhint | keine | nein | API-Hint-ID | offen |
| gleich | ESXi-Inventarcontrols | Gruppenhint | keine | nein | `role="group"` + API-Hint-ID | offen |
| `lib/settings/deploy_panel.php` | `api_base_url` | Feldhint | keine | nein | API-Hint-ID | offen |
| `lib/settings/https_panel.php` | Zertifikat/Schlüssel/PFX | Upload-Gruppenhint | keine | nein | `fieldset` + API-Hint-ID | offen |
| `lib/settings/system_panel.php` | Zeitzone, Sitzung, Kennwortpolicy | je Feld-/Formhinweis | keine | nein | API-Hint-ID am jeweiligen Control | offen |
| `lib/system_status_esxi_panels.php` | VLAN-Neuzuordnung | Gruppenhint | keine | bedingt sichtbar | `role="group"` + API-Hint-ID | offen |
| `portal/mission_details.php` | Datastore/Datacenter | Gruppenhint | keine | Inventarnotizen bedingt | `role="group"`, eine stabile Hint-ID | offen |
| gleich | Autostartschalter | Gruppenhint | keine | Mission/Template | `role="group"` + API-Hint-ID | offen |
| gleich | beide Autostartverzögerungen | Gruppenhint | keine | nein | beide Controls referenzieren Gruppen-Hint-ID | offen |
| `lib/deploy_queue_panel.php` | Modus/Staffelung | dynamischer Feldgruppenhint | keine | `data-stagger-lock` | JS pflegt Hint-ID in `aria-describedby` | offen |
| gleich | `powercycle_wait` | dynamischer Feldhint | keine | `data-powercycle-lock` | JS pflegt Hint-ID | offen |
| gleich | `start_wait` | statischer + dynamischer Feldhint | keine | `data-start-wait-lock` | beide IDs, JS entfernt/ergänzt dynamische ID | offen |
| gleich | Verbose | Gruppenhint | keine | nein | `role="group"` + API-Hint-ID | offen |
| gleich | VM-Auswahl | Gruppenhint | keine | Mission/Leerzustand | `role="group"` + stabile Hint-ID | offen |
| gleich | Storage | Gruppenhint | keine | Mission/Live-Update | `role="group"` + API-Hint-ID | offen |
| gleich | Staffelung | Feldhint | keine | Modus lockt Control | API-Hint-ID plus Error-ID | offen |
| gleich | Zeitplanung | Gruppenhint | keine | Sofort/Geplant | `role="group"` + API-Hint-ID | offen |
| `lib/vm_edit_panels.php` | Standortcontrols | Gruppenhint | keine | Datacenter/Inventarnotizen bedingt | `role="group"` + stabile Hint-ID | offen |
| gleich | Hotplug | Gruppenhint | keine | nein | `role="group"` + API-Hint-ID | offen |
| gleich | Autostart | Gruppenhint | keine | Mission schaltet Text/Lock | `role="group"` + stabile Hint-ID | offen |
| `lib/vm_edit_rows.php` | Interfaces | Gruppenhint Gateway | keine | Zeilen per JS | Panelgruppe referenziert API-Hint-ID | offen |
| gleich | Disks | Gruppenhint Typ | keine | Zeilen per JS | Panelgruppe referenziert API-Hint-ID | offen |

## C. Wiederholte Controls ohne eigenen Hint/Fehler

Die Interfacefelder `id`, `ip`, `subnet`, `gateway`, `dns1`, `dns2`, `vlan`,
`mode`, `type`, `mac` und die Diskfelder `disk_name`, `disk_size`, `disk_type`
werden serverseitig und im `<template>` erzeugt. Jedes sichtbare Control erhält
eine aus Form, Feld und Zeilenindex gebildete ID. `__INDEX__` bleibt im Template
bis `forms.js` beim Einfügen Namen und IDs vollständig ersetzt. Zeilenfehler
werden aktuell als Gruppenfehler im Alert ausgegeben; die Etappe erfindet keine
zweite, vom Validator nicht gelieferte Feldzuordnung.

## D. Bewusst ausgeschlossene allgemeine Prosa

Ausgeschlossen sind unter anderem Seitenintro, Queue-Erwartung/Blockerliste,
Preview- und Storage-Erklärung außerhalb der Queueform, Retention-/CSV-/Log-
Hinweise, Tabellenleerzustände, Status-/Diagnoseprosa, Paket-Upgradehinweise,
Missions-Exporthinweis, Directory-Einrichtungs-/Such-/Controllertexte und
Settings-Metadaten. Sie erklären Seite, Ablauf oder Bestand und nicht die
Eingabe eines einzelnen Controls beziehungsweise einer Controlgruppe.

Die API-URL-Resetzeile und die drei HTTPS-Umschalter sind ebenfalls bewusst
ausgeschlossen: Sie sind bestätigte Aktionsbuttons mit erklärender Aktionsprosa,
keine Checkbox- oder Feldeingaben. Die Ersterfassung hatte sie fälschlich als
Checkboxgruppen einsortiert; die Korrektur erfolgte vor der Implementierung.

## E. Abschluss und Negativnachweise

- `lib/forms.php` ist alleiniger Owner stabiler Control-, Hint- und Error-IDs;
  `form_input_class()` ist entfernt und `vm_field_error()` delegiert an
  `form_error_html()`.
- `FormAccessibilityTest` pinnt Normalisierung, Zeilenscope, Template-Marker,
  mehrere Hints, Fehlerverkettung und HTML-Escaping.
- `FormAccessibilityContractTest` scannt alle Matrix-Owner, verbietet die alte
  API und handgeschriebene `aria-describedby`-/Fehlerausgabe und hält den
  monotonen `__INDEX__`-Ersatz fest.
- Der DOM-Check in `accessibility.spec.js` läuft über jede Portalseite in Hell
  und Dunkel und verwirft doppelte IDs, tote Referenzen sowie sichtbare Hint-
  oder Error-IDs ohne eindeutigen Owner. Inaktive dynamische Hints dürfen erst
  dann unreferenziert sein, wenn sie wirklich `hidden` sind.
- `form-accessibility.spec.js` beweist Serverfehler, die Browser-
  Accessibility-Beschreibung, zwei per Tastatur erzeugte Interface- und
  Diskzeilen sowie die Live-Verknüpfung der drei Deploy-Lock-Hints.
- Die kanonische Fast-Lane bestand 29/29 Gates. Der vollständige
  Integrationlauf bestand 35/36 Gates; allein der historische
  Passwort-ID-Selektor in `etappe12-ux.spec.js` erwartete noch die manuelle
  Vor-Etappe-14-ID. Nach seiner Umstellung auf die von `form_element_id()`
  erzeugte stabile ID bestand das vollständig wiederholte `e2e-portal`-Gate:
  241/241 Chromium-Fälle sowie zwei identische Läufe je Theme (4/4) sind grün.
  Damit ist die aktuelle Abnahmemenge aller 36 Integration-Gates grün; die
  Änderung zwischen Komplett- und Wiederholungslauf betraf ausschließlich den
  E2E-Selektor des vollständig wiederholten Gates.
- Audit-SSoT, persistierte Joblogs, Container-/Health-Vertrag, Datenbankschema
  und Machine-API-Wire sind fachlich nicht betroffen: Etappe 14 ändert
  Portal-Markup, Formularhelfer und Portal-JavaScript, führt keine Migration
  und keinen Maschinenendpunkt ein. `audit-contract`, Schema-Konvergenz,
  Health-/Exposure-Vertrag und die vollständige PHPUnit-Suite sind im selben
  Integrationlauf grün.
