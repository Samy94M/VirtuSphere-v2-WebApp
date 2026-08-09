# Glossar

Dieses Glossar verwendet die Begriffe so, wie Portal, Maschinen-API, Ansible und MECM sie verwenden. Anzeigen dürfen übersetzt werden; Wire-Felder und die fünf Legacy-Statusstrings bleiben unverändert.

## VM-Statusstufen

- **1/5 Initializing:** reservierte Nummerierung; kein aktiver Codepfad schreibt diese Stufe.
- **2/5 Registered:** Portal-Datensatz existiert. Auf ESXi muss noch keine VM vorhanden sein.
- **3/5 Deployed:** der scoped MAC-Rückruf des Ansible-Hosts wurde angenommen. MECM hat noch keine ResourceID bestätigt.
- **4/5 OS Installing:** MECM Device-Sync hat ResourceID und Zuordnung zurückgemeldet. Die VM kann per PXE in die Task Sequence gehen.
- **5/5 OS Installed:** die erste `getinfo`-Clientmeldung ist angekommen. Das ist kein Beweis, dass Hostname, statische IP und Datenträgerphasen bereits beendet sind; dafür gelten die Client-Phasen im VM-Editor.

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
- **owned:** eine Regel oder Collection ist durch VirtuSphere erstellt oder ausdrücklich adoptiert und darf deshalb innerhalb eines bestätigten Reconciliation-Plans verändert werden.
- **manual:** außerhalb von VirtuSphere angelegt. Solche Regeln werden angezeigt beziehungsweise bewahrt, aber nie still übernommen oder entfernt.
- **Provenienz:** gespeicherter Nachweis aus Ziel, Typ, MECM-ID, Herkunft, Akteur und Zeitpunkt. Nur dieser Nachweis erlaubt eine automatische Entfernung eigener veralteter Regeln.
- **Reconciliation:** Vergleich von gewünschtem Zustand, eigener Provenienz und tatsächlich vorhandenem MECM-Zustand. Das Ergebnis ist ein Plan aus Hinzufügen, Entfernen, Bewahren und Konflikten.

## ESXi-Identität und Diagnose

- **MOID:** Managed Object ID; aktueller Griff eines vSphere-Inventarobjekts. Sie kann sich bei erneuter Registrierung ändern und ist allein kein dauerhafter Identitätsbeweis.
- **Instance-UUID:** dauerhafte VM-Identität, die vor jeder ESXi-Mutation zusammen mit dem Namen geprüft wird.
- **Adoption:** ausdrücklich bestätigte Übernahme einer bereits vorhandenen, vorher unbekannten VM-Identität. Sie verändert die VM nicht.
- **Korrelations-ID:** durchgängige Kennung für eine Portal-Anfrage oder einen Auftrag. Sie verbindet Fehlermeldung, Auftragsprotokoll, Maschinenrückruf und Auditzeile.
- **Trust-Modus `strict`:** ESXi-Zertifikat wird gegen ein CA-Bundle oder ein hinterlegtes Serverzertifikat geprüft.
- **Trust-Modus `legacy_insecure`:** Zertifikatsprüfung ist ausdrücklich abgeschaltet. Der Modus ist für sichtbare Bestandsmigration gedacht, nicht als stiller Fallback.

Die Betriebswege stehen in [Go-live](operations/go-live.md), [Störungsdiagnose](operations/troubleshooting.md) und [Bereitstellungskette](operations/deploy-chain.md).
