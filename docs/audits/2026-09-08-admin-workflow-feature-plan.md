# Umsetzungsplan: Admin-Arbeit im Portal erleichtern

Stand: 08.09.2026. Status: geplant, noch nicht umgesetzt. Fachliche Prüfung gegen den aktuellen Quellcode durchgeführt; Korrekturen und zusätzliche Abnahmekriterien sind unten eingearbeitet. Dies ist keine Laufzeit- oder Integrationsabnahme.

## Ziel und Umfang

Admins sollen Änderungen vorab verstehen, wiederkehrende Arbeit gesammelt erledigen und den Verlauf einer VM sowie die Bearbeitung offener Probleme an einer Stelle nachvollziehen können. Dieser Plan umfasst genau die elf vom Nutzer ausgewählten Erweiterungen. Die Reihenfolge unten ist ein Umsetzungsvorschlag; alle elf gehören zum Zielumfang.

| ID | Ausgewähltes Feature | Etappe |
| --- | --- | --- |
| F01 | Sammelbearbeitung mit Änderungsvorschau | E3 |
| F02 | Vergleich von Missionen oder Vorlagen | E4 |
| F03 | Gemeinsame VM-Chronik | E5 |
| F04 | Kopierbuttons für VM-Name, Hostname, IP, MAC und Job-ID | E1 |
| F05 | Anklickbare Dashboard-Zahlen für VMs und MECM ausstehend | E1 |
| F06 | Gezielte Abschlussmeldungen für beobachtete Aufträge | E6 |
| F07 | Zuständigkeit und Bearbeitungsvermerk an offenen Problemen | E6 |
| F08 | Verwendungsnachweis für Pakete, Betriebssysteme, VLANs und Zugangsdaten | E2 |
| F09 | Vorlagen-Assistent mit Namensvorschau | E4 |
| F10 | Änderungsvorschau auch beim normalen Speichern mit Erklärung der Wirksamkeit | E3 |
| F11 | Kalender oder Zeitachse für geplante Aufträge | E5 |

## Ausgangsbasis und gemeinsame Leitplanken

Das Portal besitzt bereits Missionsvorlagen und Klonen, geplante Aufträge und Staffelung, VM-Statusverlauf, strukturierte Audits, Inventar-Abweichungen, Notizen und Sammelaktionen zum Löschen und MECM-ID-Reset. Die Erweiterungen bauen auf diesen Funktionen auf. Vor jeder Etappe werden der aktuelle Code und bestehende Tests erneut geprüft, damit parallele Projektänderungen berücksichtigt werden.

Die vorhandenen Repository-Writer bleiben für Validierung, Transaktionen und Sperren zuständig. Maschinenverträge, MACs, MECM-IDs, Rolloutrevisionen und eingefrorene Rolloutnamen werden nicht durch Komfortfunktionen umgangen. Änderungen an der Portal-Konfiguration dürfen keine ungeplante Änderung auf ESXi oder in MECM auslösen.

Alle Ansichten beachten RBAC; Aktionen prüfen ihre Berechtigung erneut beim Schreiben. Portaltexte bleiben DE/EN-lokalisiert. Bestätigungen, Formattribute, Statusdarstellung, Zeitformatierung und Navigation verwenden die bestehenden gemeinsamen Helfer. Neue Module werden fokussiert ergänzt und in den vorhandenen Registrierungen eingebunden.

Große Listen erhalten serverseitige Filter, Pagination und sichtbare Gesamtzahlen. Für Auswahlgrößen, Vorschauen und Polling werden vor Implementierung passende Grenzen im jeweiligen SSoT festgelegt. Gekürzte Darstellungen dürfen den bestätigten Schreibumfang niemals ändern. Das Portal bleibt ohne externe Dienste oder Laufzeitdownloads nutzbar.

## E0: Fachliche und technische Grundlage festlegen

**Arbeit:** Die elf Features bestehenden Routen, Repository-Funktionen, Auditereignissen und Berechtigungen zuordnen. Für Vorschauen eine gemeinsame Darstellung von Feld, Altwert, Neuwert und Wirkung entwerfen. Zulässige Änderungen und die vorhandenen fachlichen Writer pro Feld festhalten. Für Beobachtungen und Problemzuständigkeiten additive Datenmodelle samt Aufbewahrung und Verhalten beim Löschen entwerfen.

**Entscheidungen für die Umsetzung:** Sammelbearbeitung beginnt innerhalb einer Mission mit einer expliziten VM-Auswahl. Für den Auftragskalender dient eine Zeitachse mit Tages-/Wochenumschaltung als erste Darstellung. Abschlussmeldungen erscheinen im Portal. Ein Problem gilt durch einen Bearbeitungsvermerk nicht als technisch behoben.

**Abnahme:** Jede Funktion hat eine Datenquelle, eine definierte Berechtigung und klare Leer-, Konflikt- und Fehlerzustände. Neue Schemafelder und fachliche Verträge werden bei Bedarf als Migration beziehungsweise ADR dokumentiert. Die weiter unten aufgeführten E0-Entscheidungen zu Vorschau, Sortierung, Chronik, Jobzeiten und Problemidentität sind vor der jeweiligen Implementierung konkretisiert. Die weiteren Etappen können einzeln geliefert und geprüft werden.

## E1: Direkter Zugriff und Kopieren

### F04: Kopierbuttons

**Funktionsumfang:** Kopieren neben VM-Name, gewünschtem Windows-Hostnamen, aktivem Rolloutnamen, IP-Adresse, MAC-Adresse und Job-ID an den jeweiligen Listen- und Detailstellen. Bei mehreren IPs oder MACs ist jeder Wert eindeutig seiner Netzwerkkarte zugeordnet. Namen bleiben exakt erhalten.

Ein gemeinsamer JavaScript-Helfer und ein gemeinsames zugängliches Bedienelement geben eine kurze Erfolgsmeldung. Leere Werte erhalten keine Kopieraktion. Wenn die Clipboard-API im HTTP-LAN oder aufgrund von Browserrechten nicht funktioniert, bleibt der Wert auswählbar und der Nutzer erhält einen verständlichen Hinweis zum manuellen Kopieren.

**Anschlussstellen:** Portal-Assets und Assetregistrierung, VM-Liste, registrierte VM-Editor-Renderer und Deploy-Detailansicht.

**Abnahme:** Exakte Werte einschließlich Unicode und Sonderzeichen; kein Kopieren von Beschriftungen; Tastaturbedienung; keine falsche Erfolgsmeldung bei verweigertem Clipboard-Zugriff.

**Randfälle/QoL:** Nur tatsächlich angezeigte und berechtigte Werte kopieren; keine versteckten Geheimnisse im DOM. Die IP ist als konfigurierte Adresse zu bezeichnen, sofern keine beobachtete Adresse vorliegt; DHCP liefert keinen erfundenen Wert. Buttons innerhalb von Formularen haben `type="button"`, verständliche individuelle Namen und erhalten den Fokus. Eine einzige begrenzte Statusmeldung verhindert Screenreader-Lärm. Formularfelder werden beim Klick aus ihrem aktuellen Wert gelesen, Textanzeigen aus ihrem aktuellen Anzeigestand.

### F05: Anklickbare Dashboard-Zahlen

**Funktionsumfang:** Die Zahlen für VMs und ausstehende MECM-Übernahmen führen in eine missionsübergreifende VM-Liste mit dem passenden Filter. Mission, Identitäten, Status und Direktzugriff auf die VM sind dort sichtbar. Templates werden eindeutig bezeichnet, soweit sie zum jeweiligen Zählumfang gehören.

Zähler und Zielansicht verwenden dieselbe fachliche Auswahl. Die bestehende missionsgebundene VM-Liste bleibt der Einstieg für die Bearbeitung einer konkreten Mission. Die neue Gesamtansicht ist zunächst lesend und dient ausschließlich den gewählten Dashboard-Einstiegen; eine globale Suche ist kein zusätzlicher Auftrag dieses Plans.

**Anschlussstellen:** `portal/dashboard.php`, VM-Repository, gemeinsame Sortier- und Statushelfer; neue fokussierte Listenroute nach Prüfung der Navigation.

**Abnahme:** Für unveränderte Daten stimmen Kachelzahl und Trefferzahl überein. Nulltreffer, gelöschte Einträge und laufende Statusänderungen werden verständlich behandelt. Filter und Sortierung bleiben bei Navigation erhalten.

