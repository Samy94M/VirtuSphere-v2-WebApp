# Plan: MECM-Clientbereitstellung vereinfachen und upgradesicher machen

**Stand:** 2026-09-20

**Status:** Robustheitsreview v10 mit Code-/Vertragsabgleich, bestätigtem Portal-Core-, Packaging-, Dependency-, Audit- und Dokumentationszielbild sowie einem offenen Betriebsentscheid, noch keine Produktivänderung

**Bereich:** `Powershell-MECM/clients`, `Powershell-MECM/mecm`, Portal-Paketzuweisung, DE/EN-Help, zugehörige Tests und Betriebsdokumentation

## Zielbild

Ein Administrator führt auf dem MECM-Server genau einen dokumentierten Installer aus. Dieser baut und prüft vier vollständige Content-Verzeichnisse, aktualisiert den Content der vier eindeutig identifizierten MECM-Applications in-place und stößt die Verteilung an. Abweichende vorhandene Deployment Types werden nicht still umgebaut, sondern bleiben ein Blocker. Auf genau einer stabilen Core-Device-Collection wird anschließend ausschließlich die letzte Anwendung `client_staticip` als `Required` bereitgestellt. MECM installiert deren drei Abhängigkeiten automatisch in der festgelegten Reihenfolge. Die Netzwerkänderung läuft damit zuletzt.

Im Portal sieht ein Administrator pro VM im bestehenden MECM-Bereitstellungspfad nur den fachlichen Baustein **Core**, nicht vier technische Phasen. Bei neuen, noch nicht bereitgestellten VMs mit einer aktiven, aus MECM synchronisierten Tasksequenz ist Core standardmäßig ausgewählt, kann beim Anlegen aber bewusst abgewählt werden. Die konkrete Auswahl wird beim Anlegen gespeichert; der Default wird niemals beim späteren Rendern oder durch eine Migration ergänzt. Bereits bereitgestellte VMs werden beim Cutover nicht automatisch nachselektiert. Der bestehende Device-Sync übersetzt die gewünschte Portalzuweisung in eine von VirtuSphere nachweisbar besessene direkte Mitgliedschaft der Core-Collection. Nach dem Cutover kommt das Core-Targeting nicht mehr aus der dauerhaft belegten OS-Collection `Deploy Windows 2022`; deren Aufgabe bleibt die Betriebssystembereitstellung.

Jedes heutige Content-Verzeichnis enthält genau den aus der Spezifikation abgeleiteten Satz:

```text
<phase>.ps1
VirtuSphere-Client-Common.ps1
VirtuSphere-Client-Logging.ps1
bootstrap.json
```

Die Installationszeile nennt nur `<phase>.ps1`; sie ist der Einstiegspunkt und keine vollständige Dateiliste. Eine Anwendung darf nicht als durch VirtuSphere verwaltet gelten, wenn ihr Content, Deployment Type, Detection Contract oder ihre Abhängigkeit vom erwarteten Vertrag abweicht.

### Vier Namen, die nicht verwechselt werden dürfen

| Begriff | Beispiel erste Phase | Bedeutung |
|---|---|---|
| MECM-Objektidentität | `CI_ID`/`ModelName` des vorhandenen CM-Objekts | Stabile technische Identität für Upgrade, Referenzen und Audit; der Anzeigename allein ist kein Eigentumsbeweis. |
| MECM-Anzeigename | `client_getInfos` | Das Objekt, das der Admin in der Konsole auswählt. |
| Contentordner | `client_getInfos` | Unterordner unter `PackagesBase` und `ContentShare`. |
| Einstiegsskript | `client_getInfos.ps1` | Datei, die die Installationszeile startet; der Zielname wird projektgesteuert vereinheitlicht. |

Für die Datenträgerphase gilt entsprechend: MECM-Anzeigename, Ordner und projektgesteuert vereinheitlichtes Einstiegsskript `client_VMDisksOnline` beziehungsweise `client_VMDisksOnline.ps1`. Die deployte Endanwendung ist nach der bestätigten Neuordnung `client_staticip` mit dem Einstiegsskript `client_staticip.ps1`. Doku und Help müssen diese Spalten ausdrücklich beschriften; eine bloße Pfeilkette ohne Spaltenbezeichnung ist nicht ausreichend.

## Aktueller Stand und eigentliche Fehlerursache

Der aktuelle Installer `install-VirtuSphere-Clients.ps1` kann bereits vollständige Vier-Dateien-Verzeichnisse erzeugen und vergleicht lokale und freigegebene Inhalte per SHA-256. Die vier im Code definierten Anwendungen bilden bereits diese Kette:

```text
client_getInfos
  -> client_hostname
    -> client_staticip
      -> client_VMDisksOnline
```

Das ist der dokumentierte Iststand, nicht der freigegebene Zielgraph. Die Implementierung benennt die beiden abweichenden Einstiegsskripte ausschließlich im Projekt zu `client_getInfos.ps1` und `client_VMDisksOnline.ps1` um und stellt die Dependency-Reihenfolge kontrolliert auf `client_getInfos` → `client_hostname` → `client_VMDisksOnline` → `client_staticip` um. Die bestehenden MECM-Anzeigenamen und Objektidentitäten werden dabei nicht als neue Versionsobjekte dupliziert.

Die beobachteten Altanwendungen `client_getinfo` und `client_getinfo_2.1` gehören jedoch nicht zu diesem exakten, vom Installer verwalteten Vertrag. Wurde eine solche Anwendung manuell angelegt und als Content nur das Phasenskript ausgewählt, landen `Common`, `Logging` und `bootstrap.json` nicht im `ccmcache`. Software Center bewertet dann nur den tatsächlich konfigurierten Deployment Type und dessen Detection Method; es kennt den beabsichtigten VirtuSphere-Vertrag nicht.

Das erklärt auch den scheinbaren Widerspruch:

- Manuell gestartet funktioniert `client_getinfo.ps1`, wenn die erforderlichen Geschwisterdateien beziehungsweise die Registry-Konfiguration am manuellen Ausführungsort vorhanden sind.
- Über eine falsch angelegte MECM-Anwendung fehlen diese Dateien im Cache oder die Detection Method prüft einen zu schwachen beziehungsweise alten Marker.
- „Erfolgreich installiert“ bedeutet deshalb nur, dass MECM den konfigurierten Vertrag als erfüllt betrachtet hat. Es beweist nicht automatisch, dass der gewünschte VirtuSphere-Zustand erreicht wurde.

## Frühere Erkenntnisse aus dieser Session

1. Im `ccmcache` lag nur `client_getinfo.ps1`; `VirtuSphere-Client-Common.ps1`, `VirtuSphere-Client-Logging.ps1` und `bootstrap.json` fehlten. Das ist kein vollständiger VirtuSphere-Contentsatz.
2. In MECM existierten beziehungsweise liefen `client_getinfo` und `client_getinfo_2.1`. Der heutige Code verwaltet dagegen exakt `client_getInfos`. Ähnliche Schreibweise bedeutet nicht dasselbe CM-Objekt.
3. `Common` und `Logging` sind keine separat installierten Programme. Sie müssen als Geschwisterdateien im Contentordner liegen und werden vom Phasenskript per `$PSScriptRoot` geladen.
4. `Common` enthält einen Notfall-DNS-Kandidaten `virtusphere.lan:8021`; die Notfall-IP ist derzeit leer. Die reguläre standortspezifische Adresse wird nicht in den Quelltext geschrieben.
5. Der Client-Installer erzeugt aus `-WebApi`, `-Scheme` und optional `-CertThumbprint` die `bootstrap.json`. Im Iststand importiert nur `client_getinfo.ps1` sie vor der ersten API-Auflösung in die Registry; der vereinheitlichte Zielname lautet `client_getInfos.ps1`.
6. Sobald eine nichtleere Registry-`WebAPI` existiert, überspringt die heutige Bootstrapfunktion den gesamten Import. Das betrifft auch ein möglicherweise noch fehlendes `Scheme` oder `CertThumbprint`; „füllt alle fehlenden Werte“ wäre daher als Beschreibung zu weit.
7. Nach dem Bootstrap ist die Registry die effektive Laufzeitkonfiguration. Ein späteres Paketupgrade ändert vorhandene Clientwerte nicht automatisch. Ein IP-zu-DNS-Wechsel braucht deshalb einen eigenen, kontrollierten Client-Migrationsweg.
8. Ein manueller Erfolg beweist Quelllogik und lokale Dateien im manuellen Kontext, aber nicht den MECM-Lauf als `SYSTEM`, die native Registry-Sicht, den tatsächlichen Cacheinhalt oder die ausgewählte MECM-Application.
9. „Software Center: installiert“ ohne VirtuSphere-Log kann bedeuten, dass Detection bereits vor Enforcement erfüllt war. Bei einer klassischen Package/Program-Konfiguration kann dagegen nur der Prozessreturncode gewertet worden sein. Ohne `AppDiscovery`/`AppEnforce` beziehungsweise `execmgr` darf die Ursache nicht geraten werden.
10. Der bestehende Installer baut bereits vier vollständige Ordner, vergleicht lokalen und UNC-Inhalt über relativen Pfad, Länge und SHA-256, prüft Ownership/Deployment-Type-Vertrag und legt keine Collection-Deployments an.
11. Der bestehende Installer prüft noch nicht belastbar die native 64-Bit-Ausführung/Detection, wartet nicht auf tatsächliche DP-Konvergenz und nutzt bei vorhandenen Applications `Update-CMDistributionPoint`, ohne nachzuweisen, dass die mit `-DpGroupName` genannte Gruppe bereits wirklich Ziel aller vier Inhalte ist.
12. Die vorhandenen Handbücher verwenden teilweise Skriptnamen als Bezeichnung der Application-Kette. Das driftet von den in `Get-VsClientAppSpecs` definierten MECM-Anzeigenamen und begünstigt genau die beobachteten manuellen Doppelanlagen.
13. Das Portal besitzt mit `deploy_vm_packages` bereits eine per VM gespeicherte Sollzuweisung. `mecm_plan.php` projiziert diese Zuordnung in die Geräteliste, und `mecm_new-device-sync.ps1` setzt beziehungsweise entfernt nur die eigenen direkten Collection-Regeln. Für Core ist daher kein zweiter Zuweisungsmechanismus nötig.
14. Die Provenienz in `deploy_vm_mecm_rules` trennt VirtuSphere-Regeln von manuellen oder fremden Collection-Mitgliedschaften. Eine Portalabwahl darf nur die eigene Regel entfernen und niemals eine manuell gepflegte MECM-Regel löschen.
15. Die heutige breite Kopplung an `Deploy Windows 2022` belastet auch lange bereitgestellte VMs weiterhin mit einer Required Policy. Weil diese Collection laut Betrieb nicht zuverlässig bereinigt wird, ist sie kein sicherer Besitzer des einmaligen Core-Provisionings.
16. Die Client-README und das MECM-Runbook erklären zwar Dependencies, verwenden in der Kettendarstellung aber Skriptnamen (`client_getinfo`, `Set-VMDisksOnline`) statt der wirklichen MECM-Anzeigenamen (`client_getInfos`, `client_VMDisksOnline`). Außerdem ist nicht überall eindeutig gesagt, dass nur die Endanwendung deployed wird.
17. Die Portal-Hilfe beschreibt aktuell nur normale, versionierte Autoimporter-Pakete aus `VirtuSphere_Applications`. Core liegt dagegen in `VirtuSphere_Core`, besitzt eine vierstufige Dependency-Kette und darf nicht denselben Versions-, Retirement- oder Uninstall-Vertrag erben.
18. Die Paketdiagnose in der Portal-Hilfe nennt Wrapper-Logs unter `%ProgramData%`/`%LOCALAPPDATA%`. Die Core-Phasen schreiben dagegen nach `C:\Program Files\VirtuSphere\Logs`. Ohne getrennte Überschriften führt die Hilfe den Helpdesk zum falschen Logpfad.

## Festgelegte Designentscheidungen

1. **Vier stabile CM-Objektidentitäten statt neuer Versionsobjekte.** Die eindeutig übernommenen Applications bleiben bestehen und ihr Content wird in-place aktualisiert. Namen wie `_2.1`, `_neu` oder `_final` führen in der Praxis meist zu parallelen CM-Objekten, Detection-Konflikten und schwer rückbaubaren Policies. Anzeigename und Identität werden im Inventar getrennt ausgewiesen.
2. **Nur die Endanwendung wird deployed.** `client_staticip` ist der einzige Collection-Einstieg. Die anderen drei Applications werden als automatisch zu installierende Abhängigkeiten geführt und in MECM als solche beschrieben.
3. **Keine automatische Deployment-Erstellung.** Collection, Zweck `Available`/`Required`, Zeitplan, Wartungsfenster und Wake-up-Verhalten sind Betriebsentscheidungen. `install-VirtuSphere-Clients.ps1` und das Portal erzeugen oder verändern das Deployment nicht implizit; der Deployment-Verantwortliche legt es nach Runbook/Freigabe einmalig an. Der Projekt-Preflight validiert danach den vollständigen Vertrag und blockiert bei Drift.
4. **Kein automatisches Löschen.** Der Installer darf weder Altanwendungen noch Deployments, Task-Sequence-Referenzen, Supersedence-Beziehungen oder Quellverzeichnisse löschen. Er erstellt höchstens einen belastbaren Rückbaubericht.
5. **Keine Application Group als zweiter Orchestrator.** Die vorhandene Abhängigkeitskette ist ausreichend. Eine zusätzliche Gruppe würde Reihenfolge und Fehlerzustände an zwei Stellen verwalten.
6. **SSoT wird nach Lebenszyklus getrennt.** `Get-VsClientAppSpecs` besitzt den statischen MECM-Vertrag; der freigegebene Packaging-Auftrag besitzt die gewünschte Standortkonfiguration; der erzeugte Build-Beleg besitzt den ausgelieferten Paketsatz; native HKLM besitzt die effektive Client-Laufzeitkonfiguration. `bootstrap.json` ist Transport für die Erstkonfiguration und keine vierte dauerhaft konkurrierende Wahrheit.
7. **Native 64-Bit-Ausführung wird Teil des überprüften Vertrags.** Der Deployment Type und die Registry Detection müssen dieselbe native Registry-Ansicht verwenden. Eine 32-Bit-Konfiguration kann sonst unter `Wow6432Node` lesen oder schreiben und einen widersprüchlichen Zustand erzeugen.
8. **Verteilung angefordert ist nicht gleich auf dem DP verfügbar.** Das Skript muss diese Zustände sprachlich und im Exit-Verhalten unterscheiden. Optionales Warten darf nur mit begrenztem Timeout und prüfbarem Fortschritt erfolgen.
9. **Ein Upgrade braucht einen echten Rollback-Satz.** Application Revision, Content-ID, Package Source und der lokal freigegebene Ordner sind verschiedene Dinge. Vor der Aktivierung wird der letzte freigegebene Vierer-Satz nach Abschluss nicht mehr umgeschrieben, mit Manifesthash und Build-ID aufbewahrt; Rückkehr wird vor Produktion getestet.
10. **Preflight ist Pflichtbestandteil jedes schreibenden Laufs.** Ownership-, Namens-, Content-, Bitness-, Referenz-, Berechtigungs- oder DP-Ziel-Unsicherheit blockiert vor der ersten Aktivierung eines Package-Source-Ordners und vor der ersten MECM-Änderung. `-ValidateOnly` beendet denselben Pflicht-Preflight lediglich vor Apply. Es gibt keinen `-SkipPreflight`-Schalter. Ein Lauf darf nicht erst nach drei veränderten Applications beim vierten Objekt entdecken, dass der Gesamtvertrag unzulässig ist.
11. **Keine Legacy-Übernahme.** Die Altanwendungen `client_getinfo` und `client_getinfo_2.1` werden unabhängig von Ordner, ähnlichem Namen oder vorhandenen Markern niemals als neuer Core übernommen. Sie werden read-only inventarisiert, anschließend kontrolliert aus dem Targeting genommen und zunächst retired; Löschen bleibt ein separates späteres Cleanup. Für die vier exakten Zielobjekte gilt ein projektverwalteter Managed Marker mit stabiler Objekt-ID.
12. **Keine automatische Wiederholung zustandsverändernder Phasen.** Eine Contentrevision ist kein Auftrag, Hostname, IP oder Datenträger erneut zu ändern. Repair und Migration erhalten eigene, explizite Verträge.
13. **Die MECM-Dependencies bleiben bestehen.** Core wird nicht in ein klassisches Package/Program und nicht in einen monolithischen Client-Wrapper umgebaut. Neustart, Detection und Teilfortschritt bleiben je Phase im MECM-Application-Modell sichtbar.
14. **Ein fachlicher Core-Baustein, vier technische Applications.** Portal und Admin-Runbook zeigen Core als eine Auswahl. Technisch wird nur `client_staticip` auf die Core-Collection deployed; `client_VMDisksOnline` hängt von `client_hostname`, dieses von `client_getInfos` ab. Vier einzelne Core-Checkboxen oder vier Required Deployments sind ausgeschlossen.
15. **Bestehende Paketzuweisung erweitern, keinen Sonder-Boolean erfinden.** Core nutzt den vorhandenen VM-zu-Paket-Zuweisungspfad, erhält aber einen expliziten, synchronisierten Typ wie `core` statt die Semantik aus Namen, Ordner oder Version zu erraten. Ein paralleles Feld `core_enabled` würde einen zweiten Desired-State-Owner schaffen.
16. **Eine stabile Core-Collection statt versionsweiser Ziele.** Die Collection-Identität bleibt bei Content-Upgrades gleich. Neue Versionscollections würden Bestands-VMs erneut targeten oder alte Zuordnungen verwaisen lassen.
17. **Abwahl ist Policy-Entzug, kein Rollback.** Die Abwahl entfernt nach Device-Sync nur die von VirtuSphere besessene direkte Core-Mitgliedschaft. Implizites Uninstall ist für das Required Deployment verboten. Hostname, IP, Datenträgerzustand, Registrywerte und Detection Marker bleiben unverändert.
18. **Soll und Ist bleiben getrennt.** `Core ausgewählt` bedeutet gewünschtes Targeting. `Mitgliedschaft gesetzt`, `Policy empfangen`, `Installation läuft`, `Phase abgeschlossen` und `fehlgeschlagen/unbekannt` sind eigene beobachtete Zustände. Das Portal darf aus dem Haken niemals „installiert“ ableiten.
19. **Sichere Migration nach Alter der VM.** Neue, noch nicht an MECM übergebene VMs mit aktiver MECM-Tasksequenz erhalten Core beim Anlegen standardmäßig. Bestehende oder bereits übergebene VMs bleiben beim Schema-/Feature-Cutover und beim späteren Öffnen unverändert und benötigen eine bewusste Auswahl oder einen ausdrücklich freigegebenen Repair-/Migrationsauftrag.
20. **OS- und Core-Targeting werden entkoppelt.** `Deploy Windows 2022` bleibt für PXE/OS zuständig. Sein altes Core-Deployment wird erst nach Pilot, Zielvergleich und Policy-Konvergenz deaktiviert oder entfernt; während des Cutovers darf nicht gleichzeitig eine zweite Required Core-Policy wirken.
21. **Keine manuell gepflegte OS-Familie und keine Namensheuristik.** Die Core-Berechtigung folgt dem bereits vorhandenen technischen MECM-Pfad: Die VM besitzt eine aktive `deploy_os`-Zuordnung, die aus einer MECM-Tasksequenz synchronisiert wurde. Weder ein Adminfeld `os_family` noch ein Präfixtest auf `Windows` wird zur zweiten Wahrheit. Fehlt dieser technische Bezug, blockiert das Portal die Core-Zuweisung mit einer konkreten Diagnose.
22. **Core umfasst alle vier Phasen einschließlich `client_getInfos`.** Ist Core vor dem ersten Device-Sync abgewählt, wird keine der vier Applications für diese VM angefordert: keine WebAPI-/Snapshot-Registry, kein Client-Ready-ACK, keine Hostname-, Datenträger- oder IP-Aktion. Das Portal zeigt dafür den zulässigen Erwartungszustand `Core nicht beauftragt` statt einen Fehler bei 4/5 oder einen erfundenen Erfolg bei 5/5. Eine spätere Abwahl ist nur Policy-Entzug und kann bereits empfangene oder gestartete MECM-Ausführung wegen Policy-Latenz nicht sicher abbrechen.
23. **Core darf bewusst nachgereicht werden.** Der fehlende automatische Bestandsdefault ist keine Sperre: Ein berechtigter Admin kann Core bei einer bestehenden VM später auswählen, die vollständigen Auswirkungen bestätigen und die gespeicherte Änderung über den vorhandenen expliziten MECM-Transfer anfordern. Erst danach erzeugt der Device-Sync die besessene Core-Mitgliedschaft. MECM führt alle Phasen aus, deren Detection noch nicht erfüllt ist; eine bereits als erfüllt erkannte Phase wird durch bloßes erneutes Auswählen nicht als Reparatur wiederholt.
24. **Netzwerkänderung zuletzt.** Der Zielgraph lautet `client_getInfos` → `client_hostname` → `client_VMDisksOnline` → `client_staticip`; nur `client_staticip` wird deployed. So erfolgt die statische IP erst nach der Datenträgerphase. Das reduziert das Risiko, dass eine neue IP oder Boundary-Zuordnung den Abruf beziehungsweise die Ausführung einer noch folgenden Phase unterbricht. Der reale MECM-Pilot muss trotzdem belegen, dass Content, Neustart und Policy über die gesamte Kette funktionieren.
25. **Collection-Identität wird ausschließlich durch das Projekt verwaltet.** Der Installer beziehungsweise ein eigener projektversionierter Migrationspfad erstellt und ändert die stabile Core-Collection samt Marker und gespeicherter Collection-ID. Eine manuelle Umbenennung oder eine nur namensgleiche Ersatzcollection wird nicht übernommen; ID-/Namensdrift blockiert mit Reparaturhinweis auf den Projektinstaller.
26. **Standortkonfiguration liegt in genau einer geschützten Datei.** Der normale Packaging-Lauf liest `C:\ProgramData\VirtuSphere\MECM\ClientPackaging.psd1`; WebAPI, Scheme, Zertifikatfingerabdruck, lokale/UNC-Paketpfade, DP-Gruppe und Bundlearchiv werden nicht bei jedem Lauf neu eingetippt. `Import-PowerShellDataFile -LiteralPath` lädt ausschließlich konstante Daten ohne `Invoke-Expression`; anschließend prüft der Projektvertrag exakte Schlüssel, Typen und Werte. Nur ein projektversionierter Initialisierungs-/Änderungsbefehl darf die Datei nach Validierung schreiben. Der Installer zeigt Quelle, effektive Werte und Konfigurationshash vor Apply und blockiert bei zu breiten ACLs, unbekanntem Schema oder unbekannten Schlüsseln.
27. **Build-Receipt ist der unveränderliche Beleg, nicht die Konfiguration.** Jeder erfolgreiche Build schreibt neben dem vollständigen Rollbackbundle ein `receipt.json` mit BundleId, Konfigurationshash, effektiven nicht geheimen Werten, Quellen-/Dateihashes, Application-/Content-IDs, Admin, UTC-Zeit und Gate-Ergebnissen. Das Receipt wird niemals als Eingabe für einen späteren normalen Build verwendet.
28. **Rollbackaufbewahrung ist Projektpolicy.** Erfolgreiche Bundles liegen außerhalb der aktiven Package Source im konfigurierten `BundleArchivePath`, als dokumentierter Standard `D:\VirtuSphere\Base\ClientBundles`. Geschützt sind stets die fünf neuesten erfolgreichen Bundles und zusätzlich jedes erfolgreiche Bundle, das noch keine 180 Tage alt ist. Ein normaler Install-/Upgradelauf löscht nichts; ein eigener Cleanup erzeugt zuerst einen What-if-Plan und benötigt danach eine explizite Apply-Freigabe gegen dieselbe Plan-ID.
29. **Core-Auswahl gehört zur gewünschten VM-Konfiguration.** Clone, Template sowie Mission-Export/-Import führen die typisierte Core-Zuweisung mit, aber niemals MECM-Laufzeitwerte wie Collection-Membership, ResourceID oder Phasenstatus. Fehlt im Zielsystem der eindeutige neue Core-Katalogeintrag, wird die Zuordnung sichtbar übersprungen und auditiert; es gibt kein Namensraten.
30. **Kein hartcodierter API-Fallback im Zielvertrag.** `virtusphere.lan`, eine Paket-Notfall-IP oder ein anderer Scriptdefault dürfen einen fehlenden beziehungsweise widersprüchlichen Standortauftrag nicht verdecken. Fehlt die native Registry-Konfiguration vollständig, übernimmt `client_getInfos` genau den vollständigen, gültigen Bootstrap-Satz. Ist ein vollständiger Registry-Satz vorhanden und stimmt mit dem Bootstrap überein, wird er verwendet. Ist er partiell, ungültig oder abweichend, blockiert der normale Lauf mit einem stabilen Driftcode; nur ein eigener freigegebener Migrations-/Repairauftrag darf ihn ersetzen.
31. **Mission-Transfer wird wegen `kind` zu Formatversion 2.** Das Feld ändert nicht nur Darstellung, sondern die Auflösungs- und Sicherheitssemantik. Neue Exporte schreiben `format_version=2` und für jede Paketreferenz ein explizites `kind`. Der neue Importer akzeptiert Version 1 ausschließlich als Legacy-Software ohne Core und Version 2 als typisierten Vertrag. Ein alter Version-1-Importer weist Version 2 über seine bestehende Versionsprüfung zurück, statt `kind` zu ignorieren und Core als normales Paket aufzulösen.
32. **Audit folgt den vorhandenen Spalten und Transaktionsgrenzen.** Der Portal-Akteur kommt aus `deploy_logs.user_id`, IP und CorrelationId aus den bestehenden Auditspalten; ein zweites freies `actor`-Kontextfeld ist verboten. Einzelne VM-Erstellung/-Bearbeitung erhält ein atomisches Core-Desired-State-Ereignis. Mission-Clone und -Import verwenden das bestehende aggregierte Mission-Ereignis mit Core-Zählern statt einer Auditzeile pro VM. Zustandsänderung und zugehöriger Audit-Write müssen in derselben `repo_transaction()` liegen; ein `false` des Writers wird zum Fehler und rollt die Fachänderung zurück.
33. **Direkte Regel ist noch keine ausgewertete Mitgliedschaft.** Core-Regeln werden ausschließlich über Collection-ID und ResourceID geschrieben. Danach wird genau einmal je veränderter Collection ein Update angestoßen; das Portal unterscheidet `Regel bestätigt`, `Collection-Auswertung ausstehend` und `Mitgliedschaft beobachtet`. Ein Cmdlet-Erfolg allein darf nicht als Policyempfang ausgegeben werden. Die Standortkonfiguration enthält die stabile `CoreLimitingCollectionId`; deren Verlust oder ein Gerät außerhalb dieser Begrenzung blockiert statt eine wirkungslose Regel grün zu melden.
34. **Normale Upgrades gelten nur für künftige beziehungsweise noch nicht erkannte Ausführungen.** Der Packaginglauf aktualisiert Definition und Content, erhöht aber keinen Detection Marker und spielt Hostname, Datenträger oder IP auf bereits abgeschlossenen VMs nicht erneut ein. Ein bestehender Client benötigt für Konfigurationsmigration oder Phasenwiederholung einen getrennten, zielgebundenen Repair-/Migrationsauftrag mit eigener Collection, Vorschau, Freigabe und Evidenz; dieser Auftrag gehört nicht zum normalen Installer.
35. **Der Create-Default braucht einen expliziten Eingabevertrag.** Der eine Core-Haken wird bei einer berechtigten interaktiven Neuanlage zunächst sichtbar ausgewählt gerendert, aber erst aus einem eindeutigen POST mit Form-Sentinel gespeichert. `core_choice_present=1` plus fehlender Checkboxwert bedeutet bewusst `aus`; ein vollständig fehlendes Feld bedeutet nicht „Default anwenden“. Der Repository-Owner erhält zusätzlich eine geschlossene Herkunft (`interactive_create`, `edit`, `clone`, `import`); nur `interactive_create` darf den Nur-neue-VM-Default materialisieren. Dieses Herkunftssignal steuert den Schreibpfad, ist aber kein zweiter persistierter Desired State.
36. **`Core nicht beauftragt` ist nur ein abgeleiteter Anzeigezustand.** Die bestehenden Lifecyclewerte und der exakte Machine-Wire-Vertrag werden nicht um einen sechsten gespeicherten Status erweitert. `mecm_client_ack` bleibt alleiniger Writer des bestehenden Client-Ready-/5-von-5-Übergangs. Wenn Core im Desired State aus ist, leitet der Portal-Helper die Erwartungsanzeige `Core nicht beauftragt` separat ab; bei späterer Auswahl gilt wieder der reale Lifecycle. Eine Abwahl nach abgeschlossenem Core löscht weder die historische 5/5-Evidenz noch Phasenmarker.

