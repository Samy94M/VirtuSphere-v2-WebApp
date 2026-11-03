# Glossar

Dieses Glossar verwendet die Begriffe so, wie Portal, Maschinen-API, Ansible und MECM sie verwenden. Anzeigen dürfen übersetzt werden; Wire-Felder und die fünf Legacy-Statusstrings bleiben unverändert.

## VM-Statusstufen

Der Stufenwert x/5 (`vm_status`) ist das Wire-Feld der MECM-Schnittstelle. Er hat zwei Quellen: die gespeicherte Spalte, die nur die unten genannten Schreiber setzen, und den Wert, den die Maschinen-API bei jeder Abfrage aus Lebenszyklus und MECM-Abgleich berechnet (`virtusphere_legacy_status_from_states()`). Die Übergänge des Workers (markieren, Vorzustand, Fehler) ändern nur die beiden Achsen. Das Portal zeigt den gespeicherten Wert als Tooltip der Phase und im Block „Technisch“ des VM-Editors; der Device-Sync liest den berechneten. Die Abläufe zeichnet [Lebenszyklus einer VM](operations/vm-lifecycle.md).

- **1/5 Initializing:** wird nie gespeichert. Die Maschinen-API liefert ihn, wenn keine spätere Stufe zutrifft (Lebenszyklus `initializing`, `deploying` oder `failed` ohne MECM `pending` oder `registered`); das Portal zeigt für dieselbe VM den gespeicherten Wert, etwa 2/5.
- **2/5 Registered:** gespeichert beim Anlegen, beim Import und beim Kopieren aus einer Vorlage. Der Portal-Datensatz existiert; auf ESXi muss noch keine VM vorhanden sein.
- **3/5 Deployed:** gespeichert, wenn der scoped MAC-Rückruf des Ansible-Hosts angenommen wurde oder „MECM-ID zurücksetzen“ die VM neu vorgemerkt hat. MECM hat noch keine ResourceID bestätigt.
- **4/5 OS Installing:** gespeichert, wenn der MECM Device-Sync ResourceID und Zuordnung zurückgemeldet hat. Die VM kann per PXE in die Task Sequence gehen.
- **5/5 OS Installed:** gespeichert, wenn `client_getinfo` seine Registry-Daten vollständig geschrieben und den Client-Ready-ACK bestätigt bekommen hat. Das ist kein Beweis, dass Hostname, statische IP und Datenträgerphasen bereits beendet sind; dafür gelten die Client-Phasen im VM-Editor.

### Phasen

Die Spalte „Phase“ in VM-Liste, VM-Übersicht und VM-Editor wird beim Lesen abgeleitet und nie gespeichert oder übertragen. Geprüft wird von oben nach unten; es gilt die erste Phase, deren Beleg vorliegt. Ein fehlgeschlagener Auftrag setzt die Phase nicht zurück, sondern erscheint als Befund.

| Phase | Gilt, wenn | Stufenwert auf der Leitung |
|---|---|---|
| Client fertig | Lebenszyklus `os_installed` und jede Clientphase hat zuletzt `finished` gemeldet | 5/5 |
| Windows meldet sich | Lebenszyklus `os_installed` | 5/5 |
| An MECM übergeben | Lebenszyklus `os_installing` oder MECM `registered` | 4/5 |
| MAC bekannt | Lebenszyklus `deployed` oder MECM `pending` | 3/5 |
| Auf ESXi | Instanz-UUID gebunden | 2/5, nach einem Fehler 1/5 |
| In Arbeit | Lebenszyklus `deploying` | 1/5 |
| Angelegt | alles andere | 2/5, nach einem Fehler 1/5 |
| Vorlage | VM einer Vorlagen-Mission | wird nicht übertragen |

## Auftragsstatus

- **queued:** gespeichert und bereit beziehungsweise für einen späteren Zeitpunkt geplant.
- **running:** von einem Worker mit Lock und Heartbeat beansprucht.
- **cancelling:** Abbruch angefordert; der Worker muss noch einen sicheren Schrittpunkt bestätigen. Der Auftrag gilt weiterhin als aktiv.
- **cancelled:** Abbruch abgeschlossen; spätere Rückrufe werden abgelehnt.
- **succeeded:** alle für den Modus erforderlichen Schritte sind erfolgreich abgeschlossen.
- **partial:** mindestens ein Ziel war erfolgreich und mindestens eines nicht; die Einzelergebnisse sind maßgeblich.
- **failed:** der Auftrag konnte seinen erforderlichen Umfang nicht erfolgreich abschließen.

## MECM und Provenienz