**Verbindliche Auswahl:** Der heutige VM-Zähler zählt alle `deploy_vms`, einschließlich Vorlagen. „MECM ausstehend“ verwendet `updated = 1 OR mecm_sync_state = pending`; das ist nicht gleichbedeutend mit einer überfälligen VM. Diese Prädikate werden einmal im Reader definiert und von Zähler und Trefferliste verwendet. Joins auf Interfaces/Pakete vervielfachen weder Treffer noch Anzahl. Ein geänderter Filter setzt die Seite zurück. Ein alter Dashboardstand darf nach zwischenzeitlichen Writes eine andere Trefferzahl ergeben; die Liste zeigt ihren aktuellen Stand.

## E2: Abhängigkeiten sichtbar machen

### F08: Verwendungsnachweis

**Funktionsumfang:** Auf Paket-, Betriebssystem-, VLAN- und Zugangsdatenansichten erscheint ein Bereich „Verwendet in“. Er nennt betroffene Missionen, Vorlagen und VMs mit Direktlinks. Direkte Zuweisung, mittelbare Nutzung und Referenz eines aktiven oder geplanten Auftrags werden getrennt erklärt; historische Referenzen erscheinen nur bei vorhandener belastbarer Verknüpfung.

Bei VLANs zählt die exakte gespeicherte Zuordnung. Bei Zugangsdaten werden ausschließlich Referenzen und Bezeichnungen gezeigt. Der Verwendungsnachweis ergänzt vorhandene Änderungs- und Löschprüfungen, ersetzt deren unmittelbare serverseitige Prüfung aber nicht.

**Datenmodell:** Paketzuordnungen laufen über `deploy_vm_packages.package_id`, Betriebssysteme über den gespeicherten Namen `deploy_vms.vm_os`, VLANs über Interfacewerte und gesondert `deploy_missions.wds_vlan`. Betriebssystem-/Paketkataloge bleiben MECM-eigen; es entstehen keine neuen Bearbeitungsaktionen für sie. Bestehende OS-Nutzungszähler in `lib/repo/catalog.php` werden in denselben Scope eingebunden. Ein gleichnamiges Inventarobjekt beweist weder einen VM-Standort noch die Nutzung eines Zugangs: ESXi-/Ansible-Zugänge sind insbesondere über Jobreferenzen nachweisbar. Bei gelöschten Zugängen sind Job-FKs NULL; verlorene Herkunft wird nicht aus Namen rekonstruiert. Ein nicht auswertbarer Nachweis ist nicht „unbenutzt“. Die bestehende Löschprüfung für Zugänge berücksichtigt derzeit nur queued/running; diese Abweichung zum aktiven Statusvertrag einschließlich cancelling ist vor einer darauf gestützten Freigabe gezielt zu prüfen und im gemeinsamen Owner zu korrigieren.

**Anschlussstellen:** Katalog- und Credential-Repositories, Missions-/VM-Zuweisungen, vorhandene Netzwerk- und Deploy-Abfragen sowie jeweilige Portalrenderer.

**Abnahme:** Mehrfachnutzung, unbenutzte Einträge, Vorlagen, geplante Jobs und zwischenzeitlich entfernte Referenzen werden korrekt dargestellt. Kein Secret gelangt in Liste, HTML oder Vorschau. Große Trefferlisten bleiben begrenzt und durchblätterbar.

## E3: Änderungen vorab prüfen und gesammelt anwenden

### F10: Vorschau beim normalen Speichern

**Funktionsumfang:** Bei fachlichen Änderungen an VMs, Missionen und Vorlagen zeigt der Speichervorgang zuerst die tatsächlichen Unterschiede. Reine Notizen- und Bearbeitungsvermerke bleiben direkt speicherbar. Änderungen ohne Unterschied erzeugen keine wirkungslose Bestätigung.

Die Vorschau erklärt pro Änderung die Wirkung: nur im Portal gespeichert, für einen zukünftigen Auftrag relevant oder mit einem ausdrücklich erforderlichen Folgeschritt verbunden. Besonders klar werden ESXi-Name, gewünschter Windows-Hostname und aktiver MECM-Rolloutname unterschieden. Vor Bindung einer MECM-ID folgt der Rolloutname bereits beim Speichern dem gewünschten Namen; die bestehende Rolloutfunktion entscheidet über die Revision. Erst ein eingefrorener Rolloutname erfordert die explizite Reset-Aktion für die Aktivierung einer Änderung. Vorlagen haben keinen Rolloutnamen. Die Vorschau darf keinen zusätzlichen Reset auslösen. Hardwareänderungen dürfen nicht als bereits auf ESXi angewendet dargestellt werden.

Vorschau und endgültiger Writer verwenden dieselbe Normalisierung und Validierung. Eine gebundene Revision beziehungsweise ein Fingerprint umfasst die relevanten Daten und Abhängigkeiten. Vor dem Schreiben werden sie im bestehenden Lockpfad erneut geprüft; veraltete Vorschauen verlangen eine neue Prüfung und erhalten die Eingaben.

**Anschlussstellen:** `lib/vm_edit_page.php`, registrierte VM-Edit-Module, Missionsaktionen, Repository-Validatoren, `lib/forms.php` und gemeinsame Modalrenderer.

**Abnahme:** Alt-/Neuwerte entsprechen genau dem späteren Write. Abbrechen schreibt nichts. Paralleländerungen, entfernte Pakete und aktive Jobs führen zu nachvollziehbaren Konflikten. Ein direkter POST kann Vorschaupflicht und fachliche Prüfung nicht umgehen. Geheimnisse werden nicht als Alt-/Neuwerte angezeigt.

**Vorschauvertrag:** Pro Vorgang einen serverseitig gebundenen, begrenzten Entwurf mit Nutzer, Aktion, Ziel-IDs, normalisierter Änderung, vollständigem fachlichem Ausgangsfingerprint, Ablaufzeit und einmaliger Bestätigung verwenden. Ein clientseitig berechenbarer Hash allein belegt keine geprüfte Vorschau. Mehrere Tabs haben getrennte Vorgänge; Sprachwechsel verändert keine fachliche Revision. Ein wiederholter Submit liefert das gespeicherte Ergebnis oder einen eindeutigen bereits-verarbeitet-Hinweis, nie einen zweiten Create. Nach einem unklaren Commit-Ausgang wird zuerst der Vorgang aufgelöst. Entwurfsabschluss, Write und verpflichtendes Audit müssen atomar sein. PRG nach Erfolg; Zurück/Bearbeiten erhält sämtliche Eingaben einschließlich abgewählter Checkboxen, Vererbung und dynamischer Zeilen.

**Konflikte und Wirkung:** `updated_at` allein reicht wegen sekundengenauer Zeitstempel und separat veränderlicher Kindtabellen nicht als Vorschaufingerprint. Der Vergleich umfasst nur fachlich relevante Konfiguration und Wirkungsabhängigkeiten, nicht laufende Heartbeats oder reine Darstellung. Die vorhandene Versionsprüfung wird dabei nicht still entfernt. Alt-/Neuwerte kommen aus dem vollständigen normalisierten Bundle, nicht aus dem gekürzten Audittext. RAM nutzt `lib/vm_ram.php`; „geerbt“, explizit 0, leer und unverändert bleiben verschieden. Bestehende Paket-/OS-Zuordnungen werden vor einem Write erneut gelesen, da MECM-Sync sie parallel verändern kann. Hinweise zu MECM-Übernahme stammen aus `lib/mecm_plan.php`, `lib/repo/mecm_provenance.php` und den Rollout-/Standort-/Modus-Ownern; keine zweite Liste von Wirkungsregeln im Renderer.

**Bedienung:** Eine lesbare Vorschauseite beziehungsweise ein Inline-Schritt mit dem bestehenden gemeinsamen Bestätigungsdialog kombinieren; kein zweiter Dialogaufbau und keine doppelte Bestätigungsfolge. Nur Notizen zu ändern umgeht weiterhin weder RBAC noch bestehende Job-/Versionssperren. Neue Submitaktionen werden im Confirm-Vertrag begründet klassifiziert, auch bei deaktiviertem JavaScript.

### F01: Sammelbearbeitung mit Änderungsvorschau

