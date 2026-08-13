# LDAPS-/Active-Directory-Integrationsplan

Status: am 2026-08-13 gegen die nach Etappe 5 veränderte Codebasis erneut geprüft
und umgesetzt; die Software- und Dokumentationsprüfungen laufen. Die Freigabe
für Produktion bleibt unabhängig davon bis zum protokollierten Ziel-AD-,
Channel-Binding- und FPM-CA-Rotationsnachweis gesperrt.

Geltungsbereich: ein Active-Directory-Domain-Services-Verzeichnis, mehrere priorisierte Domänencontroller, ausschließlich explizit importierte Portalbenutzer

Entscheidungs-ADR bei Umsetzung: ADR-0039

## 0. Reaudit 2026-08-13 und verbindlicher Übergangsvertrag

Seit der Plan erstellt wurde, wurden die Deploy-/Betriebs-Masterplan-Etappen
1 bis 5 abgeschlossen. Gleichzeitig entstand uncommittiert ein AD-Vorlauf aus
Laufzeit-, Schema-, Auth-, Benutzer- und Logteilen. Dieser Vorlauf ist kein
erreichter Etappenstand: ADR-0039, Ziel-AD-/CA-Rotations-Spikes, die im Plan
genannten Modulgrenzen, AD-spezifische Tests, Systemstatus und die vollständige
Benutzerroute fehlen. Migration 0040 ist bewusst noch nicht angewandt; deshalb
dürfen die bereits verdrahteten Loginpfade nicht als funktionsfähig oder
releasefähig gelten.

Für die Weiterarbeit gilt:

- Migration `0040_active_directory_authentication` ist für diesen Strang
  reserviert. Ein paralleler Plan darf dieselbe Nummer nicht belegen. Vor jeder
  Änderung an `migrate.php` oder `struktur.sql` wird der aktuelle HEAD erneut
  gelesen und nur der eigene Hunk übernommen.
- Etappe 0 wird in 0A und 0B getrennt. 0A umfasst ADR, hermetische TLS-/FPM-
  Spikes und die reproduzierbare Ziel-AD-Prüfanweisung. Feature-deaktivierte
  Grundlagen dürfen danach entstehen. 0B ist der echte Nachweis gegen jeden
  Ziel-DC und bleibt eine harte Barriere vor Aktivierung, Pilotlogin, Merge
  eines standardmäßig sichtbaren AD-Loginpfads und Release. Diese Trennung
  schwächt keinen Sicherheitsnachweis; sie verhindert nur, dass fehlender
  Zugriff auf die spätere Zielumgebung sichere, deaktivierte Grundlagen
  blockiert.
- Bereits vorhandener Code wird etappenweise gegen die Exitkriterien
  übernommen, korrigiert oder verworfen. Seine bloße Existenz ersetzt keinen
  negativen Test und keinen Vorher-/Nachher-Nachweis.
- Der Masterplan arbeitet ab Etappe 6 unter anderem an SSH-/Ansible-
  Fehlerklassifikation und später an Systemstatus/Hilfe. Der AD-Strang besitzt
  seine Codes ausschließlich in `directory_constants.php` und führt
  gemischte Dateien über hunkgenaue Übergaben; `connection_errors.php` wird
  nicht zur zweiten AD-Code-SSoT.

Der Reaudit ergänzt folgende zuvor nicht ausreichend geschlossene Verträge:

| Lücke | Verbindliche Korrektur |
|---|---|
| Suchkonto- und Benutzer-Bind teilen LDAP-Code 49 | getrennte typisierte Ergebnisse; nur eine echte Benutzerablehnung zählt als Benutzerfehlversuch, eine Suchkontoablehnung öffnet den revisionsweiten Circuit Breaker |
| parallele Loginrequests können vor dem Zählen mehrere Binds starten | jeder Versuch reserviert vor LDAP atomar eine begrenzende Zeile; Erfolg/Infrastruktur geben sie frei, ein abgestürzter Pending-Versuch bleibt bis zur kurzen Retention konservativ begrenzend |
| letzter lokaler Admin und letzter Controller werden heute als Check-dann-Write behandelt | Locking Read, Invariantenprüfung und Änderung liegen jeweils in derselben `repo_transaction()`; Konkurrenztests beweisen, dass nie beide letzten Zeilen gleichzeitig verschwinden |
| Controllercooldown sortiert nur, begrenzt aber die Kandidatenmenge nicht | gesunde Kandidaten zuerst; aus ausschließlich abgekühlten Kandidaten wird genau einer gewählt; Deadline und verbleibendes Budget werden vor jeder blockierenden Operation neu berechnet |
| Controller wird bereits nach Suchkonto-Bind grün | Erfolg wird erst nach der für den Aufrufer maßgeblichen RootDSE-/Suchoperation persistiert; eine falsche Benutzeranmeldung färbt einen technisch gesunden Controller nicht rot |
| Login erzeugt nach LDAP-Erfolg ohne finalen DB-Snapshot die Session | Konfigurationsrevision/-aktivität, Controllerfreigabe, GUID, lokale Aktivität und AD-Eignung werden unmittelbar vor Sessionerzeugung gemeinsam erneut gelesen |
| Sessionausfall kann nach Ablauf des Recheckintervalls jeden Request erneut binden lassen | Session und Controllerzustand führen ein begrenztes Retry-at; `directory_verified_at` bleibt ausschließlich der letzte Erfolg und darf durch einen Fehler nicht verlängert werden |
| UPN ist bis 255 Zeichen, der Versuchszähler nur 191 | DB-Spalte, Validator, Indexbudget, Tests und Bounds-Guard werden auf denselben Wert gebracht; überlange Werte erreichen weder LDAP noch einen fehlschlagenden DB-Insert |
| Restore kann einen alten grünen Controllerzustand unverändert wiederbeleben | Restore-Poststep deaktiviert AD, erhöht die Konfigurationsrevision und lässt alle Controller sichtbar erneut testen; die abgeleitete CA-Datei ist nie Backupbestandteil |
| CA-Dateipfad akzeptiert vorhandene Dateien ohne Integritäts-/Symlinkprüfung und sammelt Hashdateien unbegrenzt | dedizierter nicht-weböffentlicher Laufzeitpfad, `lstat`/Regular-File-/Owner-/Mode-/Hashprüfung, atomarer Replace und begrenzte Bereinigung nicht mehr referenzierter Dateien |
| Zertifikatswiderruf war nicht als Supportgrenze entschieden | diese Ausbaustufe prüft Kette, FQDN, Zeit, Server-Auth-EKU und TLS-Minimum, führt aber keinen Online-OCSP-/CRL-Abruf aus; dies wird sichtbar dokumentiert und im Zielbetrieb mit Zertifikatslaufzeit/Rotation kompensiert; CRL-Dateisupport wäre eine eigene Erweiterung |
| Konfigurations- und Controllertests können konkurrierende Adminänderungen überschreiben | Formular und Test tragen die erwartete Revision; der abschließende Write ist CAS/locking und fordert bei Abweichung einen neuen Test statt einen fremden Stand zu überschreiben |
| Suchlimitüberschreitung wird als Mehrdeutigkeit missverstanden | Suchergebnis ist `{rows, truncated}`; Import bleibt einzelobjektgenau, die UI behauptet bei Kappung keine Vollständigkeit |
| vorhandene CA-/LDAP-Adapterdatei überschreitet die Modulgrenze | Identität, TLS-Material/Probe und nativer LDAP-Adapter werden wie in Abschnitt 8 getrennt und erhalten jeweils isolierte Tests |

## 1. Ziel und feste Produktentscheidung

VirtuSphere soll zusätzlich zu lokalen Konten Benutzer aus einem klassischen Active Directory Domain Services (AD DS) über streng geprüftes LDAPS authentifizieren. Die Autorisierung bleibt Eigentum von VirtuSphere: Ein Verzeichnisbenutzer erhält erst dann Portalzugriff, wenn ein Administrator ihn ausdrücklich importiert, aktiviert und einer lokalen Rolle zugeordnet hat.

Die folgenden Punkte sind keine späteren Optionen, sondern Teil des Sicherheitsvertrags:

- Ein AD-Passwort wird weder in der Datenbank noch in Session, Formular-Stash, Flash, Audit, PHP-Fehlerlog oder Containerlog gespeichert.
- AD-Konten besitzen kein lokales Ersatzpasswort. Ein Fehler im Verzeichnisweg darf nie auf `password_verify()` zurückfallen.
- Mindestens ein aktiver lokaler Administrator bleibt als Notfallzugang erhalten.
- Das Portal akzeptiert AD-Anmeldungen nur über HTTPS. Die Maschine-API und ihr bestehender HTTP-Vertrag bleiben davon unberührt.
- Der Verzeichnisweg verwendet ausschließlich `ldaps://` mit LDAPv3, TLS ab Version 1.2, FQDN- und Zertifikatskettenprüfung. Es gibt keinen Schalter für unverschlüsseltes LDAP, `TLS_REQCERT=never`, IP-Adressen als Controllerziel oder einen „Legacy insecure“-Modus.
- Mehrere Domänencontroller sind eine priorisierte Failoverliste innerhalb derselben AD-Domäne, keine Unterstützung mehrerer Domänen oder Forests.
- Die dauerhafte Identität ist die binäre `objectGUID`. UPN, Anzeigename, E-Mail, `sAMAccountName` und Distinguished Name sind veränderliche Attribute und niemals der Autorisierungsanker.
- Rollen werden lokal als `admin` oder `user` verwaltet. AD-Gruppen, Just-in-time-Provisionierung, automatische Domänenfreigabe und Gruppen-Rollen-Mapping sind nicht Teil dieser Ausbaustufe.
- Der vorhandene Login bleibt formularbasiert. Das ist weder Integrated Windows Authentication noch SSO und transportiert keine AD-MFA-/Conditional-Access-Entscheidung. Wenn das später gefordert wird, ist Kerberos oder OIDC/AD FS eine neue Architekturentscheidung.

## 2. Heutiger Stand und konkrete Lücken

Der Bestand ist für lokale Konten sicher aufgestellt, nimmt aber an mehreren Stellen an, dass jedes Portalmitglied ein lokales Passwort besitzt:

- `lib/auth.php::login()` sucht ausschließlich `deploy_users.name`, verifiziert immer einen lokalen Hash, setzt die lokale Kontosperre und erzeugt danach die Portal-Session.
- `portal/users.php` legt ausschließlich lokale Konten an und zeigt für jede Zeile Passwortreset, `must_change_password` und lokale Sperrentsperrung.
- `portal/account.php` zeigt für jedes Konto die lokale Passwortänderung.
- `deploy_users.password` ist im Frischschema `NOT NULL`; `auth_source` und eine externe Identität fehlen.
- `deploy_login_attempts` unterscheidet keine Anmeldequelle. Gleichnamige lokale und AD-Anmeldeversuche würden denselben Zähler verwenden.
- Das PHP-Image enthält noch kein `ext-ldap`.
- Es gibt keine SSoT für Controllerreihenfolge, Verzeichniszustände, Failoverursachen, AD-Kontozustand oder Verzeichnisblocker.
- Systemstatus und Hilfe kennen keinen Verzeichnisdienst.