- **Collection:** MECM-Sammlung. VirtuSphere verwendet unter anderem Missions-, Betriebssystem- und Paket-Collections.
- **ResourceID:** MECM-interne numerische Gerätekennung. Sie wird nach dem Import an das Portal zurückgemeldet.
- **Rolloutname (Snapshot):** der Windows-Hostname, den VirtuSphere genau diesem Rollout an MECM und den PXE-Client liefert (`mecm_rollout_hostname`). Er friert mit der ersten Bindung ein und ist weder ein behaupteter Live-Name von Windows noch ein Rücklesewert aus MECM.
- **Rolloutrevision:** monotoner Zähler je VM, den jede mutierende Rückmeldung dieses Rollouts trägt. Eine veraltete, fehlende oder zukünftige Revision wird ohne Seiteneffekt mit 409 abgewiesen.
- **Tombstone:** die beim Reset aufbewahrte vorherige ResourceID (`mecm_previous_id`). Sie hält die nächste Übergabe fail-closed, bis der Administrator das alte Gerät in MECM gelöscht hat; VirtuSphere löscht dort nie.
- **Hostname-Claim:** Zeile in `deploy_vm_hostname_claims`, die einen normalisierten Rolloutnamen genau einer Nicht-Vorlagen-VM zuordnet. Eine VM darf ihren eingefrorenen und ihren neuen Namen gleichzeitig halten.
- **owned:** eine Regel oder Collection ist durch VirtuSphere erstellt oder ausdrücklich adoptiert und darf deshalb innerhalb eines bestätigten Reconciliation-Plans verändert werden.
- **manual:** außerhalb von VirtuSphere angelegt. Solche Regeln werden angezeigt beziehungsweise bewahrt, aber nie still übernommen oder entfernt.
- **Provenienz:** gespeicherter Nachweis aus Ziel, Typ, MECM-ID, Herkunft, Akteur und Zeitpunkt. Nur dieser Nachweis erlaubt eine automatische Entfernung eigener veralteter Regeln.
- **Reconciliation:** Vergleich von gewünschtem Zustand, eigener Provenienz und tatsächlich vorhandenem MECM-Zustand. Das Ergebnis ist ein Plan aus Hinzufügen, Entfernen, Bewahren und Konflikten.

## Ansible-Nachweise

- **Manueller Volltest:** auf Klick ausgeführter Gesamttest des gespeicherten Ansible-Zugangs: SSH, Toolchain, SFTP und – sofern konfiguriert – Portal-Rückweg samt IP-Allowlist. Er wird durch Missionsaufträge nicht erneuert.
- **Test veraltet:** der letzte bestandene oder eingeschränkt bestandene Volltest liegt außerhalb des Gültigkeitsfensters. Das meldet keinen Defekt; der aktuelle Gesamtzustand ist unbekannt. Ein bekannter Fehlschlag bleibt dagegen rot.
- **Letzter vom Worker bearbeiteter Missionsauftrag:** jüngster terminaler, einer Mission zugeordneter Job des Ansible-Zugangs, den ein Worker mindestens einmal übernommen hat (`attempts > 0`). Er belegt nur den ausgeführten Modus und verweist auf dessen Auftragsprotokoll; missionslose Inventarjobs, laufende Aufträge und ein vor dem Start aus der Warteschlange abgebrochener Auftrag zählen nicht. Genannt werden der ausgeführte Modus und der **aktuelle** Missionsname, kein Namensschnappschuss des Laufs: nach einer Umbenennung steht dort der neue Name.

## ESXi-Identität und Diagnose

- **MOID:** Managed Object ID; aktueller Griff eines vSphere-Inventarobjekts. Sie kann sich bei erneuter Registrierung ändern und ist allein kein dauerhafter Identitätsbeweis.
- **Instance-UUID:** dauerhafte VM-Identität, die vor jeder ESXi-Mutation zusammen mit dem Namen geprüft wird.
- **Adoption (VM):** frühere, ausdrücklich bestätigte Übernahme einer vorhandenen VM-Identität. Seit WM-E2a zurückgebaut; eine namensgleiche fremde VM blockiert, alte Auditzeilen bleiben lesbar.
- **Korrelations-ID:** durchgängige Kennung für eine Portal-Anfrage oder einen Auftrag. Sie verbindet Fehlermeldung, Auftragsprotokoll, Maschinenrückruf und Auditzeile.
- **Trust-Modus `strict`:** ESXi-Zertifikat wird gegen ein CA-Bundle oder ein hinterlegtes Serverzertifikat geprüft.
- **Trust-Modus `legacy_insecure`:** Zertifikatsprüfung ist ausdrücklich abgeschaltet. Der Modus ist für sichtbare Bestandsmigration gedacht, nicht als stiller Fallback.

Die Betriebswege stehen in [Go-live](operations/go-live.md), [Störungsdiagnose](operations/troubleshooting.md) und [Bereitstellungskette](operations/deploy-chain.md).