**Funktionsumfang:** In einer Mission mehrere VMs explizit auswählen und CPU/RAM, Betriebssystem sowie Paketzuweisungen gesammelt ändern. Für Pakete gibt es klar getrennte Operationen „hinzufügen“, „entfernen“ und „ersetzen“. Weitere Hardwarefelder werden nur aufgenommen, wenn ihre Semantik über den vorhandenen Writer eindeutig abgebildet werden kann.

Jedes Feld besitzt einen ausdrücklichen Zustand „unverändert lassen“. Leeren, Abwählen und Ersetzen sind davon unterscheidbar. Die Vorschau zeigt pro VM Altwert, Neuwert, Wirkung und gegebenenfalls Blockiergrund sowie vollständige Anzahlen. Identitäten und maschineneigene Laufzeitfelder sind keine Sammel-Eingabefelder.

Eine blockierte Auswahl wird zunächst korrigiert oder explizit verkleinert und neu geprüft. Die bestätigte Auswahl wird innerhalb ihrer zulässigen Größe atomar geändert. Wird sie nach der Vorschau ungültig, erfolgt kein Teil-Write. Ein verschwundener VM-Eintrag erweitert die Auswahl niemals auf alle VMs.

**Anschlussstellen:** `portal/vms.php`, bestehende Bulk-Auswahl, VM-Validierung/Persistenz, Vorschaukomponenten aus F10 und strukturierte Audits.

**Abnahme:** Gemischte Ausgangswerte, leere Auswahl, doppelte/fremde IDs, Maximalumfang, laufende Jobs, Paralleländerungen und Transaktionsrollback. Das Ergebnis und Audit entsprechen exakt der bestätigten Auswahl; MACs und Rolloutdaten bleiben gemäß ihrem Vertrag erhalten.

**Schreibpfad:** Der existierende `repo_save_vm()` ersetzt auch Disks und Pakete. Er darf nicht mit leeren Platzhalterlisten oder einem unvollständigen Formular aufgerufen werden. Ein Bulk-Adapter liest vollständige Bundles im bestehenden Sperrpfad und wendet ausschließlich die gewählten Operationen an; unveränderte Kindtabellen dürfen nicht unnötig gelöscht/neu erzeugt werden. Benötigte Unterstützung wird im vorhandenen Persistenz-Owner ergänzt. Der Hauptwriter und seine Netz-/Rolloutprüfungen bleiben gemeinsam. VM-IDs werden streng als skalare positive Ganzzahlen validiert, anschließend eindeutig und stabil sortiert; Arraywerte, `12abc`, negative Zahlen und Overflow dürfen nicht durch Integer-Casts andere Ziele ergeben.

**Katalog/QoL:** Bestehende zurückgezogene Pakete/OS dürfen unverändert erhalten bleiben; neue Zuweisungen kommen aus dem aktuellen zulässigen Picker-Scope. „Hinzufügen“ ohne neue IDs und „Entfernen“ ohne Treffer sind No-ops. Ein leeres „Ersetzen“ bedeutet alle Pakete entfernen und erhält eine passende Bestätigung. Die Vorschau nennt ausgewählt/geändert/unverändert/blockiert getrennt. Auswahl über Seiten hinweg wird ausdrücklich dargestellt; „alle sichtbaren“ darf nie „gesamte Mission“ bedeuten. Die neue atomare Bearbeitung ändert nicht die bestehende Skip-Semantik von Bulk-Löschen und Bulk-Reset. Geplante queued-Jobs werden nicht pauschal wie running/cancelling behandelt; jeder Writer behält seine vorhandene fachliche Sperrentscheidung.

## E4: Vergleichen und aus Vorlagen erzeugen

### F02: Vergleich von Missionen oder Vorlagen

**Funktionsumfang:** Zwei Missionen, zwei Vorlagen oder eine Mission und eine Vorlage auswählen. Der Vergleich zeigt Unterschiede in Missionswerten, VM-Bestand, CPU/RAM, Festplatten, Netzwerkkarten, Betriebssystemen und Paketen. Filter „nur Unterschiede“ und die Kategorien hinzugefügt, entfernt, geändert und unverändert erleichtern die Sichtung.

VMs werden zunächst anhand exakter Namen zugeordnet. Bei abweichenden Präfixen kann der Admin eine explizite Paarung wählen. Mehrdeutige Zuordnungen werden sichtbar und niemals still durch Ähnlichkeit aufgelöst. Der Vergleich arbeitet mit gespeicherten Sollwerten; Laufzeitstatus, MACs und MECM-IDs gelten nicht als Konfigurationsabweichung. Reihenfolge wird nur dort ignoriert, wo sie fachlich keine Bedeutung hat.

**Vergleichsvertrag:** Die Paarung ist eindeutig 1:1 über die konkreten Quell-/Ziel-IDs gebunden und bleibt unabhängig von Sortierung/Filter. Ein Präfixvorschlag darf Paarungen vorbereiten, braucht aber die sichtbare Bestätigung und Konfliktprüfung. Beide Seiten werden als konsistenter lesender Stand erfasst; Refresh erneuert beide und kennzeichnet veraltete Paarungen. Ersteller, Zeitstempel und technische DB-IDs zählen nicht als Sollabweichung. Interfaces und Disks werden mit ihrer fachlichen Reihenfolge und Multiplizität verglichen; IDs sind zwischen Klonen nicht gleich und Disk-IDs werden heute beim Speichern neu erzeugt. Gespeicherte Vererbung und daraus berechneter effektiver Wert stehen getrennt. Paketnamen samt Version bleiben vollständig; kein Zusammenfalten auf Basisnamen. Der gemeinsame Felddeskriptor muss alle editierbaren Mission-/VM-/Kindfelder klassifizieren, einschließlich Autostart, Hotplug, Gasttyp und Domain. Nicht vergleichbare Werte erscheinen ausdrücklich, nie als gleich. JSON-Export und gekürzte Audittexte sind kein vollständiges Vergleichsmodell.

**Anschlussstellen:** Missions-/VM-Reader, Paket- und Interfacezuordnungen, normalisierte Felddarstellung aus F10. Die Ansicht ist lesend; automatische Übernahme gehört nicht zu diesem Feature.

**Abnahme:** Identische Konfigurationen mit anderen Datenbank-IDs bleiben gleich. Exakte ESXi-Namen, Datenträger-/Interfaceidentitäten, Mehrfachzuordnungen und fehlende VMs werden korrekt behandelt. Große Vergleiche bleiben navigierbar und zeigen ihren vollständigen Umfang.

### F09: Vorlagen-Assistent mit Namensvorschau

**Funktionsumfang:** Geführter Ablauf „Vorlage wählen → Zielmission und Namen festlegen → Zielwerte zuordnen → Vorschau → Anlegen“. Namenspräfix, Startnummer und Stellenzahl werden für ESXi-Namen und Windows-Hostnamen getrennt konfiguriert. Passende Missionsziele wie Datacenter, Datastore und WDS-Portgruppe können bewusst gesetzt werden; Netzzuordnungen erfolgen explizit.

Die Vorschau zeigt sämtliche entstehenden VMs, Identitäten, Zielzuordnungen sowie Namens- und Netzwerkkonflikte. Der bestehende Clone-Owner wird um eine validierte Abbildung von Quell-VM-ID auf Zielwerte erweitert: Der heutige Writer kopiert Namen unverändert und unterstützt diese Abbildung noch nicht. Bestehende Aufrufer behalten ihr Verhalten. Zielwerte werden vor Insert, Namensclaim und Netzwerkprüfung angewendet, nicht nachträglich umbenannt. Sein Rücksetzen beziehungsweise frisches Initialisieren aller Laufzeit- und Rolloutdaten bleibt erhalten. Ein abschließender Link führt zur neuen Mission oder zur bestehenden Bereitstellungsansicht.

**Anschlussstellen:** Vorhandener Vorlagen-Klonpfad, Namensvalidatoren, Netzwerk-Writer, Inventar-/Zielauswahl und Vorschau aus F10.

**Abnahme:** Lange Namen, Nummerierungsgrenzen, verbotene Zeichen, Case-/Unicodevarianten, belegte Namen und zwischenzeitlich geänderte Vorlagen. Bei einem Konflikt entsteht keine halbe Mission. Die Vorschau startet keinen Deploy-Auftrag und übernimmt keine MACs oder MECM-IDs der Vorlage.

