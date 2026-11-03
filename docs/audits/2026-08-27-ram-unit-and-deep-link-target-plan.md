# Umsetzungsplan: RAM-Eingabe mit Einheit und markiertes Deep-Link-Ziel

Stand: 27.08.2026

Status am 08.09.2026: Teile B und A im Arbeitsbaum implementiert. Native Parser-, Formular- und Vertragsprüfungen vorhanden. Browser-/No-JavaScript-Abnahme und Visual-Gate noch offen, weil Docker Desktop nicht startet. Keine Baseline aktualisiert. Der folgende Plan bleibt die fachliche Spezifikation; jüngere Modulpfade sind im Abschlussnachtrag genannt.

## 0. Geltung, Reihenfolge und Vorbedingungen

Die beiden Vorhaben teilen nur ihren Fundort. Sie werden getrennt umgesetzt,
abgenommen, committet und bei Bedarf zurückgerollt:

1. Teil B behebt zuerst die bestehende, kleine Deep-Link-Regression.
2. Teil A erweitert danach die RAM-Eingabe, ohne den gespeicherten MB-Vertrag zu
   ändern.

Vor Teil A wird der aktuelle Arbeitsbaum neu gegen den Dateigrößenratchet
gemessen. `portal/vm_edit.php` liegt durch parallel vorhandene Änderungen
bereits über seiner eingetragenen 520-Zeilen-Grenze. Die RAM-Arbeit darf die
Allowance nicht erhöhen. Sie extrahiert RAM-Normalisierung und -Rendering in
fokussierte Module und bringt die Seite mindestens auf ihre bestehende Grenze
zurück. Fremde Änderungen in `defaults.php`, `vm_edit_form.php`,
`forms.js` und `vm_edit.php` werden erhalten und in den neuen Schnitt
integriert.

Nicht Teil dieses Plans:

- Datenträgergrößen. Sie bleiben in GB.
- Der gemessene Hostspeicher aus dem ESXi-Inventar
  (`ansible_inventory_capability.php`, Feld `ram_mb`).
- Eine Änderung des Fragmentabstands. Er bleibt bei 92 px.
- Eine Schemaänderung von `deploy_vms.vm_ram`. Der bestehende VARCHAR ist
  technisch nicht ideal, sein Umbau wäre aber eine eigene Migration mit
  Import-/Legacy-Folgen.

Kurze Pfade mit `lib/`, `portal/`, `lang/`, `tests/Unit`, `tests/Static` oder
`tests/Support` beziehen sich in diesem Plan auf `Docker/WebAPI/`. Alle anderen
Pfade sind relativ zum Repository-Root angegeben.

---

# Teil A: RAM-Eingabe mit Einheitenwahl

## A.1 Ist-Zustand

Das Feld ist heute ein Verbund aus freiem Zahlenfeld in MB und einer
Auswahlliste fester GB-Presets in `portal/vm_edit.php`. Die Presets kommen aus
`VIRTUSPHERE_RAM_PRESETS_MB` in `lib/defaults.php`; die Anzeige teilt derzeit
direkt durch 1024. `portal/assets/forms.js` verbindet beide über
`data-combo-input` und `data-combo-picker`.

Gespeichert wird eine MB-Zahl als `varchar(255)` in `deploy_vms.vm_ram`.
`repo_validate_vm_payload()` validiert sie mit `intRange()` gegen
`VIRTUSPHERE_VM_LIMITS['ram_mb_min'|'ram_mb_max']` von 128 bis 1048576.
`lib/ansible_yaml.php` schreibt denselben ganzzahligen MB-Wert als `memory:`
in die Playbook-Konfiguration.

Der Portalfehlerpfad hält Formularwerte über
`$vm = array_merge($vm ?? [], $_POST)` sticky. `$inlineVmFields` hält keine
Werte fest; es verhindert nur, dass ein bereits am Feld gerenderter Fehler
nochmals in der allgemeinen Fehlerliste erscheint.

## A.2 Geschlossene Entscheidungen