Die Integration darf diese Annahmen nicht mit verstreuten `if ($ad)`-Zweigen flicken. Sie braucht eine eigene kleine Domäne mit einem Adapter für LDAP, einem Repository für Konfiguration/Beobachtungen und genau einem Auth-Dispatcher.

## 3. Ownership- und SSoT-Karte

| Frage | Einzige SSoT | Verbraucher |
|---|---|---|
| Unterstützte Anmeldequellen | `lib/directory_constants.php` (`local`, `active_directory`) | Schema, Auth, Benutzerseite, Session, Tests |
| AD-Konfiguration | Singleton `deploy_ad_config` über `lib/repo/directory.php` | Setup, Login, Sessionprüfung, Systemstatus |
| Controllerreihenfolge | `deploy_ad_controllers.priority`, eindeutig und lückenlos durch Repo-Operation | Login, Tests, Systemstatus |
| Verwendbare Controller | eine Prädikatsfunktion aus `enabled`, aktueller Konfigurationsrevision und Testzustand | Aktivierung, Login, Blocker, Anzeige |
| Dauerhafte AD-Benutzeridentität | `deploy_users.ad_object_guid` | Import, Login-Gate, Revalidierung |
| VirtuSphere-Freigabe | `deploy_users.is_active` | Login und jede Folgeseite |
| VirtuSphere-Rechte | bestehendes `deploy_users.role` plus `role_has_permission()` | unverändert alle Portalpfade |
| TLS-Vertrauen | CA-Bundle aus `deploy_ad_config`, validiert und nur als abgeleitete Datei materialisiert | TLS-Probe und native LDAP-Verbindung |
| LDAPS-Ergebniscodes | typisierte Codes in `lib/directory_constants.php` | Audit, UI, Status, Tests; nie roher LDAP-Diagnosetext |
| Controllerbeobachtung | `deploy_ad_controller_state` | Benutzerseite und Systemstatus |
| Aktivierungsblocker | `directory_activation_blockers()` | Hinweiskarten und derselbe serverseitige Aktivierungsgate |
| Statusampel | `directory_health_snapshot()` plus eine Rangfunktion | Übersichtskarte, Detailbereich, Hilfelegende |
| Bindursprung | getrennte Service-/Benutzer-Bind-Ergebnisse | Rate Limit, Circuit Breaker, Audit und Failover; LDAP-Code 49 allein entscheidet nie die Wirkung |
| Loginreservierung | eine vor LDAP persistierte Attempt-ID | gemeinsames IP-Budget und quellenspezifisches Benutzerbudget unter Parallelität |
| Zeilenübergreifende Schutzinvarianten | transaktionale Repositoryoperationen | letzter lokaler Admin, letzter verwendbarer Controller, Aktivierung und HTTPS-Abhängigkeit |
| Auditkategorien und Tabs | bestehendes `VIRTUSPHERE_LOG_CATEGORIES`/`VIRTUSPHERE_LOG_TABS` | `logs.php`, Retention, Deep Links |
| Grenzwerte | benannte Konstanten, sichtbare Zahlen in Hilfetexten nur per Platzhalter | LDAP-Adapter, UI, Hilfe, Tests |

`lib/directory_constants.php` wird ein fokussierter SSoT-Baustein und vom Enum-/Bounds-Guard erfasst. Die allgemeine Logtaxonomie bleibt in `lib/constants.php`; sie darf nicht in der AD-Domäne gespiegelt werden.

## 4. Bewusste Supportgrenzen

Unterstützt wird:

- eine AD-DS-Domäne;
- mehrere beschreibbare Domänencontroller derselben Domäne;
- LDAPS auf Port 636 oder einem ausdrücklich eingetragenen abweichenden Port;
- Benutzeranmeldung per vollständigem UPN;
- explizite Suche und Import einzelner Benutzer;
- lokales Rollen- und Aktivitätsmanagement;
- Umbenennen oder Verschieben eines importierten AD-Objekts, weil die GUID bestehen bleibt.

Nicht unterstützt wird in dieser Etappe:

- Microsoft Entra ID ohne AD DS;
- Global Catalog über 3269, mehrere Domänen, Trust-Auflösung oder Forest-weite Suche;
- Login per Anzeigename, E-Mailalias oder frei geratenem `DOMAIN\\name`;
- AD-Gruppen als Rollenquelle;
- AD-Passwortänderung/-reset im Portal;
- SSO, Kerberos, NTLM, MFA oder Conditional Access;
- Clientzertifikatsauthentifizierung am LDAP-Server;
- Read-Only Domain Controller (RODC) als freigegebener Authentisierungsendpunkt, solange dessen Password-Replication-Policy, Cache- und WAN-Fallbackverhalten nicht in einer eigenen Ausbaustufe getestet und sichtbar gemacht werden;
- ein unsicherer Zertifikats- oder Hostnamen-Bypass.

Ebenfalls nicht unterstützt ist ein Online-Abruf von CRLs oder OCSP-Antworten
durch VirtuSphere. Die Anwendung prüft Zertifikatskette, FQDN, Gültigkeitszeit,
Server-Authentication-EKU und TLS-Mindestversion strikt. Das Runbook benennt
die fehlende Online-Widerrufsprüfung und verlangt eine betriebliche
Zertifikatslaufzeit/-rotation, die zu diesem Risiko passt. Ein späteres
fail-closed CRL-Bundle braucht eigene Speicherung, Rotations- und
Ausfallsemantik und wird nicht still in das CA-Feld hineingedeutet.

Diese Grenzen stehen sowohl in der Portalhilfe als auch im Betriebsrunbook. Eine spätere Erweiterung auf mehrere Domänen braucht ein neues Datenmodell; ein zweites Base-DN darf nicht als Sonderfall in die erste Konfiguration geschoben werden.

## 5. Verpflichtende technische Vorstufe

Vor Schema- und UI-Arbeit werden zwei kleine, reproduzierbare Spikes abgeschlossen und protokolliert.

### 5.1 AD-Richtlinie und Channel Binding

VirtuSphere würde den Benutzer mit Simple Bind innerhalb einer streng validierten TLS-Verbindung prüfen. Für Windows Server 2022 ist der effektive Standard der DC-Richtlinie „LDAP server channel binding token requirements“ laut Microsoft `Never`; eine lokale oder domänenweite GPO kann ihn trotzdem auf `When Supported` oder `Always` setzen. Zwei aktuelle Microsoft-Seiten beschreiben den Randfall TLS + Simple Bind nicht widerspruchsfrei: Die Richtlinienseite sagt, `Always` weise LDAPS-Clients ohne CBT ab, während der am 12. Februar 2026 aktualisierte ADV190023-Artikel sagt, `LdapEnforceChannelBinding` habe auf TLS + Simple Bind keine Auswirkung. Beide bestätigen, dass Simple Bind selbst keine CBT-Information liefert. VirtuSphere leitet daraus keine vermeintliche Garantie ab. Die tatsächliche Kombination aus Patchstand, wirksamer GPO beziehungsweise `LdapEnforceChannelBinding` und dem mit PHP verlinkten OpenLDAP-Client wird gegen jeden Ziel-Domänencontroller getestet. Der Betrieb darf sich weder auf den Betriebssystemstandard noch auf eine einzelne der widersprüchlichen Beschreibungen verlassen.

Die separate LDAP-Signing-Richtlinie ist kein Grund, auf Port 389 auszuweichen: `Require signing` verwirft Simple Binds ohne SSL/TLS, während der geplante Bind ausschließlich im validierten TLS-Tunnel auf 636 erfolgt. Auf Windows Server 2022 muss die Richtlinie weiterhin explizit geprüft werden; die strengeren Standardwerte neuer Windows-Server-Versionen werden nicht vorweggenommen.

Abnahmeregel:

- Dienstkonto-Bind, RootDSE-Abfrage, Benutzersuche und Benutzer-Bind funktionieren mit den produktiven Signing-/Channel-Binding-Richtlinien.
- DC-Ereignisse für abgewiesene unsichere Bindings werden kontrolliert; VirtuSphere erzeugt keine Klartextbindung.
- Lehnt die Richtlinie den Client ab, wird weder die Richtlinie herabgesetzt noch die Zertifikatsprüfung gelockert. Dann stoppt diese Umsetzung vor Aktivierung und eine Kerberos-/OIDC-Entscheidung ersetzt den Simple-Bind-Entwurf.

### 5.2 CA-Wechsel im langlebigen PHP-FPM-Prozess

PHP verlangt, dass TLS-Optionen für eine `ldaps://`-Verbindung global vor `ldap_connect()` gesetzt werden. Der Spike beweist im echten PHP-8.4-FPM-/OpenLDAP-Build:

- App-spezifisches CA-Bundle statt globalem Abschalten der Prüfung;
- Hostname-Mismatch, unbekannte CA, abgelaufenes Zertifikat und fehlendes Server-Auth-EKU werden abgelehnt;
- ein atomar ersetztes CA-Bundle wird von neuen Verbindungen in bereits laufenden FPM-Workern sicher verwendet;
- parallele Requests können keine fremde oder halb geschriebene CA-Datei sehen;
- vorhandene Pfade, Symlinks, falsche Owner/Modi und Inhalt-Hash-Abweichungen
  werden abgelehnt; alte Hashdateien wachsen nicht unbegrenzt;
- der temporäre/abgeleitete CA-Pfad bleibt nach Restore reproduzierbar.

Falls OpenLDAP den TLS-Kontext im Prozess so cached, dass eine sichere Live-Rotation nicht beweisbar ist, muss vor der UI-Implementierung eine kontrollierte FPM-Neulade-Strategie analog zum HTTPS-Watcher entworfen werden. „Container irgendwann manuell neu starten“ ist kein akzeptierter, unsichtbarer Vertrag.

### 5.3 Verifizierte Windows-Server-2022-Rahmenbedingungen

Der Online-Faktencheck gegen Microsofts aktuelle Primärdokumentation ist Teil dieser Planung und wird beim Go-live nochmals gegen die dann installierten kumulativen Updates und die tatsächlich wirksamen GPOs wiederholt.