**Zusätzliche Voraussetzungen:** Die Reihenfolge der Nummerierung wird einmal aus einem vollständigen, stabilen Quellscope mit ID-Tie-Breaker materialisiert und vor Bestätigung nicht durch Pagination verändert. Standardmäßig bleiben Namen erhalten; Nummerierung ist ausdrücklich gewählt. Leere Vorlagen erhalten einen klaren Zustand. Die vollständige Quelle einschließlich Kindtabellen wird erneut geprüft und während des Klonens konsistent gehalten. Konkurrierende Klone mit gleichem ESXi-Zielnamen, aber verschiedenen Windows-Hostnamen benötigen einen nachgewiesenen gemeinsamen Namensschutz: Der heutige globale ESXi-Namenscheck ist laut Code nur anwendungsseitig, ein Hostnamenclaim deckt diesen Fall nicht ab. Ein erforderlicher Schutz gehört in den Namens-/Persistenz-Owner für alle Erzeuger, nicht nur in den Assistenten. Kollisionsgleichheit folgt den jeweiligen Namens-/DB-Verträgen; ESXi- und MECM-Namen dürfen keine gemeinsame Normalisierung bekommen.

**Netzwerk/QoL:** Ziel-Missionswerte und VM-Overrides werden getrennt gezeigt; bestehende Overrides dürfen eine bewusst gewählte Missionsänderung nicht unbemerkt aushebeln. Statische IPs werden durch Präfix/Nummerierung nicht geändert. Beibehaltene statische IPs erhalten einen klaren Hinweis, ohne nicht nachweisbare globale Netzfreiheit zu versprechen. WDS-Portgruppe und allgemeines VLAN-Mapping durchlaufen ihre jeweiligen Owner. Ohne qualifiziertes Inventar ist ein Zielwert nicht als geprüft auszugeben; die eigentliche ziel-/modusgebundene Deploy-Freigabe erfolgt weiter in `deploy_queue_blockers()`.

## E5: Verlauf und Zeitplanung zusammenführen

### F03: Gemeinsame VM-Chronik

**Funktionsumfang:** Auf der VM-Detailseite ein gemeinsamer chronologischer Verlauf aus vorhandenen Statuswechseln, zugehörigen Deploy-Aufträgen, MECM-/Clientmeldungen und Änderungs-Audits. Jede Zeile zeigt Quelle, Zeitpunkt, verständlichen Inhalt, gegebenenfalls handelnde Person und einen passenden Detail-/Loglink. Filter nach Ereignisart und Zeitraum sowie serverseitiges Nachladen gehören dazu.

Die Verknüpfung erfolgt über belastbare VM-, Job- und Revisionsbezüge. Gleiche Namen oder MACs in historischen Daten sind allein kein Beweis. Quellzeit und Eingangszeit bleiben unterscheidbar, soweit beide existieren. Die stabile Sortierung besitzt einen eindeutigen Tie-Breaker. Aufbewahrungsgrenzen und fehlende Historie werden erklärt; die Ansicht rekonstruiert keine nicht gespeicherten Ereignisse und erzeugt keine duplizierte Telemetrie.

**Beleggrenze:** `deploy_client_events` und die bisherigen VM-Statusereignisse besitzen einen VM-Bezug, aber keine durchgängige Rolloutrevision oder Client-Quellzeit. Solche Einträge werden VM-bezogen ohne behauptete Rolloutzuordnung angezeigt. Der Statusreader muss die vorhandene Ereignis-ID für Pagination projizieren. Gemeinsame MECM-Heartbeats sind Systemmeldungen und keine Ereignisse jeder VM. Ein Jobbezug erfordert materialisierte Ergebniszeilen oder eine nachweisbare explizite Auswahl; historische „ganze Mission“-Jobs dürfen nicht rückwirkend über die heutigen Missionsmitglieder zugeordnet werden.

**Cursor und Rechte:** Pro Quelle einen High-Water-Mark beim Öffnen erfassen; Seiten verwenden Server-Eingangszeit, festes Quellenranking und Quell-ID als Gesamtordnung. Neue Ereignisse erscheinen nach bewusstem Refresh; inzwischen gelöschte Ereignisse bleiben fehlend, ohne Vollständigkeitsversprechen. Ein nicht lesbarer Teilstrom wird als nicht verfügbar gekennzeichnet und verhindert nicht die übrige Chronik. Auditdetails bleiben zunächst an `users.manage` gebunden, Deploydetails an `deploy.run`; Filtern geschieht vor Zählen und Pagination. Kein Sicherheits-/Login-/Credential-Audit wird allein wegen derselben Person oder Korrelations-ID in eine VM-Chronik gezogen. Eine spätere Freigabe reduzierter VM-Audits für normale Nutzer benötigt einen gesonderten Berechtigungsvertrag. Identische Quellereignisse werden einmal dargestellt; verschiedene Quellen dürfen nicht anhand ähnlichen Textes dedupliziert werden.

**Anschlussstellen:** Bestehender VM-Statusverlauf, strukturierte Audit-Reader/Presenter, Clientereignisse und Deploy-Ergebnisse; fokussierter gemeinsamer lesender Aggregator.

**Abnahme:** Rolloutwechsel, gelöschte/aufbewahrungsbedingt fehlende Quellen, gleichzeitige Ereignisse, Teilergebnisse und eingeschränkte Berechtigungen. Seitenwechsel verlieren oder verdoppeln unter dem gewählten Cursorvertrag keine Ereignisse. Die Chronik ändert keinen Lifecycle-Status.

### F11: Zeitachse für geplante Aufträge

**Funktionsumfang:** Tages- und Wochenansicht mit Mission, Zielhost, Modus, geplantem Start und aktuellem Zustand. Filter nach Mission und Host, zusätzlich eine tabellarische Darstellung für kleine Bildschirme und Tastaturbedienung. Jeder Auftrag führt zu seiner vorhandenen Detailansicht.

Geplante Aufträge erscheinen als frühestmögliche Startmarken ohne erfundene Endzeit. `scheduled_at` garantiert keinen tatsächlichen Start: Queue, Dienstpause und laufende Aufträge können ihn verzögern. Für laufende und abgeschlossene Aufträge werden ausschließlich nachgewiesene Laufzeiten gezeigt. `locked_at` wird beim Abschluss geleert, `updated_at` verändert sich auch bei Heartbeats und späteren Korrekturen; beides liefert keine vollständige historische Laufzeit. E0 muss daher entweder einen passenden bereits gespeicherten Beleg nachweisen oder additive Job-Ausführungszeitpunkte im gemeinsamen Claim-/Terminalvertrag planen. Zeitpunkte einzelner Remote-Schritte oder Create-Einheiten sind keine Job-Gesamtlaufzeit. Historische fehlende Zeitpunkte bleiben „nicht aufgezeichnet“. Staffelungen bleiben als Gruppe und als einzelne Aufträge erkennbar. Gleichzeitige Planungen werden sichtbar, jedoch ohne unbelegte Behauptung eines Ressourcen- oder Ausführungskonflikts.

**Anschlussstellen:** Bestehende Deploy-Zeitplanung, Queue-/Job-Reader und URL-/Statushelfer. Die Zeitachse ist zunächst lesend; Zeitänderungen laufen über vorhandene bestätigte Aktionen.

**Abnahme:** Sommer-/Winterzeit, Tagesgrenzen, abgesagte und überfällige Aufträge, Staffelgruppen und laufende Jobs ohne Endzeit. Zeitzone sichtbar erklären. Kein Drag-and-drop-Write und keine neue Scheduler-Logik.

**Zeit-/Navigationsvertrag:** UTC bleibt Speicher-/Vergleichszeit, `portal_timezone()` und `portal_format_timestamp()` besitzen die Darstellung. Server liefert Zeitbasis und eindeutige UTC-/Offsetwerte; Browser-Lokalzeit oder Uhrenabweichung dürfen keine negative Laufzeit erzeugen. Lokale Tagesfenster werden an ihren Grenzen in UTC umgerechnet und können an Zeitumstellungen unterschiedlich lang sein; Abfragen verwenden halboffene Intervalle und berücksichtigen überlappende Ausführungen. Doppelte Herbststunden erhalten einen unterscheidbaren Offset. Sofortige queued-Jobs ohne `scheduled_at` stehen in einem eigenen Wartebereich. Systemjobs ohne Mission besitzen nur ihre System-/Joblinks. Gelöschte Zugänge ergeben „Ziel nicht mehr zuordenbar“. Wiederholung erzeugt nach bestehendem Vertrag eine neue Job-ID ohne übernommenen Zeitplan oder Staffelgruppe.