1. **Der Datenvertrag bleibt MB.** Datenbank, Repo-Schicht, Missionstransfer,
   Machine-API, Audit-Diff und Ansible-YAML sehen ausschließlich ganze MB.
2. **Die Einheit ist Portalzustand.** `vm_ram_unit` wird weder gespeichert noch
   exportiert noch als Auditkontext registriert.
3. **Umschalten rechnet um.** 4096 MB werden 4 GB; die Zahl wird nicht
   unverändert unter ein anderes Einheitenlabel gestellt.
4. **Der Server ist autoritativ.** JavaScript verbessert die Bedienung, aber der
   POST-Pfad validiert und rechnet selbst.
5. **MB akzeptiert nur ganze Dezimalziffern.** Kein Vorzeichen, Exponent,
   Gruppierungszeichen oder Dezimaltrenner.
6. **GB akzeptiert bis zu zehn Nachkommastellen.** Punkt und Komma werden
   akzeptiert, intern auf Punkt normalisiert. Erlaubt ist
   `^[0-9]+(?:[.,][0-9]{1,10})?$`; `.5`, `1.`, Exponenten, Vorzeichen und
   Gruppierungszeichen werden abgelehnt. Der getrimmte Rohwert ist in beiden
   Einheiten auf 16 Zeichen begrenzt; längere Eingaben sind ein geordneter
   Zahlenfehler.
7. **Grenzen werden vor der Rundung in der getippten Einheit geprüft.**
   0,1249 GB bleibt zu klein und 1024,0001 GB zu groß, auch wenn eine spätere
   Rundung einen erlaubten MB-Wert ergeben könnte.
8. **Nicht-ganzzahlige GB-Ergebnisse werden half-up auf den nächsten MB
   gerundet.** Die Berechnung arbeitet über Dezimalziffern und ganzzahligen
   Zähler/Nenner, nicht über unbeschränkte Float-Casts. Bei maximal zehn
   Nachkommastellen bleiben auch Browser-Zwischenwerte im sicheren
   Ganzzahlbereich.
9. **Leere Eingaben sind im Portal ein Fehler.** Das bisherige stille Zurückfallen
   auf 4096 MB bleibt nur in der unveränderten Repo-Schicht für Legacy-Aufrufer,
   nicht im Editor.
10. **Eine wirklich fehlende Einheit bedeutet MB.** Das erhält alte Portal-POSTs.
    Ein vorhandener nicht-skalarer oder unbekannter Wert wird dagegen
    abgelehnt.
11. **Die Anfangsanzeige ist deterministisch und kompakt.** Werte unter 1024 MB
    öffnen in MB. Ab 1024 MB wird GB verwendet, wenn der exakte Wert mit
    höchstens drei Nachkommastellen darstellbar ist. Damit werden 4096 MB als
    4 GB und 1536 MB als 1.5 GB angezeigt; 1331 MB bleiben 1331 MB.
12. **Technische Größen verwenden in der gerenderten Zahl einen Punkt.** Ein
    deutsches Komma wird bei Eingabe trotzdem akzeptiert. So bleibt die
    Darstellung konsistent mit den bestehenden technischen Größenformattern.

## A.3 SSoT und Modulgrenzen

Es gibt genau eine Faktormap:

`VIRTUSPHERE_RAM_INPUT_FACTORS_MB = ['mb' => 1, 'gb' => 1024]`

Sie liegt bei den VM-Defaults in `lib/defaults.php`. Ihre Keys sind zugleich
die erlaubten Einheitentokens. Es gibt keine zweite Einheitenliste.

Neue reine RAM-Domänenfunktionen liegen in `lib/vm_ram.php`:

- `vm_ram_parse_input(string $value, string $unit): array` liefert ein
  getaggtes Ergebnis mit `ok`, optionalem `mb` und einem Fehlercode aus
  `required|unit|number|integer|range`. Verschiedene Fehler kollabieren nicht
  auf ein bedeutungsloses `null`.