### Abdeckung der bisherigen Sitzungsentscheidungen

| Bestätigte Entscheidung | Im Plan verankert |
|---|---|
| Dependencies bleiben; kein monolithisches Package/Program | Entscheidungen 2, 5, 13 und 14; Dependency-Vertrag; MC03 |
| Ein Core-Haken steuert alle vier Phasen | Entscheidungen 14, 18 und 22; Portal-Zielbild; statischer UI-Vertrag |
| Core aus bedeutet: auch `client_getInfos`, Registry und ACK laufen nicht | Entscheidung 22; Edge-Case-Matrix; Help-/Statusvertrag |
| Core kann bei Bestands-VMs bewusst nachgereicht werden | Entscheidung 23; Abwahl/Nachwahl; MC04A und Pilot |
| Neue berechtigte VMs standardmäßig an, Bestands-VMs niemals rückwirkend | Entscheidungen 19 und 21; Cutover; Repository-/Migrationstests |
| Admin kann den sichtbaren Create-Default bewusst abwählen | Entscheidung 35; Form-Sentinel/Herkunftsvertrag; Negativtests für Create/Edit/Clone/Import |
| Berechtigung aus synchronisierter aktiver MECM-Tasksequenz, nicht aus OS-Namen | Entscheidung 21; Portal-Provenienz; MC04A |
| Reihenfolge `getInfos` → `hostname` → `VMDisksOnline` → `staticip` | Entscheidung 24; Dependency-Vertrag; Pilot |
| Neue projektgesteuerte Skriptnamen | MC01, MC03, Contract-Tests und Definition of Done |
| `client_getinfo` und `client_getinfo_2.1` nie adoptieren | Entscheidung 11; Legacy-Scan; MC02/MC04 |
| Standortwerte zentral aus geschützter Datei (Option B) | Entscheidungen 26 und 30; SSoT-Kapitel; Config-/ACL-Tests |
| Fünf Bundles und mindestens 180 volle Tage | Entscheidung 28; versionierte Retention-Policy; Cleanup-Tests |
| Core bei Clone, Template und Export/Import mitführen | Entscheidungen 29 und 31; MC04A; Transfer-Fixtures |
| Logging, Registry-Provenienz, Doku und DE/EN-Help | Betriebsvertrag, Dokumentationsspiegel, MC05/MC06 |
| `Core nicht beauftragt` ohne Änderung des Machine-Lifecycle-Vertrags | Entscheidung 36; Anzeige-Helper; bestehender ACK bleibt alleiniger 5/5-Writer |

Damit sind alle bisher ausdrücklich beantworteten Punkte enthalten. Der neue Review hat zusätzlich die unten ausgewiesene Betriebsfrage zum Zeitpunkt disruptiver Ausführung gefunden; sie war in der bisherigen Sitzung noch nicht entschieden.

## Erkenntnisse aus der Microsoft-Dokumentation