| Fakt für Windows Server 2022 | Konsequenz für VirtuSphere |
|---|---|
| AD DS nimmt LDAPS auf TCP 636 an; 3269 ist LDAPS für den Global Catalog. | VirtuSphere verwendet pro ausdrücklich eingetragenem DC dessen FQDN auf 636. Port 3269 und forestweite Suche bleiben außerhalb des Scopes. |
| Das DC-Zertifikat braucht Server-Authentication-EKU `1.3.6.1.5.5.7.3.1`, den DC-FQDN in CN oder DNS-SAN, den zugehörigen privaten Schlüssel und eine vom Client vertraute Kette. | Setup und Controller-Test prüfen Name, Kette, Gültigkeit und Verwendungszweck; eine IP als Ziel und jeder Trust-Bypass bleiben verboten. |
| Der NTDS-Zertifikatsspeicher wird gegenüber „Local Computer/Personal“ bevorzugt und kann neue Zertifikate ohne DC-Neustart übernehmen. | Das Runbook beschreibt beide Microsoft-Varianten und bevorzugt NTDS für kontrollierbare Auswahl/Rotation. VirtuSphere muss trotzdem sein CA-Bundle sicher neu laden. |
| TLS 1.3 ist in Windows Server 2022 auf Protokollebene standardmäßig aktiv, muss aber auch von Anwendung und Dienst unterstützt werden. | TLS 1.2 bleibt das sichere Mindestniveau; TLS 1.3 wird ausgehandelt, wenn Schannel, DC-Dienst und der PHP/OpenLDAP-Client es gemeinsam unterstützen. VirtuSphere erzwingt nicht unnötig ausschließlich TLS 1.3. |
| Channel Binding hat auf DCs mit Windows Server 2022 und älter den effektiven Standard `Never`. Microsofts Richtlinien- und ADV190023-Seiten widersprechen sich beim Einfluss von `Always` auf TLS + Simple Bind. | Der 2022-Standard macht den Entwurf voraussichtlich kompatibel, ist aber keine Freigabe. Der Aktivierungstest muss den produktiven Patch-/GPO-Stand beweisen; bei `Always` ist eine erfolgreiche echte Kompatibilitätsprüfung zwingend. |
| `Require signing` weist Simple Binds ohne SSL/TLS ab. Windows Server 2025 hat für neue AD-Deployments strengere Signing-Standards, Server 2022 nicht automatisch. | Es gibt keinen Port-389-Fallback. Das Runbook erfasst den tatsächlichen 2022-Richtlinienstand und vermeidet Annahmen aus einer 2025-Anleitung. |
| Passwortänderungen werden bevorzugt zum PDC-Emulator repliziert; bei einem lokal vermeintlich falschen Passwort fragt ein DC standardmäßig den PDC-Emulator. PDC-Erreichbarkeit und `AvoidPdcOnWan` können dieses Verhalten beeinflussen. | Ein frisches Passwort kann bei Replikations-/WAN-Problemen vorübergehend abgelehnt werden. VirtuSphere wiederholt einen autoritativ abgelehnten Benutzer-Bind niemals gegen weitere DCs, weil dies Fehlversuche und Sperrrisiko vervielfachen kann. |
| RODCs authentisieren abhängig vom lokalen Credential-Cache beziehungsweise leiten bei erreichbarem WAN an einen schreibbaren DC weiter; die Password-Replication-Policy kann Caching verbieten. | RODCs werden in dieser Ausbaustufe nicht als Controller akzeptiert. Eine spätere Freigabe braucht Erkennung, PRP-/WAN-Szenarien, eigene Statusaussagen und Tests. |

Der Unterschied von Windows Server 2022 ändert damit weder `objectGUID` als Identitätsanker noch den expliziten Import oder die lokale Rollenhoheit. Er konkretisiert vor allem die Aktivierungsvorprüfung, Zertifikatsdokumentation und die Regel „kein Failover nach einer autoritativen Passwortablehnung“.

## 6. Abhängigkeiten und Containervertrag

Das PHP-Image erhält `libldap2-dev` und das native `ext-ldap`; `composer.json` deklariert `ext-ldap` als Plattformanforderung. Eine zusätzliche Laufzeitbibliothek aus Composer ist nicht nötig.

Der Build und die Release-Prüfung beweisen:

- `extension_loaded('ldap')` im ausgelieferten PHP-Container;
- vollständige LDAP- und TLS-Konstanten für PHP 8.4, insbesondere LDAPv3, Netzwerk-/Suchtimeout, `LDAP_OPT_X_TLS_CACERTFILE`, `LDAP_OPT_X_TLS_REQUIRE_CERT` und TLS-1.2-Minimum;
- keine Runtime-Downloads, CDN- oder Cloudabhängigkeit;
- das CA-Material liegt außerhalb des Webroots; die Datenbank bleibt seine SSoT, die Datei ist nur atomar erzeugtes Laufzeitmaterial;
- Container bleibt read-only, nur der eng begrenzte vorhandene oder neue Volume-/tmpfs-Pfad ist schreibbar;
- Healthcheck und bestehende FPM-Bereitschaft bleiben erhalten.

Die LDAP-Erweiterung ist eine Produktvoraussetzung, kein optionales Feature, das erst beim ersten AD-Login mit einem 500 auffällt. Fehlt sie wider Erwarten, ist dies ein Aktivierungsblocker und ein roter Systemzustand; lokale Konten bleiben nutzbar.

## 7. Datenmodell und Migration

Die Umsetzung erhält eine neue additive Migration nach dem dann aktuellen Migrationsstand und aktualisiert gleichzeitig das Frischschema. Der genaue Migrationsname wird erst beim Implementieren gegen den aktuellen Kopf vergeben; weder Plan noch aktive Doku behaupten eine dauerhaft aktuelle Migrationsanzahl.

### 7.1 `deploy_users`

Neue Spalten:

- `auth_source ENUM('local','active_directory') NOT NULL DEFAULT 'local'`;
- `password VARCHAR(255) NULL`;
- `ad_object_guid BINARY(16) NULL`;
- `ad_upn VARCHAR(255) NULL`;
- `ad_sam_account_name VARCHAR(255) NULL`;
- `ad_display_name VARCHAR(255) NULL`;
- `ad_account_enabled TINYINT(1) NULL` als letzte beobachtete AD-Aussage, getrennt von `is_active`;
- `ad_last_checked_at TIMESTAMP NULL`.

Indizes/Constraints:

- eindeutiger Index auf `ad_object_guid` (mehrere `NULL` erlaubt);
- Index auf `auth_source, is_active`;
- Check-Constraint für die Form: lokales Konto hat Hash und keine AD-GUID; AD-Konto hat keinen Hash, eine GUID und einen UPN;
- AD-Konten haben zusätzlich `must_change_password=0` und `locked_until IS NULL`;
- bestehende Zeilen werden explizit als `local` behandelt, ihre Hashes bleiben bytegenau unverändert.

`name` bleibt der stabile Portal-/Auditbezeichner und wird beim Import zunächst mit dem UPN belegt. Die aktuelle globale Eindeutigkeit bleibt bestehen. Kollidiert der UPN mit einem bestehenden Portalnamen, wird der Import mit einer klaren Meldung abgelehnt; es wird nicht automatisch umbenannt. Für die Anmeldung eines AD-Kontos ist trotzdem die GUID der Gate-Schlüssel, nicht `name`.

Die Anmeldequelle eines bestehenden Datensatzes ist unveränderlich. Ein
Static-/Repositoryvertrag verbietet jedes generische Update dieses Felds. Ein
lokales Konto wird nicht „umgewandelt“; der Administrator legt/importiert eine
neue Identität und deaktiviert die alte bewusst. Dadurch werden Passwort-,
Lockout- und Auditgeschichte nicht rückwirkend umgedeutet.

### 7.2 `deploy_ad_config`

Singleton mit festem Primärschlüssel `1`:

- `enabled`;
- `revision` als monotone Konfigurationsrevision;
- vom ersten erfolgreichen Controller gelesener `default_naming_context`;
- optional engerer `user_search_base_dn`, der durch eine echte Suche validiert wird;
- `bind_upn` des technischen Lesekontos;
- `bind_secret_ciphertext`, verschlüsselt über den bestehenden `APP_KEY`-/libsodium-Weg;
- `ca_certificate_pem` als validiertes CA-Bundle ohne privaten Schlüssel;
- `automatic_bind_blocked_revision` und Zeitpunkt/typisierter Grund als
  revisionsweiter Circuit Breaker für ein abgelehntes Suchkonto;
- `created_by`, `updated_by`, `created_at`, `updated_at`.

Das Dienstkonto ist kein Eintrag in `deploy_users` und kein ESXi-/Ansible-Credential. Es erhält keine Portalrolle. Die Oberfläche nennt es konsequent „AD-Suchkonto“ und erklärt: lesen/suchen ja, Portalzugang nein.

### 7.3 `deploy_ad_controllers`

- `id`;
- `host` als ASCII-FQDN, niemals IP oder URI;
- `port`, Standard 636;
- `priority`, über das Repository lückenlos und eindeutig geordnet;
- `enabled`;
- `created_by`, `updated_by`, Zeitstempel;
- eindeutige Kombination `host, port`.

Ein Controller gehört implizit zur Singleton-Konfiguration. Beim Test muss sein RootDSE denselben `defaultNamingContext` liefern; ein Controller einer anderen Domäne wird nicht aktiviert.

### 7.4 `deploy_ad_controller_state`

Eine aktuelle Beobachtungszeile pro Controller, löschend an den Controller gekoppelt:

- `controller_id`;
- `config_revision`, gegen die getestet wurde;
- `last_attempt_at`, `last_success_at`;
- typisierter `last_outcome`;
- `consecutive_transport_failures` und `retry_after` für kurzen Failover-Cooldown;
- bei manuellem TLS-Test beobachtete Zertifikats-Serien-/SHA-256-Kennung und `certificate_not_after`;
- `updated_at`.

Es werden weder roher LDAP-Diagnosetext noch DN, Passwort oder Suchfilter in dieser Tabelle gespeichert. Eine alte erfolgreiche Beobachtung mit anderer Konfigurationsrevision ist sichtbar „erneut testen“, niemals grün.

### 7.5 `deploy_login_attempts`

Die Tabelle erhält `auth_source` mit Default `local`; `username` wird auf das
UPN-Maximum erweitert. Eine Attempt-ID wird vor jedem Passwortprüfpfad als
`pending` reserviert. Ein Abschluss klassifiziert sie als `success`,
`credential_failure` oder `infrastructure`; nur Pending und Credential-
Fehlschläge zählen. Benutzer- und IP-Zähler nehmen die Quelle als Teil ihres
Schlüssels, während das globale IP-Budget beide Quellen gemeinsam begrenzt.
Bestehende Zeilen werden als abgeschlossene lokale Erfolge beziehungsweise
Credential-Fehlschläge eingeordnet. Der bestehende kurze Retentionvertrag
bleibt SSoT; ein Prozessabbruch hinterlässt bewusst höchstens bis dahin eine
konservativ zählende Reservation.

AD-Fehler mit autoritativ abgelehnten Zugangsdaten zählen. Verzeichnis-/TLS-/Netzwerkfehler zählen nicht als falsches Passwort und dürfen weder einen Portalbenutzer noch das Dienstkonto durch lokale Wiederholungen sperren.

### 7.6 Migration, Backup und Restore