## E6: Beobachtung und Zusammenarbeit

### F06: Gezielte Abschlussmeldungen

**Funktionsumfang:** Ein Nutzer kann einen Auftrag ausdrücklich beobachten und die Beobachtung beenden. Eine kleine Meldungsübersicht im Portal zeigt dessen Abschluss mit Ergebnis und Direktlink. Erfolg, Fehler, Teilergebnis und Abbruch werden getrennt bezeichnet; unbekannte Remote-Ergebnisse dürfen nicht als Erfolg erscheinen. Meldungen sind als gelesen markierbar und bleiben beim Seitenwechsel erhalten.

Die Beobachtung gilt pro Nutzer und Auftrag und erfasst den Abschluss nach Beginn der Beobachtung. Bereits abgeschlossene Jobs zeigen unmittelbar ihr vorhandenes Ergebnis. Hintergrundabfragen sind begrenzt, geben die Session-Sperre vor Datenbankarbeit frei und stellen nach Rückkehr in einen aktiven Tab den aktuellen Stand her. Nutzer brauchen kein offenes Joblog. Lesen bestätigt nur die Meldung, niemals die technische Behebung eines Problems.

**Anschlussstellen:** Layout/registrierte Portal-Assets, authentisierter lesender Endpunkt, kanonische Jobstatus-Reader; additive Persistenz für Beobachtung und Lesestand.

**Abnahme:** Seitenwechsel, Reload, mehrere Tabs, kurzzeitiger Verbindungsabbruch, Abschluss während Abwesenheit, Verlust der Berechtigung und Joblöschung. Pro Nutzer/Auftrag/Abschluss kein wiederholter Hinweis für denselben gelesenen Zustand. Keine externen Push-, E-Mail- oder Cloud-Dienste erforderlich.

**Identität und Retention:** Beobachten, beenden und als gelesen markieren sind authentisierte CSRF-geschützte POSTs mit Aktionsklassifikation; GET-Polls schreiben keine Lesestände. Die Ergebnisdarstellung verwendet `lib/deploy_terminal_presenter.php` und die Create-Ergebnisowner, nicht nur den rohen Jobstatus. Gelesen ist an eine stabile Abschlussidentität gebunden, nicht an `updated_at`; spätere Detail-/Recoveryänderungen erzeugen nicht denselben Abschluss erneut. Retries und Staffelgruppen sind neue beziehungsweise mehrere Job-IDs: zunächst explizite Einzelbeobachtung ohne stilles Abonnement neuer Jobs. Unsubscribe löscht keine Audits; erneutes Beobachten desselben abgeschlossenen Jobs setzt keine gelesene Meldung zurück.

Ein rein lesender Poll kann einen zwischen Abschluss und Rückkehr gelöschten Job nicht rekonstruieren. E0 legt fest: erhaltene Jobzeilen bleiben die Ergebnisquelle; bei Purge/Löschung bleibt nur eine begrenzte nutzerbezogene Referenz mit „Auftrag nicht mehr verfügbar“, ohne erfundenes Ergebnis oder historischen Namen aus neu vergebenen IDs. Eine darüber hinaus garantierte Offline-Zustellung würde einen transaktionalen Abschlussdatensatz erfordern und darf nicht nebenbei behauptet werden. Aufbewahrung, Maximalzahl und Cleanup der Beobachtungen werden explizit festgelegt und durch Restore-Tests mitgeprüft.

**Polling/QoL:** Gebündelte Abfrage statt eines Pollers pro Job, keine überlappenden Requests, Backoff bei Ausfällen, Pause in versteckten Tabs und sofortige Aktualisierung bei Rückkehr. 401/403/Passwortwechselpflicht beendet Polling und nutzt den bestehenden Sitzungsfluss; der Poll darf die Sitzung nicht unbegrenzt künstlich aktiv halten. Eine persistente Meldungsliste ist die zuverlässige Anzeige über mehrere Tabs, kurzfristige Hinweise werden pro Tab begrenzt. Eine tabübergreifende Exactly-once-Toast-Zusage ist ohne weiteren Zustellvertrag ausgeschlossen. Sprache wird erst beim Anzeigen gewählt; keine lokalisierten Meldungstexte dauerhaft speichern. Der Ausfall dieser Komfortfunktion blockiert weder Jobabschluss noch die übrige Portalnavigation.

### F07: Zuständigkeit und Bearbeitungsvermerk

**Funktionsumfang:** An konkret identifizierten offenen VM-/Jobproblemen „Übernehmen“, „Zuweisen“ und „Freigeben“ anbieten. Sichtbar sind zuständige Person, Übernahmezeit und ein Verlauf von Bearbeitungsvermerken. Die bestehende Berechtigungssystematik bestimmt Selbstübernahme und Fremdzuweisung; allgemeine VM-/Missionsnotizen bleiben davon getrennt.

Die Zuordnung hängt an einer stabilen Problemidentität einschließlich relevantem Auftrag beziehungsweise Rollout. Eine neue Störung desselben Typs erbt keine alte Erledigung. Die fachliche Quelle entscheidet, ob das Problem noch besteht. Verschwindet es, bleibt die Bearbeitung nachvollziehbar; ein manueller Vermerk beseitigt keinen technischen Blocker. Zugehörige Ereignisse werden in F03 sichtbar, soweit sie die VM betreffen.

**Anschlussstellen:** Bestehende Problemdarstellungen, Benutzer-/Berechtigungsmodell, strukturierte Audits, neue additive Persistenz und VM-Chronik aus F03. Eine zusätzliche zentrale Problemseite ist hierfür nicht vorausgesetzt.

**Abnahme:** Gleichzeitige Übernahme durch zwei Nutzer, deaktivierte Konten, unberechtigte Zuweisung, gelöschte Zielobjekte, wiederkehrende Probleme und unverändert fortbestehende Blocker. Vermerke sind HTML-sicher, zeitlich nachvollziehbar und verändern keine Maschinenzustände.

**Problemvertrag:** Die erste Version bindet ausschließlich belegbar identifizierbare VM-Fortschrittswarnungen und konkrete Job-/Create-Probleme ein. Fortschrittswarnungen nutzen `lib/vm_progress.php` mit ihrer bestehenden Beobachtungsuhr; Job-/Create-Probleme nutzen ihre jeweiligen Ergebnisowner und bei Bedarf Einheit/Versuch. Kein allgemeines Ticketsystem mit einer zweiten technischen Statusmaschine. Unlesbarer Befund bedeutet „Zustand unbekannt“, nicht behoben. Derselbe historische fehlgeschlagene Job bleibt fehlgeschlagen, auch wenn ein Retry gelingt; Verknüpfung und Bearbeitungsabschluss sind separat zu bezeichnen. Wo keine stabile Episodenidentität nachweisbar ist, muss sie vor Aktivierung der Zuweisung ergänzt werden; kein Hash aus übersetztem Text oder stetig wechselndem Alter.

**Rechte und Vermerke:** Selbstübernahme/Freigabe und Vermerk benötigen die jeweilige Objekt-Schreibberechtigung (`vms.write` beziehungsweise `deploy.run`). Fremdzuweisung erfordert zusätzlich `users.manage`; das Zielkonto muss aktiv und für das Objekt berechtigt sein. Ein eingeschränkter Picker liefert nur ID/Anzeigename zulässiger Konten, keine AD-/Login-/Kontodetails. Übernahme/Wechsel sind CAS-geschützt und schreiben den Audit in derselben Transaktion. Vermerke sind begrenzter Plaintext und zunächst append-only; Korrekturen erfolgen als Nachtrag. Ihr Inhalt wird nicht in den Audit-Kontext dupliziert. Deaktivierung behält die Zuordnung sichtbar mit Handlungsbedarf; Umbenennung lässt die stabile Benutzer-ID bestehen. Retention und Verhalten bei Objektlöschung werden für Referenz, Vermerke und Audit getrennt festgelegt. Übergang von nicht beobachtet zu erneut beobachtet muss eine neue Fortschrittsepisode ergeben; Anzeige der Chronik erzeugt keine Problemzeilen.

## Reihenfolge und Lieferumfang