- `vm_ram_display_state(mixed $stored): array` liefert mindestens
  `value`, `unit`, `valid` und entscheidet allein über die Regel aus A.2.11.
- `vm_ram_format(mixed $stored): string` erzeugt die gemeinsame Anzeige für
  gültige Werte in der VM-Liste. Einen ungültigen Altwert gibt die Funktion
  unverändert zurück, statt ihm eine erfundene gültige Einheit anzuhängen.
- Grenzwerte in einer sichtbaren Einheit werden ausschließlich aus
  `VIRTUSPHERE_VM_LIMITS` und der Faktormap abgeleitet.

Portal-spezifische Verdrahtung liegt in `lib/vm_edit_ram.php`:

- `vm_edit_ram_from_post(array $post): int` unterscheidet fehlende Keys von
  vorhandenen Arrays/ungültigen Skalaren, übersetzt den Parse-Fehler in eine
  `ValidationException(['vm_ram' => ...])` und liefert nur bei Erfolg MB.
- `render_vm_ram_field(...)` rendert Zahl, Einheit, Preset, Fehleranker und
  den kurzen Rundungshinweis. Dadurch wächst `portal/vm_edit.php` nicht weiter.

`repo_validate_vm_payload()`, `lib/ansible_yaml.php`,
`lib/mission_transfer*.php` und die Machine-API bleiben unverändert. Ein
Einheitenparameter in einer dieser Schichten wäre ein Schnittfehler.

Die Faktormap ist kein Benutzergrenzwert und wird **nicht** in
`BOUNDS_ARRAY_KEYS` von `scripts/check-bounds-sync.php` aufgenommen. Der
Bounds-Wächter bleibt für die bestehenden Min-/Max-Konstanten zuständig.

## A.4 Verbindlicher POST- und Fehlervertrag

Der Portalpfad liest den Rohwert nicht zuerst in `$vmData`, sondern normalisiert
ihn:

| POST-Form | Ergebnis |
|---|---|
| `vm_ram=6144`, Einheit fehlt als Key | 6144 MB, Legacy-Kompatibilität |
| `vm_ram=6&vm_ram_unit=gb` | 6144 MB |
| `vm_ram=1,3&vm_ram_unit=gb` | 1331 MB, half-up |
| `vm_ram=1536&vm_ram_unit=mb` | 1536 MB |
| leeres `vm_ram` | Feldfehler, kein Speichern |
| `vm_ram[]=6` | Feldfehler, kein Default |
| `vm_ram_unit[]=gb` | Feldfehler, kein MB-Fallback |
| unbekannte Einheit, etwa `tb` | Feldfehler |
| Nachkommastelle in MB | Feldfehler |
| Zahl außerhalb der Grenzen der getippten Einheit | `validate.range_unit` |

Erst der normalisierte Integer wird als String unter
`$vmData['vm_ram']` an `repo_save_vm()` übergeben. Damit vergleicht der
bestehende Auditpfad gespeicherte MB vor und nach der Änderung.

Alle erwartbaren Eingabefehler sind `ValidationException`, keine
`RuntimeException` und kein PHP-Warning. Sie erzeugen weder Systemfehlerlog
noch erfolgreichen `vm.changed`-Eintrag.

## A.5 Anzeige- und Browservertrag

Das Feld besteht aus drei sichtbaren Controls:

1. freier Wert als `type="text"`, `inputmode="decimal"`, `required`,
   `maxlength="16"` passend zur serverseitigen Grammatik;
2. benannte Einheitenauswahl `vm_ram_unit`;
3. die bestehende Presetauswahl.

Alle drei besitzen getrennte zugängliche Namen. Der Fehler ist dem Verbund
zugeordnet und wird einmal unter dem Feld ausgegeben.

Die Faktormap und die aus den SSoTs abgeleiteten Grenzen werden über
`data-*`-Attribute gerendert. `forms.js` enthält weder den Literal-Faktor
1024 noch eine zweite Grenzliste.

Browserregeln:

- Preset „8 GB“ setzt Wert `8`, Einheit `gb` und das passende Preset
  gemeinsam.