- Vor der Schemaänderung wird geprüft, dass bestehende Benutzer einen nichtleeren Hash besitzen; Abweichungen werden als Preflightbefund gemeldet statt still passend gemacht.
- Frischschema und Migration konvergieren über den vorhandenen Schema-Check.
- Datenbankbackup enthält Konfiguration, verschlüsseltes Suchkonto-Secret, CA-Bundle, Controller und Benutzerbindung. Die abgeleitete CA-Datei wird nicht separat gesichert.
- Restore braucht denselben `APP_KEY`; danach wird das CA-Laufzeitmaterial neu erzeugt und jeder Controller als „nach Restore erneut prüfen“ behandelt, bis ein Test oder eine echte Verzeichnisoperation ihn bestätigt.
- Der Restore-Poststep setzt `enabled=0`, erhöht die Revision und lässt die
  alten `validated_revision`-Werte unverändert als veraltete Evidenz stehen.
  Nur ein manueller Test kann sie wieder auf die neue Revision heben; eine
  echte Loginoperation darf ohne verwendbaren Controller nicht selbst den
  Restore-Gate umgehen.
- Ein falscher `APP_KEY` erzeugt keinen Fallback und keine rohe Crypto-Fehlermeldung im Portal.

## 8. Modulzuschnitt

Die heutige `auth.php` und `users.php` dürfen durch AD nicht zu Sammeldateien anwachsen. Vorgesehene Verantwortungen:

- `lib/directory_constants.php`: Quellen, Ergebnis-/Statuscodes, Grenzwerte;
- `lib/directory_identity.php`: GUID-Binärkonvertierung, UPN-Normalisierung, Account-Flags, LDAP-Filterbausteine;
- `lib/directory_tls.php`: CA-Validierung/-Materialisierung und manuelle TLS-Zertifikatsprobe;
- `lib/directory_client.php`: dünner Adapter um native PHP-LDAP-Aufrufe, typisierte Ergebnisse, kein DB-/HTML-Wissen;
- `lib/directory_auth.php`: importiertes-GUID-Gate, Controllerwahl, Failover, Session-Revalidierung;
- `lib/directory_status.php`: Controller- und Gesamtampel, Frische, Aktivierungsblocker;
- `lib/repo/directory.php`: alle Konfigurations-, Controller-, Zustands- und Importtransaktionen;
- `lib/user_admin.php` beziehungsweise fokussierte Handler-/Rendererdateien: Benutzeraktionen außerhalb der View;
- `auth.php`: gemeinsamer Rate-Limit-Prolog, expliziter Dispatcher, gemeinsamer Sessionabschluss; lokale Hashlogik bleibt ein eigener Pfad.

Jedes Modul bleibt unter der ADR-0006-Grenze oder begründet vor Überschreitung einen weiteren Split. Öffentliche Helper, Zustandscodes und Queryformen erhalten Unit-/Static-Verträge.

## 9. Einrichtungs- und Änderungslogik

### 9.1 Platzierung unter „Benutzer“

Die Hauptnavigation bleibt unverändert. Unter „Benutzer“ entstehen zwei serverseitig adressierbare Ansichten:

1. „Benutzerkonten“: lokale und importierte Konten, Rollen, Aktivität und Import;
2. „Active Directory“: Grundkonfiguration, Controllerliste, Tests und Aktivierung.

Ein Helper wie `users_url()` validiert Ansicht und Abschnitt; Form-Redirects und Hinweise bauen keine Fragmente von Hand. Die Ansichten werden in fokussierten Partial-/Handlerdateien gerendert, während `users.php` Route, Permission und Layout besitzt.

### 9.2 Ersteinrichtung als geführter Ablauf

Der erste AD-Bereich zeigt eine nummerierte Checkliste und genau den nächsten ausführbaren Schritt:

1. Portal-HTTPS inklusive HTTP-zu-HTTPS-Weiterleitung aktivieren.
2. Aktiven lokalen Notfalladministrator bestätigen.
3. CA-Bundle, AD-Suchkonto-UPN und dessen Passwort eingeben.
4. Ersten Controller-FQDN und Port eintragen.
5. „Testen und Grundkonfiguration anlegen“ ausführen.
6. Weitere Controller hinzufügen, einzeln testen und aktivieren.
7. AD-Anmeldung aktivieren.
8. Einen normalen AD-Pilotbenutzer suchen/importieren und anmelden.

Der erste erfolgreiche Test liest `defaultNamingContext` und `dsServiceName` aus RootDSE. Über das damit bezeichnete NTDS-Settings-Objekt wird `msDS-isRODC` gelesen; `TRUE` ergibt den typisierten, verständlichen Testausgang „RODC in dieser Version nicht unterstützt“. Wechselndes Adminpersonal muss weder einen Base-DN erraten noch aus einem späteren Authentisierungsfehler auf einen RODC schließen. Ein optionaler engerer Suchbereich ist ein Expertenfeld mit klarer Erklärung und echtem Suchtest.

Jeder Blocker kommt aus `directory_activation_blockers()` und enthält, abhängig von der Zielberechtigung, den Link zur Stelle, die ihn behebt. Derselbe Array entscheidet den Aktivierungsbutton. Ein deaktivierter Button ohne erklärende Karte ist verboten.

`users_url()` validiert nicht nur ein Fragmentmuster, sondern eine geschlossene
SSoT-Menge der tatsächlich gerenderten Ansichten und Abschnitte. Unbekannte
Anker werden abgelehnt, damit ein Link nicht formal gültig und praktisch leer
sein kann.

### 9.3 Konfigurationsänderung ohne Blindflug

Bei deaktiviertem AD darf eine unvollständige Konfiguration als Entwurf gespeichert werden. Bei aktivem AD gilt „testen, dann atomar übernehmen“:

- Leeres Passwortfeld bedeutet „bestehendes Secret behalten“, niemals „löschen“.
- Der Kandidat aus Base-DN/Suchbereich, Bind-UPN, altem oder neuem Secret und CA-Bundle wird gegen einen ausgewählten aktuell erreichbaren Controller getestet.
- Erst bei vollständig erfolgreichem TLS-, Bind-, RootDSE- und Suchtest wird die neue verschlüsselte Konfiguration in einer Transaktion gespeichert und `revision` erhöht.
- Der Kandidat enthält die beim Rendern gelesene erwartete Revision. Hat ein
  anderer Administrator während des Netzwerktests gespeichert, scheitert der
  CAS; der Kandidat wird nicht auf den neuen Stand rebasiert und muss erneut
  getestet werden.
- Der getestete Controller erhält den neuen Revisionsstand; alle anderen sind sichtbar erneut zu testen, aber nicht gelöscht.
- Schlägt der Kandidat fehl, bleibt die aktive Konfiguration unverändert und weiter nutzbar.

CA-Rotation wird mit überlappendem Bundle dokumentiert: zuerst alte und neue Kette gemeinsam vertrauen und testen, dann DC-Zertifikate drehen, zuletzt alte CA nach erneutem Test entfernen.

### 9.4 Controlleraktionen

- Neuer Controller startet deaktiviert und ungetestet.
- Aktivieren ist nur nach erfolgreichem Test gegen die aktuelle Revision möglich.
- Hoch-/Herunterstufen verwendet transaktionale Repositoryoperationen; keine frei editierbaren doppelten Prioritätszahlen und kein nur mit Maus bedienbares Drag-and-drop.
- Deaktivieren oder Löschen des letzten verwendbaren Controllers ist bei aktivem AD blockiert.
- Prüfung und Änderung des letzten Controllers laufen unter denselben Locks;
  zwei parallele Aktionen können nicht beide den jeweils anderen als Reserve
  sehen.
- „Verbindung testen“ ist eine sichere, CSRF-geschützte POST-Aktion; der Testzustand wird gespeichert und der Ausgang auditiert.
- Es gibt zunächst keinen automatischen periodischen Bind-Test und keinen „alle testen“-Batch. Das vermeidet wiederholte Fehlbinds und eine Sperre des Suchkontos. Status erneuert sich durch Einzeltest oder echte Verzeichnisoperation und sagt diese Kadenz ausdrücklich.

## 10. Benutzer suchen und importieren

Die Suche ist ein CSRF-geschützter POST, damit Namen/UPNs nicht in URL, Browserhistorie oder Proxy-Accesslog landen. Sie nimmt mindestens eine kurze, begrenzte Suchzeichenfolge an und sucht nur AD-Benutzerobjekte in der konfigurierten Search Base.

Sicherheitsregeln:

- Jeder Filterwert läuft durch `ldap_escape(..., LDAP_ESCAPE_FILTER)`.
- Filterstruktur und Attributliste sind Code-SSoT; Administratoren können keinen freien LDAP-Filter eingeben.
- Referrals sind aus. VirtuSphere verbindet ausschließlich konfigurierte Controller.
- Ergebnis- und Zeitlimit sind benannte Konstanten; der Adapter liefert
  Zeilen und `truncated` getrennt, und die UI meldet abgeschnittene Ergebnisse
  statt Vollständigkeit oder Mehrdeutigkeit zu behaupten.
- Angezeigt werden nur benötigte Attribute: Anzeigename, UPN, `sAMAccountName`, E-Mail und beobachteter Aktivstatus.
- Computer, Kontakte, Gruppen und deaktivierte Nicht-Normalbenutzer werden ausgeschlossen oder klar als nicht importierbar angezeigt.

Der Import vertraut keinem versteckten DN/UPN aus dem Browser. Der POST trägt nur eine kurzlebige serverseitig validierbare Referenz; der Handler fragt das Objekt erneut ab, liest GUID/UPN selbst und schreibt in einer Transaktion. Damit kann ein manipuliertes Formular weder eine fremde GUID noch eine höhere Rolle einschleusen.

Importlogik:

1. Objekt erneut exakt lesen.
2. Eindeutige 16-Byte-GUID verlangen.
3. Doppelte GUID ablehnen und zum vorhandenen Portalbenutzer verlinken.
4. Namenskollision klar melden.
5. AD-Konto mit `password=NULL`, `must_change_password=0`, lokal gewählter Rolle und eigenständigem `is_active` anlegen.
6. Aktion unter `users` mit Akteur und neuer Portal-Benutzer-ID auditieren.

Eine spätere Objektlöschung und Neuanlage mit gleichem UPN erzeugt eine neue GUID und erhält daher keinen Zugriff. Das alte Portalobjekt bleibt nachvollziehbar, wird als „im AD nicht gefunden“ angezeigt und kann lokal deaktiviert werden.

## 11. Loginlogik

### 11.1 Explizite Quelle

Ist AD aktiv und der Request sicher, zeigt `portal/login.php` eine zugängliche Auswahl „Active Directory“ beziehungsweise „Lokales Konto“. AD ist der normale sichtbare Weg; der lokale Notfallzugang bleibt klar auffindbar. Die Quelle ist ein explizites POST-Feld und wird weder aus `@`, Namenskollision noch einem Fehlschlag erraten.

Ist das Portal per HTTP aufgerufen, bleibt nur lokaler Login möglich und ein klarer Hinweis erklärt, dass AD-Anmeldung HTTPS benötigt. Der Server lehnt einen manipulierten AD-POST über HTTP ebenfalls ab.

Benutzername wird getrimmt und längenbegrenzt, das Passwort nie getrimmt oder normalisiert. Ein leeres AD-Passwort wird vor `ldap_bind()` abgelehnt, weil PHP sonst einen anonymen Bind versuchen kann.

### 11.2 AD-Ablauf

