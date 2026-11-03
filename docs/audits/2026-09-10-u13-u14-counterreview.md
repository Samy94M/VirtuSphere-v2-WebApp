# U13/U14: manuelle Gegenprüfung

Stand: 10.09.2026, HEAD `aa3daac` plus bestehende und neue Arbeitsbaumänderungen.
Keine Tests, Linter, Guards, Browser- oder Performancemessungen ausgeführt.
Dieser Bericht hält den Prüfumfang fest; Bearbeitungsstatus und spätere
Abnahmeergebnisse bleiben im [zentralen Register](2026-09-08-system-chain-audit-register.md).

## Root: ausgewählte unveränderte Schutzpfade

| Originalowner | Gelesener Schutz / Aussage | Grenze |
|---|---|---|
| `Docker/WebAPI/lib/bootstrap.php`, `lib/lang.php` | Sessionstart und Localeauswahl einschließlich Sessionpersistenz gehen der Liveblockerarbeit voraus. Übersetzungen lesen danach den geladenen Katalog. | Kein allgemeiner Auftrag, Sessions anderer Portalseiten früh zu schließen. |
| `Docker/WebAPI/lib/auth.php` | `current_user()` startet bei Bedarf die Session, initialisiert eine fehlende Ablaufzeit, prüft absoluten Ablauf, Aktivstatus und AD-Revalidierung samt Sessionwrites. `can()` ohne expliziten Benutzer würde `current_user()` erneut aufrufen. | Frühe Freigabe darf erst nach diesem Pfad liegen; Folgeaufrufe müssen den lokalen Benutzer verwenden. Reale AD-/Expiry-Konkurrenz bleibt Abnahme. |
| `Docker/WebAPI/lib/deploy_queue_blocker_view.php` | `deploy_blocker_json()` und der Actionfilter nutzen den übergebenen Benutzer. Zielrechte filtern die Aktion, die Erklärung bleibt bestehen. JSON nutzt keinen HTML-/CSRF-Formrenderer. | Vollständige Blockerentscheidung und ihre Prewrite-/Claim-Rechecks werden nicht durch einen vorherigen Livewert ersetzt. |
| `Docker/WebAPI/mecm_client_ack.php`, `lib/mecm_rollout_fence.php` | POST und bestehende Allowlist-/MAC-Grenze, Revision vor Deduplizierung unter Zeilensperre, Commit vor Antwort; technische 5/5-Zustände bleiben unverändert. | 5/5 belegt nicht, dass der Client die HTTP-Antwort empfangen oder spätere Phasen abgeschlossen hat. Kein neuer Wiretest. |
| `Powershell-MECM/clients/client_getinfo.ps1`, `VirtuSphere-Client-Common.ps1` | Publizierter Snapshot vor verbindlichem ACK, `SetupState=complete` erst nach positiver Antwort. Fehler entfernt den Marker. `Send-VsPhase` sendet einmal best effort, ohne Outbox. | Ein späterer MECM-Folgelauf hängt von Detection/Enforcement ab; kein garantiertes Nachliefern verlorener Events. |
| `Powershell-MECM/clients/client_hostname.ps1` | Domainmitglied wird übersprungen; bereits korrekter Name endet ohne Reboot. Nach tatsächlichem Rename wird `finished` vor dem versteckten Reboottimer versucht. | Ausbleibendes Event ist kein Beweis, ob Rename/Reboot oder Boot stattgefunden haben. |
| `Docker/WebAPI/lib/esxi_inventory_deviation_report.php` | Exakte Namen, pro Art qualifizierte Evidenz aller konfigurierten Quellen, fehlende Qualifikation sperrt Negativvergleich. Fehlgeschlagene Folgebeobachtung oder Alter macht vollständigen Nachweis historisch. | Die Darstellungsänderung darf gespeicherte Namen nicht mit frischer Negativ-/Freigabeevidenz gleichsetzen. |
| `Docker/WebAPI/lib/repo/vlan_reassign.php` | Exakte gespeicherte VLANbytes; vollständiger Scope und Fingerprint, aktive Jobs, Zielaktivität und vorgeschlagene Netzkonfiguration vor dem Write unter Transaktion erneut geprüft. | Ein Inventarhinweis darf diese Schutzpfade nicht freigeben oder ersetzen. |
| `Docker/WebAPI/lib/deploy_create_release.php`, `lib/repo/deploy_create_identity.php` | Terminaler Job, unresolved Unit, kein aktiver Missionsjob, geeigneter neuerer Inventarnachweis, weder Name noch gespeicherte UUID vorhanden; vollständiger Recheck unter Sperre und statusgebundener Write. Operatorbegründung separat gespeichert. | Die Freigabe selbst erstellt, löscht oder adoptiert keine VM; Recent-Tasks-Aussage ist kein automatisch erhobener Befund. |

`SessionHardeningContractTest` und `session-security.spec.js` wurden als
bestehende Regressionen gelesen. Das ist keine aktuelle Bestätigung von
Cookieflags, Sessionrotation, Ablauf, Logout oder browserseitigem Verhalten.
Der [QA-Plan](2026-09-10-u13-u14-qa-plan.md) ordnet die offenen Nachweise ein.

## Unabhängige Astra-Gegenprüfung

Die unabhängige Gegenprüfung ist im
[Astra-Bericht](2026-09-10-u13-u14-astra-review.md) mit ihrem genauen Umfang,
den gefundenen Korrekturen und Nachweisgrenzen dokumentiert. U13 wurde nach
Korrektur des DB-Lockhelfer-Lebenszyklus, des DE-zu-EN-Localegegenfalls und der
vollständigen SQL-Profilvalidierung erneut anhand des Codes gelesen. Der
Sessiontest erhält die ursprüngliche Requestpromise auch nach früh beobachteter
Rejection, sodass sein späteres Await den Fehler weiterhin meldet.

Der Pingnachweis ist auf erfolgreiche CSRF-geschützte Antwort während des
blockierten Live-Reads begrenzt; er vergleicht keinen gespeicherten Ablaufwert.
Der isolierte Performancevergleich verwendet denselben vollständigen
Arbeitsbaum und Harness in beiden Kopien und ersetzt nur zwei Produktdateien
durch die gesicherten Originale. Weder Root noch Astra haben Tests oder
Messungen ausgeführt. Der finale U14-Text-/Korpusbericht wurde ebenfalls
nachgelesen. Die letzten Korrekturen grenzen den möglichen Hostnamebericht,
die getrennte Backupretention, den Dump der Anwendungsdatenbank und die offenen
E1/E7/E9-Laufzeitnachweise korrekt ein. Astra meldet zum Abschluss keinen
offenen P1/P2 im geprüften Schlussstand; die dokumentierten Korpus- und
Laufzeitlücken bleiben bestehen.