- Freie Eingabe synchronisiert das Preset über den kanonischen MB-Wert.
- Einheitenwechsel rechnet über den Faktor am Markup um. Beim Wechsel nach MB
  wird auf ganze MB normalisiert; der Rückwechsel verwendet bis zu zehn
  Nachkommastellen und bleibt bei wiederholtem Umschalten stabil.
- Eine leere Eingabe darf die Einheit wechseln, ohne einen Wert zu erfinden.
- Eine syntaktisch ungültige Eingabe wird beim Einheitenwechsel nicht gelöscht.
  Der neue Einheitentoken bleibt sichtbar, die Vorschau zeigt den lokalisierten
  Eingabefehler und der Server lehnt den POST ab.
- Ein kurzer, auch ohne JavaScript sichtbarer Hinweis erklärt, dass GB-Werte,
  die keinen ganzen MB ergeben, auf den nächsten MB gerundet werden.
- Mit JavaScript zeigt eine `aria-live="polite"`-Vorschau den kanonischen
  MB-Wert und kennzeichnet eine tatsächliche Rundung.

`components.css` erhält für `.ram-field` drei definierte Tracks. Ein
Viewport, der den Verbund und die umgebende `.vm-form-grid` zum Umbruch
zwingt, wird geometrisch geprüft. Kein Control darf aus Panel oder Grid ragen.

## A.6 Änderungen im Einzelnen

| Datei | Änderung |
|---|---|
| `lib/defaults.php` | eine Faktormap als Einheiten-SSoT |
| `lib/vm_ram.php` | neuer reiner Parser, Anzeigestatus und Listenformatter |
| `lib/vm_edit_ram.php` | Portal-POST-Normalisierung und RAM-Feldrenderer |
| `portal/vm_edit.php` | Modul einbinden, normalisierte MB verwenden, alten Inline-Renderer entfernen; Seite nicht über die Allowance wachsen lassen |
| `portal/assets/forms.js` | Einheit, Presets, Vorschau und stabile Umrechnung aus Markup-Faktoren |
| `portal/assets/css/components.css` | dreiteiliges RAM-Feld und responsive Begrenzung |
| `portal/vms.php` | gemeinsame formatierte Tabellenanzeige; CSV bleibt roh |
| `lang/{de,en}/vm_edit.php` | `label_ram` ohne Einheit; Einheiten-, Vorschau-, Rundungs- und Aria-Texte |
| `lang/{de,en}/validate.php` | `field_ram_mb` ohne feste Einheit; `number` und `range_unit` |
| `lang/{de,en}/vms.php` | eigener CSV-Header „RAM (MB)“ |
| `tests/Unit/PortalComboHooksTest.php` | Markup/JS-Vertrag um RAM-Einheitenhooks und Faktoren erweitern |
| neue/fokussierte Unit- und Contracttests | Parser, Anzeige, POST-Shape, SSoT und Dateivertrag |

Bewusst unverändert:

- `scripts/check-bounds-sync.php`
- `scripts/check-enum-sync.sh`
- `lib/repo/vms.php`
- `lib/ansible_yaml.php`
- `lib/mission_transfer*.php`
- Machine-API-Endpunkte, Migrationen und Schema

## A.7 Edge Cases und Legacy-Werte

Pflichttestmatrix:

1. MB und GB an beiden exakten Grenzen sowie unmittelbar darunter/darüber.
2. Punkt und Komma; ein bis zehn Nachkommastellen.
3. Halbwerte, die den festgelegten half-up-Zweig beweisen.
4. Leerer String, nur Whitespace, `.5`, `1.`, `+1`, `-1`, `1e3`,
   `NaN`, `INF`, Gruppierungszeichen und mehr als zehn Nachkommastellen.
5. Sehr lange Ziffernfolge: geordneter Feldfehler, kein Overflow und kein
   PHP-Warning.
6. Fehlender Unit-Key im Unterschied zu vorhandenem Array- oder unbekanntem
   Wert.