1. Gemeinsames globales IP-Limit prüfen.
2. AD-spezifisches Benutzer/IP-Throttle prüfen; es ist eine Anwendungsdrossel, keine lokale Kontosperre.
3. Aktive Konfiguration und priorisierte verwendbare Controller als unveränderlichen Request-Snapshot laden.
4. Strenge TLS-Optionen setzen, per vollständiger `ldaps://fqdn:port`-URI verbinden, LDAPv3 wählen, Referrals abschalten und Timeouts setzen.
5. AD-Suchkonto binden.
6. Den eingegebenen vollständigen UPN exakt suchen; null oder mehrere Treffer sind Ablehnung.
7. GUID und AD-Kontozustand lesen.
8. Lokalen `deploy_users`-Datensatz anhand der GUID laden. Nicht importiert, lokal deaktiviert, falsche Quelle oder AD-deaktiviert bedeutet generische Ablehnung ohne Benutzer-Bind.
9. Auf demselben Controller einen getrennten Benutzer-Bind mit dem servergelieferten DN und dem eingegebenen Passwort durchführen.
10. Vor Sessionerzeugung Konfigurationsrevision, AD-Aktivierung, Controllerfreigabe und Benutzeraktivität erneut aus der DB prüfen; ein paralleles Abschalten darf nicht durch den alten Snapshot rutschen.
    Der finale Snapshot umfasst außerdem unveränderte GUID, AD-Eignung und den
    noch nicht gesetzten revisionsweiten Suchkonto-Circuit-Breaker.
11. Den vorhandenen gemeinsamen Sessionabschluss verwenden: Session-ID und CSRF rotieren, Rolle/Benutzer-ID setzen, Ablaufzeit starten, `last_seen_at` schreiben.
12. AD-Cacheattribute und Controllerbeobachtung nur bei Änderung beziehungsweise gedrosselt aktualisieren.

UPN und DN kommen für den Bind aus dem Verzeichnisergebnis; aus Benutzereingabe wird nie ein DN zusammengesetzt.

### 11.3 Failoververtrag

Controller werden einzeln und sichtbar in Prioritätsreihenfolge versucht. Die Mehrfach-URI-Funktion von `ldap_connect()` wird nicht verwendet, weil VirtuSphere dann nicht sicher unterscheiden könnte, welcher Controller welchen Bind abgelehnt hat.

Failover ist erlaubt bei:

- DNS-/TCP-/TLS-Verbindungsfehler;
- Timeout oder eindeutigem „Server nicht verfügbar“;
- Suchfehler ohne autoritative Zugangsdatenentscheidung;
- exaktem „Benutzer nicht gefunden“ vor einem Passwort-Bind, um Replikationsverzögerungen bei UPN-Änderungen abzufangen.

Failover stoppt sofort bei:

- autoritativ abgelehntem Suchkonto-Bind; dieser Zustand pausiert weitere automatische Versuche bis zur Konfigurationsänderung oder einem manuellen Test, damit das Dienstkonto nicht gesperrt wird;
- deaktiviertem/nicht importiertem Portalbenutzer;
- autoritativ abgelehntem Benutzer-Bind, unabhängig davon, ob Passwort, Sperre oder Ablauf die AD-Ursache ist;
- mehrdeutigem Suchergebnis oder GUID-Konflikt.

Ein Transportabbruch während eines Benutzer-Binds darf den nächsten Controller nur innerhalb eines festen Gesamtbudgets versuchen. Jeder Controller wird pro Login höchstens einmal angesprochen. Falsche Zugangsdaten werden nie „zur Sicherheit“ an alle Controller gesendet.

Ein kurzer persistierter Cooldown verhindert, dass jeder Login erst auf einen bekannten ausgefallenen Primärcontroller wartet. Existiert kein anderer Controller, wird trotz Cooldown genau der am ehesten fällige Kandidat einmal versucht, statt einen Zustand ohne Messung fortzuschreiben. Die Anwendung deaktiviert Controller nie automatisch.

Das Gesamtbudget ist eine echte monotone Deadline, kein Check nur zwischen
zwei Aufrufen. Netzwerk-, LDAP-Operations- und verbleibendes Gesamtbudget
werden vor jeder blockierenden Operation auf das Minimum geklemmt. Der
Deadlinehelper besitzt eine injizierbare Uhr; Tests warten keine realen
Timeouts. DNS-/Resolververhalten des ausgelieferten Images gehört zum
hermetischen und zum Ziel-DC-Test.

### 11.4 Ergebnis und Enumeration

Benutzersichtbare Antworten unterscheiden nur:

- ungültige oder nicht freigegebene Anmeldung;
- temporär nicht verfügbarer Verzeichnisdienst;
- IP-/Anmeldedrossel.

Sie verraten nicht, ob UPN, Import, lokaler Aktivschalter, AD-Status oder Passwort der Grund war. Technische Ursachen sind typisiert in Audit und Systemstatus sichtbar. Antwortzeiten für importiert/nicht importiert werden im Sicherheitstest gemessen; es wird kein langer `sleep()` eingebaut, der selbst zum DoS-Werkzeug wird.

### 11.5 Lockout

- Das bestehende `locked_until` gilt nur für lokale Konten.
- AD-Konten werden dort nie gesperrt und zeigen keine lokale Entsperraktion.
- Das Portal drosselt wiederholte AD-Versuche pro UPN/IP, bevor weitere Binds entstehen; AD bleibt Autorität für domänenweite Sperren.
- Ein Verzeichnisfehler erhöht keinen Passwortfehlerzähler.
- Nach einer AD-Passwortänderung kann Replikationsverzug vorübergehend zu einer Ablehnung am ersten erreichbaren DC führen. Wegen Lockout-Schutz wird bei „invalid credentials“ nicht weiterprobiert; Hilfe und Troubleshooting erklären diesen bewussten Trade-off.

## 12. Laufende Session und AD-Deaktivierung

Nur die erfolgreiche Anmeldung zu prüfen wäre eine Lücke: Ein im AD deaktivierter Benutzer könnte seine laufende Portal-Session bis zu jeder manuellen Verlängerung behalten.

Darum speichert die Session für AD-Anmeldungen `directory_verified_at`. `current_user()` revalidiert nach einem benannten Intervall über Suchkonto und GUID:

- Objekt vorhanden, richtiger Benutzertyp und aktiv: Session bleibt, Cacheattribute dürfen aktualisiert werden.
- Objekt gelöscht, GUID nicht mehr vorhanden, Konto abgelaufen oder deaktiviert: Session wird beendet und einmal unter `auth` auditiert.
- AD-Integration lokal deaktiviert oder Benutzer lokal deaktiviert: sofort beenden.
- alle Controller technisch nicht erreichbar: kurze, benannte Grace-Zeit seit der letzten erfolgreichen Prüfung; danach AD-Session beenden. Lokaler Notfalladmin bleibt erreichbar.

Zusätzlich speichert die Session die verifizierte Konfigurationsrevision und
ein begrenztes `directory_retry_after`. Ein temporärer Fehler verändert
`directory_verified_at` nie und erzeugt innerhalb des Retryfensters keinen
Bind pro Seitenrequest. Lokale Deaktivierung und globales AD-Abschalten werden
trotz Retryfenster auf jeder Anfrage aus der DB wirksam.

`session_ping.php` läuft durch denselben Guard. Eine Verlängerung darf die AD-Revalidierung nicht umgehen. Rollen und `is_active` werden weiterhin auf jeder Anfrage aus der lokalen DB gelesen; AD-Gruppen ändern keine Rechte.

Die konkreten Defaultwerte für Recheck, Grace, LDAP-Netzwerk-/Suchtimeout, Gesamtbudget, Suchlimit, Controllercooldown und Statusfrische werden als Konstanten festgelegt, durch Last-/Ausfalltests bestätigt und in sichtbaren Texten nur per Platzhalter ausgegeben.

## 13. Benutzeroberfläche und QoL

### 13.1 Kontenliste

Jede Zeile zeigt eindeutig:

- Quelle als Badge „Lokal“ oder „Active Directory“;
- Portalname, bei AD zusätzlich UPN und Anzeigename;
- lokale Rolle und lokalen Aktivstatus;
- für AD den zuletzt beobachteten Verzeichnisstatus samt formatiertem Zeitpunkt;
- letzten Portalzugriff.

Quellenspezifische Aktionen:

- lokal: Passwortreset, `must_change_password`, lokale Sperre lösen;
- AD: „Im AD prüfen/synchronisieren“, kein Passwortfeld, keine lokale Entsperrung;
- beide: Rolle ändern und lokal aktivieren/deaktivieren.

Filter „Alle/Lokal/Active Directory“ und Suche nach Portalname/UPN halten die Liste nutzbar. Sortierung verwendet die vorhandenen Portal-Sort-Helper; neue CSV-Links übernehmen aktive Sortierung/Filter.

### 13.2 Kontoansicht

`account.php` zeigt AD-Benutzern Quelle und UPN sowie den Satz, dass Passwortänderungen in Windows/AD erfolgen. Das lokale Passwortformular wird nicht gerendert und ein manipulierter POST serverseitig abgelehnt. `must_change_password` ist für AD immer false.

### 13.3 Schutzgeländer

- Der letzte aktive lokale Administrator kann weder deaktiviert noch demotiert werden. Der bestehende „letzter Admin“-Guard wird dafür quellensensitiv verschärft.
- Diese Invariante ist transaktional; paralleles Demotieren/Deaktivieren zweier
  lokaler Administratoren kann nicht auf null erfolgreiche Notfallkonten
  konvergieren.
- AD-Aktivierung wird blockiert, solange HTTPS/Redirect, LDAP-Extension, vollständige Konfiguration, aktueller Controllertest oder lokaler Notfalladmin fehlen.
- HTTPS oder Redirect können nicht deaktiviert werden, solange AD aktiv ist; der Settings-POST verwendet denselben Prädikatshelper.
- Aktivierung und HTTPS-/Redirectänderung serialisieren auf derselben
  Konfigurationszeile beziehungsweise einem gemeinsamen Lock, damit zwei
  parallele POSTs keinen verbotenen Endzustand erzeugen.
- Entfernen/Deaktivieren des letzten verwendbaren Controllers und Löschen der AD-Konfiguration sind bestätigte destruktive Aktionen.
- Suche, Test und Import werden in den Confirm-Contract als begründete sichere Aktionen aufgenommen; Rollen-/Deaktivierungsbestätigungen bleiben erhalten.
- Kein Secret wird nach ValidationException über `form_remember()` wieder in die Session geschrieben.

### 13.4 Responsivität und Zugänglichkeit

Die neue Tab-/Heading-/Action-Geometrie wird bei einem Viewport getestet, der Controlleraktionen und Kontenzeilen tatsächlich umbrechen lässt. Alle sichtbaren Klassen erhalten CSS-Regeln, Tabellen bleiben in `table-wrap`, Zeilenaktionen besitzen eindeutige Labels mit Benutzer-/Controllername, Status ist nie nur Farbe, und die Einrichtung funktioniert ohne JavaScript.

## 14. Logs und Protokolle

### 14.1 Kein neuer Reiter