| Etappe | Ergebnis | Fachliche Abhängigkeit |
| --- | --- | --- |
| E0 | Abgegrenzte Datenmodelle, Berechtigungen und Vorschausemantik | Ausgangsbasis |
| E1 | Kopierbuttons und Dashboard-Direktzugriff | E0 |
| E2 | Verwendungsnachweise | E0 |
| E3 | Gemeinsame Vorschau und Sammelbearbeitung | E0; nutzt E2 für Abhängigkeitslinks |
| E4 | Konfigurationsvergleich und Vorlagen-Assistent | E3 |
| E5 | VM-Chronik und Auftragszeitachse | E0; unabhängig von E4 umsetzbar |
| E6 | Beobachtete Aufträge und Problemzuständigkeit | E0; F07 nutzt die Chronik aus E5 |

E3 und E4 benötigen besonders sorgfältige Transaktions- und Konfliktprüfung. E5 benötigt eine saubere Zuordnung der vorhandenen Datenquellen und gegebenenfalls neue Jobzeitpunkte. E6 benötigt eigene additive Persistenz und einen Cleanup-Vertrag. Innerhalb E3 wird zuerst F10 geliefert, innerhalb E4 zuerst der lesende Vergleich. F06 kann vor F07 geliefert werden; die Auftragszeitachse braucht die Chronik nicht. Verlässliche Aufwandsschätzungen folgen nach E0; dieser Plan verspricht keine ungeprüften Umsetzungstermine.

## Review: festgestellte Planlücken und Konsequenzen

Die folgenden Befunde sind aus Code-/Dokumentlektüre bestätigt. Sie beschreiben Lücken oder unzutreffende Annahmen im ersten Plan; nur R10 benennt zusätzlich eine bestehende Codeabweichung. Die Planentscheidungen sind eingearbeitet, die Implementierungsnachweise stehen noch aus.

| Befund | Relevanz | Quellbeleg und korrigierte Entscheidung |
| --- | --- | --- |
| R01 | Hoch: falsche Aussage zur Namenswirkung | `lib/repo/vm_rollout.php`, `repo_vm_rollout_values_for_edit()`: ungebundene Namen folgen dem Sollwert; Reset ist nur für eingefrorene Namen nötig. F10 erklärt die Zustände getrennt. |
| R02 | Hoch: Vorschau ist ohne Bindung und atomaren Write nicht verbindlich | `lib/repo/vms_persistence.php`, `repo_save_vm()` und `repo_replace_disks()`: Zeitstempel allein erfassen nicht den vollständigen Bundlezustand, Disk-IDs wechseln beim Speichern. F10 bindet vollständige fachliche Entwürfe, F01 erhält unveränderte Kindtabellen. |
| R03 | Hoch: Assistent war mit dem vorhandenen Clone-Aufruf nicht umsetzbar | `lib/repo/missions.php`, `repo_clone_template_to_new_mission()`/`repo_clone_mission_vms()`: Namen werden unverändert kopiert; es gibt keine Zielabbildung. F09 erweitert den einen Owner vor Insert/Claims. |
| R04 | Hoch: konkurrierende ESXi-Namensvergabe nicht durch Hostnamenclaim abgedeckt | `lib/repo/vms_persistence.php`, `repo_vm_name_conflict_global()`: anwendungsseitiger Check; `lib/repo/vm_rollout.php` schützt separat den MECM-Hostnamen. F09 verlangt einen gemeinsamen konkurrierenden Namensnachweis statt Scheinsicherheit durch den Assistenten. |
| R05 | Hoch: Chronik könnte Audit-Rechte umgehen | `portal/logs.php` fordert `users.manage`, `portal/deploy_log.php` fordert `deploy.run`. F03 übernimmt diese Quellgrenzen vor Query/Pagination und gibt keine Roh-Audits über einen breiter zugänglichen Endpunkt aus. |
| R06 | Hoch: historische Zuordnung war zu weit versprochen | `lib/repo/client_events.php`, `lib/repo/status_events.php`, `lib/repo/deploy_job_queue.php`: fehlende Ereignisrevisionen sowie nicht durchgängig materialisierte historische Jobscopes. F03 stellt nur belegbare Beziehungen und vorhandene Zeitarten dar. |
| R07 | Hoch: allgemeine Joblaufzeiten fehlen | `lib/repo/deploy_job_worker.php`, `lib/repo/deploy_job_cancel.php`: Lockzeit wird geleert, Updatezeit ist veränderlich; Schema enthält außerdem schrittspezifische Zeiten. F11 unterscheidet diese und verlangt vor einer Laufzeitdarstellung geeignete Jobbelege beziehungsweise additive Zeitpunkte. |
| R08 | Mittel: Pagination und bestehende Sortierung passen noch nicht zusammen | `lib/portal_sort.php`, `portal_sort_apply()`: sortiert übergebene Arrays. Die neue Gesamtansicht braucht globale Ordnung vor LIMIT, siehe SSoT-Matrix; kein Sortieren nur der aktuellen Seite. |
| R09 | Mittel: Verwendungsnachweis darf kein einheitliches FK-Modell erfinden | `lib/repo/catalog.php`: OS nutzt Namen, Pakete IDs; Schema/`lib/repo/credentials.php`: historische Credential-FKs können NULL sein. F08 unterscheidet Beziehungstypen und unbekannte Herkunft. |
| R10 | Hoch vor abgeleiteter Änderungs-/Löschfreigabe: aktive Zugangsnutzung unvollständig | `lib/repo/credentials.php`, `repo_delete_credential()` zählt queued/running; der aktive Jobvertrag umfasst auch cancelling. F08 darf diese Prüfung nicht als vollständigen Scope übernehmen. Eng begrenzte Korrektur und Race-Test gehören zur betroffenen E2-Arbeit. |
| R11 | Mittel: Abschlussmeldung ohne Aufbewahrungsvertrag kann verloren gehen | `lib/repo/deploy_job_maintenance.php` purgt Systemjobs, Missionslöschung kaskadiert Jobs. F06 benennt die Grenze lesender Beobachtung und die Behandlung verschwundener Ziele. Retry ist eine neue Job-ID. |
| R12 | Hoch: Problemstatus und Zuständigkeit drohen auseinanderzulaufen | `lib/vm_progress.php` und bestehende Job-/Create-Owner liefern unterschiedliche Befunde. Es gibt keine gemeinsame Problemepisodenquelle im Plan. F07 begrenzt Typen, bindet ihre Identität und hält technische Behebung und Bearbeitung getrennt. |

## SSoT- und Drift-Matrix für die Umsetzung

Alle Pfade mit `lib/`, `portal/` oder `lang/` beziehen sich hier auf `Docker/WebAPI/`.