7. Validierungsfehler an einem anderen Feld: Zahl und bekannte Einheit bleiben
   exakt sticky; die Anzeigeheuristik überschreibt den POST nicht.
8. 1536 MB öffnen als 1.5 GB und wechseln verlustfrei zurück.
9. 1,3 GB werden als 1331 MB gespeichert; Reload zeigt 1331 MB, und der
   Rundungshinweis hat das vor dem Speichern erklärt.
10. Wiederholtes MB/GB-Umschalten akkumuliert keine Rundungsfehler.
11. Presetwahl aus stehendem MB-Modus setzt auch die Einheit auf GB.
12. JavaScript deaktiviert: 6 GB werden serverseitig zu 6144 MB.
13. Alter gespeicherter nichtnumerischer oder außerhalb der Grenze liegender
    VARCHAR-Inhalt wird nicht auf 0 oder GB gecastet. Er bleibt sichtbar in MB,
    wird als ungültiger Altwert gekennzeichnet und muss vor dem Speichern
    korrigiert werden.
14. Vorlagen, Missionskopien und Missionstransfer behalten rohe MB.

## A.8 Fehlermeldungen, Hilfe und Troubleshooting

Die neue Meldung `validate.range_unit` erhält
`:field`, `:min`, `:max` und `:unit`. Alle Zahlen werden am Aufrufort aus
`VIRTUSPHERE_VM_LIMITS` und der Faktormap abgeleitet. Keine Grenze wird in
einen Katalog geschrieben.

`validate.number` erklärt die erlaubte Dezimalzahl; die bestehende
`validate.integer` bleibt für MB und andere Ganzzahlfelder. Die unbekannte
Einheit verwendet einen lokalisierten Feldfehler am RAM-Verbund, nicht einen
generischen Seitenfehler.

Die sichtbare Einheit, der kurze Rundungshinweis und die Live-Vorschau reichen
als Feldhilfe. `help_missions.php` muss keinen weiteren Absatz bekommen; sein
Hot-Add-Abschnitt bleibt unverändert.

`docs/operations/troubleshooting.md` erhält eine kompakte Diagnosekette:

`6 GB im Editor` -> `6144 in deploy_vms.vm_ram` ->
`vm_ram 6144 im Audit-Diff` -> `memory: 6144 in der Ansible-Konfiguration`.

Sie trennt drei Fehlerklassen:

- falsche Anzeige bei korrektem DB-Wert,
- falsche Portalnormalisierung vor dem Speichern,
- korrekter DB-/YAML-Wert, aber abweichender ESXi-Istzustand.

Es wird nicht behauptet, ESXi runde Speicher automatisch auf ein bestimmtes
Vielfaches. Der unterstützte Vertrag endet beim ganzzahligen MB-Wert des
Ansible-Moduls und der vSphere-API.

## A.9 Audit, Protokolle und Logs

`VIRTUSPHERE_AUDIT_EVENT_VM_CHANGED` bleibt unverändert. Die Eingabeeinheit
ist kein Auditfeld. Bei einer Änderung von 4096 MB auf eingegebene 6 GB lautet
der gespeicherte Diff sinngemäß
`vm_ram: "4096" -> "6144"`, niemals `4096 -> 6`.

Ein erfolgreicher Save erzeugt weiterhin genau den bestehenden
`vm.changed`-Eintrag. Ein Validierungsfehler erzeugt keinen erfolgreichen
VM-Change-Eintrag und keinen neuen System-/Fehlerlogpfad. Es entstehen keine
Joblog-, Containerlog-, Machine-API- oder Retentionänderungen.

## A.10 VM-Liste und CSV

Die bisher als Nebenbefund geführte Einheitenanzeige wird zusammen mit dem
Editor umgesetzt:

- Tabelle: `4 GB`, `1.5 GB`, `1331 MB` aus `vm_ram_format()`.
- Ungültiger Altwert in der Tabelle: unveränderter Rohwert, keine erfundene
  Einheit; der Editor übernimmt die Korrekturführung aus A.7.