Ein eigener AD-Reiter unter `logs.php` wird bewusst nicht angelegt. Er würde eine einzige Untersuchung aufteilen:

- „Wer hat sich angemeldet oder wurde abgewiesen?“ gehört weiterhin in `auth`.
- „Wer hat ein Konto importiert, deaktiviert oder seine Rolle geändert?“ gehört weiterhin in `users`.
- „Wie wurde das Verzeichnis konfiguriert und welcher Controllerzustand wurde beobachtet?“ erhält die neue Kategorie `directory`.

`directory` liegt im bestehenden Reiter „Sicherheit“ und erbt dessen lange Aufbewahrung. ADR-0026, Kategorie-/Tab-Abdeckung, Retention, Labels, Badge und Deep-Link-Test werden erweitert. Systemstatus verlinkt über `log_category_url(VIRTUSPHERE_LOG_CATEGORY_DIRECTORY)`, nie mit handgeschriebener Query.

### 14.2 Ereignismatrix

| Ereignis | Kategorie | Inhalt |
|---|---|---|
| lokaler/AD-Login erfolgreich | `auth` | Quelle, Portal-Benutzer-ID; bei AD Controller-ID |
| falscher/nicht freigegebener AD-Login | `auth` | eingegebener gekürzter UPN wie im bestehenden Anti-Enumeration-Vertrag, keine Einzelursache |
| AD wegen Infrastruktur nicht möglich | `auth` nur als Benutzerereignis, `directory` nur bei Zustandsübergang | keine Doppelzeile pro Request |
| AD-Session wegen deaktiviertem/gelöschtem Objekt beendet | `auth` | Portal-Benutzer-ID und typisierter Grund |
| Konfiguration angelegt/geändert, Secret/CA rotiert, AD aktiviert/deaktiviert | `directory` | Akteur, Revision und Art der Änderung, nie Secret/DN/PEM |
| Controller hinzugefügt, priorisiert, aktiviert, deaktiviert, entfernt | `directory` | Controller-ID und sichere Host-/Portangabe |
| manueller Controller-Test | `directory` | Erfolg oder typisierter Fehlercode, Zertifikatsmetadaten ohne PEM |
| automatischer Controllerfehler/Recovery bei Login oder Revalidierung | `directory` | nur Zustandsübergang beziehungsweise gedrosseltes Fortbestehen |
| AD-Benutzer importiert/synchronisiert | `users` | Ziel-Benutzer-ID, Quelle und Akteur |
| Rolle/lokaler Aktivstatus geändert | `users` | bestehender Vertrag, unabhängig von Quelle |

Alle Texte folgen stabilen Satzmustern. Rohe `ldap_error()`, Diagnostic Message, DN, Filter, Passwort, Ciphertext, CA-PEM oder vom Server gelieferte freie Texte sind in Audit und UI verboten. Fehlercodes werden zentral auf sichere Operatorhinweise gemappt.

### 14.3 Flood- und Datenschutzregeln

- Erfolgreiche Controllerbeobachtungen aktualisieren die Zustandszeile höchstens gedrosselt und erzeugen keine Auditzeile pro Login.
- Ein Ausfall erzeugt eine Zeile beim Übergang `ok -> failure`, eine Recovery-Zeile und danach nur gedrosselte Wiederholung, falls betrieblich nötig.
- Fehlanmeldungen bleiben unter dem bestehenden IP-Auditdeckel.
- Suchbegriffe und Suchergebnisse werden nicht auditiert; nur ein tatsächlich importierter Benutzer ist ein dauerhaftes Ereignis.
- CSV-Export und Suche des vorhandenen Logportals funktionieren ohne AD-Sonderpfad.

### 14.4 Abgrenzung zu Windows-Ereignisprotokollen

VirtuSphere liest oder kopiert keine Ereignisprotokolle der Domänencontroller. Im Einrichtungs-/Troubleshooting-Runbook steht als getrennte Betreiberprüfung, dass Windows-DCs Event 2887 als tägliche Zusammenfassung unsicherer LDAP-Binds protokollieren können und Event 2889 nach bewusst aktivierter Diagnose „16 LDAP Interface Events = 2“ einzelne unsichere Clients benennt. Diese Diagnose wird nur zeitlich begrenzt nach Microsoft-Anleitung aktiviert. Ein korrektes VirtuSphere-LDAPS-Setup darf nicht als Klartext-/unsignierter Port-389-Client auftauchen.

Damit bleiben die Zuständigkeiten eindeutig: `logs.php` beantwortet, was VirtuSphere getan und beobachtet hat; das Windows-Ereignisprotokoll beantwortet, was der Domänencontroller auf Protokollebene angenommen oder abgewiesen hat. Es gibt weder einen neuen Logreiter noch eine privilegierte Eventlog-Sammelverbindung von VirtuSphere zu den DCs.

## 15. Systemstatus

Ein Systemstatusbereich ist sinnvoll, aber nur als kompakte beobachtbare Diagnose. Er ist keine zweite Konfigurationsseite und kein automatischer Dauerprobe-Generator.

### 15.1 Sichtbarkeit und Inhalt

- Nur Benutzer mit `users.manage` sehen AD-Übersichtskarte und Detailbereich; Controller-FQDNs werden normalen Portalbenutzern nicht offengelegt.
- Ohne gespeicherte AD-Konfiguration erscheint keine dauerhaft graue Karte.
- Bei Entwurf oder aktivem AD zeigt der Bereich Gesamtzustand, Aktivierung, verwendbare Controllerzahl, letzte erfolgreiche Verzeichnisoperation und pro Controller Priorität, Host/Port, letzten Test/Erfolg, Zertifikatsablauf und typisierten Zustand.
- Aktionen sind Links zu „Benutzer → Active Directory“ und zum gefilterten Sicherheitsprotokoll. Systemstatus selbst bleibt read-only.
- Ein Kadenzsatz sagt ausdrücklich: „Erneuert sich bei AD-Anmeldung, Sessionprüfung oder manuellem Controllertest; kein periodischer Bind-Test.“

### 15.2 Ampelableitung

- neutral: Entwurf deaktiviert oder noch nie getestet;
- grün: AD aktiv, mindestens ein verwendbarer Controller, kein aktueller Controllerfehler und Beobachtung frisch;
- gelb: mindestens ein Controller funktioniert, aber ein anderer ist ausgefallen, veraltet oder nach Konfigurationsänderung ungetestet; ebenso bald ablaufendes Zertifikat;
- rot: AD aktiv, aber kein verwendbarer Controller, Suchkonto-/TLS-Konfiguration ungültig, alle Controller ausgefallen oder Zertifikat abgelaufen;
- „veraltet“ bleibt ein eigener verständlicher Zustand und wird nicht als bewiesener Ausfall verkauft.

`directory_health_snapshot()` berechnet Controller- und Gesamtzustand einmal mit demselben `now`. Übersicht, Detailpanel und Help-Legende verwenden diese SSoT. Ein Dashboard-Tile wird zunächst nicht hinzugefügt; der Systemstatus genügt und vermeidet Lärm für eine reine Adminintegration.

## 16. Hilfe und Dokumentation

### 16.1 Portalhilfe

Die bestehende Benutzerhilfe erhält statt eines Fachbegriffsblocks einen Ablauf, den eine neue Administratorin von oben nach unten ausführen kann:

1. „Welche Konten gibt es?“ – lokal versus AD, Rolle bleibt lokal.
2. „Was muss im AD vorbereitet sein?“ – schreibbare Controller mit FQDN, TCP 636, Serverzertifikat, CA-Bundle, Lesekonto und Hinweis auf die wirksamen Signing-/Channel-Binding-GPOs.
3. „Active Directory einrichten“ – exakte Reihenfolge und Bedeutung jedes Felds.
4. „Weitere Controller hinzufügen“ – Priorität und Failover verständlich erklärt.
5. „Benutzer importieren“ – suchen, Ergebnis prüfen, Rolle wählen, importieren.
6. „So melden sich Benutzer an“ – vollständiger UPN, kein SSO, Passwortänderung außerhalb des Portals.
7. „Was bedeuten die Zustände?“ – gemeinsame Ampellegende und Aktualisierungskadenz.
8. „Wenn AD ausfällt“ – lokaler Notfalladmin, Status, Protokolllink, keine Richtlinienlockerung.
9. „Wartung“ – Suchkonto- und CA-Rotation, DC hinzufügen/entfernen.
10. „Nicht unterstützt“ – mehrere Domänen, Gruppenmapping, JIT, Entra, MFA/SSO.

Jeder Text nennt die Portalbezeichnung des Ziels und trägt den passenden Link. Kein Absatz sagt nur „unter Einstellungen prüfen“, wenn der Weg „Benutzer → Active Directory → Controller“ gemeint ist.

`help_system_status` erklärt die AD-Zeile mit demselben Statusrenderer. Inline-Hinweise bleiben kurz; die vollständige Erklärung lebt in Hilfe/Runbook und wird direkt verlinkt.

Alle Portaltexte laufen über `__t()` und werden gleichzeitig in DE/EN angelegt. Deutsche Texte verwenden echte Umlaute, kurze aktive Sätze und erklären Akronyme beim ersten Auftreten.

### 16.2 Architektur- und Betriebsdokumentation

Bei Umsetzung werden mindestens gepflegt:

- ADR-0039: Identitätsquelle, expliziter Import, GUID-Anker, LDAPS-Trust, Failover, lokale Rollen, Session-Revalidierung und Supportgrenzen;
- `docs/operations/active-directory.md`: vollständiges Runbook mit Vorbereitung, Einrichtung, Test, Pilot, Controller-/Secret-/CA-Rotation, Abschaltung und Wiederherstellung;
- Installationsanleitung/Deployment: ausgehender TCP-Port, DNS, Zertifikatsanforderungen, PHP-LDAP im Image und Supportmatrix;
- Windows-Server-2022-Anhang: geprüfter OS-Build/Patchstand, effektive Signing-/Channel-Binding-Richtlinien, bekannte Microsoft-Dokumentationsabweichung, RODC-Ausschluss und Auswirkung von PDC-/WAN-Erreichbarkeit nach Passwortänderungen;
- Go-live: HTTPS-Redirect, lokaler Notfalladmin, zwei getestete Controller empfohlen, Pilotlogin, Restore-/Failovertest;
- Troubleshooting: Entscheidungsbaum TLS/DNS/Port/Suchkonto/Benutzerstatus/Replikation, konkrete Portalpfade und sichere DC-Ereignisprüfung;
- Backup/Restore: `APP_KEY`-Bindung des Suchkonto-Secrets und Controllertest nach Restore;
- Security-Matrix: Authentisierung, Secret Storage, Transport, Injection, Enumeration, Lockout, Audit und Sessionentzug;
- QA/Testplan/Changelog/README sowie AGENTS/GROK-Regeln.

Neue konstruktive/negative Agentregeln müssen mindestens verbieten:

- freie oder ungeescapte LDAP-Filter;
- AD-Authentisierung ohne importierte GUID;
- Quelle-Fallback;
- unsichere TLS-Optionen, IP-Controllerziele und unvalidierte CA-Dateien;
- rohe LDAP-Diagnosen oder Secrets in Logs;
- AD-Passwortreset im Portal;
- handgeschriebene Benutzer-/Status-/Log-Deep-Links;
- eine AD-Ampel, die nicht aus dem gemeinsamen Snapshot stammt.

Aktive Dokumentation nennt keine von Hand gepflegten Test-/Migrationszahlen. Werte aus Konstanten werden entweder nicht als Ist-Zahl wiederholt oder dynamisch/guardbar gespiegelt.

## 17. Sicherheits- und Edge-Case-Matrix

| Fall | Erwartetes Verhalten |
|---|---|
| unbekannter AD-UPN | generische Ablehnung; kein Benutzer-Bind, gedrosselter Versuch |
| gültiger, aber nicht importierter AD-Benutzer | generische Ablehnung; niemals Session |
| importiert, lokal deaktiviert | generische Ablehnung; AD wird nicht als Bypass verwendet |
| importiert, im AD deaktiviert/abgelaufen | generische Ablehnung; Zustand/Audit typisiert |
| falsches Passwort | genau ein autoritatives Benutzer-Bind; kein Controller-Fan-out |
| falsches/abgelaufenes Suchkonto-Secret | kein Benutzerfehlversuch; revisionsweiter Circuit Breaker verhindert parallelen DC-Fan-out bis manuellem Test oder neuer Revision |
| leeres Passwort | vor LDAP abgelehnt; niemals anonymer Bind |
| primärer DC nicht erreichbar | nächster verwendbarer Controller innerhalb Gesamtbudget |
| primärer DC lehnt Passwort ab | sofort stoppen; kein zweiter Bind |
| Benutzer-UPN gerade repliziert | „nicht gefunden“ darf vor Passwort-Bind zum nächsten DC wechseln |
| Suchkonto-Passwort falsch/abgelaufen | automatische Bindversuche pausieren, rot im Status, lokaler Admin repariert |
| Controller aus anderer Domäne | Test `domain_mismatch`, nicht aktivierbar |
| eingetragener Controller ist ein RODC | Test `read_only_controller_unsupported`, nicht aktivierbar; kein irreführender grüner TLS-/Bindstatus |
| DNS zeigt auf Zertifikat mit falschem Namen | TLS-Abbruch, kein Bypass |
| IP statt FQDN | ValidationException vor Speicherung |
| unbekannte/abgelaufene CA oder Serverzertifikat | TLS-Abbruch; Zertifikatsursache lokalisiert, Details sicher |
| CA-Rotation | Kandidat testen, atomar übernehmen, andere Controller sichtbar erneut testen |
| LDAP-Injection im UPN/Suchfeld | escaped Datenwert; Filterstruktur unverändert |
| verschobener/umbenannter Benutzer | neue UPN-Suche liefert gleiche GUID; Zugriff bleibt, Cache aktualisiert |
| gelöschter und gleichnamig neu angelegter Benutzer | neue GUID; kein Zugriff ohne neuen Import |
| doppeltes/mehrdeutiges Suchergebnis | keine Auswahl nach „erstem Treffer“, sicherer Abbruch |
| parallele AD-Deaktivierung während Login | Revision-/Aktivitäts-Recheck vor Session verhindert Login |
| AD wird während Session deaktiviert | nächste Benutzerprüfung beendet AD-Session |
| alle DCs während Session weg | kurze Grace; danach Abmeldung, lokaler Admin bleibt nutzbar |
| PHP-LDAP fehlt | Aktivierung blockiert, lokale Anmeldung bleibt |
| DB nicht erreichbar | bestehender allgemeiner Fehlerpfad; kein Versuch, AD als DB-Ersatz zu benutzen |
| `APP_KEY` passt nicht | Secret nicht entschlüsselbar, roter Konfigurationszustand, kein Fallback |
| gleichnamiges lokales/AD-Konto | explizite Quelle verhindert Downgrade; Import-Namenskollision wird erklärt |
| eigener letzter lokaler Admin soll deaktiviert/demotiert werden | serverseitig blockiert |
| Passwort enthält Sonderzeichen/Unicode | unverändert an LDAP, nie trimmen/normalisieren/loggen |
| UPN enthält Filtersonderzeichen/Unicode | UTF-8 erhalten, Filterkontext korrekt escapen |
| Referrals/Cross-Domain-Treffer | Referrals aus; kein Kontakt zu nicht konfigurierten Hosts |
| lange Controllerliste/Ausfall | Gesamtzeitbudget begrenzt Antwort; Cooldown verhindert wiederholte Primärwartezeit |
| zwei parallele Adminaktionen gegen letzte lokale Admins/Controller | genau eine Änderung gewinnt; die zweite sieht unter Lock den neuen Zustand und wird abgelehnt |
| parallele Logins an der Rate-Limit-Grenze | höchstens das verbleibende reservierte Budget erreicht LDAP; Infrastrukturabschlüsse zählen danach nicht als Passwortfehler |
| Netzwerktest läuft während Konfigurationsänderung | erwartete Revision passt nicht; kein Lost Update und kein grüner Zustand für den falschen Kandidaten |
| Restore mit zuvor grünem AD | AD ist deaktiviert, Revision erhöht, alle Controller sichtbar erneut zu testen |
| temporärer Session-Recheckfehler und viele Seitenrequests | Grace bleibt am letzten Erfolg verankert; Retry-at verhindert einen Bind pro Request |
| CA-Laufzeitpfad ist Symlink/falsch befüllt | sicherer Abbruch vor LDAP; keine fremde Datei wird gelesen oder überschrieben |
| Browser ohne JavaScript | Setup, Import, Login und Controllerpriorisierung bleiben bedienbar |

## 18. Teststrategie

### 18.1 Unit- und Static-Tests

- Auth-Dispatcher und harte Trennung beider Quellen;
- GUID-Binär-/Darstellungsumwandlung mit bekannten Fixtures und Roundtrip;
- Filterescaping für `*`, Klammern, Backslash, NUL und Unicode;
- FQDN/Port/Base-DN/PEM-Validatoren, Verbot privater Schlüssel und IP-Ziele;
- RootDSE-/`dsServiceName`-Auswertung und RODC-Ablehnung über `msDS-isRODC`;
- AD-Accountflags einschließlich `ACCOUNTDISABLE` und Ablaufgrenzen;
- Ergebniscode- und Failovermatrix, insbesondere „invalid credentials stoppt“;
- Controllerpriorität, Revisionsinvalidierung, Cooldown und Gesamtbudget;
- Suchkonto-Circuit-Breaker getrennt von Benutzer-Credentialfehlern;
- Loginreservation und parallele Grenzfälle für Benutzer- und globales IP-Budget;
- Aktivierungsblocker und Schutz des letzten lokalen Admins;
- Session-Recheck/Grace/Abmeldung;
- Redaktionsvertrag: kein Testsecret in Exception, Audit, State oder gerendertem HTML;
- Logkategorie vollständig genau einem Tab zugeordnet, Security-Retention und Deep Link;
- Statusaggregation und gemeinsame Legende;
- Formaktionen vollständig im Confirm-/Safe-Action-Inventar;
- CSS-Class-, i18n-, Request- und Linkverträge.

### 18.2 Datenbank-/Migrationstests

- Upgrade eines realistischen lokalen Bestands ohne Hashänderung;
- Auth-Shape-Constraint für lokale und AD-Zeilen;
- doppelte GUID, Namenskollision, FK-/Cascade-Verhalten;
- atomare Konfigurationsrevision und Prioritätsupdates unter Konkurrenz;
- CAS gegen eine während des Netzwerktests geänderte Revision;
- Loginversuchszähler getrennt nach Quelle, globales IP-Limit gemeinsam,
  Pending-/Crash-Reservation und Infrastrukturfreigabe;
- konkurrierende letzte-Admin-/letzte-Controller-Aktionen;
- Frischschema-/Migrationskonvergenz;
- Backup/Restore mit richtigem und falschem `APP_KEY`.

### 18.3 Hermetische LDAP-Integration

Eine test-only, gepinnte TLS-LDAP-Fixture wird in der Integration-/Release-Lane gebaut oder als Repository-Testservice bereitgestellt. Sie beweist den nativen PHP/OpenLDAP-Weg, nicht nur einen Mock:

- vertrauenswürdiges, unbekanntes, abgelaufenes und namensfalsches Zertifikat;
- Dienstkonto-Bind, Suche, GUID-Rohwert und Benutzer-Bind;
- zwei Controller mit Ausfall/Recovery;
- falsches Passwort erreicht laut Fixture-Zähler keinen zweiten Controller;
- Such-Not-found darf ohne Passwort zum zweiten Controller;
- Timeout-/Budgetverhalten und Referral-Off;
- CA-Rotation innerhalb langlebiger FPM-Worker.

AD-spezifische Semantik, die ein generisches LDAP nicht ehrlich simuliert, bleibt als reale Windows-AD-Abnahme separat: RootDSE, `objectGUID`, `userAccountControl`, Kontenablauf, UPN-Änderung, Replikation und produktive Signing-/CBT-Policy.

### 18.4 Browser-E2E

- Setupblocker und direkte Links;
- ersten/zweiten Controller anlegen, testen, priorisieren und aktivieren;
- Benutzer suchen/importieren, Quellenbadge und quellenspezifische Aktionen;
- AD-Login, lokaler Login, keine Quelle-Fallbacks;
- de-/reaktivieren und Rollenänderung wirken sofort;
- AD-Kontoansicht besitzt kein Passwortformular;
- Status- und Log-Deep-Links;
- DE/EN und erzwungene Wrap-Geometrie auf Desktop/Mobil;
- manipulierte POSTs gegen Import, Passwortreset eines AD-Benutzers, letzte lokale Admins und unsicheren Login.

Der vorhandene lokale `auth.setup` bleibt als Notfall-/Regressionpfad erhalten; AD-E2E erhält einen eigenen Zustand und überschreibt keine lokale Baseline.

### 18.5 Negative Sicherheitsabnahme

- Quellcode-/Log-/DB-Suche nach Testpasswort, Bind-Secret, DN und PEM;
- LDAP-Injection, Arrayparameter, überlange Eingaben und NUL;
- MITM-/Zertifikatsfehler und HTTP-AD-POST;
- Benutzerenumeration und Antwortzeitvergleich;
- Passwortspray/IP-Limit und AD-Lockoutzählung;
- paralleles Deaktivieren/Revisionwechsel während Login;
- Session bleibt nach AD-Deaktivierung nicht verlängerbar;
- Restore/Wrong-Key und Extension-missing;
- keine externen Runtimeverbindungen außer zu explizit konfigurierten DC-FQDNs.

Neue mehrteilige Test-/Checkpfade geben die vorgeschriebenen `[n/total] RUN`-/Ergebniszeilen aus und erweitern `VirtuSphere.ProgressReporting.Tests.ps1`. Der LDAP-Fixture-Aufbau darf nicht als undurchsichtiger Langläufer in einem gepufferten Gate verschwinden.