| Thema | Bestehender Owner | Erweiterung und Drift-Abnahme |
| --- | --- | --- |
| Felder, Defaults und Vorschau | `lib/repo/missions.php` (`REPO_MISSION_EDITABLE_COLUMNS`), `REPO_VM_COLUMNS` in `lib/repo/vms.php`, Vorgaben in `lib/defaults.php`, Validatoren in `lib/repo/vms_validation.php`, Kindwriter in `lib/repo/vms_persistence.php` | Gemeinsame beschreibende Klassifikation für Vorschau/Vergleich/Bulk; keine zweite Schreiballowlist. Jede relevante Owner-Spalte muss beschrieben oder explizit begründet ausgeschlossen sein. Nicht alle `REPO_VM_COLUMNS` sind benutzereditierbar. Transfer-Feldlisten bleiben der Exportvertrag und werden nicht zur generischen Schreibfreigabe. |
| Tatsächliche Wirkung | `lib/repo/vm_rollout.php`, `lib/mecm_plan.php`, `lib/repo/mecm_provenance.php`, `lib/repo/vm_location.php`, Modus-/Autostart-Owner | Darstellung aus deren Entscheidungen ableiten; Paketwahl ist keine zugesagte Deinstallation, Hardware-Sollwert kein Remote-Reconfigure. Grenzfälle Template/ungebunden/eingefroren sowie aktuelle/geplante Aufträge gemeinsam testen. |
| Netzwerk und Namen | `lib/repo/vm_network.php`, `lib/vm_network_contract.php`, `lib/esxi_object_names.php`, `lib/mecm_hostname.php` | Exakte ESXi-Werte, eigene MECM-Namensregeln und Mission-WDS erhalten; vorhandene Lockreihenfolge benutzen. Neuer Scope-Adapter darf keine zweite Validierung implementieren. |
| Globale Listen und Sortierung | `lib/portal_sort.php` | Validierte Sortschlüssel/-richtung und Header bleiben gemeinsam. Für DB-Pagination den Owner um eine Query-Sortbeschreibung erweitern, die vor LIMIT eine globale Ordnung mit eindeutigem ID-Tie-Breaker liefert; vorhandene Array-Aufrufer bleiben kompatibel. SQL-Collation und PHP-Anzeigesortierung nicht still als identisch behandeln. Gegenbeweis mit Treffern über mehrere Seiten. |
| Jobstatus, Zeiten und Meldungen | `lib/deploy_constants.php`, `lib/deploy_display.php`, `lib/deploy_terminal_presenter.php`, `lib/deploy_create_result.php`, Job-Writer | Ein gemeinsamer Abschlussbeleg statt neuer Statusliste im Browser. Additive Jobzeitpunkte müssen Claim, Erfolgs-/Fehlerabschluss, Abbruch vor Start, Reaper, Recovery und Supervisor abdecken; Backfill aus `updated_at` ist unzulässig. |
| Zeitdarstellung | `lib/portal_time.php`, `lib/layout.php`, `deploy_parse_schedule()` in `lib/repo/deploy_job_input.php` | Keine zweite Zeitzoneneinstellung, kein Parsing nackter DB-Zeitstrings als Browser-Lokalzeit; UTC-Grenzen und Formatierer mit DST-Fixtures prüfen. |
| Audit und Problemvermerke | `lib/audit_event_definitions.php`, `lib/audit_registry.php`, `lib/audit_presenter.php`, `lib/repo/log.php` | Neue Ereignisse mit typisiertem Kontext registrieren. IDs, Mengen und Operation erfassen; Notes nicht duplizieren. Gekürzte Audit-Snippets sind weder Vorschau noch vollständige Zuordnungsdatenbank. CAS-Write und verpflichtender Audit atomar. |
| Limits und Aufbewahrung | `lib/constants.php`, `lib/defaults.php`, jeweilige Fachkonstanten, bestehende Maintenance-Owner | Featuregrenzen fachlich definieren statt Deploy-Scope-Limit willkürlich für Vergleich/Clone zu übernehmen. Requestbytes, PHP-Eingabelimits, Kindzeilen, Entwürfe pro Nutzer, Pollbatch, Vermerkgröße und Retention berücksichtigen. Neue Konstanten in Bounds-Checks integrieren, keine zweite UTF-8-Kürzung. |
| Schema und Betriebsform | `Docker/mysql/mysql-init/struktur.sql`, `lib/migrations/`, `lib/migrate.php` | Frische Installation und Upgrade müssen konvergieren. IDs/FKs/Indizes, Unique-Beobachtungen, CAS-Versionen und Cleanup prüfen; keine vorab erfundene Migrationsnummer. Code-/Schema-Rollout und Restore für neue Persistenz dokumentieren. |
| Portalstruktur und Hilfe | `lib/layout.php`, `lib/layout_modals.php`, `lib/forms.php`, `lib/help_page.php`, `lib/deploy_urls.php`, `lib/vm_urls.php`, `lib/repo/log.php` | Asset-/Modulregistrierung, Confirm-Aktionskatalog, CSS-/Form-/Help-/Deep-Link-Verträge erweitern. Neuer zentraler URL-Helfer nur für tatsächlich neue Ziele; keine handgebauten bestehenden Links. |

E0 muss außerdem den vollständigen serverseitigen Entwurfsvertrag, die genaue Jobzeitquelle, zulässige Problemtypen samt Episodenidentität sowie Aufbewahrungs-/Löschentscheidungen schriftlich abschließen. Diese Punkte sind technische Voraussetzungen der gewählten Features, keine zusätzlichen Produktfeatures. Entwürfe und neue Tabellen sind als geplant zu kennzeichnen, bis sie umgesetzt sind.

## Doku- und Hilfematrix

Die aktive Hilfe beschreibt immer den ausgelieferten Stand. In dieser Planprüfung werden deshalb nur Plan und Änderungsbedarf festgehalten. Pro Feature werden Renderer, DE-/EN-Kataloge und fachlich betroffene Betriebsdokumente gemeinsam mit dem Code geändert. Fachliche Erklärungen erhalten einen Owner; andere Hilfebereiche verlinken darauf.

| Feature | Hilferenderer unter `Docker/WebAPI/lib/help/` | Kataloge unter `Docker/WebAPI/lang/de/` und spiegelgleich `lang/en/` | Inhalt und weitere Dokumentation |
| --- | --- | --- | --- |
| F01 Sammelbearbeitung | `missions.php` als fachlicher Owner; `packages.php` verweist auf ihn | `help_missions.php`, `help_packages.php`; UI: `vms.php`, `vm_edit.php`, `validate.php` | Bisherige Aussage „löschen oder MECM-ID zurücksetzen“ erweitern. Atomare Bearbeitung von bestehendem Bulk-Skip unterscheiden; unverändert/ersetzen/leeren, retired-Kataloge, Auswahlgrenzen und MECM-Folgeschritt erklären. |
| F02 Vergleich | `missions.php` | `help_missions.php`; UI im passenden Missionskatalog oder einem registrierten neuen Vergleichskatalog | Sollvergleich, manuelle Paarung, geerbte/effektive Werte, fehlende Daten und Bedeutung der Kategorien; kein ESXi-Istvergleich und keine automatische Übernahme. |
| F03 Chronik | `missions.php`; `system_status.php` verlinkt für Diagnose | `help_missions.php`, `help_system_status.php`; UI: `vm_edit.php` und vorhandene Auditdarstellung | Quellen, Rollen, Zeitbasis, Pagination, Retention und fehlende Rolloutzuordnung erklären. `docs/operations/troubleshooting.md` um Einstieg über die Chronik ergänzen. |
| F04 Kopieren | `overview.php` als einmalige allgemeine Erklärung | `help_overview.php`, `common.php`; zusätzliche labels in den jeweiligen Featurekatalogen | Kopierte Identität, konfigurierte IP, Erfolg/Fehler und HTTP-Manuell-Fallback. Ein Hinweis genügt; kein eigener Help-Tab. |
| F05 Dashboard | `overview.php` | `help_overview.php`, `dashboard.php` und Listenlabels | Zählumfang einschließlich Vorlagen, ausstehend versus überfällig, Filter-/Sortierverhalten und Aktualität. |
| F06 Abschlussmeldungen | `deploy.php`; `overview.php` verlinkt zur Erklärung | `help_deploy.php`, `help_overview.php`, `deploy.php`, gegebenenfalls gemeinsamer Meldungskatalog | Beobachten/gelesen/beenden, Einzeljob versus Retry/Gruppe, Offline-/Löschgrenzen und Portal-only-Zustellung. `docs/operations/deploy-chain.md` und `docs/operations/troubleshooting.md` anpassen. |
| F07 Zuständigkeit | `missions.php` als Verhaltensowner; `users.php` für Rechte und deaktivierte Konten; `deploy.php` verlinkt | `help_missions.php`, `help_users.php`, `help_deploy.php` sowie Vermerk-/Zuständigkeitslabels | Technischer Befund versus Bearbeitung, Übernahme/Zuweisung, Episoden, Korrekturvermerke und Retention. `docs/operations/vm-progress-observation.md`, `docs/operations/troubleshooting.md` und `docs/GLOSSARY.md` ergänzen. |
| F08 Verwendung | `packages.php` für Pakete/OS, `system_status.php` für VLAN-/Inventarbezug, `credentials.php` für Zugänge | `help_packages.php`, `help_system_status.php`, `help_credentials.php`; UI: `packages.php`, `os.php`, `vlans.php`, `credentials.php` | Namen versus IDs, direkte/mittelbare Nutzung, aktive/historische Jobreferenz und unbekannte Herkunft. `docs/operations/esxi-inventory.md` ergänzen; Kataloge bleiben extern gepflegt. |
| F09 Assistent | `missions.php` | `help_missions.php`, `missions.php`, `validate.php` und feldbezogene UI-Kataloge | Vorlagenabschnitt auf Assistent, getrennte Namensregeln, Nummerierung, Vererbung, statische IPs und atomare Anlage erweitern. Import/Export bleibt ein eigener Ablauf. |
| F10 Speichervorschau | `missions.php`; `packages.php` verweist auf dort erklärte Wirkung | `help_missions.php`, `help_packages.php`, `mission_details.php`, `vm_edit.php`, `validate.php` | Entwurf, Zurück/Bearbeiten, Ablauf, Konflikt, Notizen-Ausnahme und Wirkung je Feld. Bestehende Rollout-/Standorttexte gezielt ergänzen, nicht doppeln. `docs/operations/mecm-integration.md` auf passende Bedienhinweise prüfen und bei geänderten Schritten anpassen. |
| F11 Zeitachse | `deploy.php`; `settings.php` behält Zeitzoneneinstellung als Owner | `help_deploy.php`, bei neuem Verweis `help_settings.php`; UI: `deploy.php` | Frühester Start, Wartebereich, tatsächliche/fehlende Zeiten, UTC/Portalzone, Staffelung, Retry und Systemjobs. `docs/operations/deploy-chain.md` und Diagnoseabschnitt in `docs/operations/troubleshooting.md` anpassen. |