- Sortierung: weiterhin numerisch über den rohen `vm_ram`-Wert.
- CSV-Daten: weiterhin die rohe MB-Zahl.
- CSV-Header: eigener lokalisierter Schlüssel „RAM (MB)“, damit ein
  maschinenlesbarer Rohwert nicht unter einer einheitenlosen Überschrift steht.

## A.11 Drift- und Testvertrag

Unit:

- `vm_ram_parse_input()` über die vollständige Matrix aus A.7.
- `vm_ram_display_state()`: 4096 -> 4 GB, 1536 -> 1.5 GB,
  128 -> 128 MB, 1331 -> 1331 MB, ungültiger Altwert bleibt ungültig.
- POST-Shape: fehlender Unit-Key gegen Unit-Array/unbekannten Wert.
- Faktormap besitzt genau `mb` und `gb`; Parser, Renderer und Presets leiten
  daraus ab.

Static/Contract:

- `PortalComboHooksTest` beweist alle neuen Markup/JS-Hooks in beide
  Richtungen.
- `forms.js` enthält keinen eigenen RAM-Faktor oder eigene RAM-Grenzen.
- `portal/vm_edit.php` ruft den Portalnormalisierer auf und übergibt nur dessen
  MB-Ergebnis.
- Dateigrößenratchet: keine erhöhte Allowance; neue PHP-Module bleiben unter
  400 Zeilen.

Integration/E2E:

- Save von 6 GB speichert 6144 und auditiert 6144.
- POST ohne Unit-Key speichert einen gültigen MB-Wert unverändert.
- Unbekannte/nicht-skalare Einheit und leeres RAM ändern DB und Audit nicht.
- Ein anderer Validierungsfehler spielt Zahl und Einheit zurück.
- Preset, Wechsel, Komma, Rundung, Reload und No-JavaScript-Pfad.
- Tabellenanzeige, numerische Sortierung und CSV-Header/Rohwert.
- Responsive Geometrie und zugängliche Namen.

Unverändert grün bleiben insbesondere:

- `MissionTransferRoundTripTest`
- `VmIdentityCollisionTest`
- `AnsibleHotplugYamlTest`
- `AnsiblePlaybookVariableContractTest`
- `AnsibleServerlistYamlSafetyTest`
- Machine-API-Wire- und Mission-Import-Verträge

---

# Teil B: Das Ziel eines Deep-Links markieren

## B.1 Befund

Der Fragment-Sprung selbst besitzt bereits 92 px `scroll-margin-top` und wird
nicht geändert. Der Defekt liegt in der Markierung:

`components.css` beschreibt im Kommentar eine adressierte Zeile oder Karte,
stylt aber nur `tr:target`. Die tatsächlichen dynamischen Ziele sind:

- ESXi: `<article class="inventory-card" id="credential-<id>">`
- Ansible: `<article class="status-row" id="credential-<id>">`

Die einzige Tabellenzeile mit ID ist der versteckte Zugangsdateneditor. Kein
Produktlink springt dorthin. `tr:target` ist daher toter Code. Der eigentliche
Anlass ist der Ansible-Link, dessen `.status-row` heute vollständig
unmarkiert bleibt.

Frühere manuelle Messungen sind nur Befundhilfe, kein reproduzierbarer
Abnahmenachweis. Die Abnahme verwendet definierte Playwright-Viewports und beide
Themes statt einer nicht vollständig beschriebenen 21-Messungen-Matrix.

## B.2 Geschlossene Umsetzung

Beide dynamischen Zielartikel erhalten das semantische Attribut
`data-deep-link-target`. Die Markierung hängt damit an ihrer Rolle als
Fragmentziel und nicht an einer Markup-Klasse, die bei einem späteren Umbau
wechseln kann.

`status.css` erhält:

- `[data-deep-link-target]:target` als gemeinsame Regel;
- einen opaken Farbton aus `var(--accent)` und `var(--surface-muted)`, nicht
  aus Accent und Transparent;
- einen dezenten Inset-Ring, der den bestehenden Kartenrahmen nicht
  überschreibt;