## 19. Umsetzungsreihenfolge mit Exitkriterien

### Etappe 0A: ADR, hermetische Spikes und Zielprotokoll

- ADR-0039 als akzeptierte Entscheidung schreiben.
- Unvollständigen Vorlauf inventarisieren; keine spätere Etappe wird allein
  durch vorhandene Dateien als erledigt markiert.
- Hermetisch CA-Wechsel, falschen Namen/CA/Zeit/EKU, globale TLS-Optionen in
  langlebigen FPM-Workern, parallele Requests und CA-Dateipfadhärtung beweisen.
- Reproduzierbares, geheimnisfreies Ziel-AD-Abnahmeprotokoll bereitstellen.
  Verbindliche Vorlage:
  `docs/audits/2026-08-13-ldaps-target-ad-validation-protocol.md`.

Exit: Architektur und lokaler PHP/OpenLDAP-Vertrag sind grün; die reale
Zielprüfung ist als offener 0B-Gate sichtbar und kann nicht versehentlich als
„nicht anwendbar“ übersprungen werden.

### Etappe 0B: reale Ziel-AD-Freigabe

- Betriebssystem-Build/Patchstand, effektive LDAP-Signing- und Channel-Binding-Richtlinien sowie `AvoidPdcOnWan` der Zielumgebung erfassen, ohne Richtlinien automatisiert zu verändern.
- Echten AD-Policy-/CBT-Test gegen jeden vorgesehenen schreibbaren DC und PHP-FPM-CA-Rotation beweisen.
- Ergebnis und unterstützte Windows-/Policy-Grenze dokumentieren.

Exit: kein offener Zweifel, ob die gewählte Authentisierung mit der
Zielrichtlinie und strenger TLS-Prüfung funktioniert. Ohne diesen Exit bleiben
AD-Aktivierung, Pilotlogin und Release gesperrt, auch wenn alle hermetischen
Tests grün sind.

### Etappe 1: Laufzeit und SSoT

- `ext-ldap`, Composerplattformanforderung und Container-Smoke;
- fokussierte Konstanten, typisierte Ergebnisse, Validator/GUID-/Filterhelper;
- getrennte Identitäts-, TLS- und native LDAP-Module unter der Dateigrenze;
- Unit- und Redaktionsverträge.

Exit: native Verbindungsschicht ist ohne DB/UI testbar und lehnt unsichere TLS-/Filtervarianten ab.

### Etappe 2: Schema und Repository

- Migration/Frischschema für Benutzerquelle, AD-Konfiguration, Controller, State und Loginversuchsquelle;
- revisionsweiten Suchkonto-Circuit-Breaker, Loginreservation und
  Restore-Invalidierung;
- Repositories, Transaktionen, Revision/Priorität, Backup-/Restorekonvergenz;
- bestehende lokale Fixtures und Seed auf explizite lokale Form bringen.
- DDL-Fehlerinjektion nach jedem additiven Schritt und grüner Wiederanlauf;
  MySQL-DDL wird nicht fälschlich als atomare Transaktion dokumentiert.

Exit: lokale Authdaten bleiben unverändert; alle Invarianten sind in DB/Repo geprüft.

### Etappe 3: Benutzer-AD-Konfiguration

- Benutzer-Unteransichten, Setupflow, Kandidatentest, Controllerverwaltung;
- Aktivierungsblocker einschließlich HTTPS und lokalem Admin;
- Secret-/CA-Rotation und Confirm-/Safe-Action-Verträge.

Exit: AD kann sicher eingerichtet/getestet werden, ist aber noch feature-flagmäßig nicht für Login freigegeben.

### Etappe 4: Suche und Import

- begrenzte POST-Suche, serverseitiger Re-read, GUID-Import;
- Kontenliste, Filter, Status und quellenspezifische Aktionen;
- Accountseite für AD.

Exit: Nur ein expliziter, eindeutig erneut gelesener AD-Benutzer kann eine lokale Autorisierungszeile erhalten.

### Etappe 5: Login, Failover und Sessions

- explizite Quellenauswahl, gemeinsamer Rate-Limit-Prolog;
- AD-Gate/Bind, Controllerfailover, keine Fallbacks;
- Session-Revalidierung/Grace und lokale Notfalladmin-Garantie;
- Konkurrenz-, Lockout- und Ausfalltests.

Exit: alle Auth-/Edge-Case-Matrixfälle grün; lokale Anmeldung vollständig regressionsfrei.

### Etappe 6: Beobachtbarkeit

- `directory`-Kategorie im Sicherheitsreiter, Retention/Deep Links;
- gedrosselte Zustandsübergänge;
- konditionaler read-only AD-Systemstatus mit gemeinsamer Ampel;
- bewusst kein Dashboardtile und kein neuer Logreiter.

Exit: Ein wechselnder Admin kann aus Benutzerseite → Systemstatus → Sicherheitsprotokoll Ursache und nächsten Schritt finden, ohne rohe LDAP-Fehler interpretieren zu müssen.

### Etappe 7: Hilfe, Betriebsdoku und Releaseabnahme

- DE/EN-Portalhilfe und alle genannten aktiven Dokumente;
- hermetische LDAP-Fixture, echte AD-Abnahme und Playwright;
- Fast-, Integration- und Release-Lane; Guard-Mutation für neue SSoTs;
- Go-live-/Rollbackprotokoll.

Exit: Doku beschreibt nur nachgewiesenes Verhalten; keine bekannte Lücke ist als „später prüfen“ im aktiven Produktpfad verblieben.

## 20. Rollout und Rückweg

1. Vollständiges Backup und Restore-Drill nach bestehendem Runbook.
2. Neues Image mit LDAP-Extension ausrollen; Feature bleibt DB-seitig deaktiviert.
3. Lokalen Notfalladmin testen und Zugang sicher verwahren.
4. Portal-HTTPS und Redirect prüfen.
5. Gepatchten Windows-Server-2022-Stand und wirksame Signing-/Channel-Binding-Richtlinien im Abnahmeprotokoll erfassen; AD-Grundkonfiguration und mindestens zwei schreibbare Controller empfehlen, jeden einzeln testen.
6. AD aktivieren und normalen Pilotbenutzer importieren; noch keine AD-Adminrolle.
7. Login, falsches Passwort, nicht importierter Benutzer, primärer DC-Ausfall, UPN-Änderung und Session-Deaktivierung protokolliert abnehmen.
8. Erst danach bei Bedarf einen importierten Benutzer lokal zum Portaladmin machen; lokaler Admin bleibt bestehen.
9. Backup/Restore mit AD-Konfiguration testen und Controller danach erneut prüfen.

Rollback ist featureseitig: AD über den lokalen Admin deaktivieren. Lokale Benutzer, Rollen und Sessions funktionieren weiter; AD-Zeilen bleiben für Audit deaktiviert erhalten. Es gibt keine destruktive Down-Migration. Das bestätigte Löschen der AD-Konfiguration entfernt verschlüsseltes Suchkonto-Secret, CA und Controller, aber nicht historische Auditzeilen oder importierte Benutzerobjekte; diese bleiben ohne Konfiguration nicht anmeldbar.

## 21. Definition of Done

Die Integration ist erst fertig, wenn alle folgenden Aussagen gleichzeitig wahr sind:

- Mehrere Controller lassen sich priorisieren, strikt testen und bei technischen Fehlern kontrolliert verwenden.
- Ein falsches Benutzer- oder Suchkonto-Passwort wird nicht an mehrere Controller vervielfacht.
- Ausschließlich eine importierte, aktive GUID mit lokaler Rolle erhält eine Session.
- Kein AD-Konto besitzt oder akzeptiert einen lokalen Passwortfallback.
- AD-Login funktioniert nur über Portal-HTTPS und streng validiertes LDAPS.
- Lokaler Notfalladmin kann nicht versehentlich entfernt, deaktiviert oder demotiert werden.
- AD-Deaktivierung/-Löschung beendet laufende AD-Sessions innerhalb des dokumentierten Prüfvertrags.
- Audit, Sicherheitsreiter und Systemstatus zeigen sichere, verständliche, gedrosselte Ursachen und direkte Reparaturlinks.
- Kein neuer Logreiter teilt Authentisierungs- und Verzeichnisuntersuchungen unnötig auf.
- Portalhilfe und Runbook führen wechselndes Adminpersonal ohne implizites AD-/LDAP-Vorwissen durch Setup, Betrieb, Rotation, Ausfall und Rückweg.
- DE/EN-Parität, CSS-/Confirm-/Deep-Link-/Enum-/Doc-/Progress-Guards und alle Testlanes sind grün.
- Echte Ziel-AD-Policy und CA-Rotation im langfristig laufenden PHP-FPM wurden praktisch bewiesen.

## 22. Primärquellen für die technische Umsetzung

- Microsoft: [Configure certificates for LDAP over SSL in Active Directory Domain Services](https://learn.microsoft.com/en-us/windows-server/identity/ad-ds/configure-ldap-signing-certificates)
- Microsoft: [What's new in Windows Server 2022 – TLS 1.3](https://learn.microsoft.com/en-us/windows-server/get-started/whats-new-in-windows-server-2022)
- Microsoft: [Manage LDAP signing using Group Policy for Active Directory Domain Service](https://learn.microsoft.com/en-us/windows-server/identity/manage-ldap-signing-group-policy)
- Microsoft: [LDAP session security settings and requirements after ADV190023](https://learn.microsoft.com/en-us/troubleshoot/windows-server/active-directory/ldap-session-security-settings-requirements-adv190023)
- Microsoft: [Domain controller LDAP server channel binding token requirements](https://learn.microsoft.com/en-us/previous-versions/windows/it-pro/windows-10/security/threat-protection/security-policy-settings/domain-controller-ldap-server-channel-binding-token-requirements)
- Microsoft: [Password change processing and conflict resolution functionality in Windows](https://learn.microsoft.com/en-us/troubleshoot/windows-server/active-directory/password-change-processing-conflict-resolution-function)
- Microsoft: [RODC Password Replication Group and authentication behavior](https://learn.microsoft.com/en-us/services-hub/unified/health/remediation-steps-ad/review-the-removal-of-default-members-from-the-denied-rodc-password-replication-group)
- Microsoft: [`msDS-isRODC` attribute](https://learn.microsoft.com/en-us/windows/win32/adschema/a-msds-isrodc) und [RootDSE attributes](https://learn.microsoft.com/en-us/windows/win32/adschema/rootdse)
- Microsoft: [User naming attributes](https://learn.microsoft.com/en-us/windows/win32/ad/naming-properties)
- Microsoft: [UserAccountControl property flags](https://learn.microsoft.com/en-us/troubleshoot/windows-server/active-directory/useraccountcontrol-manipulate-account-properties)
- PHP: [`ldap_connect()`](https://www.php.net/manual/en/function.ldap-connect.php), [`ldap_set_option()`](https://www.php.net/manual/en/function.ldap-set-option.php), [`ldap_escape()`](https://www.php.net/manual/en/function.ldap-escape.php) und [LDAP-Konstanten](https://www.php.net/manual/en/ldap.constants.php)