**Hilfenavigation:** Neue benötigte Abschnitts-IDs zuerst in `lib/help_page.php` unter `VIRTUSPHERE_HELP_SECTIONS` registrieren und exakt einmal im zugehörigen Partial rendern. `HelpAnchorContractTest` und berechtigungsabhängige Tab-/Linkfälle erweitern; Links aus Meldungen gehen über `help_url()` und zeigen nur auf für die jeweilige Rolle erreichbare Erklärungen. `VIRTUSPHERE_HELP_PANELS` bleibt unverändert, solange kein fachlich nötiger neuer Tab entsteht. Neue Katalogdateien nur über den vorhandenen Loader und dessen Paritätsvertrag einbinden.

### Übergreifende Projektdokumentation

| Dokument | Erforderliche Behandlung |
| --- | --- |
| `docs/QA.md` | Szenarien und Nachweisorte für Vorschaukonflikte, atomare Bulk-Writes, Clone-Races, Berechtigungen, Cursor, Zeitbasis und Beobachtungs-/CAS-Persistenz dokumentieren. Vorhandene UI-/Schema-/Concurrency-Abschnitte erweitern; keine festgeschriebenen Testanzahlen. |
| `docs/QUALITY-GATES.md` | Neue Seiten/Aktionen und die Zuordnung zu bestehenden Gates aktualisieren; Gates/Order bleiben ausschließlich in `scripts/check.ps1`. Neue Gateerklärung nur bei tatsächlich neuem Gate. |
| `docs/TESTPLAN.md` | Nur gezielte Verweise auf neue Test-/Nachweisowner, wenn nötig. Der Testplan ist ein Index, kein neuer Befund- oder Fortschrittsregister. |
| `docs/GLOSSARY.md` | Entwurf/Änderungsvorschau, Auftrag beobachten, gelesen, zuständig, Bearbeitungsvermerk und technischer Befund eindeutig abgrenzen. Bestehende Maschinenstatus nicht umbenennen. |
| `docs/CHANGELOG.md` | Pro ausgelieferter Etappe Verhalten und gegebenenfalls Upgradebedarf dokumentieren; Planung nicht als fertige Funktion eintragen. |
| `docs/operations/backup.md` | Datenabdeckung, Cleanup und Restore der neuen Entwurfs-/Beobachtungs-/Vermerkpersistenz prüfen und relevante neue Grenzen dokumentieren. Keine zusätzlichen Backup-Dateiformate ohne fachlichen Bedarf. |
| `docs/DEPLOYMENT.md`, `docs/INSTALLATION-ANLEITUNG.md`, `docs/operations/go-live.md` | Anpassen, soweit additive Schemaänderungen, neue Background-Cleanup-Pfade oder Rollout-Reihenfolge dies erfordern. Keine UI-Anleitung an mehreren Stellen pflegen. |
| `docs/adr/README.md` und neue ADRs | Vorschau-/Bulk-Transaktionsvertrag sowie Beobachtungs-/Problemepisodenvertrag vor Persistenz festhalten; Nummern erst aus dem dann aktuellen Index vergeben. Für neue Jobzeitpunkte den bestehenden Scheduling-/Terminalvertrag gezielt ergänzen. |
| Bestehende ADRs | ADR-0013/0014/0016 als UI-/Driftgrundlage; ADR-0020/0021/0023 für Katalog/Clone/Transfer; ADR-0022/0026/0033 für Zeiten/Retention/Abbruch; ADR-0034/0038/0041/0043 für Wirkung, Fortschritt, Create und Rollout referenzieren. Nur tatsächliche Vertragsänderungen als Amendment, keine historische Umschreibung für jede Komfortfunktion. |
| `AGENTS.md`, `GROK.md`, `README.md` | Nur bei neuen dauerhaften Ownern/Verträgen oder geändertem Architektur-Einstieg knapp aktualisieren; keine Featureliste oder Fortschrittshistorie einfügen und Budgets erhalten. |

Vor Abschluss jeder Etappe die bisherigen Aussagen über „nur zwei Bulk-Aktionen“, Rechte auf Auditdetails, Statusverlauf, Vorlagen-Klonen und Zeitanzeige in beiden Sprachen erneut suchen. Bereits passende Hilfetexte verlinken statt kopieren. Unbeteiligte Betriebs- und Sicherheitsdokumente werden nur bei tatsächlich geänderten Aussagen angepasst.

## Prüfung und Abschlusskriterien

Jede Etappe erhält passende fachliche und Browserprüfungen über den öffentlichen Runner `scripts/check.ps1`; Lane und Gates werden nach tatsächlichem Änderungsumfang gewählt. Schreibende Features prüfen insbesondere Vorschau-/Write-Gleichheit, CSRF/RBAC, Paralleländerungen, Sperrreihenfolge und Rollback. Lesende Features prüfen Datenzuordnung, Filter, Berechtigungen, Pagination und verständliche leere Zustände. DE/EN-Parität, Formularzugänglichkeit, CSS-/Modal-/Linkverträge und responsive Umbruchabstände gehören zur Portalabnahme.

**Gezielte zusätzliche Nachweise aus diesem Review:** Vorschaufingerprint bei zwei Änderungen in derselben Sekunde und bei isolierter Kindtabellen-/MECM-Sync-Änderung; Replay nach verloren gegangener Erfolgsantwort; paralleler Clone mit gleichem ESXi- und unterschiedlichem Windows-Namen; Paketoperationen mit retired/neuer Version während offener Vorschau; Sortierung über mehrere Seiten mit identischen Sortwerten; Audit-Filter vor Anzahl/Cursor; Chronik mit identischen Zeitstempeln, nachträglich eintreffenden und während Pagination gepurgten Daten; Abbruch vor Claim sowie fehlende historische Jobzeiten; Beobachtung mit Purge und wiederholtem Subscribe; Problemübernahme im Rennen mit Behebung/Neustart der Beobachtung. Fehlertexte müssen den nächsten möglichen Schritt nennen und Eingaben erhalten.

Vorhandene passende Vertragsprüfungen werden erweitert: `PortalConfirmContractTest`, `PortalAssetRegistryContractTest`, `VmEditModuleContractTest`, `VmRepoModuleContractTest`, `HelpAnchorContractTest`, `AuditProducerContractTest` und die einschlägigen Form-/CSS-/Deep-Link-Verträge. Feldabdeckung und globale Sortierung benötigen gezielte Drift-Gegenbeispiele; neue deklarative Listen erhalten keine ungetestete zweite Kopie. Schema-Konvergenz, Restore und Rollout-Kompatibilität sind Voraussetzung für die persistenten Features. Dieser Plan selbst wird auf vollständige F01-F11-Abdeckung, korrekte Dateiverweise und Widersprüche geprüft; die zwei allgemeinen Doku-Gates prüfen archivierte Auditpläne nicht vollständig semantisch.

Visuelle Abnahmen laufen ausschließlich im synthetischen `virtusphere-qa`-Stack über das vorhandene Visual-Projekt und dessen Metadatenvertrag. Baselineänderungen bleiben dem vorgeschriebenen, von einer Person ausgeführten Writer vorbehalten. Lange Prüfläufe erhalten einen live lesbaren Fortschrittslog mit den tatsächlichen `[n/total]`-Zeilen.

Eine Etappe ist erst abgeschlossen, wenn ihr Funktionsumfang und ihre Abnahmekriterien nachgewiesen sind, Hilfe und erforderliche Vertragsdokumentation aktualisiert wurden und keine relevanten bestehenden Maschinen- oder Portalverträge verletzt werden. Alle elf Features bleiben bis dahin als geplant geführt.