- keine Animation und kein zeitgesteuertes Aufblitzen.

`components.css` verliert den falschen Kommentar sowie die toten
`tr:target > td`- und `tr:target`-Regeln. Der 92-px-Abstand bleibt in der
bestehenden Zielgruppe in `status.css`.

## B.3 Edge Cases

1. Die geöffnete ESXi-Karte behält `inventory-card-open` und ihren
   Accentrumrand. Hintergrundtönung und Inset-Ring überschreiben ihn nicht.
2. Die Ansible-`.status-row` wird im Browser genauso geprüft wie die
   ESXi-`.inventory-card`; sie ist der ursprüngliche Defekt.
3. Helles und dunkles Theme verwenden dieselben Tokens und müssen beide einen
   sichtbaren Unterschied zeigen.
4. Die Markierung bleibt, solange der Hash das Ziel benennt.
5. „Details schließen“ wechselt auf den Abschnittsanker; danach entspricht der
   Kartenhintergrund wieder seinem Zustand vor dem Ziel-Hash.
6. Der Test vergleicht dasselbe Element vor und nach dem Fragmentwechsel. Er
   hängt nicht von einer möglicherweise fehlenden Nachbarkarte ab.
7. Zwei Elemente mit derselben Credential-ID können nicht gleichzeitig
   entstehen: die ID ist eindeutig und ein Credential besitzt genau einen Typ.
8. Ein unbekannter/staler dynamischer Hash bleibt eine Browser-Navigation ohne
   Ziel. Der neue Vertrag behauptet nur, dass jedes tatsächlich gerenderte
   Credential-Ziel markiert wird.

## B.4 Drift-Wächter

Der bestehende `tests/Static/SystemStatusDeepLinkContractTest.php` wird
erweitert, nicht durch einen parallelen Test verdoppelt.

Der neue Teil:

- globbt weiterhin alle `lib/system_status*.php`-Renderer;
- findet jedes Element, das ein dynamisches
  `id="credential-..."` rendert;
- verlangt am selben Element `data-deep-link-target`;
- liest Stylesheets über den bestehenden `tests/Support/CssRules.php`-Parser;
- verlangt genau einen wirksamen
  `[data-deep-link-target]:target`-Vertrag in `status.css`;
- beweist mit einer negativen Mutation, dass ein entferntes Attribut oder eine
  entfernte CSS-Regel den Test rot macht;
- besitzt eine Zero-Match-Sicherung für Renderer und CSS-Regel.

Damit darf sich `.status-row` später in eine andere Markup-Form ändern, ohne
dass die Zielsemantik mitwandern muss.

## B.5 E2E und visuelle Abnahme

`tests/e2e/specs/system-status.spec.js` verwendet die bestehende synthetische
ESXi-/Ansible-Fixture und prüft:

1. ESXi-Karte ohne Credential-Hash: Ausgangshintergrund erfassen.
2. Link auf `?inventory=<id>#credential-<id>` öffnen: Details sichtbar und
   Zielhintergrund/Inset-Ring unterscheiden sich vom Ausgang.
3. Details schließen: Hash zeigt auf den ESXi-Abschnitt und die Zielmarkierung
   ist verschwunden.
4. Ansible-Zeile ohne Hash erfassen, dann
   `#credential-<ansible-id>` öffnen und denselben Unterschied beweisen.
5. Die vier Prüfungen in hell und dunkel ausführen.
6. Einen schmalen und einen üblichen Desktop-Viewport verwenden; das Ziel bleibt
   unterhalb der Sticky-Leiste sichtbar.

Die Aussage ist berechneter Stil plus Geometrie, nicht ein Screenshotvergleich
mit Real- oder Zufallsdaten.

## B.6 Audit, Logs und Protokolle

Teil B ist ausschließlich Markup/CSS plus Test. Der GET-Deep-Link erzeugt:

- keinen neuen Audit-Event,
- keinen POST-/CSRF-Pfad,
- keinen Job-, PHP-, Container- oder Fehlerlogeintrag,
- keine Retention-, RBAC-, Schema- oder Machine-API-Änderung.