| Microsoft-Vertrag | Konsequenz für VirtuSphere |
|---|---|
| Eine Application enthält Deployment Types; deren Content Location verweist auf den Content-Ordner, während Installation Program nur den Startbefehl angibt. [Create applications](https://learn.microsoft.com/en-us/intune/configmgr/apps/deploy-use/create-applications) | Der gesamte Vier-Dateien-Ordner muss Content Source sein. Die Installationszeile allein verteilt keine Hilfsdateien. |
| Abhängigkeiten können automatisch installiert werden und benötigen dann kein eigenes Deployment. Die maximale Abhängigkeitstiefe beträgt fünf Ebenen. [Create applications](https://learn.microsoft.com/en-us/intune/configmgr/apps/deploy-use/create-applications) | Die vierstufige Kette ist zulässig; in der Collection genügt die Endanwendung. |
| MECM wertet Dependencies vor der Hauptanwendung aus; `Auto Install` installiert die abhängige Application ohne eigenes Deployment. Hard- und Soft-Reboot sind getrennte Return-Code-Typen. [Create applications](https://learn.microsoft.com/en-us/intune/configmgr/apps/deploy-use/create-applications) | Die vorhandenen vier Applications bleiben der technische Orchestrator. Ein einzelner Wrapper müsste Restart/Resume, Teilzustand und Netzwerkunterbrechung selbst nachbauen und ist daher nicht die Vereinfachung. |
| Änderungen an Quellinhalten erfordern „Update Distribution Points“; für Application Content entsteht dabei eine neue Content-ID. [Deploy and manage content](https://learn.microsoft.com/en-us/intune/configmgr/core/servers/deploy/configure/deploy-and-manage-content) | Ein Upgrade muss alle vier Content-Sätze aktualisieren und deren DP-Zustand getrennt prüfen. |
| Wird eine abhängige Application nach dem Deployment der Hauptanwendung aktualisiert, wird deren neuer Content nicht automatisch allein deshalb verteilt. [Deploy applications](https://learn.microsoft.com/en-us/intune/configmgr/apps/deploy-use/deploy-applications) | Jede Änderung an `Common`, `Logging` oder `bootstrap.json` muss bewusst für alle vier Applications verteilt werden. |
| Ein `Required` Deployment installiert automatisch zum konfigurierten Termin. Eine Install-Policy gewinnt bei einem Konflikt gegen eine Uninstall-Policy. [Deploy applications](https://learn.microsoft.com/en-us/intune/configmgr/apps/deploy-use/deploy-applications) | Doppelte Zielpfade über `Deploy Windows 2022` und die neue Core-Collection sind ein Cutover-Blocker; eine vermeintliche Gegen-Deinstallation wäre weder sicher noch wirksam. |
| Eine Application-Policy an eine Device Collection gilt für alle Geräte dieser Collection; Policyempfang und Enforcement folgen getrennt und lassen sich über Application-/Assignment-ID verfolgen. [Application deployment for device collections](https://learn.microsoft.com/en-us/troubleshoot/mem/configmgr/app-management/understand/device-deployment-technical-reference) | Die Portalwahl erzeugt keine Installation unmittelbar. Erst Device-Sync, Collection-Evaluation, Policyempfang, Detection und Enforcement ergeben den beobachtbaren Ablauf. |
| Änderungen erzeugen Application-Revisions. Supersedence ist für den Wechsel zwischen verschiedenen Application-Identitäten gedacht; bei derselben Identität ist ein normales In-place-Upgrade möglich. [Revise and supersede applications](https://learn.microsoft.com/en-us/intune/configmgr/apps/deploy-use/revise-and-supersede-applications) | Für den Normalfall keine neuen Versions-Applications erzeugen. Supersedence nur für eine kontrollierte Migration fremder/alter Identitäten. |
| Eine Application beziehungsweise ein Deployment Type kann nicht gelöscht werden, solange Deployments, Abhängigkeiten oder Task Sequences darauf verweisen. „Retire“ entfernt weder installierte Software noch bestehende Deployments. [Management tasks for applications](https://learn.microsoft.com/en-us/intune/configmgr/apps/deploy-use/management-tasks-applications) | Vor jedem Rückbau vollständigen Referenzgraphen ermitteln; zuerst Policies und Referenzen lösen, dann stilllegen, zuletzt löschen. |
| Das Löschen oder Deaktivieren eines Deployments entfernt die Policy, deinstalliert aber nicht automatisch die Application und wirkt erst nach Client-Policy-Aktualisierung. [Disable and delete application deployments](https://learn.microsoft.com/en-us/intune/configmgr/apps/deploy-use/disable-delete-deployments) | Zwischen Policy-Entfernung und Objektlöschung ist eine beobachtete Konvergenzphase erforderlich. |
| Microsoft weist zusätzlich darauf hin, dass Disable/Delete keine sofortige Clientwirkung garantiert und ein bereits empfangener Auftrag trotzdem noch laufen kann. [Disable and delete application deployments](https://learn.microsoft.com/en-us/intune/configmgr/apps/deploy-use/disable-delete-deployments) | Eine Core-Abwahl verspricht niemals „abgebrochen“. Portal und Runbook nennen den letzten beobachteten Policy-/Enforcementzustand und behandeln laufende beziehungsweise bereits heruntergeladene Ausführung als unbekannt, bis neue Client-Evidenz eintrifft. |
| Das Deinstallieren einer Hauptanwendung deinstalliert deren Abhängigkeiten nicht automatisch. Gleichzeitig können verbleibende Install-Deployments eine Anwendung erneut installieren. [Uninstall applications](https://learn.microsoft.com/en-us/intune/configmgr/apps/deploy-use/uninstall-applications) | „Uninstall“, „Deployment löschen“, „Retire“ und „Application löschen“ sind vier verschiedene Vorgänge und dürfen im Runbook nicht vermischt werden. |
| Ab Configuration Manager 2107 kann ein Required Deployment beim Austritt aus seiner Zielcollection ein implizites Uninstall auslösen, wenn diese Option aktiviert ist. Microsoft warnt besonders bei automatisch ausgewerteten Collections vor unerwarteten Massenänderungen. [Uninstall applications](https://learn.microsoft.com/en-us/intune/configmgr/apps/deploy-use/uninstall-applications) | Für Core ist implizites Uninstall ein harter Vertragsverstoß. Abwahl bedeutet nur Ende des Targetings und darf weder eine Deinstallation noch eine Rückkonfiguration auslösen. |
| 32- und 64-Bit-Prozesse haben logisch getrennte Registry-Ansichten. [Registry Redirector](https://learn.microsoft.com/en-us/windows/win32/sysinfo/32-bit-and-64-bit-application-data-in-the-registry) | Ausführung und Detection müssen explizit auf native 64-Bit-Sicht geprüft werden. |
| `AppIntentEval.log`, `AppDiscovery.log`, `AppEnforce.log`, `CAS.log` und bei klassischen Packages `execmgr.log` haben unterschiedliche Rollen. [Configuration Manager log files](https://learn.microsoft.com/en-us/intune/configmgr/core/plan-design/hierarchy/log-files) | Diagnoseanleitung muss zunächst zwischen Application und Package/Program unterscheiden und dann die passenden Logs nennen. |
| Content- und DP-Group-Status unterscheiden Erfolg, laufende Verteilung und Fehler; Status kann nach einer Erholung zeitverzögert wirken. [Monitor content](https://learn.microsoft.com/en-us/intune/configmgr/core/servers/deploy/configure/monitor-content-you-have-distributed) | Der Installer berichtet pro Content-ID und Ziel-DP. Eine grüne Cmdlet-Rückkehr allein ist keine DP-Abnahme; ein alter Fehlerstatus allein ist umgekehrt ebenfalls kein sicherer aktueller Fehlerbeweis. |
| Content Validation prüft Dateien auf dem DP; Redistribute überschreibt den Content zur Reparatur. Clients benötigen außerdem eine passende Boundary-Group-Beziehung zum DP. [Manage distribution points](https://learn.microsoft.com/en-us/intune/configmgr/core/servers/deploy/configure/install-and-configure-distribution-points) | „Auf DP vorhanden“, „integritätsgeprüft“ und „für Pilotclient erreichbar“ werden als drei getrennte Gates behandelt. Redistribute ist Reparatur, nicht der normale Updatepfad. |
| Eine Collection-Auswertung verarbeitet direkte Regeln, schneidet das Ergebnis anschließend an der Limiting Collection und schreibt erst dann die Mitgliedschaft; abhängige Collection-Graphen können weitere Verzögerung erzeugen. [Collection evaluation](https://learn.microsoft.com/en-us/intune/configmgr/core/clients/manage/collections/collection-evaluation) | Direkte Core-Regel, ausgewertete Mitgliedschaft und Policyempfang sind drei Evidenzstufen. Limiting-Collection-ID, Updateauftrag und `colleval.log` gehören in Diagnose und Pilot. |
| Microsoft empfiehlt, Collection-Auswertungspläne und insbesondere inkrementelle Auswertung bewusst zu begrenzen. [Collections best practices](https://learn.microsoft.com/en-us/intune/configmgr/core/clients/manage/collections/best-practices-for-collections) | Core erzeugt genau eine stabile Collection und keine Collection pro Version/VM. Der Installer schaltet inkrementelle Auswertung nicht still ein; vorhandene Site-Policy wird inventarisiert und dokumentiert. |
| Der maximale Deployment-Type-Laufzeitwert entscheidet mit, ob eine Application in ein Wartungsfenster passt; ist kein Fenster lang genug, startet sie nicht. [Create applications](https://learn.microsoft.com/en-us/intune/configmgr/apps/deploy-use/create-applications) | Preflight und Pilot prüfen Maximum Runtime für jede Phase, vorhandene Wartungsfenster und die Einstellungen für Installation/Restart außerhalb eines Fensters. Ein Portaltransfer darf keinen sofortigen Start versprechen. |
| Wartungsfenster gehören zu Device Collections, gelten am Client und können sich aus mehreren Collection-Mitgliedschaften zusammensetzen. Content darf je nach Deployment außerhalb des Fensters geladen werden, die Ausführung benötigt jedoch ein passendes wirksames Fenster; zu kurze Fenster führen zur späteren erneuten Bewertung. [Use maintenance windows](https://learn.microsoft.com/en-us/intune/configmgr/core/clients/manage/collections/use-maintenance-windows) | Variante B darf nicht nur das Fenster der Core-Collection ansehen. Der Read-only MECM-Report muss die am konkreten Gerät effektiv relevanten Fenster, Zeitzone, Typ, nächste Ausführung und ausreichende Restdauer belegen; fehlende oder veraltete Evidenz blockiert den Transfer. |
| Eine simulierte Required-Bereitstellung wertet Detection, Requirements und Dependencies aus, ohne zu installieren. [Simulate application deployments](https://learn.microsoft.com/en-us/intune/configmgr/apps/deploy-use/simulate-application-deployments) | Vor dem ersten realen Pilot wird der Zielgraph simuliert. Die Simulation ersetzt nicht den echten Workgroup-/Restart-/Netzwerkpilot, fängt aber falsche Detection- und Dependencydefinitionen vor Fachaktionen ab. |
| Application-Policy wird beim nächsten Client-Policyzyklus geladen; Policy- und Enforcement-Schritte haben eigene Zustände und Logs. [Device deployment technical reference](https://learn.microsoft.com/en-us/intune/configmgr/apps/understand/device-deployment-technical-reference) | Migration wartet nicht eine pauschale Zeit, sondern prüft Assignment-ID, Policyempfang, Evaluation und Enforcement auf dem Pilotclient. |
| Contentermittlung, Download und Enforcement sind getrennte Clientschritte; die Dokumentation gibt keinen Vertrag, dass sämtliche späteren Inhalte einer mehrstufigen Dependency-Kette schon vor der ersten Fachaktion vollständig gecacht sind. [Application deployment download](https://learn.microsoft.com/en-us/troubleshoot/mem/configmgr/app-management/understand/deployment-download-technical-reference) | Dies ist eine vorsichtige Ableitung: Die netzwerkändernde Phase `client_staticip` wird als Endanwendung zuletzt ausgeführt. Der Pilot prüft zusätzlich CAS-/ContentTransferManager-/AppEnforce-Evidenz statt ein vollständiges Vorab-Caching anzunehmen. |
| Application Author, Application Deployment Manager und Application Administrator besitzen unterschiedliche Aufgaben; Microsoft empfiehlt minimale benötigte Rechte. [Role-based administration](https://learn.microsoft.com/en-us/mem/configmgr/core/understand/fundamentals-of-role-based-administration) | Runbook trennt Packaging/Reconciliation, Deployment und Cleanup in Rollen. Fehlende Sicht durch Security Scope darf nicht als „Objekt existiert nicht“ interpretiert werden. |
| Site-Backup sichert Content Library und Package Sources nicht automatisch; beide müssen separat gesichert werden. [Backup and recovery](https://learn.microsoft.com/en-us/intune/configmgr/core/servers/manage/backup-and-recovery) | Vor einem In-place-Upgrade wird der vorherige Content inklusive Manifest separat aufbewahrt. Revisions-Restore allein ist kein belegter Content-Rollback. |
| MECM kann frühere Application-Revisions wiederherstellen; eine Contentänderung erzeugt jedoch eine neue Revision beziehungsweise Content-ID, während Source-Dateien separat bestehen. [Revise and supersede applications](https://learn.microsoft.com/en-us/intune/configmgr/apps/deploy-use/revise-and-supersede-applications), [Deploy and manage content](https://learn.microsoft.com/en-us/intune/configmgr/core/servers/deploy/configure/deploy-and-manage-content) | Rollback bindet vier gespeicherte CM-Revisionsnummern an genau eine BundleId und deren Sourcehashes. Nur Revision oder nur Verzeichnis zurückzudrehen gilt als unvollständig. |
| Microsofts Content Library Cleanup Tool startet bewusst im What-if-Modus und benötigt für Delete erhöhte Rechte. [Content library cleanup](https://learn.microsoft.com/en-us/intune/configmgr/core/plan-design/hierarchy/content-library-cleanup-tool) | Verwaisten DP-Content nicht mit eigenen Dateilöschungen „aufräumen“. Ein späterer Cleanup bleibt ein eigener, zunächst read-only Betriebsvorgang. |
| `Import-PowerShellDataFile` importiert konstante Schlüssel/Werte aus einer `.psd1`, ohne deren Inhalt als Code auszuführen. [Import-PowerShellDataFile](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.utility/import-powershelldatafile) | Die Standort-SSoT wird als `.psd1` geladen; `Invoke-Expression` ist verboten. Der Projektvertrag prüft anschließend exakte Schlüssel, Typen und Werte. |
| `Get-Acl` liest und `Set-Acl` setzt den Security Descriptor; Microsoft empfiehlt bei breiten ACL-Änderungen zunächst `-WhatIf`. [Get-Acl](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.security/get-acl), [Set-Acl](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.security/set-acl) | Config, Archiv, Plan und Receipt werden bei jedem Lauf auf erwartete ACLs geprüft. Vererbte Schreibrechte für normale Benutzer blockieren; ACL-Reparatur bleibt ein sichtbarer `ShouldProcess`-Schritt. |
| Windows PowerShell 5.1 verwendet je nach Schreibcmdlet unterschiedliche Default-Codierungen; `Out-File` und Umleitung schreiben grundsätzlich UTF-16LE. [about_Character_Encoding](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.core/about/about_character_encoding) | `.psd1`, JSON-Plan, Manifest und Receipt erhalten eine explizite, getestete Codierung per .NET; keine Abhängigkeit von Profil, Kultur oder Cmdlet-Default. |
| `ConvertFrom-Json` behält bei doppelten Schlüsseln nur den letzten und behandelt Schlüssel ohne `-AsHashtable` case-insensitiv; `Test-Json` existiert erst ab PowerShell 6.1. [ConvertFrom-Json](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.utility/convertfrom-json), [Test-Json](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.utility/test-json) | Der Windows-PowerShell-5.1-Packagingpfad verwendet keine JSON-Konfiguration. Externe Mission-JSONs benötigen beim neuen `kind`-Feld einen Parser-/Contract-Test gegen doppelte oder nur in Groß-/Kleinschreibung abweichende Schlüssel, bevor daraus Core aufgelöst wird. |
| `SupportsShouldProcess` stellt `-WhatIf`/`-Confirm` bereit; die What-if-Präferenz wird über Modulgrenzen nicht in jedem Fall zuverlässig vererbt und soll an verschachtelte Writes explizit weitergegeben werden. [ShouldProcess](https://learn.microsoft.com/en-us/powershell/scripting/learn/deep-dives/everything-about-shouldprocess) | Config-, Graph- und Bundle-Cleanup-Schreiber rufen `ShouldProcess` unmittelbar vor jedem Write/Delete auf und testen verschachtelte Module separat. Eine Plan-ID bleibt zusätzlich Pflicht; `-WhatIf` allein ist keine Freigabebindung. |
| MECM kann Applications samt Dependencies und Content exportieren/importieren; ZIP und Begleitordner müssen zusammenbleiben. [Import and export applications](https://learn.microsoft.com/en-us/intune/configmgr/apps/deploy-use/import-export-applications) | Der VirtuSphere-Bundle- und Missionsexport ist davon klar abzugrenzen: Bundlearchive ersetzen keinen vollständigen MECM-Export, Mission-JSON überträgt nur Portal-Desired-State und niemals CM-Objektidentitäten oder Deployments. |
| OWASP empfiehlt bei Logs mindestens „wann, wo, wer, was“, korrelierbare Interaktions-IDs, Eingabesanitisierung und das Entfernen beziehungsweise Maskieren von Tokens, Passwörtern, Keys und anderen sensitiven Daten. [OWASP Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html) | PlanId, BundleId, Actor, Host, Operation, Zielobjekt, Ergebnis und UTC-Zeit werden strukturiert protokolliert; Secret-Redigierung und CR/LF-/Delimiter-Sanitisierung bleiben Teil des vorhandenen Loggingvertrags. |

## SSoT-Ziel und Driftgrenzen

### 1. Statischer MECM-Vertrag

`Get-VsClientAppSpecs` bleibt Eigentümer von:

- exaktem Application-Namen,
- Content-Unterordner und Einstiegsskript,
- Detection Key, Name, Typ und Sollwert,
- unmittelbarer Abhängigkeit,
- Erfolgscodes und Neustartverhalten,
- Kennzeichnung „interne Abhängigkeit“ oder „deploybarer Einstiegspunkt“,
- erwarteter 64-Bit-Ausführung und 64-Bit-Registry-Detection,
- erwartetem Content-Dateisatz.

Diese Daten dürfen nicht separat in Installer, Tests und Doku als unabhängig gepflegte zweite Wahrheit entstehen. Tests lesen die Spezifikation und prüfen lediglich die absichtlich dokumentierten Mirrors.

Zusätzlich gehören die erwarteten Anzeigenamen, Ordner und Skriptnamen in dieselbe Tabellenzeile. `AppName` darf weder als Scriptname noch als technische CM-Identität missverstanden werden. Nach erstmaliger Anlage beziehungsweise expliziter Adoption hält der Build-Beleg außerdem `CI_ID`/`ModelName` fest, damit ein umbenanntes oder im falschen Security Scope unsichtbares Objekt nicht als „fehlend“ neu erzeugt wird.

### 2. Paketinhalt

`Copy-VsClientContent` bleibt alleiniger Builder. Ein einzelnes Phasenskript darf niemals direkt als Application Content Source akzeptiert werden. Der Installer prüft vor jeder MECM-Mutation:

- alle erwarteten Ordner und Dateien,
- keine unerwartete Verwechslung von Datei und Ordner,
- SHA-256, Länge und relativen Pfad lokal gegen UNC,
- lesbare und schematisch gültige `bootstrap.json`,
- konsistente `Scheme`, `WebApi` und optionalen TLS-Modus in allen vier Bootstrap-Dateien.

Der gleiche Inhalt in vier Ordnern ist absichtlich redundant: Jede Application muss aus ihrem eigenen Cacheverzeichnis allein startfähig sein. Obwohl nur `client_getInfos.ps1` den Bootstrap liest, bleibt die Struktur für alle vier Pakete einheitlich. Falls diese Entscheidung später geändert wird, geschieht das ausschließlich über `RequiredFiles` in der Spezifikation und einen Contract-Test, nicht durch manuelles Weglassen.

### 3. Gewünschte Standortkonfiguration, Bundlearchiv und Build-Beleg

Der heutige Aufrufparameter `-WebApi` ist nach Ende des Konsolenlaufs nicht als freigegebener Auftrag nachvollziehbar und muss bei jedem Lauf erneut korrekt eingegeben werden. Vier Bootstrapkopien zeigen zwar das Ergebnis, aber weder Freigabe, Quellrevision noch zusammengehörigen Paketsatz. Die beschlossene SSoT ist deshalb `C:\ProgramData\VirtuSphere\MECM\ClientPackaging.psd1`. Sie wird sicher mit `Import-PowerShellDataFile -LiteralPath` geladen und enthält ausschließlich konstante, nicht geheime Standortwerte:

- `SchemaVersion`,
- `PackagesBase` und den exakt darauf zeigenden `ContentShare`,
- `WebApi`, `Scheme` und optional `CertThumbprint`,
- `DpGroupName`,
- `CoreLimitingCollectionId` als stabile MECM-ID, niemals als lokalisierter Name,
- `BundleArchivePath`, dokumentierter Standard `D:\VirtuSphere\Base\ClientBundles` außerhalb von `D:\VirtuSphere\Base\Packages`.

Der Projektinstaller initialisiert oder ändert diese Datei nur über einen eigenen validierten Modus, setzt beziehungsweise prüft restriktive ACLs über stabile SIDs für lokale Administratoren und SYSTEM und legt vor einer Änderung eine nachvollziehbare Vorgängerkopie ab. Explizit konfigurierte Backup-/Serviceidentitäten dürfen höchstens lesen; jeder unbekannte Writer blockiert. Der normale Validate-/Apply-Lauf besitzt keine stillen Einzelparameter-Overrides: Er nennt Dateipfad, SHA-256 und alle effektiven nicht geheimen Werte, bevor er einen Plan erstellt. Das verhindert sowohl Tippfehler bei jedem Lauf als auch einen unsichtbaren veralteten Default. Die Datei wird für Windows PowerShell 5.1 explizit als UTF-8 mit BOM geschrieben; JSON-Receipts und Manifeste werden per .NET explizit in UTF-8 ohne BOM geschrieben. `Out-File`, `>` und implizite Sitzungsvoreinstellungen sind dafür verboten.

`PackagesBase` und `ContentShare` werden nicht nur textuell verglichen. Der Initialisierungsmodus legt in der lokalen Source einen zufälligen, nicht geheimen Source-Identitätsmarker an und liest exakt denselben Marker über den UNC-Pfad zurück. Stimmen Identität und Inhaltsmanifest nicht überein, blockiert jeder Build. Staging liegt als Geschwisterverzeichnis auf demselben Volume wie die aktive Source, damit die Aktivierung nicht versehentlich zu einem nicht atomaren Cross-Volume-Move wird; ein fehlgeschlagener Rename durch Virenscanner, offene Handles oder Rechte beendet den Lauf vor dem ersten CM-Write.

Zu jedem erfolgreichen Paketsatz entsteht außerhalb der vier aktiven Application-Contentordner ein nicht geheimes, maschinenlesbares Build-Receipt mit mindestens:

- eindeutiger `BundleId` und Contract-/Schemasversion,
- UTC-Zeitpunkt und ausführendem Admin,
- Source-Git-Commit beziehungsweise explizitem Source-Manifest,
- effektiven Werten für `WebApi`, `Scheme` und Zertifikatfingerabdruck,
- relativem Pfad, Länge und SHA-256 jeder ausgelieferten Datei,
- MECM-Site, Application-Identitäten, Content-IDs und vorgesehenen DP-Zielen,
- Vorgänger-`BundleId` und Pfad zum nach Abschluss nicht mehr umgeschriebenen, hashgeprüften Rollback-Satz,
- Ergebnisstatus pro Gate.

Das Receipt ist ein belegter Buildzustand, kein Secret Store, keine Eingabekonfiguration und keine automatische Freigabe. Ein Default wie `virtusphere.lan:8021` darf weder in der Konfigurationsdatei noch im Script unbemerkt als Adminentscheidung entstehen.

Jedes erfolgreiche Archivverzeichnis enthält den vollständigen Vierer-Contentsatz, Manifest, Receipt und die zum Restore benötigten Graph-/Application-Metadaten. Die Aufbewahrungsregel ist im projektverwalteten Packaging-Vertrag fest, nicht frei pro Adminlauf: geschützt bleiben die fünf neuesten erfolgreichen Bundles sowie alle erfolgreichen Bundles der letzten 180 Tage. Technischer Owner der Konstanten `MinimumSuccessfulBundles = 5` und `MinimumAgeDays = 180` ist der versionierte Policy-Abschnitt in `Powershell-MECM/mecm/VirtuSphere-ClientPackaging.ps1`; die Standortdatei darf diese Werte nicht lockern oder überschreiben. Löschbar ist ein Bundle also nur, wenn mindestens fünf neuere erfolgreiche Bundles existieren **und** es mehr als 180 volle Tage alt ist. Unvollständige Builds werden als solche getrennt markiert und niemals als Rollbackkandidat angeboten. Cleanup ist ein eigener zweistufiger Vorgang mit What-if-Bericht, Plan-ID und explizitem Apply; der normale Installer räumt das Archiv nicht auf. Bedienung, Planprüfung und Recovery stehen ausschließlich im MECM-Runbook.

Für neue Installationen wird dieselbe `BundleId` additiv im Bootstrap transportiert. `getInfos` schreibt sie nach erfolgreicher Übernahme in die Registry; Folgephasen spiegeln sie nur als Diagnose-Provenance zu ihrem eigenen letzten Erfolg. Sie ist weder Autorisierung noch Ersatz für die fachlichen Detection Marker. Bei Bestandsclients ohne BundleId bleibt der Zustand `legacy/unknown`, bis ein ausdrücklich freigegebener Migrations- oder Repairpfad ihn belegt; der Installer erfindet keine Herkunft.

### 4. Laufzeitkonfiguration auf dem Client

Die Zielauflösung besitzt genau zwei zulässige Quellen:

1. vorhandene, als Satz validierte native HKLM-Konfiguration,
2. bei vollständig fehlender Erstkonfiguration der vollständig validierte Satz aus `bootstrap.json`.

Die derzeitige Teilmerge- und Fallback-Semantik wird nicht als Sollvertrag übernommen. `WebAPI` und `Scheme` sind gemeinsam erforderlich; `CertThumbprint` ist optional, muss bei Vorhandensein genau 40 Hexzeichen besitzen und ist nur für HTTPS zulässig. Die Entscheidungsfolge ist geschlossen:

| Native Registry vor dem Lauf | Gültiger Bootstrap | Ergebnis des normalen `client_getInfos`-Laufs |
|---|---|---|
| vollständig leer | ja | gesamten Bootstrap-Satz übernehmen, logisch committen, zurücklesen und erst danach API/Detection/ACK |
| vollständig, gültig und gleicher Konfigurationshash | ja | bestehenden Satz unverändert verwenden |
| vollständig und gültig, aber anderer Hash | ja | `configuration_drift`, keine Überschreibung; eigener Migration-/Repairauftrag erforderlich |
| partiell oder ungültig | beliebig | `configuration_invalid`, keine Feldmischung, kein DNS/IP-Fallback |
| vollständig und gültig | fehlt/ungültig | Packagingvertrag verletzt; Enforcement blockiert, selbst wenn ein alter Registrysatz funktionieren würde |

Da mehrere Registrywerte nicht physisch in einer Transaktion geschrieben werden, definiert der Vertrag logische Atomarität: Common berechnet einen kanonischen `ConfigHash`, schreibt Nutzwerte plus `ConfigSchemaVersion` unter einer maschinenweiten Mutex-Sperre und setzt `ConfigState=complete`, `ConfigHash` und `ConfigCommittedAtUtc` erst nach erfolgreicher Rückleseprüfung. Alle vier Phasen akzeptieren die Konfiguration nur, wenn Pflichtwerte, Schema, Hash und Commitmarker zusammenpassen. Ein Absturz kann dadurch Teilwerte hinterlassen, aber niemals einen gültigen Commit oder Detection-/ACK-Erfolg vortäuschen. Repair entfernt oder ersetzt einen solchen Rest nur nach eigener Vorschau.

Die Dokumentation sagt deshalb eindeutig: IP beziehungsweise Host stammen aus der geschützten Packaging-Konfiguration, gelangen über `bootstrap.json` in den ersten vollständigen Registry-Commit und werden danach aus der Registry gelesen. `virtusphere.lan`, eine eingebettete Notfall-IP und ein automatisch ausprobierter Alternativhost entfallen aus dem Zielvertrag.

Jede Phase schreibt unter ihrem eigenen nativen HKLM-Statuszweig mindestens `LastAttemptAtUtc`, `LastResult`, `CompletedAtUtc` bei Erfolg, `BundleId`, `ContractVersion`, `ScriptHash` und einen stabilen `LastErrorCode`. `client_getInfos` setzt seinen Detection-Erfolg und sendet den Client-Ready-ACK erst, nachdem der logisch committete Konfigurationssatz und der vollständige Snapshot aus derselben 64-Bit-Registry-Ansicht erfolgreich zurückgelesen und schematisch validiert wurden. Fehlertelemetrie darf einen früheren Erfolgsmarker nicht als Erfolg des aktuellen Versuchs ausgeben. Werte und Dateilog werden vor Secret-, Token- und Credential-Inhalten redigiert.

Diese Marker sind historische Ausführungsevidenz, keine kontinuierliche Compliance-Prüfung. Ändert ein Administrator später Hostname, IP oder Datenträgerzustand manuell, bleibt der frühere Abschluss sichtbar und ein normales Contentupgrade spielt die Phase nicht erneut ein. Eine beabsichtigte Wiederholung benötigt den getrennten Repair-/Migrationsvertrag.

### 5. Betriebs- und Diagnosevertrag

Der Installer gibt am Ende maschinenlesbar und menschenlesbar aus:

- Pfad/Hash und effektive nicht geheime Werte der Standortkonfiguration,
- lokale Paketquelle, Staging-Ziel und UNC-Contentquelle als drei getrennte Begriffe,
- Ergebnis pro Application,
- Deployment-Type- und Abhängigkeitsdrift,
- Status `content_validated`, `distribution_requested`, `distribution_ready` oder `blocked`,
- exakt zu deployende Endanwendung,
- Pfad zum dauerhaften Laufprotokoll.

Jede Aussage erhält eine Evidenzquelle. Beispiele: `content_source_equal` aus Hashmanifest, `distribution_requested` aus Cmdletannahme, `distribution_ready` aus Content-ID/DP-Status, `policy_received` aus Assignment-ID im Clientlog und `phase_complete` aus Detection plus VirtuSphere-Phasenlog. Der Text „bereit“ ist ohne benannte Evidenzstufe verboten.

Logging, Audit und langlebige Belege werden nicht vermischt:

| Vorgang | Laufprotokoll | Langlebiger Beleg | Pflichtfelder/Aussage |
|---|---|---|---|
| Config initialisieren/ändern | vorhandenes Serverlog `C:\Program Files\VirtuSphere\Logs\<Datum>_client-packaging.log` | versionierte Vorgängerkopie unter `C:\ProgramData\VirtuSphere\MECM\ClientPackaging\ConfigHistory` | Actor, UTC, Operation, alter/neuer ConfigHash, geänderte Feldnamen, ACL alt/neu, Ergebnis; keine Secrets und keine unredigierten unbekannten Werte |
| Validate | gleiches Serverlog, eigene CorrelationId | `C:\ProgramData\VirtuSphere\MECM\ClientPackaging\Plans\<PlanId>.json` | PlanId, ConfigHash, Source-/CM-Revisionen, vier App-/Content-Sollwerte, Findings, `no_write=true` |
| Apply/Bundlebau | gleiches Serverlog mit derselben PlanId | `<BundleArchivePath>\<BundleId>\receipt.json` plus Manifest, vier Contentsätze und Graphmetadaten | PlanId, BundleId, Vorgänger, ConfigHash, Actor, Host, UTC, Datei-/Application-/Content-IDs, jedes Gate und bestätigte Writes |
| Bundle-Cleanup Preview | gleiches Serverlog | `<BundleArchivePath>\_operations\cleanup-<PlanId>-preview.json` | vollständiger Archivindex, geschützte/Kandidaten-BundleIds, Alter, Rang, Hash, Bytes, Grund; keine Löschung |
| Bundle-Cleanup Apply | gleiches Serverlog | `<BundleArchivePath>\_operations\cleanup-<PlanId>-journal.jsonl` plus abschließendes `outcome.json` | identischer Preview-Hash, `ShouldProcess`-Entscheidung, vor jedem Delete persistiertes/gespültes Intent, danach Ergebnis, jedes LiteralPath-Ziel und freigegebene Bytes |
| Core im VM-Editor auswählen/abwählen | Portal-Audit über den zentralen Audit-Registry-Writer | Datenbank-Auditereignis pro einzelner VM-Änderung | VM als `object_id`, `user_id`, CorrelationId, alt/neu, `edit_version`, Transfer erforderlich; Auswahl ist kein Installationsnachweis |
| Mission-Clone/-Import | Portal-Audit, keine PowerShell-Datei | bestehendes Mission-Transfer-Audit additiv mit Upload-/Analysehash und Core-Zählern | Quelle/Ziel, Core übernommen/ausgelassen, betroffene Anzahl, normalisierter Grund; keine ResourceID, Membership oder Phasentelemetrie |

Das Serverlog verwendet das vorhandene sechs Felder umfassende Loggingmodul, 30 Tage Aufbewahrung, Secret-Redigierung, Größenbegrenzung und CorrelationId. Build-/Cleanup-Receipts sind dagegen Geschäftsbelege und folgen der Bundleaufbewahrung; sie dürfen nicht nach 30 Tagen zusammen mit Tageslogs verschwinden. Ein Ausfall des normalen Log-Sinks bleibt gemäß Loggingvertrag nicht fatal. Vor irreversibler Cleanup-Löschung sind jedoch der zurückgelesene Preview-Plan und ein separat schreibbares Journal fachliche Vorbedingungen. Unmittelbar vor jedem einzelnen Delete wird ein Intent mit Plan-/Kandidatenhash geschrieben und auf Datenträger gespült, danach das Ergebnis ergänzt. Ein Absturz zwischen beiden Einträgen erzeugt beim nächsten Start `cleanup_uncertain` und blockiert weitere Löschungen bis zur Re-Inventur; ein Abschlussbeleg kann logisch nicht vor der Löschung verlangt werden.

„Nach Abschluss nicht umgeschrieben“ bedeutet keinen kryptografischen Schutz gegen einen lokalen Volladministrator. Manifesthash, Vorgänger-BundleId und ConfigHash machen nachträgliche Änderungen erkennbar; echten Schutz vor Server-/Datenträgerverlust liefert nur das getrennte Filesystem-Backup. Config, aktive Package Sources, Bundlearchiv und MECM Content Library müssen deshalb gemeinsam im Betriebsbackup und Restore-Test stehen.

Das Portal-Audit bleibt ein geschlossener Vertrag über `Docker/WebAPI/lib/audit_event_definitions.php`; neue Felder werden dort registriert und ausschließlich über den zentralen Writer erzeugt. Für die Umsetzung gilt:

- Ein neues Ereignis `vm.core_assignment_changed` verwendet die VM als `object_id` und besitzt als typisierten Kontext mindestens `mission_id`, `old_selected`, `new_selected`, `edit_version`, `transfer_required` und den Auslöser `create` oder `edit`. Akteur, IP, UTC und CorrelationId kommen aus den vorhandenen Auditspalten. Ein einzelner Speichervorgang erzeugt genau eine Core-Zeile; der nachfolgende MECM-Transfer dupliziert dieses Desired-State-Ereignis nicht.
- Mission-Clone und -Import erzeugen keine Core-Zeile pro VM. `mission.transferred` wird additiv um `upload_sha256` bei Dateiimport, `analysis_sha256`, `core_selected_count`, `core_skipped_count` und einen geschlossenen Skip-Grund erweitert. Ein bewusst bestätigter Import ohne nicht auflösbaren Core erhält Auditresultat `warning`, nicht `success`; die Eventdefinition erlaubt und beschreibt beide Ergebnisse. Es enthält keine ResourceID, Collection-Membership oder Clientphasentelemetrie.
- Der bestehende Transfer-/Membership-Auditpfad protokolliert die spätere MECM-Aktion getrennt mit Desired-State-Revision und Core-Collection-ID. So bleibt erkennbar, ob nur ausgewählt, bereits übertragen oder tatsächlich vom Client verarbeitet wurde.
- Packaging-Tageslogs werden nicht in das Portal-Audit kopiert. PlanId, BundleId und ConfigHash sind die gemeinsamen Korrelationswerte zwischen Serverlog, langlebigem Receipt und Handover.
- Ein fehlgeschlagener Audit-Write darf eine transaktionale Portaländerung nicht als erfolgreich bestätigen. Core-Zuweisung und zugehöriges Auditereignis werden über einen gemeinsamen Serviceowner in derselben `repo_transaction()` geschrieben oder vollständig zurückgerollt; ein falscher Rückgabewert von `audit_event()` wird ausdrücklich in eine Exception überführt. Dasselbe gilt für `repo_mark_vm_for_mecm_resync()` und dessen `queued_mecm_transfer`-Audit, die heute noch nacheinander außerhalb einer gemeinsamen Transaktion aufgerufen werden.

### 6. Dokumentationsspiegel

Die Dokumentation ist kein zweiter Konfigurationsowner. Ihre Rollen sind fest:

- `Powershell-MECM/clients/README.md`: Entwicklervertrag der vier Phasen und ihrer Registry-/Exit-Semantik.
- `docs/operations/mecm-integration.md`: kanonisches Admin-Runbook für Aufbau, Upgrade, Pilot, Diagnose und Rückbau.
- `Powershell-MECM/README.md`: kurzer Einstieg und Verweis auf das Runbook, keine zweite vollständige Schrittfolge.
- Portal-Help DE/EN: kurze Erkennungshilfe für Betrieb und Helpdesk; keine kopierte Implementierungsreferenz.
- erzeugtes Build-Receipt: Istnachweis eines konkreten Laufs.

Exakte Application-, Ordner- und Skriptnamen sind unvermeidliche Mirrors und werden deshalb automatisiert gegen `Get-VsClientAppSpecs` geprüft. DE/EN-Help muss zusätzlich Schlüssel- und Bedeutungsparität bestehen.

Die geplanten Änderungen pro Dokument sind konkret begrenzt:

| Datei/Fläche | Verbindliche Ergänzung |
|---|---|
| `docs/operations/mecm-integration.md` | Config-Initialisierung und -Änderung, Validate/Apply, ACL-/SDDL-Prüfung, Source-ID, Limiting Collection, Plan-/Bundle-/Logpfade, Dependency-Umbau, Simulation, Wartungsfensterentscheidung, Pilot, Cutover-Freeze, Retention-Journal, Restore und getrenntes Filesystem-Backup; genaue Befehle nur hier. |
| `Powershell-MECM/clients/README.md` | Ablauf Config → Bootstrap → logisch committete native Registry ohne Fallback, vollständiger Vier-Dateien-Content, neue Skriptnamen, Zielreihenfolge, Exit-/Detection-/Registry-/Clientlogvertrag. |
| `Powershell-MECM/README.md` | Kurzer Einstieg, Rollen und Link zum kanonischen Runbook; keine kopierte zweite Bedienfolge. |
| `Docker/WebAPI/lang/{de,en}/help_packages.php` | Eigener Abschnitt für Core neben normalen Paketen, Core-Logpfad `C:\Program Files\VirtuSphere\Logs`, relevante MECM-Logs, drei getrennte Statusachsen und Hinweis, dass bei abgewähltem Core keine Phase läuft. |
| `Docker/WebAPI/lang/{de,en}/help_missions.php` | Stufe 5/5 und display-only `Core nicht beauftragt` ohne neuen Lifecyclewert, korrekte Reihenfolge Hostname → Datenträger → statische IP, Transfer V1/V2, Verhalten bei fehlendem Ziel-Core und Ausschluss aller Laufzeitwerte. |
| VM-Editor DE/EN | Wirkung des einen Core-Hakens, sichtbarer und bewusst abwählbarer Default nur bei neuen berechtigten VMs, Tasksequenzdrift, Warnung bei Nachreichen/Abwahl, nach Q1 Zeitpunkt/Wartungsfenster, expliziter MECM-Transfer und Link über den registrierten `help_url()`-Abschnitt. |
| Systemstatus-Help DE/EN | Readiness des Core-Katalogs, erwartete Collection-ID/Name/Marker/Limiting Collection, Regel-vs.-Evaluation-vs.-Policy, Drift-/Mehrdeutigkeitsblocker und Aussagegrenzen von gewünschtem Zustand, Membership und Clientphase. |

Die Hilfe nennt keine erfundene Wiederherstellungswirkung: Core-Abwahl entfernt nur zukünftiges Targeting beziehungsweise die besessene direkte Regel. Bereits geänderte Registry-, Hostname-, Datenträger- oder Netzwerkeinstellungen bleiben bestehen. Clone-/Import-Help erklärt zusätzlich, dass ein Zielsystem ohne eindeutigen aktiven Core-Katalogeintrag die Zuordnung sichtbar auslässt, statt anhand des Namens zu raten.

### 7. Portal-Core-Zuweisung und Provenienz

Die neue Oberfläche darf keinen zweiten, von der vorhandenen Paketzuweisung unabhängigen Desired State erzeugen. Die Owner werden so getrennt:

| Aussage | SSoT/Owner |
|---|---|
| Welche vier Core-Applications, Deployment Types, Detection-Werte und Dependencies existieren? | `Get-VsClientAppSpecs` und der Client-Packaging-Vertrag auf dem MECM-Server |
| Welche MECM-Collection und welcher Katalogeintrag repräsentieren den fachlichen Baustein Core? | Client-Packaging-Spezifikation für den Sollnamen; vom MECM-Katalog-Sync gelesene exakte Collection-ID für den Istbezug; expliziter Katalogtyp `core`, nicht Ordnerheuristik |
| Soll diese konkrete VM Core erhalten? | bestehende VM-Paket-Zuweisung `deploy_vm_packages`, erweitert um den typisierten Core-Katalogeintrag |
| Welche Zielcollection soll der Device-Sync herstellen? | `mecm_desired_targets()` aus der gespeicherten VM-Zuweisung und der synchronisierten Collection-Identität |
| Welche direkte Mitgliedschaft darf VirtuSphere später entfernen? | Provenienz `deploy_vm_mecm_rules` mit exakter Collection-ID, Regeltyp, Herkunft und Rolloutrevision |
| Ist die Regel in MECM tatsächlich vorhanden? | MECM-Istinventar des Device-Syncs |
| Hat der Client Policy, Content und Enforcement verarbeitet? | MECM-Clientstatus und Logs mit Application-/Assignment-/Content-ID |
| Welche Fachphase wurde abgeschlossen? | native Client-Registry-Detection plus Core-Phasenlog/Portaltelemetrie; keine Ableitung aus dem Portalhaken |
| Was zeigt das Portal bei Desired Core `aus`? | ein display-only Helper aus Desired State und unverändertem Lifecycle; kein neuer persistierter Lifecyclewert und keine Änderung des Machine-Wire-Vertrags |

Core darf nicht anhand des Namenspräfixes, einer frei übersetzten Kategorie oder der Position im Portal erkannt werden. Der technische Typ bleibt sprachneutral und wird nur für die Anzeige über DE/EN-`__t()` lokalisiert. Normale Autoimporter-Pakete behalten ihren bisherigen versionsbezogenen Retirement-/Upgradevertrag; Core erhält ausdrücklich keinen automatischen Nachfolgerwechsel.

Der heutige `mecm_Packages-TaskSeq-sync.ps1` liest nur Device-Collections unter `VirtuSphere_Applications`, während `install-VirtuSphere-Clients.ps1` lediglich Applications unter `VirtuSphere_Core` pflegt und bewusst kein Deployment erzeugt. Für das Portalziel ist deshalb ein expliziter additiver Katalogvertrag erforderlich:

- eine einmalige stabile Core-Device-Collection mit eindeutigem Managed Marker und gespeicherter Collection-ID,
- eine sprachneutrale Core-Spezifikation, die Sollname und Rolle besitzt und von Installer sowie Katalog-Sync gemeinsam gelesen wird,
- ein additives Payloadfeld `kind` (`software` als kompatibler Default, `core` für diesen Eintrag) und für Core die tatsächliche `collection_id`,
- neue Schemafelder wie `package_kind` und `mecm_collection_id`, statt `core` aus `package_name`/`package_version` abzuleiten,
- ein eigener Presence-/Retirement-Zweig für Core: Ein normaler `Package`-Payload darf Core weder retire noch auf eine Versionsnachfolge umlinken oder purgen,
- additive Wire-Tests für altes Payload ohne `kind`, neues Core-Payload, fehlende/wechselnde Collection-ID, Doppelname und leeren/partiellen Quellscan.

`mecm_packages.php` bleibt der Maschinenendpunkt für diesen Katalog. Die Erweiterung ist additiv: ältere Sync-Clients liefern weiter normale Softwarepakete; sie dürfen dabei einen bereits bekannten Core-Eintrag nicht zurückziehen. Core wird erst auswählbar, wenn ein neuer Sync die eindeutige Collection-ID bestätigt hat. `getDeviceList` liefert diese ID additiv bei der Core-Zuweisung; der Device-Sync vergleicht ID und Namen, statt bei einem gleichnamigen Objekt zufällig zuzugreifen. Änderungen am Wire-Vertrag werden gleichzeitig in `MachineApiWireTest` und der MECM-Dokumentation gespiegelt.

Die Datenmigration ist fail-closed und rückwärtskompatibel:

- `deploy_packages.package_kind` ist nicht NULL, besitzt die geschlossenen technischen Werte `software`/`core` und migriert jeden Bestandsdatensatz explizit zu `software`.
- `mecm_collection_id` ist nur für `core` zulässig, verwendet eine binäre/ASCII-exakte Identität und ist dort Pflicht. Softwarepakete erhalten niemals eine Collection-ID als Nebenbedeutung.
- Eine datenbankseitige Singleton-Sicherung, beispielsweise ein eindeutig indizierter generierter Schlüssel für `package_kind=core` plus aktiven Status, erlaubt höchstens einen aktiven Core-Datensatz auch bei zwei parallelen Syncs. Die Migration prüft vor dem DDL auf bestehende Konflikte und bricht ohne Teiländerung ab.
- Ein leerer, alter oder partieller Softwarekatalogpayload darf den Core-Datensatz weder retire, relinken noch purgen. Nur ein vollständiger neuer Core-Scan mit passendem Projektmarker darf dessen beobachtete Collection-ID bestätigen; ID-Wechsel bleibt ein eigener Migrationsauftrag.
- Repository- und Maschinenprojektionen listen Felder ausdrücklich auf. `SELECT *` wird weder für `getDeviceList` noch für den neuen Wire-Vertrag als bequemer Schemaexport verwendet.

## Portal-gesteuertes Core-Zielbild

### Gewünschter Ablauf

```text
Neue VM im MECM-Bereitstellungspfad
    -> Core standardmäßig ausgewählt
        -> typisierte VM-Paket-Zuweisung gespeichert und auditiert
            -> getDeviceList liefert Core als gewünschten Collection-Target
                -> Device-Sync reconciliert nur die eigene direkte Regel
                    -> stabile Core-Collection hat genau ein Required Deployment
                        -> deployte Endanwendung: client_staticip
                        -> tatsächliche Installationsreihenfolge:
                           client_getInfos -> client_hostname -> client_VMDisksOnline -> client_staticip
                        -> Detection, Reboot und Fehler bleiben je Phase sichtbar
```

Der Portalhaken ändert keine MECM-Application, kein Deployment und keinen Client unmittelbar. Er ändert ausschließlich den gewünschten Zielzustand dieser VM. Die bestehende optimistische `edit_version` schützt die Paketauswahl vor verlorenen Paralleländerungen; der normale Portal-POST-Vertrag liefert CSRF und `vms.write`. Der neue gemeinsame Save-Service umfasst Paketrewrites und Audit in derselben Repository-Transaktion, statt wie heute `repo_save_vm()` zu committen und erst danach separat zu auditieren.

Der sichtbare Standard bei einer interaktiven Neuanlage ist keine implizite Serverannahme: Das Formular sendet einen eindeutigen Core-Bereich-Sentinel und die konkrete Auswahl. So wird ein bewusst abgewählter, standardmäßig angehakter Core nicht mit „Feld fehlt, also Standard anwenden“ verwechselt. Edit, Clone und Import übergeben eine andere geschlossene Herkunft und können den Create-Default deshalb auch bei fehlendem Feld niemals auslösen.

### Dependency-Vertrag

1. Auf der stabilen Core-Collection existiert genau ein Install-Deployment: `client_staticip`, Zweck `Required`.
2. Der Deployment Type von `client_staticip` hängt mit `Auto Install` von `client_VMDisksOnline` ab; dieses hängt von `client_hostname` und dieses von `client_getInfos` ab. Daraus folgt die eindeutige, azyklische Ausführungsreihenfolge `client_getInfos` → `client_hostname` → `client_VMDisksOnline` → `client_staticip`.
3. Jeder Dependency-Eintrag verweist auf genau den erwarteten Deployment Type, nicht nur auf einen ähnlich benannten Anzeigenamen.
4. Die Kette bleibt innerhalb der von MECM unterstützten Tiefe und wird im Pflicht-Preflight auf Zyklen, fehlende Ziele, Mehrdeutigkeit, Priorität, Return Codes und Rebootverhalten geprüft.
5. Bei jedem Bundleupgrade werden alle vier Content-Quellen, Content-IDs und DP-Ziele geprüft und jede geänderte Dependency explizit neu verteilt. Das einmalige Deployment der Endanwendung reicht nicht als Distributionsnachweis der aktualisierten Vorgänger.
6. Eine Application Group, vier einzelne Required Deployments, eine Task Sequence oder ein klassisches Package/Program dürfen nicht parallel dieselbe Core-Kette orchestrieren.
7. Vier einzelne Portaloptionen sind vorerst nicht zulässig: In einem linearen Dependency-Graph fordert jede spätere Auswahl ihre Vorgänger automatisch mit an; „nur statische IP“ würde damit alle vier Phasen auslösen und „nur Datenträger“ weiterhin getInfos und Hostname. Granulare Phasen benötigen zuerst einen anderen fachlichen Graphen und eigene Reboot-/Netzwerk-Labortests.
8. Der Istgraph darf nicht unter aktivem Required-Targeting in den Zielgraphen umgebaut werden. Nach Entfernung und belegter Policy-Konvergenz der alten Core-Deployments wird zuerst `client_VMDisksOnline` → `client_staticip` entfernt, dann `client_VMDisksOnline` → `client_hostname` gesetzt, danach `client_staticip` → `client_VMDisksOnline` gesetzt und zuletzt die alte direkte Kante `client_staticip` → `client_hostname` entfernt. Vor jedem Write wird erneut auf Fremdänderung geprüft; ein Teilschritt erzeugt einen blockierenden Laufbericht und niemals eine produktive Freigabe.

### Abwahl und erneute Auswahl

- **Abwahl vor dem ersten Device-Sync:** Es entsteht keine Core-Regel und keine Core-Policy.
- **Erstmalige nachträgliche Auswahl bei einer Bestands-VM:** Sie ist ausdrücklich zulässig. Das Portal warnt vor möglichen Änderungen an Registry, Hostname, Datenträgern und IP, speichert den Desired State und überträgt ihn erst über den vorhandenen bewussten MECM-Transfer. Danach fordert MECM die vollständige Vierer-Kette gemäß Detection an.
- **Abwahl nach gesetzter Mitgliedschaft, aber vor Installation:** Der Device-Sync entfernt nur die eigene Regel. Bis die Collection- und Client-Policy konvergiert sind, bleibt der Zustand sichtbar `Abwahl ausstehend`; ein bereits gestartetes Enforcement wird nicht als sicher abgebrochen behauptet.
- **Abwahl nach erfolgreicher Ausführung:** Nur künftiges Targeting endet. Bereits gesetzte Registrywerte, Hostname, IP und Datenträgerzustände bleiben bestehen.
- **Erneute Auswahl:** Die Regel wird erneut hergestellt. Erfüllte Detection Marker verhindern einen normalen Replay; fehlende Marker können die betreffende Dependency erneut anfordern. Das ist keine Reparaturgarantie und muss vor Bestandsgeräten bewusst geprüft werden.
- **Manuelle MECM-Mitgliedschaft:** Eine fremde oder manuelle Regel bleibt erhalten. Das Portal zeigt dann `nicht durch VirtuSphere besessen` statt sie zu entfernen oder den Policyentzug zu versprechen.
- **VirtuSphere-Regel wurde manuell entfernt, Core bleibt ausgewählt:** Der nächste Device-Sync stellt die besessene Sollregel nach Journal-/Istprüfung wieder her. Wer das verhindern will, muss zuerst den Portal-Desired-State abwählen; ein manueller MECM-Eingriff ändert die Portalabsicht nicht.
- **Regel geschrieben, Collection noch nicht ausgewertet:** Status bleibt `Collection-Auswertung ausstehend`. Erst beobachtete effektive Mitgliedschaft erlaubt den Übergang zu `Mitglied`; erst Client-Assignment-/Policyevidenz erlaubt den nächsten Status.
- **Core nie beauftragt:** Das Portal zeigt `Core nicht beauftragt` als erwartete, aus der Zuweisung abgeleitete Ansicht. Es schreibt dafür keinen neuen Lifecyclewert. Wird Core nach einem früheren 5/5 abgewählt, bleibt der historische Abschluss erhalten und die separate Desired-State-Achse zeigt `aus`.

### Tasksequenz-Berechtigung und Katalogdrift

- Der technische Berechtigungsowner ist der exakte gespeicherte `vm_os`-Wert gegen genau einen aktiven, aus MECM synchronisierten `deploy_os`-Datensatz. `vm_guest_id`, Anzeigename, Präfix und frei gepflegte OS-Familie entscheiden nicht.
- Bei einer interaktiv neu angelegten VM materialisiert der Repository-Save den Core-Default nur dann, wenn diese Berechtigung in derselben Transaktion noch gültig ist. Rendering allein schreibt nichts.
- Das interaktive Create-Formular sendet einen Presence-Sentinel. Sentinel vorhanden und Checkbox nicht gesetzt bedeutet explizit `Core aus`; ein fehlender Core-Formbereich gilt als ungültiger beziehungsweise alter Request und darf den Default nicht serverseitig erraten. Für Edit, Clone und Import ist der Herkunftstyp explizit und der Default immer gesperrt.
- Ändert ein Admin `vm_os` auf einen nicht aktiven oder mehrdeutigen Wert, während Core ausgewählt ist, blockiert der Save am Feld statt Core still abzuwählen. Der Admin entscheidet sichtbar zwischen gültiger Tasksequenz und Core-Abwahl.
- Wird die zuvor passende Tasksequenz später retired oder fehlt nach einem partiellen Katalogscan, bleibt eine vorhandene Core-Zuweisung gespeichert, wird aber als `Berechtigungsdrift` angezeigt und nicht automatisch entfernt. Abwahl bleibt möglich; Nachwahl, Clone und neuer Transfer blockieren, bis der Katalog wieder eindeutig ist.
- Template-Clone prüft den Zielzustand erneut. Mission-Import darf nach bereits bestätigter Vorschau den nicht auflösbaren Core auslassen; der normale interaktive Create-Default wird in Clone-/Importpfaden nie nachträglich angewandt.

### Sichere Einführung und Cutover

1. Core-Katalogtyp, stabile projektverwaltete Collection-Identität und das eine Required Deployment zunächst ohne produktive VM-Zuweisungen anlegen. Existiert bereits eine beabsichtigte Zielcollection, darf nur ein expliziter projektversionierter Migrationspfad ihre ID und den vollständigen Vertrag übernehmen; Name oder Ordner allein genügen nie.
2. Deploymentvertrag prüfen: Install, Required, nur Endanwendung, richtige Deadline/Wartungsfenster, maximale Laufzeiten, Outside-Window-/Restart-Verhalten, **kein implizites Uninstall**, keine konkurrierende Application Group oder Task Sequence.
3. Den vollständigen Zielgraphen zuerst als Required-Simulation gegen eine dedizierte Testcollection auswerten; Detection, Requirements und Dependencies müssen grün sein, ohne Fachaktionen auszuführen. Ergebnis und Simulations-ID werden belegt; die Simulation wird vor dem echten Required-Pilot beendet und ihre Entfernung verifiziert, damit sie kein zweiter dauerhaft aktiver Assignmentpfad bleibt.
4. Alle vier Inhalte auf den vorgesehenen DPs abnehmen und eine frische Workgroup-Pilot-VM ausschließlich über die neue Core-Zuweisung ausrollen.
5. Bestehende VMs beim Daten-/Feature-Cutover nicht automatisch mit Core verknüpfen. Nur neue, noch nicht an MECM übergebene VMs mit aktiver MECM-Tasksequenz erhalten beim Anlegen den gespeicherten Default `ausgewählt`.
6. Zielmengen von altem `Deploy Windows 2022`-Deployment und neuer Core-Collection vergleichen. Überlappung ist bis zur bewussten Cutover-Freigabe sichtbar und blockiert Produktion.
7. Für das deploymentfreie Umbaufenster neue PXE-/VM-Bereitstellungen einfrieren oder in einer sichtbaren Warteschlange halten. Sonst kann eine während der Lücke erzeugte VM weder zuverlässig den alten noch den neuen Core-Auftrag erhalten.
8. Das alte Core-Install-Deployment auf `Deploy Windows 2022` deaktivieren beziehungsweise entfernen und Policy-Konvergenz über die alte Assignment-ID belegen. Applications, Dependencies, Clientzustand und Detection Marker werden dabei nicht gelöscht.
9. Erst im nachweislich deploymentfreien Fenster beide Skriptnamen, den exakten Dependency-Graphen und die neue Endanwendung umstellen; alle vier neuen Content-IDs verteilen und den Workgroup-Pilot vollständig abnehmen.
10. Erst danach das eine neue Required Deployment von `client_staticip` auf der stabilen Core-Collection und die Portal-Core-Zuweisung für neue produktive VMs freigeben. Bestandsgeräte bleiben unverändert und können einzeln über einen dokumentierten Review ausgewählt werden.
11. Rollback des Cutovers bedeutet: neue Zuweisungen stoppen, Provenienz und Policies auswerten und den getesteten Zielpfad wiederherstellen. Es bedeutet niemals, bereits ausgeführte Netzwerk-, Hostname- oder Datenträgeraktionen pauschal zurückzudrehen.

### Noch zu bestätigende Betriebsentscheidung Q1: Ausführungszeitpunkt

Der bisherige Auftrag entscheidet bewusst **wer** Core erhält, aber noch nicht abschließend **wann** ein neu hinzugefügter Bestandsserver die disruptiven Phasen ausführen darf. Ein Required Deployment kann nach Policyempfang und Deadline ohne weiteres Portal-Klicken starten; Abwahl garantiert keinen Abbruch eines bereits empfangenen Auftrags.

- **Variante A – nach explizitem Transfer so bald wie MECM zulässt:** Einfach für frisch provisionierte VMs; der Bestätigungsdialog muss bei Bestandsservern deutlich sagen, dass Hostname/Restart/Datenträger/IP nach dem nächsten Policyzyklus beginnen können.
- **Variante B – vorhandenes MECM-Wartungsfenster ist Pflicht (empfohlen für Bestandsserver):** Der Transfer blockiert bei fehlendem oder zu kurzem Fenster; Outside-Window-Installation und -Restart bleiben deaktiviert. Frische Provisionierungsgeräte benötigen dann ebenfalls ein ausreichend langes Fenster oder einen ausdrücklich getrennten Pilot-/Provisionierungspfad.

Bei Variante B stammt die Entscheidung aus einem frischen read-only MECM-Bericht für das konkrete Gerät, nicht aus einem Portal-Default und nicht nur aus der Core-Collection. Der Bericht berücksichtigt wirksame Fenster aller relevanten Collection-Mitgliedschaften, Zeitzone, Fenstertyp, nächste Gelegenheit sowie die gesamte benötigte Lauf-/Restartreserve. Fehlt die Evidenz, ist sie älter als der definierte Freshness-Zeitraum oder reicht kein Fenster aus, blockiert der Transfer fail-closed. Das Portal fragt MECM dabei nicht spontan im Save-Request ab, sondern verwendet nur den versionierten, zeitgestempelten Synchronisations-/Reportstand.

Bis diese Wahl bestätigt ist, bleiben Deadline, Maximum Runtime, Wartungsfenster und Outside-Window-Flags Stop-Gate und dürfen nicht vom Installer verändert werden.

## Gefundene Lücken

| Priorität | Lücke | Risiko | Geplante Abhilfe |
|---|---|---|---|
| P0 | Code und Doku benennen dieselbe Kette unterschiedlich: `client_getInfos`/`client_VMDisksOnline` gegenüber `client_getinfo`/`Set-VMDisksOnline`. | Admin legt anhand der Doku neue, parallele CM-Objekte mit Skriptnamen an. | Einstiegsskripte projektgesteuert in `client_getInfos.ps1` und `client_VMDisksOnline.ps1` umbenennen; in allen Tabellen vier Spalten verwenden und exakte Mirrors durch Pester/Doc-Gate gegen die Spezifikation prüfen. |
| P0 | Das bisherige Legacy-Ownership akzeptiert ein gleichnamiges Objekt im Ordner `VirtuSphere_Core` auch ohne Managed Marker. | Fremdes oder manuell fehlerhaftes Objekt kann vom Installer als eigener Bestand behandelt werden. | Folder-only-Adoption entfernen. `client_getinfo` und `client_getinfo_2.1` niemals übernehmen; nur read-only inventarisieren, kontrolliert enttargeten und zunächst retire. Exakte Zielobjekte und Core-Collection ausschließlich über Projektmarker und stabile IDs verwalten. |
| P0 | 32-/64-Bit-Ausführung und Registry-Detection werden nicht vollständig als Drift geprüft. | Script schreibt in eine Registry-Ansicht, Detection liest die andere. | Native Ausführung und Detection-View aus CM-XML/Objektmodell validieren; Abweichung blockiert. |
| P0 | Ähnlich benannte Altanwendungen werden nicht inventarisiert. | Alte Deployments melden Erfolg oder konkurrieren mit der neuen Kette. | Read-only Legacy-Scan nach Namen, Marker, Content, Deployment, Dependency, Supersedence und Task-Sequence-Referenz. |
| P0 | Erfolgreicher Aufruf zur Content-Verteilung beweist nicht, dass jeder DP den Content besitzt. | Client erhält alten/unvollständigen Cache. | Asynchronen Zustand korrekt ausgeben; optional begrenzt auf DP-/DP-Group-Erfolg warten und Blocker melden. |
| P0 | Das Rückbauverfahren für abhängige Altanwendungen ist nicht dokumentiert. | Löschen bricht aktive Dependency Graphs, Task Sequences oder Reinstall-Policies. | Separates, nicht destruktives Migrations- und Rückbau-Runbook mit Referenzgraph und Stop-Gates. |
| P0 | Vorheriger Package Source wird nach atomarem Verzeichnistausch nicht dauerhaft als Rollback-Satz behalten. | Eine CM-Revisionswiederherstellung kann die alten Quelldateien nicht zuverlässig rekonstruieren oder erneut verteilen. | Unveränderliche, zugriffsgeschützte Bundleablage mit Manifest, Retention und getesteter Restore-Prozedur. |
| P0 | Der erste CM-Blocker kann erst auftreten, nachdem vorherige Applications bereits geändert oder zur Verteilung angestoßen wurden; auch aktive Sourceordner werden heute schon vor der vollständigen CM-Inventur ersetzt. | Halber Vierer-Vertrag trotz Exit 1 oder neue Source bei altem CM-Vertrag. | Zuerst nur in private Bundle-Stagingpfade schreiben; vollständige read-only Inventur und Reconciliation-Plan für alle vier Objekte vor jeder Sourceaktivierung/CM-Mutation; Apply exakt an diesen Plan binden und vor jedem Write auf Fremdänderung prüfen. |
| P0 | Ein vorhandener `WebAPI`-Wert verhindert heute den gesamten Bootstrapimport, selbst wenn `Scheme` oder Thumbprint fehlt/ungültig ist. | HTTP/HTTPS- oder Zertifikatsmischzustand; Verhalten hängt von Defaults statt vom Paketauftrag ab. | Standortkonfiguration als atomaren Satz validieren; partielle Bestände blockieren oder über einen ausdrücklich autorisierten Migrationspfad ersetzen. |
| P0 | Common probiert heute nach der Registry weiterhin den hartcodierten DNS-Namen `virtusphere.lan:8021` und optional eine Paket-IP. | Ein falsch paketierter Client kann einen anderen Server erreichen und den Fehler scheinbar „selbst heilen“; Bootstrap-/Registry-Drift bleibt unsichtbar. | Zielvertrag ohne hartcodierte Kandidaten; geschlossene Leer/Gleich/Abweichend/Partiell-Matrix und stabiler Driftcode. |
| P0 | Mehrere Registrywerte werden einzeln geschrieben, obwohl der Plan bisher von „atomarem Satz“ sprach. | Ein Absturz kann Teilwerte hinterlassen, die ein anderer Phasenscript als gültig liest. | Logischer Commit mit Schema, kanonischem ConfigHash, Mutex und zuletzt gesetztem Complete-Marker; alle Leser validieren den vollständigen Commit. |
| P0 | Clientmarker belegen den Fachzustand, aber nicht, welches Bundle ihn erzeugt hat. | Alte oder manuelle Marker können die neue Application vor Ausführung als installiert erkennen lassen. | Additive Provenance je Phase (`BundleId`, ContractVersion, Zeit, ScriptHash) zunächst nur diagnostisch schreiben; Detection erst nach eigener Migrationsentscheidung verschärfen. |
| P0 | Core ist heute dauerhaft über die OS-Collection `Deploy Windows 2022` targetbar; deren Mitgliedschaft wird betrieblich nicht zuverlässig bereinigt. | Bestandsserver bleiben einer zustandsverändernden Required Policy ausgesetzt; ein neues Targeting kann mit der alten Policy überlappen. | Core auf eine stabile, portalbefüllte Collection verschieben; alte Assignment-ID erst nach Pilot und nachgewiesener Policy-Konvergenz entfernen. |
| P0 | `deploy_packages` kennt noch keinen fachlichen Typ `core`; der Katalog-Sync liest nur `VirtuSphere_Applications`, während der Client-Installer keine Core-Device-Collection anlegt. | Der Portalhaken hätte kein verlässlich synchronisiertes Ziel; Namensheuristik, automatisches Retirement/Relinking oder Purge könnten Core falsch klassifizieren. | Stabile markierte Core-Collection, additive `kind`/Collection-ID-Synchronisation und geschlossene Core-Semantik ergänzen; Core von normalem Versionsnachfolger-/Purgeverhalten ausschließen und durch Wire-/Contract-Tests pinnen. |
| P0 | Ein rein repositoryseitiges `SELECT`, dass nur ein aktiver Core existiert, schützt nicht vor zwei parallelen Katalog-Syncs. | Zwei Core-Zeilen machen Checkbox, Import und Collectionziel mehrdeutig. | Datenbankseitiger Singleton-Guard für aktiven Core plus Transaktions-/Paralleltest; Bestandskonflikt blockiert die Migration vor DDL. |
| P0 | Eine direkte Membership-Regel kann erfolgreich geschrieben sein, aber wegen Limiting Collection oder noch offener Evaluation keine effektive Mitgliedschaft ergeben. | Portal meldet „Mitglied“, obwohl das Gerät nie Policy erhalten kann. | Limiting-Collection-ID als Konfiguration; Regel, Auswertung, effektive Mitgliedschaft und Policy als getrennte Zustände mit `colleval.log`-/Assignment-Evidenz. |
| P0 | Eine Portalabwahl könnte bei aktiviertem MECM-„Implicit uninstall“ eine Clientdeinstallation auslösen. | Unvorhersehbare Massenwirkung und falsche Erwartung, Hostname/IP/Disks würden zurückgesetzt. | Deployment-Preflight blockiert aktiviertes implizites Uninstall; Help und Bestätigungsdialog sagen ausdrücklich „Targeting entfernen, keine Rückkonfiguration“. |
| P0 | Bestands-VMs könnten bei Einführung eines standardmäßig gesetzten Core-Hakens unbeabsichtigt nachselektiert werden. | Fehlende Marker lösen auf handgepflegten Systemen Hostname-, Netzwerk- oder Storageaktionen aus. | Migration und Seitenrendering setzen keinen Default rückwirkend; Default ausschließlich beim Speichern einer neuen, noch nicht an MECM übergebenen VM mit aktiver MECM-Tasksequenz materialisieren und Bestandsauswahl einzeln auditieren. |
| P0 | Bei HTML-Checkboxen fehlt ein POST-Wert sowohl bei „bewusst abgewählt“ als auch bei einem alten/partiellen Request. | Ein serverseitiger „wenn fehlt, dann Create-Default“-Fallback könnte einen bewusst entfernten Haken wieder aktivieren oder bei Edit/Clone/Import erstmals Core hinzufügen. | Presence-Sentinel plus geschlossene Requestherkunft einführen. Nur vollständiger `interactive_create` darf den sichtbaren Default materialisieren; Sentinel ohne Checkbox speichert explizit `aus`, alle anderen fehlenden Felder blockieren oder bewahren den bestehenden Zustand. |
| P0 | Die Portal-Lifecycle-Stufe 5/5 wird durch den ACK von `client_getInfos` erreicht. Bei bewusst abgewähltem Core läuft dieser ACK vertragsgemäß nicht. | Eine zulässige VM ohne Core könnte dauerhaft wie ein Fehler auf 4/5 wirken; ein neuer gespeicherter Status würde dagegen den exakten Machine-Wire-Vertrag und dessen Consumer brechen. | `Core nicht beauftragt` ausschließlich als display-only Erwartungszustand aus Desired State ableiten; weder 4/5 als Fehler behandeln noch 5/5 erfinden. Persistierte Lifecyclewerte und ACK-Owner bleiben unverändert. Help und Statusmodell erklären, dass ohne Core keine der vier Phasen läuft. |
| P0 | Die heutige Kette ändert die IP vor der Datenträgerphase; ein vollständiges Vorab-Caching aller späteren Dependency-Inhalte ist nicht vertraglich belegt. | Neue IP, Route oder Boundary kann den noch folgenden Content-/Policypfad unterbrechen. | Zielgraph auf `getInfos` → `hostname` → `VMDisksOnline` → `staticip` umstellen, nur `client_staticip` deployen und Download-/Reboot-/Enforcement-Reihenfolge im realen Pilot anhand der Clientlogs belegen. |
| P0 | Während des Fensters zwischen altem OS-Deployment und neuem Core-Deployment könnten neue PXE-VMs entstehen. | Ein Gerät fällt durch beide Targetingpfade oder erhält einen nur teilweise umgebauten Graphen. | Für das Umbaufenster Provisionierung einfrieren/queued halten; Start- und Endbestand sowie nachträgliche Freigabe anhand VM-/Assignment-IDs belegen. |
| P0 | Zeitpunkt und Wartungsfenster für eine bewusst nachgereichte Bestands-VM sind noch nicht entschieden. | Ein expliziter Transfer kann kurz danach Hostname, Restart, Storage und Netzwerk im Produktivbetrieb verändern oder wegen eines zu kurzen Fensters dauerhaft hängen. | Betriebsentscheid Q1 vor Implementierung bestätigen; Deadline, maximale Laufzeiten sowie Outside-Window-Install/Restart bis dahin als Stop-Gate behandeln. |
| P0 | Ein Wartungsfenster nur an der Core-Collection zu prüfen bildet die am Client wirksamen Fenster nicht vollständig ab; mehrere Collection-Mitgliedschaften, Typ und Zeitzone beeinflussen die Ausführung. | Portal könnte einen sicheren Termin behaupten, obwohl das Gerät früher, später oder gar nicht ausführt. | Frischen, zeitgestempelten MECM-Report für das konkrete Gerät als einzige Fensterevidenz definieren; keine Liveabfrage im Save, keine UI-Heuristik. Fehlende/veraltete Evidenz oder unzureichende Restdauer blockiert Variante B. |
| P1 | Ein vorhandener Deployment Type ohne bisherige DP-Zuweisung wird möglicherweise wie ein Update statt wie eine Erstverteilung behandelt. | Kein Content am Ziel-DP. | Ist-Verteilung je Application ermitteln und bewusst zwischen initial distribute, update und redistribute unterscheiden. |
| P1 | Bei bestehenden Applications aktualisiert der Installer vorhandene DPs, weist aber die übergebene `DpGroupName` nicht nachweislich als Ziel zu. | Der Parameter suggeriert einen Zielzustand, der bei Bestandsobjekten nicht hergestellt wird. | Soll-/Ist-DP-Matrix pro Application; fehlende Zielzuweisung explizit hinzufügen, unerwartete zusätzliche Ziele nur melden und nicht automatisch entfernen. |
| P1 | Portal-Hilfe erklärt Logs und Phasen, aber nicht vollständig Bootstrap und Vier-Dateien-Content. | Admin legt erneut nur das Einstiegsskript an. | DE/EN-Hilfe und Betriebsdoku um ein identisches „Was liegt im ccmcache?“-Beispiel ergänzen. |
| P1 | „Nur Endanwendung deployen“ ist nicht prominent genug. | Vier unabhängige Deployments erzeugen Rennen und unklare Zustände. | Beschreibungen in CM und Abschlussausgabe des Installers; Runbook mit einem einzigen Deployment. |
| P1 | Client-README und Runbook zeigen die Dependency-Kette mit Skriptnamen, obwohl Admins in MECM andere Anzeigenamen sehen. | Erneute manuelle Doppelanlage wie `client_getinfo_2.1`; falsche Endanwendung wird deployed. | Jede Doku-Tabelle trennt CM-Identität, Anzeigename, Contentordner und Einstiegsskript; Core-Help nennt nur den fachlichen Namen und verlinkt auf das Runbook. |
| P1 | Portal-Hilfe „Pakete“ trennt generische Paketwrapper-Logs nicht von Core-Phasenlogs. | Helpdesk sucht unter `%ProgramData%`/`%LOCALAPPDATA%`, obwohl Core unter `C:\Program Files\VirtuSphere\Logs` protokolliert. | Zwei ausdrücklich benannte Diagnoseabschnitte mit jeweiligem Objekttyp, Logpfad, CM-Logs und Aussagegrenze. |
| P1 | Portal-Auswahl und beobachteter Installationszustand sind noch nicht als getrennte Achsen modelliert. | Ein gesetzter Haken wird als „installiert“ oder eine entfernte Regel als „zurückgebaut“ verstanden. | Desired-State-Haken, Sync-/Membershipstatus und Phasenstatus getrennt darstellen; jede Anzeige nennt Evidenz und Empfangszeit. |
| P1 | Stable Detection Marker unterscheiden nicht automatisch alte und neue Content-Revisionen. | Ein Content-Upgrade wird auf bereits erkannten Clients nicht erneut ausgeführt. | Upgradeklasse explizit bestimmen: reine Paket-/Helper-Korrektur, freiwillige Reparatur oder bewusstes Phase-Replay. Kein stilles Marker-Bumping. |
| P1 | `PackagesBase`, `ContentShare` und der serverseitige allgemeine Package-Share sind leicht zu verwechseln. | Installer prüft oder verteilt den falschen Ordner. | Preflight-Tabelle mit Zweck, effektivem Pfad, Erreichbarkeit und erwarteter Struktur. |
| P1 | Lokaler Package-Source-Pfad und UNC können textuell plausibel aussehen, aber auf verschiedene Shares/Volumes zeigen. | Hashprüfung erfolgt gegen einen anderen Inhalt als MECM verteilt. | Zufälligen Source-Identitätsmarker und Manifest über beide Zugriffspfade lesen; Staging nur als Same-Volume-Geschwisterpfad aktivieren. |
| P1 | „Client-Applikationen bereit“ fasst Source, CM-Definition, Distribution und Clientausführung zu grob zusammen. | Grüne Schlusszeile wird als Ende des Rollouts gelesen. | Evidenzstufen mit eigenem Status und Exitcode; Abschluss nennt den nächsten menschlichen Schritt und offene Gates. |
| P1 | Fehlende Helfer können vor Initialisierung des Phasenlogs scheitern; vorhandene Detection kann Enforcement vollständig überspringen. | Kein VirtuSphere-Log wird fälschlich als „Skript lief nicht“ oder „alles okay“ interpretiert. | Diagnosematrix aus CM- und VirtuSphere-Logs; früher, selbstgenügsamer Paket-Guard beziehungsweise verlässliche AppEnforce-Evidenz planen. |
| P1 | Security Scopes und RBAC-Sicht werden nicht als Inventargrenze ausgewiesen. | „Nicht gefunden“ kann „für diesen Admin unsichtbar“ bedeuten; Duplikat oder unvollständiger Report. | Site-/Provider-/Rollen-/Scope-Preflight; Unsichtbarkeit oder unzureichende Rechte blockiert statt neue Objekte anzulegen. |
| P1 | Der heutige Clientinstaller nutzt den gemeinsamen serverseitigen Loggingvertrag noch nicht als durchgängigen, korrelierbaren Betriebsnachweis. | Konsolenerfolg, CM-Mutation und Buildbeleg lassen sich nach einer Schichtübergabe nicht sicher zusammenführen; ein früher Fehler kann ohne dauerhafte Spur bleiben. | Vor jedem fachlichen Schritt Logger plus CorrelationId/PlanId initialisieren; strukturierte Tageslogs und getrennte langlebige Plan-/Receipt-Dateien erzeugen, frühe Loggerfehler zusätzlich über Exitcode und Konsole sichtbar halten. |
| P1 | Der bestehende VM-Änderungsaudit bildet Kindzuweisungen nicht als eigenen, atomaren Core-Vorgang ab. | Core-Auswahl kann in einem allgemeinen VM-Diff fehlen oder doppelt mit dem MECM-Transfer protokolliert werden. | Geschlossen registriertes `vm.core_assignment_changed`, exakt ein Desired-State-Ereignis pro Transaktion; späteren Membership-Transfer getrennt auditieren. |
| P1 | `repo_save_vm()` committet heute vor dem separaten `vm.changed`-Audit; auch der MECM-Transfer markiert und auditiert nacheinander. | Auditfehler kann einen gespeicherten Core-Zustand oder Transferauftrag ohne Spur hinterlassen. | Gemeinsamer Serviceowner mit einer `repo_transaction()`; `audit_event() === false` wirft und rollt Fachwrite zurück. Clone/Import bleiben aggregierte Mission-Ereignisse. |
| P1 | Mission-Transfer serialisiert beziehungsweise löst Pakete heute nur über Name/Version auf und kennt keinen Typ; die bestehende Formatprüfung akzeptiert nur exakt Version 1. | Ein nur additiv eingefügtes `kind` würde von einem alten Importer ignoriert und könnte Core als normales Paket auflösen. Naive Raw-JSON-RegEx-Prüfung wäre bei Escapes und verschachtelten Objekten ebenfalls unzuverlässig. | Export auf Formatversion 2 anheben, V1 im neuen Importer ausschließlich als Software lesen und V2 vollständig typisieren; `core` nur gegen genau einen aktiven Core-Katalogeintrag auflösen. Doppelte/case-variierte Schlüssel vor Decode mit einem begrenzten string-/escape-/nesting-bewussten Tokenizer, nicht mit Regex, abweisen. |
| P1 | Die Portalhilfe nennt für normale Paketwrapper und Core teilweise dieselben Logorte und beschreibt in der Missionshilfe noch die alte Phasenreihenfolge. | Helpdesk sucht am falschen Ort oder erwartet nach der IP-Änderung noch die Datenträgerphase. | DE/EN-Hilfe nach Objekttyp trennen und die Zielreihenfolge Hostname → Datenträger → statische IP spiegeln; Sprach-/Semantiktest blockiert Drift. |
| P1 | Tageslogaufbewahrung, Bundle-Retention und Disaster-Recovery-Backup sind noch nicht klar getrennt. | Ein 30 Tage alter Log-Rollover könnte als Verlust eines Rollbackbelegs missverstanden werden; lokales `D:` wird fälschlich als Backup betrachtet. | 30-Tage-Diagnoselog, bundlegebundene Geschäftsbelege und externes Filesystem-Backup als drei getrennte Verträge dokumentieren und testen. |
| P1 | Ein Cleanup-Outcome kann nicht zugleich vor dem Delete existieren und dessen Ergebnis enthalten. | Ein Crash zwischen Dateilöschung und Abschlussbeleg hinterlässt einen nicht erklärbaren Archivzustand. | Preview plus vor jedem Delete gespültes Intent-/Outcome-Journal; offenes Intent setzt `cleanup_uncertain` und sperrt weitere Löschungen. |
| P1 | Ein normaler Contentupgrade erreicht Geräte mit bereits erfüllter Detection nicht erneut. | Admin erwartet eine Common-/Logging-/Bootstrap-Korrektur auf Bestandsclients, obwohl MECM korrekt nichts ausführt. | Normalupgrade ausdrücklich „future provisioning/no replay“; bestehende Geräte nur über getrennten, zielgebundenen Repair-/Migrationsvertrag. |
| P2 | Es fehlt ein kompakter Client-Diagnosecheck. | Fehleranalyse beginnt mit Raten im `ccmcache`. | Read-only Diagnosehilfe beziehungsweise dokumentierter Befehl für Cacheinhalt, Prozessbitness, Registry-Sicht, Marker und relevante Logs. |
| P2 | Es gibt keine kurze Schichtübergabe mit „letzter guter BundleId“, Pilot, Status und nächster Aktion. | Neues Personal wiederholt Arbeit oder überspringt ein offenes Gate. | Standardisiertes Handover-Template neben dem Build-Receipt; keine freien, nur in Chat oder Konsolenhistorie vorhandenen Hinweise. |

## Auditurteil

Der ursprüngliche Plan hatte die richtige Grundrichtung: vollständiger Folder-Content, eine deployte Endanwendung, automatische Dependencies, kein automatisches Löschen und ein realer Workgroup-Pilot. Er war jedoch noch **nicht implementierungsreif**. Sieben grundlegende Grenzen waren nicht geschlossen; die nachfolgende Lückentabelle verfeinert sie um die beim Code-, SSoT- und Edge-Case-Review gefundenen Einzelfälle:

1. CM-Objektidentität und Anzeigename waren nicht getrennt.
2. Die Dokumentation spiegelte die tatsächlichen Application-Namen falsch beziehungsweise uneindeutig.
3. Preflight und Apply waren nicht als Gesamttransaktion für alle vier Applications beschrieben.
4. Ein belastbarer Content-Rollback und eine Bundle-Provenance fehlten.
5. Die partielle Registry-/Bootstrap-Semantik und die tatsächliche DP-Zielgruppe waren nicht entschieden.
6. Core-Targeting hing dauerhaft an der OS-Collection und unterschied Portal-Soll, MECM-Mitgliedschaft und Client-Ist nicht.
7. Runbook, Client-README und Portal-Help trennten MECM-Anzeigenamen, Skriptnamen, normale Pakete und Core-Diagnose nicht ausreichend.

Mit den Stop-Gates und Arbeitspaketen dieser Revision ist der Plan für eine Umsetzung geeignet, sobald der offene Betriebsentscheid am Ende beantwortet ist. Reale MECM-Wirkung, SYSTEM-/64-Bit-Verhalten und Rollback bleiben Laborgates und dürfen nicht durch lokale Pester-Evidenz ersetzt werden.

## Upgrade-Strategie

### Normaler Upgrade derselben vier Applications

Das ist der bevorzugte Pfad:

1. Freigegebenen Auftrag aus der ACL-geprüften `ClientPackaging.psd1` mit Konfigurationshash, WebAPI, Scheme, DP-Gruppe und Änderungsart erfassen; der normale Build besitzt keine stillen Einzelparameter-Overrides.
2. Der immer ausgeführte Pflicht-Preflight liest private Staging-Source, UNC-Zielstruktur, alle vier CM-Objekte, komplette Referenzen, RBAC-Sicht und DP-Ziele. Er erzeugt einen Reconciliation-Plan mit unveränderlicher Plan-ID. Mit `-ValidateOnly` endet der Lauf hier ohne Aktivierung oder CM-Write.
3. Letztes freigegebenes Bundle samt Manifest außerhalb der aktiven Quellordner sichern und seine Lesbarkeit testweise prüfen.
4. Vollständige vier Content-Verzeichnisse zunächst ausschließlich in einem privaten Bundle-Stagingpfad aufbauen; erst wenn alle vier fertig, gegengeprüft und der Pflicht-Preflight grün sind, darf Apply die aktiven Sourceordner ersetzen.
5. Direkt vor Apply alle gelesenen CM-Revisions-/Objekt- und Source-Hashes gegen die Plan-ID prüfen. Fremdänderung seit Preflight blockiert.
6. Besitz, Objektidentitäten, Deployment Types, Detection, Ausführungskontext, Registry-Sicht, Return Codes und den gesamten Dependency Graph nochmals bestätigen.
7. Alle geänderten Applications in-place revidieren und Content für alle beabsichtigten DPs aktualisieren. Unerwartete zusätzliche DP-Ziele werden nicht still entfernt.
8. DP-Ergebnis pro Content-ID beobachten; „angefordert“ nicht als „bereit“ ausgeben. Für den Piloten zusätzlich Content Validation und Boundary-/Downloadpfad prüfen.
9. Pilot ausführen und fachliche Zustände, CM-Evidenz und VirtuSphere-Logs korrelieren.
10. Das eine bestehende Deployment der Endanwendung nur beibehalten, wenn Zweck `Required`, stabile Core-Collection, Zeitplan, deaktiviertes implizites Uninstall und Upgradeklasse ausdrücklich zum Auftrag passen. Ein Contentupgrade verändert weder Portalzuweisungen noch Collection-Mitgliedschaften.
11. Build-Receipt und Handover erst nach Abschluss aller erreichten Gates finalisieren.

Wichtig: Eine neue Content-Revision führt auf einem Client, dessen Detection Marker bereits erfüllt ist, nicht automatisch zu einer erneuten Ausführung. Das ist bei diesen zustandsverändernden Phasen grundsätzlich sicherer als ein blindes Replay. Jede Änderung muss deshalb einer Klasse zugeordnet werden:

- **Nur für Neuinstallationen:** neue Clients erhalten den neuen Content; bestehende Marker bleiben gültig.
- **Reparatur:** gezielter, separat autorisierter Repair-Pfad ohne Änderung der normalen Detection.
- **Erzwungene Migration:** eigener, versionierter Migrationsvertrag mit idempotenter Vorprüfung und bewusstem Rollout. Insbesondere Hostname-, IP- und Disk-Phasen dürfen nicht durch bloßes Hochzählen eines Markers ungeprüft erneut laufen.

### Rollback eines fehlgeschlagenen Upgrades

Rollback ist ebenfalls ein geplanter Apply und keine manuelle Dateikopie:

1. Neue Deployments beziehungsweise Pilotzuweisungen stoppen, ohne einen Client-Uninstall zu behaupten.
2. Fehlerklasse bestimmen: nur DP/Content, CM-Definition, Client-Policy oder bereits ausgeführte Fachaktion.
3. Bei reinem Content-/Definitionsfehler die gespeicherte Vorgänger-BundleId, deren Source-Manifest und die passende CM-Revision auswählen.
4. CM-Revision und Package Source als zusammengehörigen Satz wiederherstellen, Content-ID neu verteilen und DP-Zustand prüfen.
5. Bereits ausgeführte Hostname-, IP- oder Storageänderungen nicht durch generisches Content-Rollback „zurückdrehen“. Dafür gelten eigene fachliche Recovery-Verträge und Journale.
6. Auf einem Pilotclient Policy, Detection und tatsächlichen Zustand erneut belegen; erst danach den Rollout wieder öffnen.

Ein Exit 1 nach teilweiser CM-Mutation ist deshalb kein automatischer Rollbackauftrag: Ein blinder Rücksprung könnte bereits korrekt verteilte oder fachlich ausgeführte Zustände verschlechtern. Der Laufbericht muss exakt nennen, welche Writes bereits bestätigt wurden.

### Migration von `client_getinfo`, `client_getinfo_2.1` oder klassischen Packages

Der Installer meldet solche Objekte nur. Ein separates Runbook führt durch:

1. Typ bestimmen: Application/Deployment Type oder klassisches Package/Program.
2. Alle aktiven und simulierten Deployments, Collections und Zeitpläne inventarisieren.
3. Eingehende und ausgehende Dependencies, Supersedence, Application Groups und Task Sequences inventarisieren.
4. Detection Marker und Registry-Ansicht mit der neuen Kette vergleichen.
5. Neue Endanwendung `client_staticip` zunächst auf einer frischen Workgroup-Pilot-VM bereitstellen; die drei Vorgänger kommen ausschließlich über den neuen Dependency-Graphen.
6. Alte Install-Deployments deaktivieren beziehungsweise löschen und Policy-Konvergenz über Assignment-ID/Policylog auf repräsentativen Clients belegen; eine feste Wartezeit allein genügt nicht.
7. Sicherstellen, dass keine alte Required Policy die Altanwendung erneut installiert.
8. Alte Objekte zunächst nur `Retire`; dadurch werden Clients nicht deinstalliert. Sie werden weder umbenannt noch markiert oder als neue Core-Objekte übernommen.
9. Erst in einem späteren, separat freigegebenen Cleanup und nach bestätigter Referenzfreiheit revisions- und objektweise löschen. Für den neuen linearen Zielgraphen ist ohne weitere Referenzen die sichere Richtung vom Verbraucher zur Grundlage: `staticip`, `VMDisksOnline`, `hostname`, `getInfos`. Bei Altobjekten entscheidet ausschließlich der tatsächlich ermittelte Graph, nicht der Name.
10. Quellordner erst nach Abschluss der CM-/DP-Aufbewahrung und einer vereinbarten Sicherungsfrist entfernen.

### Warum frühes Löschen gefährlich ist

- Eine noch referenzierte Dependency verhindert das Löschen oder macht eine bestehende Anwendung unvollständig.
- Eine Task Sequence kann eine Application referenzieren, obwohl in der normalen Deploymentsicht nichts auffällt.
- Deployment-Löschung ist auf Clients nicht sofort wirksam.
- Retire ist keine Deinstallation und keine Deployment-Löschung.
- Uninstall entfernt Abhängigkeiten nicht automatisch.
- Alte und neue Detection Marker können denselben lokalen Zustand deuten; parallele Policies sind deshalb nicht deterministisch genug für einen sauberen Migrationsnachweis.
- Das Löschen der Content Source vor Abschluss einer Aktualisierung oder Reparatur kann DP-Aktionen unmöglich machen.

Folgerung: Der Produktionsinstaller erhält niemals einen Schalter wie `-RemoveLegacyAutomatically`. Ein späteres Cleanup-Werkzeug wäre standardmäßig `-WhatIf`, verlangte exakte Objekt-IDs und müsste jeden Blocker erneut serverseitig prüfen.

## Diagnose ohne Raten

| Beobachtung | Zuerst prüfen | Zulässige Aussage |
|---|---|---|
| Software Center zeigt sofort „Installiert“, kein VirtuSphere-Log | `AppDiscovery.log`, `AppIntentEval.log`, Application-/DT-ID und Detection vor dem vermeintlichen Lauf | Detection hat die gewählte Application als vorhanden bewertet; noch keine Aussage, welches Skript den Marker erzeugte. |
| `AppEnforce.log` enthält keinen Start | Policy-/Assignment-ID in `PolicyAgent.log`, Evaluation und Requirements | Enforcement wurde nicht belegt; nicht behaupten, PowerShell sei gelaufen. |
| `AppEnforce.log` startet PowerShell, aber kein VirtuSphere-Log | tatsächlicher Cacheordner, Commandline, Exitcode, Vorfehler beim Import von Common/Logging | Einstieg wurde versucht; Fehler kann vor Loggerinitialisierung liegen. |
| Nur ein `.ps1` im Cache | Content-ID und Deployment-Type-Contentquelle in `CAS.log`/CM, Source-Manifest | Ausgelieferter Content entspricht nicht dem VirtuSphere-Bundlevertrag. |
| Klassisches Package/Program statt Application | `execmgr.log`, Program Command Line und Success Codes | Application-Detection-/Dependency-Aussagen sind nicht anwendbar. |
| DP-Status grün, Client wartet auf Content | Boundary Group, Location Services/CAS, konkrete Content-ID | DP-Bestand und Client-Erreichbarkeit sind verschiedene Gates. |
| Registrywert nur unter `Wow6432Node` | Deployment-Type-Bitness und Detection-32-Bit-Option | Registry-View-Drift; keinen Wert manuell duplizieren, bevor der Vertrag korrigiert ist. |
| Neue Bootstrap-IP, Bestandsclient nutzt alte Adresse | native HKLM-Werte und BundleId | Erwartetes Verhalten des heutigen Erstimportvertrags; Standortmigration nötig. |

Jede Helpdesk-Anleitung beginnt mit CM-Objekttyp, Application-/Package-ID, Assignment-ID und Zeitfenster. Anzeigename, zufälliger `ccmcache`-Ordner und Software-Center-Text allein sind keine belastbaren Korrelationsschlüssel.

## Intuitiver Betriebsablauf für wechselndes Adminpersonal

```text
Auftrag freigeben
    -> Pflicht-Preflight + Plan-ID
        -> bei ValidateOnly hier ohne Write enden
        -> Findings beheben oder bei grün Apply derselben Prüfung
                -> vier Content-IDs auf Ziel-DPs bereit
                    -> frische Workgroup-Pilot-VM
                        -> Endanwendung einmalig Required auf Core-Collection deployen
                            -> Core pro neuer VM im Portal auswählen
                                -> Device-Sync setzt besessene Mitgliedschaft
                                    -> MECM installiert Endanwendung plus Dependencies
                                        -> Handover/BundleId und getrennte Statusachsen dokumentieren
```

### Rollen statt implizitem Vollzugriff

| Rolle | Darf/Verantwortet | Darf nicht still annehmen |
|---|---|---|
| Packaging-Verantwortlicher | Source prüfen, Bundle bauen, CM-Definitionen reconciliieren, Contentverteilung anstoßen | Collection und Required-Zeitplan seien automatisch richtig. |
| Deployment-Verantwortlicher | Endanwendung an freigegebene Pilot-/Produktionscollection deployen, Policy und Status beobachten | Grüne DP-Anzeige beweise erfolgreiche Clientfachaktion. |
| Portal-Operator | Core für eine VM auswählen oder abwählen, sichtbare Warnungen und Syncstatus prüfen | Haken bedeute bereits installiert; Abwahl rolle Clientänderungen zurück; fremde MECM-Regeln würden entfernt. |
| Helpdesk/Betrieb | IDs korrelieren, Logs und Registry read-only erfassen, Runbook-Entscheidungsbaum anwenden | Cacheordner löschen, Marker setzen oder Skripte manuell als „Fix“ starten. |
| Change Owner | Upgradeklasse, Pilot, Go/No-Go und Cleanup freigeben | `Retire`, Deployment Delete und Uninstall seien dasselbe. |

### Bedienregeln

- Jeder Runbook-Schritt nennt **Wo**, **mit welcher Rolle**, **welcher exakte Befehl**, **erwartetes Ergebnis**, **Log/Evidenz** und **Stop-Bedingung**.
- Kopierbare Befehle verwenden Platzhalter wie `<MECM-SERVER>` und `<WEBAPI-IP>:8021`; Beispieladressen werden sichtbar als Beispiele markiert.
- Der Installer zeigt vor Apply eine kurze Zusammenfassung und verlangt keine Interpretation langer grüner/gelber Konsolentexte. Der Exitcode bleibt maschinenlesbar.
- Hinweise und Blocker haben getrennte Codes, zum Beispiel `VSCLIENT-PREFLIGHT-OWNERSHIP` statt nur `!!`.
- Das Handover enthält: letzte gute BundleId, aktuelle Plan-ID, vier Application-IDs, vier Content-IDs, Ziel-DPs, Pilotgerät, Deployment-ID, offene Blocker, Rollback-BundleId und nächste Aktion.
- Die VM-Oberfläche zeigt Core in einer eigenen Kategorie mit genau einem Haken. Direkt daneben stehen getrennt gewünschte Auswahl, letzter Device-Sync-/Membershipnachweis und letzter Core-Phasenstand mit Zeitpunkt.
- Aktivieren ist für neue VMs der normale Weg. Abwahl einer bereits übergebenen oder aktuell targetierten VM nutzt den gemeinsamen Bestätigungsdialog und erklärt, dass nur zukünftiges Targeting endet und kein Hostname, keine IP und kein Datenträger zurückgesetzt wird.
- Manuelle CM-Schritte werden mit Screenshotpfad beziehungsweise exportiertem Status belegt; ein Chatverlauf ist kein Betriebsnachweis.

## Edge-Case-Matrix

| Fall | Erwartetes Verhalten |
|---|---|
| Frisch installierter Workgroup-Server ohne Registry-Werte | `getInfos` liest gültigen Bootstrap, schreibt native HKLM-Konfiguration, kontaktiert API, schreibt Snapshot und erst danach Detection Marker. |
| DNS `virtusphere.lan` nicht verfügbar, freigegebene Packaging-Konfiguration enthält die Ubuntu-IP | Die daraus erzeugte Bootstrap-IP funktioniert ohne DNS; Scheme und Port bleiben explizit. Es existiert kein zusätzlicher DNS-Fallback. |
| Registry enthält bereits eine vollständige gültige, aber vom Bootstrap abweichende API-Konfiguration | Normaler Lauf blockiert mit `configuration_drift` und zeigt beide nicht geheimen Hashes; keine Überschreibung und kein Ausprobieren beider Endpunkte. |
| Registry ist nur teilweise oder ungültig | Blocker oder ausdrücklich autorisierte atomare Migration; kein Mischzustand aus Registry und Bootstrap/Defaults. |
| `WebAPI` existiert, `Scheme` fehlt, Bootstrap verlangt HTTPS | Der heutige Skip wird als Drift erkannt; kein stiller Rückfall auf HTTP. |
| Prozess fällt zwischen zwei Registrywerten aus | `ConfigState=complete`/`ConfigHash` werden nicht gesetzt. Alle Phasen verweigern den uncommitteten Satz; Detection und ACK bleiben aus, Repair zeigt die Restwerte vor einer Bereinigung. |
| Registry ist vollständig gültig, aber Bootstrap fehlt | Enforcement blockiert als Paketvertragsfehler. Ein alter Registrysatz darf einen unvollständigen Contentsatz nicht grün maskieren. |
| `Common` oder `Logging` fehlt/ist inkompatibel | Phase beendet sich vor Fachaktionen ungleich 0; bei `getInfos` darf kein alter `SetupState=complete` als Erfolg dieses Versuchs stehen bleiben. CM-Enforcementlog bleibt die Evidenz, falls der Dateilog noch nicht initialisiert werden konnte. |
| Bootstrap fehlt/ist ungültig | Server-Preflight verhindert die Verteilung. Auf einem frischen Client ohne vollständige Registry-Konfiguration blockiert `getInfos`; Folgephasen lesen den Bootstrap selbst nicht. |
| Deployment Type läuft 32-Bit | Installer blockiert vor Deployment beziehungsweise Distribution. |
| UNC zeigt auf die falsche Ebene | Struktur- und Manifestprüfung blockiert vor CM-Mutation. |
| DP Group leer, DP offline oder Content stale | Ergebnis bleibt `distribution_requested`/`blocked`; kein falsches „fertig“. |
| Application existiert, ist aber nicht an der angegebenen DP-Gruppe verteilt | Initiale Zuweisung an genau diese Zielgruppe statt blindem Update vorhandener DPs. |
| Application liegt zusätzlich auf weiteren DPs | Transparente Warnung/Inventar; keine automatische Entfernung, weil andere Collections sie benötigen könnten. |
| Dependency wurde nach dem Hauptdeployment geändert | Installer aktualisiert deren Content explizit und prüft sie separat. |
| Alte und neue Applications sind gleichzeitig Required | Legacy-Bericht blockiert produktive Freigabe bis eindeutige Policy hergestellt ist. |
| Core ist über `Deploy Windows 2022` und die Core-Collection gleichzeitig Required | Cutover bleibt blockiert; alte und neue Assignment-ID werden benannt. Keine Gegen-Deinstallation anlegen. |
| Dependency-Graph soll geändert werden, während noch ein altes Core-Deployment aktiv oder auf Clients als Policy vorhanden ist | Umbau blockiert. Zuerst altes Deployment entfernen/deaktivieren und Konvergenz anhand Assignment-ID und Clientpolicy belegen; danach Graph in der festgelegten azyklischen Schreibreihenfolge ändern. |
| Graph-Umbau bricht nach einem Teilschritt ab | Keine produktive Zuweisung anlegen. Laufbericht nennt alte und bestätigte neue Kanten; erneuter Preflight entscheidet über Fortsetzung oder getesteten Graph-Rollback. |
| Neue, noch nicht übergebene VM mit aktiver MECM-Tasksequenz wird interaktiv angelegt | Core ist standardmäßig ausgewählt; der gespeicherte Desired State ist vor MECM-Übergabe sichtbar und auditierbar. |
| Admin entfernt bei dieser Neuanlage den sichtbar gesetzten Core-Haken | Der Presence-Sentinel belegt eine vollständige Eingabe; fehlender Checkboxwert bedeutet explizit `Core aus`. Der Server setzt den Default nicht erneut. |
| Bestehende/bereits übergebene VM trifft auf die neue Funktion | Keine automatische Core-Zuweisung durch Migration oder Render-Default; vorhandener Client- und MECM-Zustand bleibt unberührt. |
| Bestehende VM ohne Core speichert nur ein anderes Feld oder ein alter Client sendet keinen Core-Formbereich | Core bleibt unverändert aus. Ein fehlendes Feld wird bei Edit niemals als Create-Default interpretiert; ein unvollständiger neuer Formularvertrag blockiert sichtbar. |
| Admin wählt Core bei einer Bestands-VM erstmals nachträglich aus | Auswahl ist zulässig; Auswirkungswarnung und expliziter MECM-Transfer sind erforderlich. Nach dem Device-Sync wird die vollständige Kette anhand ihrer Detection ausgewertet. |
| Core wird vor dem ersten Device-Sync abgewählt | Keine Core-Mitgliedschaft wird erzeugt. Der persistierte Lifecycle wird nicht verändert; die UI leitet separat `Core nicht beauftragt` ab und zeigt nicht fälschlich einen Core-Erfolg. |
| Core bleibt bei einer neuen VM bewusst abgewählt | Keine der vier Applications einschließlich `client_getInfos` wird angefordert. Registry-Grundkonfiguration und Client-Ready-ACK bleiben erwartungsgemäß aus; Portalstatus ist `Core nicht beauftragt`. |
| Core wird nach Membership, aber vor Policy-Konvergenz abgewählt | Eigene Regel wird entfernt; UI zeigt ausstehenden Entzug. Bereits begonnenes Enforcement bleibt `unknown/running`, nicht „abgebrochen“. |
| Core wird nach abgeschlossener Kette abgewählt | Keine Deinstallation und keine Rückänderung. Detection Marker, Phasenprovenienz und historischer 5/5-Lifecycle bleiben bestehen; separat steht Desired Core auf `aus`. |
| Core wird später erneut ausgewählt | MECM bewertet jede Dependency erneut; erfüllte Detection verhindert normalen Replay, fehlende Detection kann eine Phase anfordern. Bei Bestands-VMs ist vorab Review nötig. |
| Required Deployment hat implizites Uninstall aktiviert | Pflicht-Preflight und Produktionsfreigabe blockieren. |
| Manuelle Core-Mitgliedschaft existiert zusätzlich zur VirtuSphere-Regel | Nur die besessene Regel darf entfernt werden; verbleibendes Targeting wird als fremde/manuelle Ursache angezeigt. |
| Core-Katalogeintrag oder stabile Collection fehlt/ist retired | VM-Speicherung erfindet kein Ziel; sichtbarer Blocker mit Link zur MECM-/Paketdiagnose, keine Namensheuristik. |
| VM besitzt keine aktive, aus MECM synchronisierte Tasksequenz | Serverseitige Validierung blockiert Core mit konkreter Diagnose. Es gibt weder ein manuell gepflegtes OS-Familienfeld noch Namensmatching auf `Windows`; UI-Ausblendung allein ist keine Konsistenzgrenze. |
| VM hat Core, ihre Tasksequenz wird später retired oder fehlt in einem partiellen Scan | Core bleibt gespeichert und wird als `Berechtigungsdrift` angezeigt; kein automatisches Enttargeting. Neue Auswahl, Clone und Transfer blockieren, Abwahl bleibt möglich. |
| Admin ändert `vm_os` auf eine nicht berechtigte Tasksequenz und lässt Core ausgewählt | Der gemeinsame Save blockiert mit Feldfehler; Core wird nicht still entfernt und `vm_os` nicht teilweise gespeichert. |
| Core-Regel wurde geschrieben, Gerät liegt aber außerhalb der Limiting Collection | Zustand wird `Regel bestätigt, keine effektive Mitgliedschaft`; Runreport nennt Collection-/Resource-ID und Limiting-Collection-ID. Kein Policy-/Installiert-Erfolg. |
| Core-Regel ist vorhanden, Collection-Auswertung läuft noch | `Collection-Auswertung ausstehend`; Updateauftrag wird höchstens einmal je Lauf/Collection gestellt. Erst eine spätere Istabfrage bestätigt Membership. |
| Bestandsserver ohne ausreichend langes Wartungsfenster wird ausgewählt | Bis Betriebsentscheid Q1 feststeht blockiert der Transfer. Bei Variante B blockiert er auch bei fehlendem/veraltetem Gerätefenster-Report; es wird weder sofortige Ausführung noch sichere Verschiebung behauptet. |
| Gerät erbt mehrere, überlappende oder in unterschiedlicher Zeitzone interpretierte Wartungsfenster | Der MECM-Report normalisiert die effektiv wirksamen Fenster für genau dieses Gerät und bewertet die benötigte Lauf-/Restartreserve. Portal oder Core-Collection allein raten keinen Termin. |
| Nachträglicher Core-Transfer liegt an einer Sommer-/Winterzeitgrenze | Der Report weist UTC-/lokale Interpretation und die MECM-Version aus; bekannte Offset-Effekte werden nicht durch eigene Portalzeitrechnung kaschiert. Pilot beziehungsweise Admin bestätigt das konkrete nächste Fenster, sonst blockiert Variante B. |
| Portal-VM wird samt Paketzuweisungen exportiert/importiert | Core wird nur als typisierte gewünschte Zuweisung übertragen; fehlt der Core-Typ im Zielsystem, wird er wie eine fehlende Zuordnung ausdrücklich gemeldet und nicht per Name geraten. |
| Alter Detection Marker ist bereits vorhanden | Normaler Contentupgrade führt die Phase nicht erneut aus. Nur ein eigener zielgebundener Repair-/Migrationsauftrag darf Marker-/Replayverhalten verändern. |
| Task Sequence referenziert Altanwendung | Löschung ist blockiert und Referenz wird mit Name/ID gemeldet. |
| Admin sieht wegen Security Scope nur einen Teil der Objekte | Preflight meldet unzureichende Inventarsicht und legt keine vermeintlich fehlende Application neu an. |
| Gleichnamiges Objekt liegt im Ordner `VirtuSphere_Core`, hat aber keinen Projektmarker | Blocker; keine implizite Adoption und keine manuelle Umbenennung. Nur ein expliziter projektversionierter Migrationspfad darf nach Vollprüfung die konkrete Objekt-ID übernehmen. Altanwendungen `client_getinfo`/`client_getinfo_2.1` sind davon ausdrücklich ausgeschlossen. |
| Deployment gerade deaktiviert/gelöscht | Cleanup wartet auf Policy-Konvergenz und prüft erneut. |
| Alter und neuer Content liegen parallel im `ccmcache` | Cachepfad allein ist kein Versionsnachweis; Content-ID, laufender Deployment Type und Manifest werden korreliert. |
| Hostname-Phase liefert 1641/3010 | MECM-Neustartvertrag bleibt erhalten; nachfolgende Dependency startet erst gemäß MECM-Zustand. |
| Statische IP trennt die laufende Verbindung | Lokale Änderung und Reporting/ACK werden getrennt protokolliert; ein verlorenes ACK darf keinen erfundenen Gesamterfolg erzeugen. |
| API-Antwort oder ACK ist unbekannt/timeout | Unbekannt bleibt unbekannt; kein blindes Wiederholen einer zustandsverändernden Phase. |
| Fremde Application trägt denselben Anzeigenamen | Ownership-/Identitätsprüfung blockiert; keine Übernahme nur anhand des Namens. |
| CM ändert sich zwischen Validate und Apply | Revisions-/Hashvergleich gegen Plan-ID blockiert Apply; neu validieren. |
| Upgrade schlägt nach zwei CM-Writes fehl | Laufbericht nennt bestätigte Writes; kein pauschaler automatischer Rollback. Operator wählt den getesteten Rollbackplan. |
| Application-Revision wird wiederhergestellt, aktive Source enthält aber neue Dateien | Rollback bleibt blockiert, bis passende Vorgänger-BundleId als Source wiederhergestellt und neu verteilt ist. |
| Frischer Server hat PowerShell/MECM-Konsole, aber falsche Rolle/Scope | Preflight benennt fehlende Berechtigung; kein Teilaufbau und keine Empfehlung „als Full Admin probieren“ ohne Rollenprüfung. |
| `ClientPackaging.psd1` fehlt | Validate blockiert mit einem kopierbaren Initialisierungsbefehl. Es gibt keinen eingebauten WebAPI-/Pfad-Fallback und keinen Write in MECM. |
| Config ist syntaktisch ungültig, enthält Codeausdrücke, unbekannte/anders geschriebene Schlüssel oder falsche Typen | `Import-PowerShellDataFile -LiteralPath` beziehungsweise die anschließende exakte Vertragsprüfung blockiert. Keine teilweise Übernahme und kein stiller Rückgriff auf eine alte Kopie. |
| Config-ACL erlaubt normalen Benutzern Schreiben oder wurde durch Vererbung aufgeweicht | Jeder Validate-/Apply-Lauf blockiert vor dem Lesen als freigegebener Auftrag. Der projektgesteuerte ACL-Repair zeigt zuerst `-WhatIf` und nennt alte/neue SDDL; er ist kein stiller Nebeneffekt eines Builds. |
| Betriebssystemsprache ändert den Namen der lokalen Administratorengruppe | ACL-Prüfung und -Reparatur verwenden die stabilen SIDs für `SYSTEM` (`S-1-5-18`) und lokale Administratoren (`S-1-5-32-544`) statt lokalisierter Kontonamen. Zusätzliche explizit konfigurierte Backup-/Service-Reader sind nur lesend erlaubt; unbekannte Writer blockieren. |
| Configpfad oder Archiv enthält Junction, Symlink/Reparse Point, relative Segmente oder zeigt auf ein Laufwerksroot | Blocker. Alle schreibenden und löschenden Ziele werden per `LiteralPath` kanonisch aufgelöst; kein rekursiver Vorgang folgt einem Reparse Point oder arbeitet gegen ein Root-/Workspace-/Package-Source-Ziel. |
| Config wird zwischen Validate und Apply geändert | Konfigurationshash passt nicht zur Plan-ID; Apply blockiert und verlangt neuen Validate-Lauf. |
| Zwei Installer- oder Cleanup-Läufe starten gleichzeitig | Ein maschinenweiter, begrenzt wartender Lock erlaubt genau einen Writer. Der zweite Lauf meldet Besitzer, Startzeit und Plan-ID und verändert nichts. Verwaiste Locks werden nicht allein wegen ihres Alters gelöscht, sondern gegen Prozess-/Plan-Evidenz geprüft. |
| Prozess oder Server fällt beim Schreiben der Config aus | Neue Datei wird im selben Verzeichnis vollständig geschrieben, zurückgelesen und validiert und erst dann atomar aktiviert. Die vorherige gültige Datei bleibt als explizit auswählbare Vorgängerkopie; normale Builds verwenden sie niemals still. |
| `WebApi` oder Scheme wird in der Config geändert | Neues Bundle trägt die neue Konfiguration; bereits erfolgreich erkannte Clients werden nicht automatisch migriert. Portal/Runbook melden ausdrücklich, dass dafür ein eigener Client-Migrations-/Repairauftrag erforderlich ist. |
| `PackagesBase`, `ContentShare` und tatsächlicher UNC-Inhalt zeigen nicht auf denselben Satz | Hash-/Strukturprüfung blockiert vor Bundleaktivierung und CM-Write; die Configdatei macht aus widersprüchlichen Pfaden keinen gültigen Auftrag. |
| Lokaler Pfad und UNC enthalten zufällig dieselben Dateien, aber unterschiedliche Source-Identitätsmarker | Blocker. MECM muss exakt den aktivierten Baum lesen, nicht nur einen momentan inhaltsgleichen Spiegel. |
| `BundleArchivePath` ist identisch mit, unterhalb von oder oberhalb der aktiven `PackagesBase`/`ContentShare`-Quelle | Blocker. Archiv und aktive Source müssen disjunkte kanonische Verzeichnisbäume sein, damit Cleanup niemals aktiven MECM-Content trifft. |
| Das dokumentierte Standardlaufwerk `D:` existiert auf diesem MECM-Server nicht | Initialisierung blockiert mit verständlicher Meldung und verlangt einen bewusst gewählten vorhandenen absoluten Pfad. Es wird weder `D:` angelegt noch still auf `C:` oder ein temporäres Verzeichnis ausgewichen. |
| Archivlaufwerk ist voll oder nicht erreichbar | Upgrade blockiert, bevor aktive Source oder CM geändert werden. Es wird nicht automatisch ein altes Bundle gelöscht, um Platz zu schaffen. |
| Es existieren nur vier erfolgreiche Bundles, alle älter als 180 Tage | Alle vier bleiben erhalten, weil mindestens die fünf neuesten geschützt sind. |
| Es existieren zwanzig erfolgreiche Bundles, alle jünger als 180 Tage | Alle zwanzig bleiben erhalten, weil die Altersgrenze zusätzlich zur Mindestanzahl schützt. |
| Es existieren zehn erfolgreiche Bundles, alle älter als 180 Tage | Nur die fünf ältesten sind Cleanup-Kandidaten; die fünf neuesten bleiben geschützt. |
| Ein Bundle erreicht exakt 180 volle Tage | Es bleibt an der Grenze geschützt. Erst bei einem Alter **größer** als 180 Tage und mindestens fünf nachweislich neueren erfolgreichen Bundles wird es Kandidat. |
| Bundle ist unvollständig, Manifest/Receipt fehlt, Hash stimmt nicht oder Zeitstempel ist unplausibel | Niemals automatisch als Rollback oder Löschkandidat verwenden. Zustand wird `corrupt/unknown`; Cleanup blockiert für dieses Objekt und das Runbook verlangt manuelle Untersuchung. |
| Systemuhr springt zurück oder mehrere Receipts haben gleiche Zeit | Retention verlässt sich nicht allein auf Dateisystemzeiten. Receipt-UTC, abgeschlossene Bundle-Sequenz/ID und Manifest werden gemeinsam geprüft; bei uneindeutiger Reihenfolge bleibt alles geschützt. |
| Cleanup-Plan wurde erzeugt, danach kommt ein neues Bundle hinzu oder ein Kandidat ändert sich | Archivindex und Kandidatenhash stimmen nicht mehr zur Plan-ID; Apply löscht nichts und verlangt eine neue Vorschau. Jeder Delete verwendet den einzeln validierten absoluten `LiteralPath`. |
| Gemeinsamer Tageslog-Sink fällt beim Cleanup aus | Der Loggingvertrag bleibt nicht blockierend. Der destructive Cleanup besitzt aber unabhängig davon einen zwingenden, zurückgelesenen Preview-Plan und ein schreib-/flushbares Intent-/Outcome-Journal; kann dieses Geschäftsprotokoll nicht geschrieben werden, findet kein Delete statt. |
| Cleanup-Prozess stürzt nach Delete, aber vor Outcome ab | Das vorher gespülte Intent bleibt ohne Abschluss. Der nächste Lauf meldet `cleanup_uncertain`, inventarisiert genau dieses LiteralPath neu und blockiert alle weiteren Deletes, bis ein Operator den Zustand gegen Backup, Preview-Hash und Archivindex geklärt hat. |
| MECM-Server oder lokales `D:` fällt vollständig aus | Lokales Bundlearchiv ist kein Disaster-Recovery-Backup. Config, aktive Package Sources, Bundlearchiv und Content Library müssen zusätzlich in einem getrennten Filesystem-Backup liegen; Restore wird gegen den Originalpfad getestet. |
| Template mit Core wird im selben System geklont, aber der Core-Katalog ist retired/mehrdeutig | Clone-Preview blockiert statt Core still zu verlieren oder ein unsicheres Ziel zu erzeugen. Nach Reparatur des Katalogs erneut klonen oder Core bewusst aus der Vorlage entfernen. |
| Mission-V2-Export enthält `kind=core`, Zielsystem besitzt keinen eindeutigen aktiven Core-Eintrag | Import-Preview nennt betroffene VMs und `Core wird nicht übernommen`; nach bewusster Bestätigung entsteht die Mission ohne Core. Das wird als Warning mit Core-Zählern, Upload- und Analysehash auditiert. |
| Mission-V1-Export kennt noch kein `kind` | Jede Referenz bedeutet ausschließlich normales Softwarepaket. Ein V1-Export kann dadurch niemals Core nur über den Namen aktivieren; im Preview wird diese Kompatibilitätsentscheidung erklärt. |
| Mission-V2-Datei wird in eine alte Portalversion importiert | Der alte Importer lehnt `format_version=2` bereits an seiner exakten Versionsprüfung ab. Er darf das unbekannte `kind` nicht still ignorieren. |
| Import enthält doppeltes Core pro VM, widersprüchliche `kind`-Schlüssel oder nur case-variierte Schlüssel | Dokumentprüfung blockiert vor Preview/Write. Nach normalem JSON-Decoding darf keine Mehrdeutigkeit bereits verloren gegangen sein; Parser-/Fixture-Test deckt diese Fälle ab. |
| Importdatei enthält keinen Core-Eintrag | Das gilt als explizit nicht übertragen. Der Create-Default für interaktiv neu angelegte VMs wird beim Import nicht nachträglich ergänzt; der Preview zeigt `Core nicht beauftragt`, danach kann ein Admin Core bewusst nachreichen. |
| Clone/Import kopiert Core-Desired-State | Neue VMs starten trotzdem ohne MAC, ResourceID, Membership, Rollout- und Phasenstatus. Nur die typisierte Paketzuweisung wird kopiert; MECM-Laufzeit entsteht später über den normalen Workflow. |
| Audit-Write für Core schlägt während der gemeinsamen Speicherung fehl | Die gemeinsame Datenbanktransaktion wird zurückgerollt; UI und API dürfen weder Erfolg noch Transferbedarf melden. Ein Retry erzeugt höchstens ein Desired-State-Ereignis für dieselbe `edit_version`. |
| Mission-Import enthält Core auf sehr vielen VMs | Genau ein aggregiertes `mission.transferred`-Ereignis mit Zählern/Hashes statt einer Auditzeile pro VM; einzelne spätere manuelle VM-Änderungen bleiben separat nachvollziehbar. |

## QoL-Verbesserungen

1. Jeder normale Installerlauf führt zuerst denselben vollständigen Preflight aus. `-ValidateOnly` erzeugt daraus ohne Sourceaktivierung oder CM-Mutation einen Text- und JSON-Bericht und beendet sich; es ist keine Sicherheitsoption, die ein Apply-Lauf überspringen könnte.
2. Abschlussausgabe: `In der Collection nur client_staticip deployen; client_getInfos, client_hostname und client_VMDisksOnline werden automatisch vorher installiert.`
3. CM-Beschreibung interner Applications: `VirtuSphere-Abhängigkeit – nicht separat deployen.` Die Endanwendung erhält: `Einziger Deployment-Einstiegspunkt für die Clientkette.`
4. Legacy-Erkennung nutzt exakte IDs und zusätzlich ähnliche Namen als Warnung; ähnliche Namen werden niemals automatisch übernommen oder gelöscht.
5. Diagnoseausgabe nennt passend zum Objekttyp die Logs: Applications über `AppIntentEval`, `AppDiscovery`, `AppEnforce`, `CAS`; klassische Packages zusätzlich beziehungsweise stattdessen `execmgr`.
6. Optional `-WaitForDistribution` mit Timeout, DP-Zähler und dauerhaftem Log. Bei vier bekannten Einheiten gilt das Repositoryformat `[0/4]`, `[n/4] RUN ...`, `[n/4] PASS|FAIL ...`.
7. Der Installer druckt die erwartete Cache-Struktur und einen kurzen Prüfpfad für die Pilot-VM, ohne Cacheordner hart zu erraten.
8. Der Pflicht-Preflight gibt eine Plan-ID aus; Apply nimmt genau diese ID entgegen oder führt denselben Preflight sichtbar neu aus. Es existiert kein Skip-Schalter. Damit sind Read-only-Prüfung und tatsächliche Änderung nachvollziehbar verbunden.
9. Eine kompakte Schlussampel zeigt getrennt `Source`, `CM contract`, `DP`, `Pilot`, `Deployment` und `Cleanup`. Nicht ausgeführte Stufen heißen `NOT_RUN`, nicht grün.
10. Ein `-Explain <Fehlercode>`-Abschnitt im Runbook übersetzt stabile Fehlercodes in Ursache, sichere Prüfung und verbotene Schnellreparatur.
11. Ein read-only Clientdiagnosepaket exportiert nur gezielte Registrywerte, relevante Logausschnitte, CM-IDs und Dateihashes; Secrets und vollständige fremde Logs werden nicht ungefiltert eingesammelt.
12. Die Portal-Kategorie `Core` enthält genau eine Checkbox und einen kurzen Satz: `Installiert die VirtuSphere-Grundkonfiguration über vier MECM-Abhängigkeiten.` Die technischen vier Einzelnamen stehen im aufklappbaren Diagnosehinweis, nicht als vier auswählbare Pakete.
13. Ein Statusblock trennt `Gewünscht`, `MECM-Mitgliedschaft` und `Clientphasen`; jede Achse zeigt Quelle und letzten Empfangszeitpunkt. Unbekannt oder ausstehend bleibt grau statt als Fehler oder Erfolg geraten zu werden.
14. Bei einer bestehenden VM zeigt die Abwahlbestätigung konkret: `Entfernt nur die VirtuSphere-Core-Zuweisung. Hostname, Netzwerk, Datenträger und Registry werden nicht zurückgesetzt.` DE/EN bleiben bedeutungsgleich.
15. Der Core-Eintrag trägt keine irreführende frei gepflegte Versionsauswahl. BundleId und Application-/Content-IDs erscheinen nur in Diagnose und Handover; der Operator wählt den fachlichen Baustein, nicht eine interne Teilrevision.
16. Der Statusblock erweitert die mittlere Achse auf `Regel`, `Collection ausgewertet`, `effektives Mitglied`, `Policy empfangen`; dadurch ist ein Limiting-Collection- oder Evaluationproblem ohne MECM-Raten sichtbar.
17. Vor dem nachträglichen Transfer einer Bestands-VM zeigt die Vorschau Tasksequenz, aktuelle Core-Auswahl, letzte bekannte Membership, vorhandene Phasenmarker und – nach Q1 – Wartungsfenster/erwarteten Start. Die Bestätigung wiederholt nur die disruptiven Auswirkungen, keine internen IDs.
18. Mission-Import zeigt Formatversion und drei getrennte Zahlen: `Core übernommen`, `Core bewusst ausgelassen`, `normale Pakete fehlen`. Ein Warning-Import bleibt optisch Warning und wird nicht durch einen generischen Erfolgsflash überdeckt.
19. Config-Initialisierung erzeugt nach erfolgreichem Schreiben einen kopierbaren Validate-Befehl; Validate zeigt lokalen Pfad, UNC-Source-ID, Limiting-Collection-ID, DP-Gruppe und Archiv, ohne dass Admins sie erneut eintippen.
20. Ein maschinenlesbares Handover enthält auch offenen Betriebsentscheid Q1, Cutover-Freeze, Simulations-ID, Collection-Evaluationsstatus und `cleanup_uncertain`; ein Schichtwechsel kann dadurch keine offene Sicherheitsstufe übersehen.

## Doku- und Help-Abdeckung

| Frage des Admins | Kanonische Antwortstelle | Pflichtinhalt |
|---|---|---|
| „Welchen Befehl führe ich aus?“ | `docs/operations/mecm-integration.md` | Voraussetzungen, Rolle, exakter Validate-/Apply-Aufruf, erwartete Ampel, Logpfad. |
| „Welche App deploye ich?“ | Runbook und Installer-Schlussausgabe | Exakter MECM-Anzeigename `client_staticip`; `client_getInfos`, `client_hostname` und `client_VMDisksOnline` ausdrücklich nicht separat deployen. |
| „Was macht der Core-Haken?“ | Portal-Help DE/EN, VM-Editor-Hinweis | Gewünschte Collection-Zuweisung; technisch eine Endanwendung plus drei Auto-Install-Dependencies; Haken ist kein Installationsnachweis. |
| „Kann ich den Standard bei einer neuen VM abwählen?“ | VM-Editor-Hinweis und Portal-Help DE/EN | Ja. Der vorgewählte Haken ist nur ein sichtbarer Create-Default; bewusstes Abwählen wird gespeichert. Bestands-, Clone- und Importpfade wenden diesen Default nie an. |
| „Was passiert beim Abwählen?“ | Portal-Help DE/EN und kontextabhängige Bestätigung | Nur besessene Membership/future Policy wird entfernt; kein Uninstall und keine Rücksetzung von Hostname, IP, Disks oder Registry; laufendes Enforcement kann nicht als abgebrochen zugesagt werden. |
| „Warum nicht vier einzelne Core-Pakete?“ | Runbook, Kurzfassung Help | Lineare Dependency-Kette, Restart-/Netzwerkgrenzen und ein einziger deploybarer Einstiegspunkt; einzelne Phasenauswahl ist kein bestehender Vertrag. |
| „Warum stehen vier Dateien im Cache?“ | Client-README; Kurzfassung im Portal-Help | Phase ist Einstieg, Common/Logging sind Laufzeitabhängigkeiten, Bootstrap ist Erstkonfiguration; nur getInfos liest Bootstrap. |
| „Woher kommt die WebAPI-Adresse?“ | Client-README und Runbook | Packaging-Konfiguration → Bootstrap → logisch committeter Registry-Satz; danach ausschließlich gültige Registry. Kein hartcodierter DNS-/IP-Notfallfallback. |
| „Warum steht Installiert, aber nichts lief?“ | Portal-Help DE/EN | Detection-vor-Enforcement, Application gegenüber Package/Program, genaue CM-Logs. |
| „Wo liegen die Logs?“ | getrennte Help-Abschnitte `Core` und `normale Pakete` | Core: `C:\Program Files\VirtuSphere\Logs` plus MECM-Application-Logs. Normale Paketwrapper: `%ProgramData%`/`%LOCALAPPDATA%`; keine Vermischung. |
| „Warum läuft bei einer VM ohne Core kein `client_getInfos`?“ | Portal-Help Missionen/Core | Der Haken steuert die vollständige Vierer-Kette. Ohne Auftrag fehlen deshalb Registry-Grundkonfiguration und 5/5-ACK erwartungsgemäß; der sichtbare Zustand lautet `Core nicht beauftragt`, nicht fehlgeschlagen oder installiert. Er ist eine Anzeigeableitung und kein zusätzlicher Machine-Lifecyclewert. |
| „Wie upgrade ich?“ | Runbook | Upgradeklasse, Plan-ID, Rollback-BundleId, DP- und Pilotgates, keine automatische Phasenwiederholung. |
| „Wie entferne ich Altobjekte?“ | separates Kapitel im Runbook | Reference Graph, Policy-Konvergenz, Retire/Uninstall/Delete-Unterschied, What-if und Stop-Gates. |
| „Was übergebe ich an die nächste Schicht?“ | Handover-Template | Bundle-/Plan-/Application-/Content-/Deployment-IDs, Pilot, Status, Blocker, nächste Aktion. |

Portal-Help bleibt kurz und auf Fehlersuche ausgerichtet. Tiefe CM-Schritte leben nur im Runbook; sonst entstehen zwei Anleitungen, die unabhängig driften. `help_packages.php` trennt normale Autoimporter-Pakete von Core, `help_missions.php` erklärt die 5/5-Auswirkung und der VM-Editor verlinkt über `help_url()` auf den registrierten Abschnitt. Alle portalsichtbaren Texte werden in DE und EN gleichzeitig geändert, nutzen dieselben Platzhalter und werden durch `lang-parity` geprüft. Application-Anzeigenamen werden nicht übersetzt; fachliche Labels wie `Core` erhalten DE/EN-Anzeigetext, ohne den technischen Typ zu verändern.

## Arbeitspakete

### MC00 – Offenen Betriebsentscheid schließen

- Q1 zwischen „nach explizitem Transfer so bald wie MECM zulässt“ und „Wartungsfenster ist Pflicht“ bestätigen.
- Daraus Deadline, maximale Laufzeiten, Outside-Window-Installation/-Restart, Portalwarnung, Preflight und Pilotabnahme einmalig ableiten; keine dieser Regeln als unabhängigen UI-Default duplizieren. Bei Variante B zusätzlich Owner, Freshness-Grenze und Schema des gerätebezogenen MECM-Fensterreports festlegen.

**Abnahme:** Eine kurze, eindeutige Betriebsregel beantwortet für frische und bestehende VMs, wann Core frühestens starten darf und was bei fehlendem/zu kurzem Wartungsfenster geschieht.

### MC01 – Vertrag und Inventar festziehen

- `Get-VsClientAppSpecs` um Rollenkennzeichnung, Required Files und 64-Bit-Vertrag erweitern.
- Den versionierten Schema-/ACL-Vertrag für `C:\ProgramData\VirtuSphere\MECM\ClientPackaging.psd1` einschließlich `CoreLimitingCollectionId`, lokaler/UNC-Source-Identität und die feste Mindestaufbewahrung von fünf erfolgreichen Bundles beziehungsweise 180 Tagen im Packaging-Modul definieren. Laden nur über `Import-PowerShellDataFile -LiteralPath`, niemals `Invoke-Expression`; genaue Schlüssel-/Typ-/Wertprüfung folgt danach.
- Application-Anzeigename, Ordner, Skriptname und technische CM-Identität getrennt modellieren; die beiden Einstiegsskripte im Projekt zu `client_getInfos.ps1` und `client_VMDisksOnline.ps1` umbenennen.
- Zielgraph auf `client_getInfos` → `client_hostname` → `client_VMDisksOnline` → `client_staticip` festlegen und `client_staticip` maschinenlesbar als einzigen deploybaren Einstieg markieren.
- Aktuelle und Legacy-Namen nur als Migrationswissen dokumentieren; Folder-only-Adoption entfernen und `client_getinfo`/`client_getinfo_2.1` ausdrücklich niemals übernehmen.
- Normalupgrade auf `future provisioning/no replay` festlegen. Stateful Repair/Migration bleibt eigener Auftrag und kann nicht durch einen Packagingparameter still aktiviert werden.
- Common/Bootstrap auf den geschlossenen Registry-Entscheidungsbaum ohne `virtusphere.lan`/Paket-IP-Fallback und auf den logischen Config-Commit mit Hash/Schema/Complete-Marker umstellen.

**Abnahme:** Eine Tabelle beschreibt jede Application einmal vollständig; Tests erkennen Namens-, Datei-, Detection-, Return-Code-, Bitness- und Dependency-Drift.

### MC02 – Read-only Preflight und Legacy-Bericht

- Den vollständigen Preflight als unvermeidliche erste Phase jedes Installerlaufs implementieren; `-ValidateOnly` stoppt nur vor Apply.
- Deployments, Dependencies, Supersedence, Application Groups und Task-Sequence-Referenzen erfassen.
- RBAC-/Security-Scope-Sicht und eindeutige Application-IDs vor einer „fehlt“-Aussage prüfen.
- Source-, UNC-, MECM- und DP-Zustand getrennt berichten; Plan-ID und Build-Receipt erzeugen.
- Lokalen Source-Identitätsmarker über den UNC zurücklesen, Limiting Collection und Collection-IDs prüfen sowie Maximum Runtime, Wartungsfenster-/Outside-Window-Flags und Simulationsfähigkeit inventarisieren.
- Den vollständigen Vierer-Reconciliation-Plan vor jeder Aktivierung der aktiven Sourceordner und vor jedem CM-Write fertigstellen; Apply gegen Fremdänderungen sperren und keinen Skip-Pfad anbieten.

**Abnahme:** Gegen eine Umgebung mit `client_getinfo` und `client_getinfo_2.1` entsteht ein vollständiger Bericht, aber keine Mutation.

### MC03 – Upgradesichere Content- und DT-Pflege

- Initial distribute, update und redistribute anhand des Istzustands unterscheiden.
- Soll-DP-Gruppe gegen tatsächliche Ziele jeder bestehenden Application abgleichen.
- 64-Bit-Ausführung/Detection prüfen.
- Installationsbefehle kontrolliert auf `client_getInfos.ps1` und `client_VMDisksOnline.ps1` umstellen, ohne neue MECM-Application-Identitäten anzulegen.
- Dependency-Graph kontrolliert auf `client_getInfos` → `client_hostname` → `client_VMDisksOnline` → `client_staticip` umstellen; danach ist nur `client_staticip` deploybarer Einstiegspunkt.
- Den Graph-Umbau nur ohne aktive beziehungsweise noch konvergierende Core-Policy in der festgelegten azyklischen Reihenfolge ausführen; Teilerfolg blockiert jede Deploymentfreigabe und wird revisionsgenau berichtet.
- Content-Revisions- und DP-Status eindeutig ausgeben.
- Fehler vor oder nach CM-Mutation klar kennzeichnen.
- Unveränderlichen Rollback-Satz mit Vorgänger-BundleId erzeugen und Restore als zusammengehörigen Source-/Revision-/Distribution-Ablauf implementieren.
- Vier Application-Revisionsnummern und Content-IDs an die BundleId binden. Restore prüft zuerst, dass dieselben CM-Objektidentitäten/Revisionshistorien noch existieren, aktiviert dann den passenden Source-Satz, stellt die vier Revisionen/Definitionen kontrolliert wieder her und verteilt/validiert neu; Deployments und Membership werden nicht blind aus dem Bundle überschrieben.
- Erfolgreiche Bundles unter dem konfigurierten, von der aktiven Package Source getrennten `BundleArchivePath` ablegen. Cleanup ausschließlich als separaten What-if-/Plan-ID-/Apply-Vorgang mit vor jedem Delete gespültem Intent-/Outcome-Journal implementieren; die Schutzbedingung lautet „unter den neuesten fünf oder höchstens 180 Tage alt“.

**Abnahme:** Änderung an einer Shared-Datei aktualisiert nachweislich alle vier betroffenen Content-Sätze auf allen Soll-DPs; beide neuen Skriptnamen und der neue Graph sind in Source, DTs und Manifest identisch; ein 32-Bit-Deployment-Type wird blockiert; ein absichtlich fehlgeschlagener Upgrade kann mit dem gespeicherten Vorgängerbundle wiederhergestellt und neu verteilt werden.

### MC04 – Kontrollierte Migration und Rückbau

- Nicht destruktives Runbook für parallele Altobjekte schreiben.
- Referenzgraph und topologische Rückbaureihenfolge ausgeben.
- Kein automatisches Delete/Uninstall in den normalen Installer aufnehmen.
- Altobjekte nicht umbenennen oder adoptieren; nach belegtem Enttargeting zunächst nur retire. Ein mögliches Löschen bleibt ein getrennt freizugebendes Cleanup.

**Abnahme:** Eine Altanwendung mit Deployment, Dependency oder Task-Sequence-Referenz kann nicht versehentlich zum Löschkandidaten werden.

### MC04A – Portal-Core-Zuweisung und OS-Cutover

- Eine stabile, projektmarkierte Core-Device-Collection anlegen und ihre ID als Vertrag führen. Keine manuelle Umbenennung oder namensbasierte Ersatzcollection übernehmen; ein beabsichtigter Identitätswechsel benötigt einen projektversionierten Migrationspfad. Den von `mecm_Packages-TaskSeq-sync.ps1` und `mecm_packages.php` gemeinsam verstandenen Katalogvertrag additiv um `kind=core` und die tatsächliche Collection-ID erweitern; alte Payloads ohne `kind` bleiben normale Softwarepakete und können Core weder erzeugen noch ändern.
- Den MECM-synchronisierten Paketkatalog um die geschlossenen Felder `package_kind` und `mecm_collection_id` erweitern; normale Pakete und Core behalten getrennte Upgrade-, Retirement-, Relink- und Purge-Regeln.
- Bestandszeilen auf `software` migrieren, Typ/Collection-Konsistenz per DB-Constraint absichern und höchstens einen aktiven Core per datenbankseitigem Singleton-Guard zulassen; parallele Syncs dürfen die Invariante nicht nur durch vorheriges Lesen schützen.
- Die bestehende `deploy_vm_packages`-Relation und den bestehenden VM-Save-/Clone-/Transferpfad weiterverwenden, statt `core_enabled` als parallelen Desired State einzuführen.
- Core beim Klonen, in Templates und beim Mission-Export/-Import als typisierte gewünschte Paketzuweisung mitführen. MECM-ResourceID, Membership- und Phasenstatus niemals kopieren; fehlenden Core-Katalog im Ziel sichtbar überspringen und auditieren.
- `VIRTUSPHERE_MISSION_EXPORT_VERSION` auf 2 erhöhen und `VIRTUSPHERE_MISSION_TRANSFER_PACKAGE_FIELDS` um `kind` erweitern. Der neue Decoder besitzt explizite V1-/V2-Zweige: V1 kennt nur `software`, weist ein dennoch vorhandenes `kind` zurück und löst ausschließlich gegen `package_kind=software` auf; V2 verlangt für jede Paketreferenz exakt `software` oder `core`. Beim Import löst `kind=core` nicht über Name/Version, sondern ausschließlich gegen genau einen aktiven Core-Katalogeintrag auf. Doppelte, widersprüchliche oder nur in der Schreibung abweichende `kind`-Schlüssel blockieren bereits am Raw-JSON-Owner vor `json_decode()`, das die Mehrdeutigkeit sonst verlieren würde. Dafür einen größenbegrenzten, string-/escape-/nesting-bewussten Tokenizer verwenden, keine Regex. `upload_sha256` wird vor Decode gespeichert; `analysis_sha256` bindet Preview und Commit an dieselbe deterministisch kanonisierte Analyse.
- Der vorhandene Clonepfad darf die gespeicherte typisierte Paket-ID übernehmen, muss aber weiterhin MAC, MECM-Ressourcenbezug, Rollout- und Phasenlaufzeit zurücksetzen. Ist der lokale Core-Eintrag retired oder mehrdeutig, blockiert der Clone-Preview. Beim systemübergreifenden Import darf ein fehlender Ziel-Core nach sichtbarer Bestätigung übersprungen werden; der Create-Default wird in diesem Pfad niemals nachträglich ergänzt.
- Im VM-Editor eine Kategorie Core mit genau einer Auswahl rendern. Default ausschließlich beim Anlegen einer neuen, noch nicht an MECM übergebenen VM mit aktiver, aus MECM synchronisierter Tasksequenz speichern; gespeicherte Bestands-VMs werden niemals durch einen Render-Default oder eine Migration verändert. Der Create-POST besitzt einen Presence-Sentinel, sodass „Default sichtbar, Admin wählt ab“ eindeutig `aus` speichert; der Save-Service akzeptiert eine geschlossene Herkunft und darf den Default nur für `interactive_create` anwenden. Edit, Clone, Import und alte/partielle Requests können ihn nicht auslösen. Keine manuell gepflegte OS-Familie und kein Namensmatching einführen.
- `vm_os` gegen genau einen aktiven `deploy_os`-Datensatz in derselben Save-Transaktion prüfen. Ein OS-Wechsel bei ausgewähltem Core blockiert bei verlorener Berechtigung; spätere Katalog-Retirementdrift entfernt Core nicht automatisch.
- `mecm_desired_targets()`, die explizite `getDeviceList`-Projektion und Device-Sync auf die stabile Core-Collection abbilden; für Core ID und Namen gemeinsam prüfen, existierende Provenienzlogik für Add/Remove beibehalten und manuelle/fremde Regeln schützen. Regelwrite, Collection-Update, effektive Mitgliedschaft und Policy werden getrennt berichtet.
- Einzelne Auswahländerungen mit VM-Objekt-ID, vorhandenem Audit-`user_id`, alter/neuer Zuordnung, `edit_version` und Zeitpunkt atomar auditieren. Mission-Clone/-Import nutzt stattdessen das aggregierte Transferereignis mit Core-Zählern. Abwahl einer bereits übergebenen/targetierten VM über den gemeinsamen Portal-Bestätigungsvertrag erklären.
- VM-Save und Core-Audit sowie MECM-Resync-Markierung und Queue-Audit jeweils über einen gemeinsamen `repo_transaction()`-Owner schreiben; ein abgelehnter Auditwrite rollt den Fachwrite zurück.
- Desired State, letzte besessene Membership-Evidenz und Core-Phasenstatus getrennt anzeigen. Keine der Achsen darf aus einer anderen geraten werden. `Core nicht beauftragt` bleibt eine display-only Ableitung; der vorhandene Machine-Lifecycle, seine exakten Wirewerte und der alleinige `mecm_client_ack`-Übergang werden nicht erweitert oder überschrieben.
- Cutoverbericht für das alte Core-Deployment auf `Deploy Windows 2022` erzeugen: Assignment-ID, Zielmenge, Überschneidung, Zeitplan, implizites Uninstall, Policy-Konvergenz und Freigabe.

**Abnahme:** Ein alter Katalog-Payload lässt Core unangetastet; ein neuer Payload bindet Core an genau eine Collection-ID. Eine neu angelegte VM mit aktiver MECM-Tasksequenz erhält standardmäßig genau eine gespeicherte Core-Zuweisung und nach Device-Sync genau eine besessene Regel; bewusstes Abwählen im Create bleibt dagegen aus. Erneutes Öffnen oder ein fachfremder Save einer alten VM erzeugt keine Zuordnung. Eine Bestands-VM bleibt beim Upgrade unangetastet, kann aber durch einen Admin bewusst ausgewählt, bestätigt und über den expliziten MECM-Transfer nachgereicht werden. Eine VM ohne aktive MECM-Tasksequenz kann Core nicht erhalten, ohne dass dafür OS-Namen oder frei gepflegte Familien ausgewertet werden. Abwahl entfernt ausschließlich diese Regel, löst kein Uninstall aus und behauptet keinen Clientrollback. Der unveränderte Machine-Lifecycle-Vertrag besteht seine Wire-Tests. Ein parallel aktives altes OS-Collection-Deployment blockiert die Produktionsfreigabe.

### MC05 – Hilfe, Logging und Bedienbarkeit

- DE/EN-Portalhilfe, `Powershell-MECM/README.md`, Client-README und MECM-Betriebsdoku synchronisieren.
- Die bestätigte Namensdrift durch klar beschriftete Application-/Ordner-/Skriptspalten entfernen.
- Vier-Dateien-Struktur, tatsächliche Bootstrap-Satzsemantik, einzelne Endanwendung und relevante Logs erklären.
- Portal-Help DE/EN um den fachlichen Core-Baustein, die technische Dependency-Kette, Abwahl ohne Rollback und den Unterschied zwischen Auswahl, Membership und Clientphase ergänzen.
- Normale Paketwrapper-Diagnose (`%ProgramData%`/`%LOCALAPPDATA%`) und Core-Phasenlogs (`C:\Program Files\VirtuSphere\Logs`) in Help und Runbook sichtbar trennen.
- Pro Core-Phase die historischen Registryfelder `LastAttemptAtUtc`, `LastResult`, `CompletedAtUtc`, `BundleId`, `ContractVersion`, `ScriptHash` und `LastErrorCode` sowie ihre Aussagegrenzen dokumentieren. `client_getInfos` erklärt zusätzlich `ConfigSchemaVersion`, `ConfigHash`, `ConfigState`, Commit-/Driftmatrix und Rücklesevalidierung vor Detection und ACK.
- In Client-README und Runbook jede Kette als Tabelle mit MECM-Anzeigename, Contentordner und Einstiegsskript darstellen; keine Skriptnamen als Application-Namen verwenden.
- Maschinenlesbaren Laufbericht, Fehlercodes, Handover-Template und dauerhaften Logpfad definieren.
- Das vorhandene `VirtuSphere-Logging.ps1` für Packaging-Läufe wiederverwenden: sechs Felder, Levelvertrag, CorrelationId, Redigierung und 30-Tage-Retention bleiben zentral. Plan-/Receipt-/Cleanup-Dateien sind getrennte Geschäftsbelege und folgen nicht dem Tageslog-Rollover.
- Auditfelder und Ereignisse ausschließlich in `audit_event_definitions.php` registrieren. Core-Desired-State, MECM-Transfer und Clientphase bleiben drei verschiedene Ereignis-/Evidenzarten; ein Vorgang erzeugt keine doppelte Auditzeile.
- Die konkrete Dateimatrix aus „Dokumentationsspiegel“ umsetzen: Runbook als einzige Admin-Schrittfolge, Client-README als technischer Phasenvertrag, Haupt-README nur als Einstieg sowie `help_packages.php`, `help_missions.php`, VM-Editor und Systemstatus jeweils gleichzeitig in DE/EN.

**Abnahme:** Ein neuer Admin kann ohne Sitzungswissen den Validate-Lauf starten, die richtige Endanwendung identifizieren, die vier erwarteten Cachedateien erklären und je nach Evidenz die richtigen Logs wählen, ohne einen Marker oder Cache manuell zu verändern.

### MC06 – Automatisierte QA

- Bestehende Pester-Verträge für Installer, Common, Logging und Client-Apps erweitern.
- Konfigurations- und Archivtests laufen unter Windows PowerShell 5.1 und decken `.psd1`-Import ohne `Invoke-Expression`, fehlende/zu breite ACLs, lokalisierungsunabhängige SID-Prüfung, explizite Backup-/Service-Reader, unbekanntes Schema/Felder/Schreibvarianten, UTF-8-BOM der `.psd1`, explizites UTF-8 ohne BOM der JSON-Belege, Konfigurationshash-Drift zwischen Validate/Apply sowie fehlendes Archivlaufwerk ab.
- Clienttests decken leere, gleiche, abweichende, partielle und ungültige Registry-/Bootstrap-Sätze, Absturz vor Commitmarker, Hashmanipulation und das vollständige Entfernen von hartcodiertem DNS-/IP-Fallback ab.
- Archivtests decken disjunkte kanonische Pfade, Root-/Reparse-/Junction-Schutz, parallele Writer, volles Archiv, beschädigte/unvollständige Bundles, Uhrsprung, gleiche Zeitstempel, veraltete Cleanup-Pläne und die exakte Schutzlogik ab: genau fünf bleiben, vier bleiben, junge Bundles bleiben und ein exakt 180 Tage altes Bundle bleibt geschützt; erst `Alter > 180 Tage` plus fünf neuere erfolgreiche Bundles erlaubt die Kandidatur.
- `-WhatIf`-/`ShouldProcess`-Tests prüfen auch Aufrufe über Hilfsfunktionen/Module, damit die Preference nicht an einer Aufrufgrenze verloren geht. Cleanup-Apply darf ohne erfolgreich zurückgelesenen Preview und vor jedem Ziel gespültes Intent kein Ziel löschen; Crash-Recovery für ein Intent ohne Outcome wird getestet.
- Negative Fixtures für fehlende Datei, Hash-Drift, falsche UNC-Ebene, 32-Bit-View, ähnliche Namen, folder-only Ownership, partielle Registry-Konfiguration, zusätzliche DTs, fremde CM-Änderung zwischen Validate/Apply und Dependency-Zyklen/-Tiefe ergänzen.
- Code-/Doku-/DE-/EN-Mirrors der Application-, Ordner- und Skriptnamen in beide Richtungen fail-closed prüfen.
- Contract-Tests pinnen `client_staticip` als einzigen Deployment-Einstieg, den Graphen `getInfos` → `hostname` → `VMDisksOnline` → `staticip` und die projektgesteuerten Einstiegsskriptnamen. Der alte Graph und die alten Skriptnamen dürfen nur in ausdrücklich markierten Ist-/Migrationsabschnitten vorkommen.
- Rollbacktest belegt, dass CM-Revision ohne passenden Source-Satz nicht fälschlich als vollständige Wiederherstellung gilt.
- Portal-/Repository-Tests decken Core-Typ, beim Create materialisierten Nur-neue-VM-Default, bewusstes Abwählen trotz sichtbarem Default, Presence-Sentinel, fehlenden/alten/partiellen Formularvertrag, geschlossene Herkunft für Create/Edit/Clone/Import, unverändertes Rendering und fachfremdes Speichern von Bestands-VMs, MECM-Tasksequenz-Berechtigung ohne OS-Namensheuristik, Clone/Export/Import, Retirement-/Relink-Ausschluss, optimistic concurrency, geschlossen registrierte Auditfelder, atomaren Auditrollback und besitzwahrende Membership-Entfernung ab.
- Datenbank-/Paralleltests belegen Default `software`, Core-Collection-Constraint, höchstens einen aktiven Core, Schutz gegen zwei gleichzeitige Syncs sowie Unberührtheit von Core bei altem/leerem/partiellem Softwarepayload.
- Clone-/Template-/Transfer-Tests belegen, dass nur der Core-Desired-State kopiert wird und ein Ziel ohne eindeutigen Core-Katalogeintrag sichtbar/auditiert auslässt, statt per Name zu raten. Fixtures umfassen V2 `kind=core`, V1 ohne `kind`, V1 mit verbotenem `kind`, expliziten V1-Softwarefilter, Ablehnung von V2 durch den alten Importer, fehlenden/retired/mehrdeutigen Ziel-Core, doppeltes Core, case-variierte/doppelte JSON-Schlüssel innerhalb verschachtelter Objekte sowie Escapes, Größen-/Nesting-Grenzen des Raw-Tokenizers, Import ohne Core, deterministischen Analysehash und vollständigen Transaktionsrollback.
- Membershiptests unterscheiden bestätigte Direktregel, Limiting-Collection-Ausschluss, offene/fehlgeschlagene Evaluation, effektive Membership, fremde Regel, manuell entfernte eigene Regel und Policyempfang. Tasksequenz-Retirement und `vm_os`-Wechsel werden ohne stilles Core-Toggling geprüft. Für Q1-B decken Fixtures fehlende, veraltete, mehrere, überlappende, typfalsche, zeitzonenverschobene und zu kurze gerätewirksame Fenster ab.
- Loggingtests prüfen Pflichtfelder, CR/LF-/Delimiter-Sanitisierung, Secret-Redigierung, Correlation über PlanId/BundleId, 30-Tage-Tageslog-Retention gegenüber bundlegebundenen Receipts sowie das Verhalten bei ausgefallenem optionalem Log-Sink und ausgefallenem zwingendem Cleanup-Beleg.
- Statische Verträge pinnen genau einen Core-Haken und verhindern vier Phasencheckboxen beziehungsweise ein zweites `core_enabled`-SSoT. Machine-Wire-Tests pinnen die bestehenden Lifecyclewerte und `mecm_client_ack` als einzigen Client-Ready-Übergang; `Core nicht beauftragt` darf nur im Anzeige-Helper vorkommen. DE/EN-Hilfe wird auf Schlüssel-, Platzhalter- und Bedeutungsparität, richtige Zielreihenfolge, korrekte Logpfade, Zustand `Core nicht beauftragt` und registrierte Help-Deep-Links geprüft.
- Falls Fortschrittsausgabe ergänzt wird, `VirtuSphere.ProgressReporting.Tests.ps1` erweitern.
- Nur `scripts/check.ps1` als öffentlichen Runner verwenden; relevante Gates mindestens PowerShell-Syntax, PowerShell-Tests, Dokumenthygiene und bei Portalhilfe DE/EN-Sprachprüfung.

**Abnahme:** Alle ausgewählten Gates laufen aus gültiger Quelle erfolgreich; fehlende Voraussetzungen werden als `infrastructure_error`, nicht als Skip/Pass dokumentiert.

### MC07 – Reales MECM-Pilot-Gate

- Frische Workgroup-VM ohne VirtuSphere-Registry vorbereiten.
- Vor der realen Installation eine simulierte Required-Bereitstellung des vollständigen Zielgraphen auswerten.
- Native SYSTEM-/64-Bit-Ausführung nachweisen.
- Im `ccmcache` für jede Phase die vollständigen vier Dateien nachweisen.
- Installationsreihenfolge `getInfos` → `hostname` → `VMDisksOnline` → `staticip`, Neustart, statische IP als letzte Phase, API/ACK, Detection und DP-Content-ID prüfen. CAS, ContentTransferManager und AppEnforce müssen belegen, dass nach der Netzwerkänderung keine Core-Phase mehr aussteht.
- Danach Upgrade einer bestehenden Pilot-VM und kontrollierte Koexistenz mit einer Altanwendung testen.
- Portalpfad abnehmen: neue VM mit Core-Default, Device-Sync-Regel, Policy-/Dependency-Reihenfolge, Abwahl vor und nach Membership, erneute Auswahl bei erfüllter Detection und Schutz einer manuellen Zusatzregel.
- Portalpfad zusätzlich mit Bestands-VM, Tasksequenzdrift, Limiting-Collection-Ausschluss, offener Collection-Evaluation und dem nach Q1 gewählten Wartungsfensterverhalten abnehmen.
- Cutover von `Deploy Windows 2022` auf die stabile Core-Collection anhand beider Assignment-IDs und tatsächlicher Client-Policy nachweisen; implizites Uninstall bleibt aus.
- Mindestens folgende Diagnosepfade provozieren: Detection überspringt Enforcement, Helferdatei fehlt vor Loggerstart, Content auf DP aber Boundary falsch, vorhandenes `WebAPI` bei fehlendem HTTPS-Scheme und Policywechsel von Alt- zu Neuanwendung.

**Abnahme:** Neue VM, bewusst nachgereichte Bestands-VM, bewusst Core-freie VM und Upgrade-VM erreichen jeweils ihren erwarteten Zustand; Simulation, Collection-, Policy-, Script- und Registry-Evidenz stimmen überein. Produktion bleibt bis zur ausdrücklichen Freigabe offen.

## Stop-Gates

Der Rollout beziehungsweise Rückbau stoppt bei:

- unbekannter oder fremder Application-Ownership,
- unvollständiger RBAC-/Security-Scope-Sicht auf den behaupteten Inventarbereich,
- parallelem Required Deployment mit überlappendem Zweck,
- gleichzeitigem Core-Targeting über `Deploy Windows 2022` und die stabile Core-Collection,
- einem Dependency-/Skriptnamen-Umbau bei noch aktivem oder nicht nachweislich konvergiertem altem Core-Deployment,
- aktiviertem implizitem Uninstall auf dem Core-Deployment,
- fehlender eindeutiger Core-Katalog-/Collection-Identität oder einer nur aus dem Namen geratenen Core-Klassifikation,
- mehr als einem aktiven Core-Katalogdatensatz oder fehlendem datenbankseitigem Singleton-/Typ-Constraint,
- fehlender, fremder oder nicht passender `CoreLimitingCollectionId`,
- einer Migration, die bereits übergebene VMs automatisch mit Core verknüpfen würde,
- einem Create-/Edit-Vertrag, der „Checkbox bewusst aus“ nicht eindeutig von „Feld fehlt“ unterscheidet oder den Nur-neue-VM-Default außerhalb von `interactive_create` anwenden könnte,
- einer verlorenen Tasksequenzberechtigung bei neuem Save/Clone/Transfer,
- nicht aufgelöster Dependency-, Supersedence- oder Task-Sequence-Referenz,
- lokaler/UNC-Manifestabweichung,
- unterschiedlicher lokaler/UNC-Source-Identität oder Cross-Volume-Aktivierung,
- einer Fremdänderung zwischen Validate-Plan und Apply,
- inkonsistenter 32-/64-Bit-Sicht,
- einer partiellen oder widersprüchlichen WebAPI-/Scheme-/Thumbprint-Konfiguration,
- einem hartcodierten DNS-/IP-Fallback oder einem Registrysatz ohne gültigen Config-Commit,
- unbekanntem DP-Status,
- offener Q1-Entscheidung beziehungsweise ungeprüften Deadline-/Maximum-Runtime-/Wartungsfenster-/Outside-Window-Einstellungen; bei Variante B zusätzlich fehlender, veralteter oder unzureichender gerätebezogener MECM-Fensterevidenz,
- einer nicht ausgewerteten oder durch die Limiting Collection ausgeschlossenen Pilotmitgliedschaft,
- fehlendem oder ungeprüftem Vorgänger-Rollbackbundle,
- offenem `cleanup_uncertain`-Intent,
- fehlender frischer Workgroup-Pilot-Evidenz,
- einem nicht eingefrorenen PXE-/VM-Zulauf im deploymentfreien Cutover-Fenster,
- einem Upgrade, das zustandsverändernde Phasen ohne ausdrücklich definierten Replay-Vertrag erneut ausführen würde.

## Nicht im automatischen Umfang

- Löschen alter Applications, Deployment Types, Packages, Deployments oder Content Sources,
- automatisches Anlegen oder Verändern produktiver Collection-Deployments,
- Ersetzen der vier MECM-Dependencies durch einen monolithischen Wrapper, ein klassisches Package/Program oder vier unabhängige Required Deployments,
- automatische Deinstallation auf Clients,
- ungeprüftes Umschalten von Hostname, IP oder Datenträgerzustand auf Bestandsmaschinen,
- Ersetzen der Dependency Chain durch Application Groups oder Task Sequences,
- Produktionsfreigabe ohne reales MECM-/DP-/Workgroup-VM-Gate.

## Definition of Done

- Ein einziger dokumentierter Installer baut, validiert und aktualisiert alle vier vollständigen Pakete.
- Der normale Installer liest die nicht geheime Standortkonfiguration ausschließlich mit `Import-PowerShellDataFile -LiteralPath` aus der ACL-geprüften `C:\ProgramData\VirtuSphere\MECM\ClientPackaging.psd1`, zeigt Pfad, Hash, Source-ID, Limiting-Collection-ID und effektive Werte und besitzt keine stillen Einzelparameter-Overrides.
- Jeder schreibende Lauf erzeugt vor Sourceaktivierung und erstem CM-Write einen vollständigen Vierer-Plan; Apply ist an dessen unveränderte Quellen und CM-Revisionen gebunden und kann die Prüfung nicht überspringen.
- Der Installer erkennt Altobjekte und alle relevanten Referenzen, verändert oder löscht sie aber nicht.
- Application-Identität, Anzeigename, Contentordner und Einstiegsskript sind in Code, Doku und Help eindeutig getrennt und automatisch gespiegelt.
- Die Einstiegsskripte heißen projektweit `client_getInfos.ps1`, `client_hostname.ps1`, `client_VMDisksOnline.ps1` und `client_staticip.ps1`; die beiden alten Dateinamen bleiben nur als ausdrücklich markiertes Migrationswissen erhalten.
- Native 64-Bit-Ausführung und Registry-Detection sind Bestandteil des überprüften Vertrags.
- In Software Center ist nur die Endanwendung `client_staticip` der vorgesehene Einstieg; `client_getInfos`, `client_hostname` und `client_VMDisksOnline` kommen automatisch in dieser Reihenfolge als Dependencies.
- Das Portal zeigt genau einen Core-Baustein; intern bleibt die vierstufige, azyklische Auto-Install-Dependency-Kette der einzige Orchestrator.
- Eine neue, noch nicht an MECM übergebene VM mit aktiver MECM-Tasksequenz erhält Core beim Anlegen standardmäßig; Bestands-VMs werden durch Migration, Upgrade oder bloßes Öffnen niemals automatisch nachselektiert.
- Der sichtbare Create-Default besitzt einen Presence-Sentinel und eine geschlossene Requestherkunft: bewusstes Abwählen speichert `aus`; Edit, Clone, Import und alte/partielle Requests können den Default niemals auslösen.
- Ein berechtigter Admin kann Core bei Bestands-VMs bewusst nachreichen; Auswirkungen, Speicherung, expliziter MECM-Transfer und anschließende Detection-Auswertung sind nachvollziehbar getrennt.
- Die Portalwahl nutzt den bestehenden typisierten Paketzuweisungsweg. Desired State, besessene MECM-Mitgliedschaft und Clientphasen werden getrennt gespeichert beziehungsweise angezeigt.
- `deploy_packages` besitzt einen geschlossenen Core-Typ, eine nur dafür gültige Collection-ID und einen datenbankseitigen Guard für höchstens einen aktiven Core; Bestandszeilen migrieren zu `software`, alte/partielle Payloads können Core nicht verändern.
- Die stabile Core-Collection besitzt genau ein Required Install-Deployment der Endanwendung, ohne implizites Uninstall und ohne paralleles OS-Collection-Targeting.
- Die stabile Core-Collection verwendet die konfigurierte geprüfte Limiting Collection. Direktregel, Collection-Auswertung, effektive Mitgliedschaft und Clientpolicy werden getrennt beobachtet; ein erfolgreicher Add-Cmdlet-Aufruf allein ist kein Membership-Erfolg.
- Core-Collection, Marker, Name und ID werden ausschließlich durch den Projektinstaller beziehungsweise einen projektversionierten Migrationspfad verändert; manuelle Umbenennung oder namensbasierte Adoption blockiert.
- Abwahl entfernt nur die nachweislich VirtuSphere-eigene Collection-Regel und setzt weder Hostname, IP, Datenträger, Registry noch Detection Marker zurück.
- Ist Core vor dem ersten Device-Sync abgewählt, läuft keine der vier Applications. Der erwartete Portalzustand ist `Core nicht beauftragt`; ein fehlender `client_getInfos`-ACK wird weder als Fehler noch als 5/5 ausgegeben. Der Zustand ist nur Anzeigeableitung: bestehende Lifecycle-/Machine-Wire-Werte und der alleinige ACK-Writer bleiben unverändert; eine spätere Abwahl löscht historische 5/5-Evidenz nicht.
- „Content verteilt“ wird nur gemeldet, wenn der geprüfte DP-Zustand das trägt; andernfalls lautet der Status eindeutig „angefordert“ oder „blockiert“.
- Eine frische Workgroup-VM erhält `WebApi`/`Scheme` aus dem Bootstrap, schreibt den logisch committeten Registry-Zustand und erzeugt nachvollziehbare Logs; kein hartcodierter DNS-/IP-Fallback ist mehr vorhanden.
- Jede Phase schreibt die vereinbarte historische Registry-Provenance und das Phasenlog; `client_getInfos` setzt Detection und ACK erst nach erfolgreicher Hash-/Schema-/Commit- und Snapshot-Rücklesevalidierung aus der nativen Registry-Sicht.
- Der Standortkonfigurationssatz folgt der geschlossenen Leer/Gleich/Abweichend/Partiell-Matrix; ein vorhandenes `WebAPI` bei fehlendem oder widersprüchlichem Scheme beziehungsweise ein abweichender gültiger Satz wird nicht still akzeptiert oder überschrieben.
- Ein Upgrade löst keine Hostname-, IP- oder Disk-Aktion allein wegen einer Content-Revision erneut aus.
- Das letzte freigegebene Bundle ist mit Manifest/BundleId wiederherstellbar; Restore der vier gebundenen CM-Revisionsnummern, der passenden Source-Sätze und DP-Verteilung wurde gemeinsam getestet, ohne Deployments/Membership blind zurückzuschreiben.
- Das getrennte Bundlearchiv schützt immer die fünf neuesten erfolgreichen Bundles und alle erfolgreichen Bundles der letzten 180 Tage; nur ein separater What-if-/Plan-ID-/Apply-Cleanup mit gespültem Intent-/Outcome-Journal kann ältere Kandidaten löschen. Offenes Intent blockiert weitere Löschung.
- Clone, Template und Mission-Transfer kopieren die typisierte Core-Auswahl, aber keine MECM- oder Phasenlaufzeitdaten; ein fehlender Core-Katalog im Ziel wird sichtbar/auditiert ausgelassen.
- Mission-Transfer V2 löst `kind=core` ausschließlich gegen einen eindeutigen aktiven Core-Katalogeintrag auf; V1-Dokumente dürfen kein `kind` tragen und lösen ausschließlich `package_kind=software` auf. Alte V1-Importer lehnen V2 ab und ein begrenzter string-/escape-/nesting-bewusster Raw-Tokenizer blockiert mehrdeutige JSON-Schlüssel vor Decode/Write.
- Core-Auswahl, MECM-Transfer und Clientphase besitzen getrennte, geschlossen registrierte Audit-/Evidenzpfade. Einzel-VM-Zuweisung und ihr Desired-State-Audit sowie Transferqueue und deren Audit sind jeweils atomar; ein Auditfehler rollt die Änderung zurück. Clone/Import erzeugen genau ein aggregiertes Mission-Ereignis mit Hashes/Zählern statt Auditspam pro VM.
- Packaging-Tageslogs sind über PlanId/BundleId mit den langlebigen Build-/Cleanup-Belegen korrelierbar. 30-Tage-Logrotation entfernt keine Receipts; ein lokales Bundlearchiv wird in Doku oder Help niemals als Disaster-Recovery-Backup bezeichnet.
- Das Rückbau-Runbook verhindert die Entfernung referenzierter Pakete und erklärt Policy-Latenz, Retire, Uninstall und Delete getrennt.
- `client_getinfo` und `client_getinfo_2.1` werden niemals als neuer Core übernommen: nur inventarisieren, kontrolliert enttargeten und zunächst retire; ein späteres Löschen ist ein eigener Auftrag.
- Doku, Portalhilfe, Tests und Implementierung spiegeln denselben Vertrag ohne unabhängige zweite Konfiguration; DE/EN-Hilfe nennt korrekte Logpfade, Zielreihenfolge und `Core nicht beauftragt` bedeutungsgleich.
- Q1 ist entschieden und Deploymentdeadline, Maximum Runtime, Wartungsfenster-/Outside-Window-Verhalten, Bestätigungsdialog, Preflight und Pilot spiegeln dieselbe Regel. Bei Variante B stammen Fenstertyp, Zeitzone, effektive Gerätefenster und Lauf-/Restartreserve aus frischer MECM-Evidenz. Der Cutover wurde mit Provisionierungsfreeze sowie einer zuvor belegten und vor dem echten Pilot entfernten Simulation durchgeführt.

## Festgelegte Produktentscheidungen

1. **Gewünschte Konfiguration:** Die nicht geheime Standortkonfiguration liegt ACL-geschützt in `C:\ProgramData\VirtuSphere\MECM\ClientPackaging.psd1` und wird ohne Codeausführung geladen; jeder Build erzeugt zusätzlich ein nach Abschluss nicht mehr umgeschriebenes, hashbelegtes Receipt. Normale Builds besitzen keine stillen Einzelparameter-Overrides.
2. **Rollback-Aufbewahrung:** Geschützt bleiben immer die fünf neuesten erfolgreichen Bundles und alle erfolgreichen Bundles der letzten 180 Tage. Löschung ist nur über einen separaten What-if-/Plan-ID-/Apply-Cleanup zulässig.
3. **Templates und Transfer:** Die typisierte Core-Auswahl wird beim Klonen, in Templates und beim Mission-Export/-Import mitgeführt. Laufzeitstatus wird nicht kopiert; fehlt der Core-Katalog im Ziel, wird sichtbar und auditiert ausgelassen.

**Noch offen:** Q1 zum Ausführungszeitpunkt einer bewusst nachgereichten Bestands-VM. Diese neue Frage stammt aus dem ausführlichen MECM-Wartungsfenster-/Required-Deployment-Review und ändert keine der drei bereits bestätigten Entscheidungen.

## Empfohlene Reihenfolge

Zuerst Q1 in MC00 schließen, danach MC01 und MC02 umsetzen. Damit entsteht eine sichere Bestandsaufnahme, bevor irgendein produktiver MECM-Zustand verändert wird. Danach MC03 und MC05 lokal implementieren und über MC06 absichern. MC04 wird zunächst als Runbook und Read-only-Graph geliefert. MC04A folgt erst, wenn Core-Katalogtyp, der beschlossene Zustand `Core nicht beauftragt`, projektverwaltete Collection-/Limiting-Identität, Transferformat V2, atomare Auditowner und sichere Bestandsnachreichung als Verträge und Tests feststehen. Erst MC07 darf Simulation, neuen Dependency-Graphen, reale Deployments, den eingefrorenen OS-Collection-Cutover und den späteren manuellen Rückbau freigeben.