## B.7 Doku

Im selben Patch:

- `.claude/rules/portal.md`: den aktuell zusammengeklebten Log-/Systemstatus-
  Bullet trennen und den semantischen Fragmentzielvertrag ergänzen;
- `AGENTS.md`: eine knappe dauerhafte Regel für `system_status_url()`,
  dynamische Credential-Ziele und `data-deep-link-target`;
- `docs/QA.md`: den erweiterten Scope des vorhandenen
  `SystemStatusDeepLinkContractTest` dokumentieren;
- `docs/QUALITY-GATES.md`: Systemstatus-Zielmarkierung bei den vertiefenden
  Specs aufführen;
- `docs/CHANGELOG.md`: sichtbare Fehlerbehebung vermerken.

Kein ADR: Es entsteht weder ein neuer Datenvertrag noch eine neue
Architekturachse. Der bestehende ADR-0013-Designvertrag und der vorhandene
Deep-Link-Builder werden nur wieder vollständig durchgesetzt.

---

# Umsetzungsreihenfolge

## Schritt 1: Teil B

1. Semantische Attribute an beide Renderer.
2. Zielregel nach `status.css`, tote `tr:target`-Regeln entfernen.
3. Bestehenden Static-Contract erweitern und Mutantenprobe durchführen.
4. E2E für ESXi und Ansible in beiden Themes.
5. Doku und Changelog.
6. Fokussierte Tests, Fast-Lane, Diffprüfung und eigener Commit.

## Schritt 2: Teil A, reine Domäne

1. Faktormap sowie `lib/vm_ram.php`.
2. Parser-/Anzeige-Unit-Tests einschließlich aller ungültigen Formen und
   Grenzfälle.
3. Keine sichtbare Änderung und kein Repo-/Wire-Umbau.

## Schritt 3: Teil A, Portal und Anzeige

1. `lib/vm_edit_ram.php`, POST-Normalisierung und Feldrenderer.
2. Dreiteiliges Markup, CSS, JS-Faktoren, Presets und Vorschau.
3. VM-Liste und CSV-Header.
4. Kataloge, Troubleshooting, QA-/Quality-Gates-Doku und Changelog.
5. Contract-, Integration-, E2E- und No-JavaScript-Nachweise.
6. Fast- und Integration-Lane, Diffprüfung und eigener Commit.

# Kanonische Abnahme

Fokussierte Tests laufen zuerst, damit ein Fehler eine kleine Ursache behält.
Der endgültige Nachweis läuft anschließend über den öffentlichen Runner:

- Teil B: relevante PHPUnit-Statictests, gezielter
  `system-status.spec.js`, danach `.\scripts\check.ps1 -Lane Fast`.
- Teil A: relevante Unit-/Static-/Integrationtests, gezielter
  `crud-vm.spec.js` samt No-JavaScript-Zweig, danach
  `.\scripts\check.ps1 -Lane Fast`.
- Gemeinsamer Endstand: `.\scripts\check.ps1 -Lane Integration`, weil der
  Patch serverseitige Formularnormalisierung, Datenbankpersistenz, Audit und
  Browserlogik gemeinsam berührt.

Der lange Integration-Lauf erhält vor dem Start einen live pollbaren
Fortschrittslog. Gemeldet werden die tatsächlichen
`[n/total] RUN|pass|fail|...`-Zeilen mindestens einmal pro Minute; es wird kein
gepufferter Lauf ohne Beobachtungspfad gestartet.

Zusätzlich vor jedem Commit:

- `git diff --check`;
- vollständiger eigener Diff gelesen;
- `php scripts/lang-audit.php --ci` über den Runner grün;
- Bounds-, Enum-, CSP-, Doku- und Dateigrößendrift über den Runner grün;
- keine Änderung an Repo-/Missionstransfer-/Machine-API-Wire-Verträgen;
- keine fremden Arbeitsbaumänderungen gestaged oder zurückgesetzt.
