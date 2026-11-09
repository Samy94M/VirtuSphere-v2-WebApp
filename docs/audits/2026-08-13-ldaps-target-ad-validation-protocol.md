# Gate 0B: AD/LDAPS-Abnahme vor Ort (Protokoll und Abhakliste)

Stand: 09.11.2026 (neu gefasst mit Paket N1a; erste Fassung 13.08.2026)
Status: verpflichtendes, noch offenes Gate 0B für die Zielumgebung

Dieses Protokoll entscheidet nur, ob die VirtuSphere-Anmeldung über Active
Directory mit der konkreten AD-Umgebung zusammenarbeitet. Es ändert keine
Gruppenrichtlinie, kein Zertifikat und kein echtes Benutzerkonto. Ohne ein
vollständig positives Ergebnis bleiben AD-Aktivierung für echte Nutzer und
Pilot gesperrt. Lokale Portalkonten bleiben davon unberührt.

Die Liste ist für einen Menschen vor Ort, ohne Internet. Zwei Rollen arbeiten
zusammen: **Portal** (Betriebskonto auf dem Docker-Host) und **AD-Betrieb**
(Admin an den DCs, Windows PowerShell 5.1 als Administrator). Hintergrund und
Entscheidungen stehen in `docs/operations/active-directory.md`, ADR-0039 und
im Register `docs/register/2026-11-09-N1a.md`.

## So wird diese Liste benutzt

- **Platzhalter** ersetzen, bevor ein Befehl läuft. In diese Datei kommen nie
  echte Namen. Die echten Werte stehen nur im Ticket der Kundenumgebung.

  | Platzhalter | Bedeutung |
  |---|---|
  | `dc1.example.test`, `dc2.example.test` | FQDN je schreibbarem DC; weitere DCs genauso |
  | `pdc.example.test` | FQDN des PDC-Emulators (aus D2) |
  | `example.test` | AD-DNS-Domäne |
  | `192.0.2.10` | IP des Docker-Hosts, wie die DCs sie sehen |
  | `192.0.2.11` | IP von DC1 (nur für N3) |
  | `BETRIEBSKONTO` | Linux-Konto auf dem Docker-Host, dem der Projektordner gehört |
  | `/opt/VirtuSphere-v2-WebApp` | Projektordner mit `docker-compose.yml`; auf einem anderen Host den eigenen Pfad |
  | `SUCHKONTO` | sAMAccountName des Lese-/Suchkontos |
  | `TESTKONTO1` bis `TESTKONTO5` | sAMAccountName der eigens angelegten Testkonten |
  | `testkonto1@example.test` usw. | UPN der Testkonten |
  | `PORTALURL` | HTTPS-Adresse des Portals, etwa `https://portal.example.test` |

- Jeder Schritt hat: **Voraussetzung**, **Handlung**, **Soll**, **Bei
  Abweichung**. Ergebnis in die Kästchenzeile schreiben: Datum, Uhrzeit,
  Kürzel, Ergebnis.
- **Grundregel bei jeder Abweichung:** anhalten, Ergebnis wörtlich als
  typisierten Portalcode oder Kurzbefund notieren, AD nicht für echte Benutzer
  aktivieren. Keine Richtlinie, kein TLS und kein Zertifikat zur Umgehung
  abschwächen. Mit dem AD-Betrieb die Ursache klären, danach den Schritt
  wiederholen.
- **Beweisregeln:** Keine Passwörter, privaten Schlüssel, LDAP-Diagnosetexte,
  vollständigen DNs oder Registry-Exporte notieren. Erlaubt sind:
  - Hostname, OS-Build, Policy und wirksamer Wert;
  - Zertifikats-Thumbprint, SHA-256 und Ablauf;
  - Zeitstempel und typisierte Portalergebnisse (`ok`, `tls_failed`,
    `service_bind_rejected` …);
  - Ticket- oder Screenshot-Referenzen.
- Jeder schreibbare DC wird **einzeln** geprüft. Ein Load-Balancer-Name zählt
  nicht.
- Geprüft wird **aus dem Prod-Container `php`**, nicht vom Admin-PC.
- **Host-Befehle** laufen als `BETRIEBSKONTO` in einer Bash auf dem
  Docker-Host, immer mit vollem Pfad. Sie enthalten keine `~` und keine `*`.
  Jeden Block einzeln einfügen, die Ausgabe nicht zurück ins Terminal kopieren.
- **DC-Befehle** sind PowerShell-5.1-Einzeiler ohne `$`, einzeln einfügen.
- Zeitbedarf: 3 bis 5 Stunden mit zwei DCs, dazu 20 bis 30 min Puffer je
  erwartbarer Klärungsrunde.

## Mitbringen

- [ ] Diese Datei und `scripts/gate0b-tls-probe.php` aus dem freigegebenen
  Release-Stand (Stick oder Checkout auf dem Docker-Host).
- [ ] CA-Kette der DC-Zertifikate als Base64-PEM (Root und gegebenenfalls
  Zwischen-CA), vom AD-Betrieb exportiert.
- [ ] Ticket mit den echten Werten aller Platzhalter.
- [ ] Zugang lokaler Notfall-Admin des Portals.

## Stammdaten

| Feld | Eintrag |
|---|---|
| Umgebung/Ticket | |
| Prüfer Portal / Prüfer AD-Betrieb (Vier Augen) | |
| Prod-Commit (`git log -1`, P1) | |
| PHP-Version, OpenLDAP-Version, TLS-Bibliothek (P2, P3) | |
| Image-ID `php` (P2) | |
| FPM-Wanduhr 300 s aktiv (P2) | |
| AD-DNS-Domäne (nur im Ticket) | |
| Geplante Search Base (nur im Ticket) | |
| Suchkonto (nur Bezeichner im Ticket) | |
| Portal-HTTPS und Umleitung geprüft (P4) | |
| Lokaler Notfall-Admin geprüft (P5) | |
| Sperrschwelle / Fenster / Dauer (D7) | |

---

## Block P: Docker-Host vorbereiten (Portal)

### P0 Dateien ablegen
- **Voraussetzung:** Stick am Docker-Host eingehängt oder Datei per scp
  übertragen.
- **Handlung:**
  ```bash
  mkdir -p /home/BETRIEBSKONTO/gate0b
  ```
  Danach `gate0b-tls-probe.php` und die CA-Datei als `ca.pem` nach
  `/home/BETRIEBSKONTO/gate0b/` kopieren.
  ```bash
  ls -l /home/BETRIEBSKONTO/gate0b/gate0b-tls-probe.php /home/BETRIEBSKONTO/gate0b/ca.pem
  ```
- **Soll:** Beide Dateien existieren. `ca.pem` beginnt mit
  `-----BEGIN CERTIFICATE-----` und enthält keinen privaten Schlüssel:
  ```bash
  grep -c "BEGIN CERTIFICATE" /home/BETRIEBSKONTO/gate0b/ca.pem
  ```
  ```bash
  grep -c "PRIVATE KEY" /home/BETRIEBSKONTO/gate0b/ca.pem
  ```
  Erste Zahl mindestens 1, zweite Zahl 0.
- **Bei Abweichung:** Neue CA-Datei beim AD-Betrieb anfordern. Eine Datei mit
  privatem Schlüssel sofort löschen und den AD-Betrieb informieren.
- [ ] Ergebnis:

### P1 Code- und Containerstand
- **Voraussetzung:** P0.
- **Handlung:**
  ```bash
  cd /opt/VirtuSphere-v2-WebApp && git log -1 --format=%h%n%ci%n%s && git status --short | head -5
  ```
  ```bash
  cd /opt/VirtuSphere-v2-WebApp && docker compose ps
  ```
- **Soll:** Der Commit ist ein Release-Stand mit Paket N1a (Changelog-Eintrag
  „AD-Anmeldung gehärtet“). Ohne N1a darf Gate 0B trotzdem laufen; dann im
  Ergebnis „vor N1a“ vermerken und F8, F10 und G1 bis G3 als „entfällt (vor
  N1a)“ markieren. `git status` ist leer. Alle Dienste laufen und sind
  `healthy`.
- **Bei Abweichung:** Ungesunde Dienste zuerst nach Betriebsdoku klären.
  Gate 0B nicht auf einem halb aktualisierten Stand fahren.
- [ ] Ergebnis:

### P2 PHP, ext-ldap, OpenLDAP und FPM-Wanduhr im Container
- **Voraussetzung:** P1.
- **Handlung:**
  ```bash
  cd /opt/VirtuSphere-v2-WebApp && docker compose exec -T php php -v
  ```
  ```bash
  cd /opt/VirtuSphere-v2-WebApp && docker compose exec -T php php --ri ldap
  ```
  ```bash
  cd /opt/VirtuSphere-v2-WebApp && docker compose images php
  ```
  ```bash
  cd /opt/VirtuSphere-v2-WebApp && docker compose exec -T php grep -E "^(request_terminate_timeout|request_slowlog_timeout)" /usr/local/etc/php-fpm.d/zz-virtusphere.conf
  ```
- **Soll:** PHP 8.4.x. `ldap support => enabled`, `Vendor Name => OpenLDAP`,
  Vendor Version 206xx (also 2.6.x). Image-ID notieren. Die letzte Ausgabe
  zeigt `request_terminate_timeout = 300s` und `request_slowlog_timeout = 30s`.
- **Bei Abweichung:** Fehlt ext-ldap, ist das Image falsch: abbrechen,
  Image-Lieferung klären. Fehlen die beiden FPM-Zeilen, stammt das Image aus
  einem Stand vor N1a; das ist kein Abbruch, aber zu vermerken.
- [ ] Ergebnis:

### P3 TLS-Bibliothek von libldap
- **Voraussetzung:** P2.
- **Handlung:**
  ```bash
  cd /opt/VirtuSphere-v2-WebApp && docker compose exec -T php dpkg-query -W libldap2
  ```
  ```bash
  cd /opt/VirtuSphere-v2-WebApp && docker compose exec -T php ldd /usr/lib/x86_64-linux-gnu/libldap.so.2
  ```
- **Soll:** In der `ldd`-Ausgabe steht `libssl.so.3` und `libcrypto.so.3`,
  kein `libgnutls`.
- **Bei Abweichung:** Steht dort `libgnutls`, wirkt die Mindestversion TLS 1.2
  im Anmeldepfad möglicherweise nicht. Abbrechen und als Befund melden. Meldet
  `ldd` „not found“ für die Datei, mit
  `docker compose exec -T php dpkg -L libldap2` den richtigen Pfad suchen.
- [ ] Ergebnis:

### P4 Portal-HTTPS und Umleitung
- **Voraussetzung:** P1.
- **Handlung:** Im Browser `PORTALURL` öffnen und das Zertifikat prüfen. Dann
  die HTTP-Variante der Adresse aufrufen. Im Portal unter
  **Einstellungen > HTTPS** nachsehen, ob HTTPS und die HTTP-zu-HTTPS-Umleitung
  aktiv sind.
- **Soll:** HTTPS ohne Warnung, HTTP leitet auf HTTPS um, beide Schalter sind
  aktiv.
- **Bei Abweichung:** Erst HTTPS nach Betriebsdoku einrichten. Ohne HTTPS lässt
  das Portal keine AD-Anmeldung zu.
- [ ] Ergebnis:

### P5 Lokaler Notfall-Admin
- **Voraussetzung:** P4.
- **Handlung:** Im privaten Browserfenster mit Quelle **Lokal** als
  Notfall-Admin anmelden und wieder abmelden.
- **Soll:** Die Anmeldung klappt. Unter **Benutzer** ist mindestens ein aktiver
  lokaler Admin vorhanden.
- **Bei Abweichung:** Erst einen zweiten lokalen Admin anlegen. Ohne lokalen
  Admin lässt das Portal die AD-Aktivierung nicht zu.
- [ ] Ergebnis:

### P6 Startzeit des FPM-Containers merken
- **Voraussetzung:** P1.
- **Handlung:**
  ```bash
  docker inspect -f '{{.State.StartedAt}}' virtusphere-v2-webapp-php-1
  ```
- **Soll:** Den Zeitstempel notieren. Er muss bis zum Ende von Block N gleich
  bleiben (CA-Wechsel ohne FPM-Neustart).
- **Bei Abweichung:** Meldet Docker „No such object“, den Namen aus
  `docker compose ps` nehmen.
- [ ] Ergebnis:

### P7 DNS und TCP 636 aus dem Container (je DC)
- **Voraussetzung:** P1. Freischaltung Docker-Host zu den DCs auf TCP 636.
- **Handlung:** Für jeden DC:
  ```bash
  cd /opt/VirtuSphere-v2-WebApp && docker compose exec -T php getent hosts dc1.example.test
  ```
  ```bash
  cd /opt/VirtuSphere-v2-WebApp && docker compose exec -T php php -r 'echo @fsockopen("dc1.example.test", 636, $e, $s, 3) ? "TCP ok" : "TCP fehlt", "\n";'
  ```
- **Soll:** `getent` zeigt genau die erwartete DC-Adresse, danach `TCP ok`.
- **Bei Abweichung:** Falsche Adresse: DNS im Container klären (Docker-DNS,
  Resolver des Hosts). `TCP fehlt`: Firewall-Freischaltung prüfen.
- [ ] dc1:
- [ ] dc2:

---

## Block D: DC-seitige Prüfungen (AD-Betrieb, je DC)

Alle Befehle als Administrator in Windows PowerShell 5.1 auf dem jeweiligen DC.

### D1 Betriebssystem und Patchstand
- **Voraussetzung:** Anmeldung am DC.
- **Handlung:**
  ```powershell
  Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion' | Format-List ProductName, DisplayVersion, CurrentBuild, UBR
  ```
  ```powershell
  Get-HotFix | Sort-Object InstalledOn -Descending | Select-Object -First 3 -Property HotFixID, InstalledOn
  ```
- **Soll:** Build 20348 bedeutet Server 2022, Build 26100 Server 2025. Das
  letzte kumulative Update ist nicht älter als die Patchvorgabe des Betriebs.
- **Bei Abweichung:** Ungepatchten DC zuerst patchen lassen oder aus der Liste
  nehmen.
- [ ] dc1:
- [ ] dc2:

### D2 Schreibbar, RODC, PDC-Emulator, Domäne
- **Voraussetzung:** AD-PowerShell-Modul (auf DCs vorhanden).
- **Handlung:**
  ```powershell
  Get-ADDomain | Format-List DNSRoot, PDCEmulator, ReplicaDirectoryServers, ReadOnlyReplicaDirectoryServers
  ```
- **Soll:** Jeder vorgesehene DC steht unter `ReplicaDirectoryServers`
  (schreibbar), keiner unter `ReadOnlyReplicaDirectoryServers`. `PDCEmulator`
  notieren, das ist `pdc.example.test`.
- **Bei Abweichung:** Einen RODC nicht ins Portal eintragen. Das Portal lehnt
  ihn ab (N5).
- [ ] Ergebnis:

### D3 LDAP Signing (wirksamer Wert)
- **Voraussetzung:** D1.
- **Handlung:**
  ```powershell
  Get-ItemProperty 'HKLM:\SYSTEM\CurrentControlSet\Services\NTDS\Parameters' | Format-List LDAPServerIntegrity, LdapEnforceChannelBinding
  ```
  ```powershell
  gpresult /scope computer /h C:\Windows\Temp\gate0b-gpresult.html /f
  ```
  Die HTML-Datei öffnen und unter **Sicherheitsoptionen** diese Einträge
  ablesen:
  - „Domänencontroller: Anforderungen für die LDAP-Serversignierung“;
  - auf Server 2025 zusätzlich „… Erzwingung“;
  - „Domänencontroller: Anforderungen an das LDAP-Serverkanalbindungstoken“.
- **Soll:** Werte notieren. `LDAPServerIntegrity` 2 heißt Signing
  erforderlich, 1 oder leer heißt OS-Standard. Server 2025 erzwingt bei neuen
  Deployments. Alle Werte sind für das Portal zulässig: Signing betrifft nur
  SASL ohne TLS, das Portal nutzt Simple Bind über LDAPS.
- **Bei Abweichung:** Keine. Den Wert nur notieren und **nicht ändern**.
- [ ] dc1:
- [ ] dc2:

### D4 Channel Binding (wirksamer Wert)
- **Voraussetzung:** D3 (derselbe Befehl).
- **Handlung:** `LdapEnforceChannelBinding` aus D3 und die GPO-Zeile notieren.
- **Soll:** 0 heißt Never, 1 When supported (Standard bei neuen
  Server-2025-Deployments), 2 Always. Laut Microsoft hat der Wert keine Wirkung
  auf Simple Bind über TLS. Der praktische Beweis ist ein erfolgreicher Block K
  und F **mit diesem Wert**. Gilt im Zielbetrieb 2, wird mit 2 getestet.
- **Bei Abweichung:** Scheitert Block K mit `service_bind_rejected`, obwohl das
  Passwort stimmt und die Policy 2 ist, liegt ein Kompatibilitätsbefund nach
  ADR-0039 vor. Abbrechen, die Policy nicht senken, eine neue
  Architekturentscheidung ist nötig.
- [ ] dc1:
- [ ] dc2:

### D5 Diagnose-Logging für die Prüfzeit (nur mit Zustimmung AD-Betrieb)
- **Voraussetzung:** Zustimmung des AD-Betriebs; der Schritt ist umkehrbar.
- **Handlung:** Alten Wert lesen und notieren:
  ```powershell
  Get-ItemProperty 'HKLM:\SYSTEM\CurrentControlSet\Services\NTDS\Diagnostics' | Format-List '16 LDAP Interface Events'
  ```
  Dann setzen:
  ```powershell
  Set-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Services\NTDS\Diagnostics' -Name '16 LDAP Interface Events' -Value 2 -Type DWord
  ```
- **Soll:** Der Wert ist 2. Ab jetzt schreibt der DC die Ereignisse 2889 und
  3039 bis 3075.
- **Bei Abweichung:** Ohne Zustimmung entfällt E1 für 2889/3039/3074/3075. Das
  im Ergebnis vermerken.
- [ ] dc1 (alter Wert):
- [ ] dc2 (alter Wert):

### D6 LDAPS-Zertifikat am DC
- **Voraussetzung:** D1.
- **Handlung:**
  ```powershell
  Get-ChildItem Cert:\LocalMachine\My | Where-Object HasPrivateKey | Format-List Subject, Issuer, NotAfter, Thumbprint, DnsNameList, EnhancedKeyUsageList
  ```
- **Soll:** Es gibt ein Zertifikat mit diesen Eigenschaften:
  - `DnsNameList` enthält den DC-FQDN;
  - EKU enthält „Server Authentication (1.3.6.1.5.5.7.3.1)“;
  - `NotAfter` liegt mehr als 30 Tage in der Zukunft;
  - der Aussteller gehört zur CA aus `ca.pem`.

  Thumbprint und Ablauf notieren. Welches Zertifikat Port 636 tatsächlich
  liefert, zeigt T2 (SHA-1 = Thumbprint).
- **Bei Abweichung:**
  - Kein passendes Zertifikat: Der DC hat kein gültiges LDAPS. Zertifikat über
    die PKI ausstellen lassen, nicht selbstsigniert.
  - Liegt das Zertifikat im NTDS-Dienstspeicher: In der MMC unter
    **Zertifikate > Dienstkonto > Active Directory Domain Services >
    NTDS\Eigene Zertifikate** nachsehen.
- [ ] dc1 (Thumbprint, Ablauf):
- [ ] dc2 (Thumbprint, Ablauf):

### D7 Sperrrichtlinie gegen Portal-Drossel (einmal je Domäne)
- **Voraussetzung:** D2.
- **Handlung:**
  ```powershell
  Get-ADDefaultDomainPasswordPolicy | Format-List LockoutThreshold, LockoutObservationWindow, LockoutDuration, MaxPasswordAge
  ```
  ```powershell
  Get-ADFineGrainedPasswordPolicy -LDAPFilter '(objectClass=msDS-PasswordSettings)' | Format-List Name, Precedence, LockoutThreshold, LockoutObservationWindow, AppliesTo
  ```
  ```powershell
  Get-ADUserResultantPasswordPolicy -Identity TESTKONTO1
  ```
- **Soll:** Für die Standardrichtlinie und jede FGPP, die Portal-Nutzer
  betrifft, gilt: `5 × aufgerundet(LockoutObservationWindow / 15 min) <
  LockoutThreshold`. Die 5 ist das Fehlversuchsbudget je Name, die 15 min sind
  das Drosselfenster des Portals. Beispiele: Schwelle 10 und Fenster 15 min
  passt (5 < 10). Schwelle 5 und Fenster 30 min passt nicht (10 ≥ 5).
  Schwelle 0 (keine Sperre) passt.
- **Bei Abweichung:** Kein Abbruch, aber Befund „Portal kann AD-Konten sperren“
  an den Nutzer. Die AD-Richtlinie nicht ändern.
- [ ] Ergebnis:

### D8 Suchkonto
- **Voraussetzung:** Suchkonto vom AD-Betrieb angelegt: normales Benutzerkonto,
  keine Admin-Gruppen, Lesezugriff auf Benutzerobjekte (Standard
  „Authentifizierte Benutzer“ genügt). Gelesen werden unter anderem
  `userAccountControl`, `pwdLastSet` und das berechnete Attribut
  `msDS-User-Account-Control-Computed`.
- **Handlung:**
  ```powershell
  Get-ADUser -Identity SUCHKONTO -Properties Enabled, LockedOut, PasswordNeverExpires, PasswordLastSet, UserPrincipalName, MemberOf | Format-List Enabled, LockedOut, PasswordNeverExpires, PasswordLastSet, UserPrincipalName, MemberOf
  ```
- **Soll:** Aktiv, nicht gesperrt, UPN vorhanden, keine privilegierte Gruppe.
  Läuft das Passwort ab (`PasswordNeverExpires` False), ist im Betriebsplan ein
  Termin zum Wechseln vermerkt. Ein abgelaufenes, gesperrtes oder deaktiviertes
  Suchkonto stoppt alle AD-Anmeldungen. Das Portal nennt den Grund dann im
  Systemstatus („Passwort des Suchkontos abgelaufen“, „Suchkonto gesperrt“,
  „Suchkonto deaktiviert oder abgelaufen“).
- **Bei Abweichung:** Konto korrigieren lassen, dann weiter.
- [ ] Ergebnis:

### D9 Testkonten anlegen (nur eigens angelegte Konten, keine echten Nutzer)
- **Voraussetzung:** Zustimmung AD-Betrieb, eigene Test-OU innerhalb der
  geplanten Search Base.
- **Handlung:** Der AD-Betrieb legt fünf Testkonten mit UPN
  `testkonto1@example.test` bis `testkonto5@example.test` an, aktiviert sie und
  setzt Passwörter, die nur im Ticket stehen. Dann:
  ```powershell
  Disable-ADAccount -Identity TESTKONTO2
  ```
  ```powershell
  Set-ADUser -Identity TESTKONTO4 -ChangePasswordAtLogon 1
  ```
  ```powershell
  Set-ADAccountExpiration -Identity TESTKONTO5 -DateTime (Get-Date).AddDays(-1)
  ```
- **Soll:**
  - TESTKONTO1 normal aktiv;
  - TESTKONTO2 deaktiviert;
  - TESTKONTO3 normal aktiv, Ziel für den Drosseltest F9;
  - TESTKONTO4 „Passwort bei nächster Anmeldung ändern“;
  - TESTKONTO5 abgelaufen.
- **Bei Abweichung:** Fehlt ein Konto, entfällt die zugehörige F-Zeile. Das
  vermerken.
- [ ] Ergebnis:

---

## Block T: TLS aus dem Prod-Container (Portal, je DC)

### T1 CA in den Container legen
- **Voraussetzung:** P0, P1.
- **Handlung:**
  ```bash
  cd /opt/VirtuSphere-v2-WebApp && docker compose cp /home/BETRIEBSKONTO/gate0b/ca.pem php:/tmp/gate0b-ca.pem
  ```
- **Soll:** Kein Fehler.
- **Bei Abweichung:** Meldet `docker compose cp` einen Fehler, mit
  `docker cp /home/BETRIEBSKONTO/gate0b/ca.pem virtusphere-v2-webapp-php-1:/tmp/gate0b-ca.pem`
  wiederholen.
- [ ] Ergebnis:

### T2 TLS-Probe je DC
- **Voraussetzung:** T1, P7.
- **Handlung:**
  ```bash
  cd /opt/VirtuSphere-v2-WebApp && docker compose exec -T php php -- dc1.example.test 636 /tmp/gate0b-ca.pem < /home/BETRIEBSKONTO/gate0b/gate0b-tls-probe.php
  ```
- **Soll:** Letzte Zeile `ERGEBNIS: ok`. TLS-Version `TLSv1.2` oder `TLSv1.3`.
  Der SAN enthält den DC-FQDN, die EKU enthält „TLS Web Server
  Authentication“. **SHA-1 = Thumbprint aus D6.** SHA-256 und Ablauf notieren.
  Das Skript bindet nicht und sendet keine Zugangsdaten.
- **Bei Abweichung:**
  - `tls-fehlgeschlagen`: Prüfen, ob die CA-Kette vollständig ist, der Name im
    SAN steht und der Port stimmt.
  - SHA-1 passt nicht zu D6: Der DC liefert ein anderes Zertifikat als gedacht;
    AD-Betrieb klärt, welches.
  - `ok-aber-laeuft-bald-ab`: Erneuerung vor der Aktivierung.
- [ ] dc1:
- [ ] dc2:

---

## Block K: Portalkonfiguration und Controllertest (Portal)

Alles unter **Benutzer > Active Directory**. Die AD-Anmeldung bleibt bis
Block F **deaktiviert**.

### K1 Konfiguration speichern
- **Voraussetzung:** P4, P5, D8, T2 grün.
- **Handlung:** Diese Felder eintragen und speichern:
  - Suchkonto-UPN;
  - Suchkonto-Passwort;
  - CA-Bundle (Inhalt von `ca.pem`, z. B. mit
    `cat /home/BETRIEBSKONTO/gate0b/ca.pem` anzeigen und kopieren);
  - Search Base.
- **Soll:** „Gespeichert“. Das Passwortfeld ist danach leer, das Portal zeigt
  das Passwort nie wieder an.
- **Bei Abweichung:** Die Formularmeldung notieren. Mögliche Meldungen: CA
  ungültig, privater Schlüssel, Search Base ungültig, Suchkonto-Passwort mit
  Nullzeichen oder zu lang.
- [ ] Ergebnis:

### K2 Controller anlegen und testen (je DC)
- **Voraussetzung:** K1.
- **Handlung:** Für jeden DC einen Controller mit FQDN `dc1.example.test` und
  Port 636 anlegen, Priorität nach Plan setzen. Dann **Verbindung testen**.
- **Soll:** Ergebnis `ok`. Der Test hat dabei TLS, Suchkonto-Bind, RootDSE,
  `dnsHostName` = FQDN, gleiche Domäne, schreibbaren DC und Search Base
  geprüft. Unter **Systemstatus** stehen bei diesem Controller SHA-256 und
  Ablauf wie in T2.
- **Bei Abweichung:**

  | Ergebnis | Bedeutung und Vorgehen |
  |---|---|
  | `tls_failed` | wie T2 |
  | `service_bind_rejected` | Suchkonto-Passwort falsch (D8); bei korrektem Passwort und CBT 2 siehe D4 |
  | `service_password_expired` | Suchkonto-Passwort abgelaufen oder muss geändert werden (D8) |
  | `service_account_disabled` | Suchkonto deaktiviert oder abgelaufen (D8) |
  | `service_account_locked` | Suchkonto gesperrt (D8) |
  | `invalid_response` | `dnsHostName` passt nicht zum eingetragenen FQDN; den FQDN aus dem DC-Zertifikat verwenden |
  | `domain_mismatch` | DC gehört zu einer anderen Domäne |
  | `read_only_controller_unsupported` | RODC, entfernen |
  | `search_failed` | Search Base falsch oder nicht lesbar |
  | `timeout` | Netzwerk, oder der DC antwortet nach dem TLS-Handshake nicht |

- [ ] dc1:
- [ ] dc2:

---

## Block N: Negativfälle und CA-Wechsel im laufenden FPM (Portal)

AD-Anmeldung weiter deaktiviert.

Was dieser Block belegt:
- Jeder Verbindungstest prüft zuerst die Zertifikatskette mit der gespeicherten
  CA über PHP/OpenSSL. Erst danach öffnet er die Verbindung über libldap.
- Eine CA, die diesen Test nicht besteht, macht keinen Controller
  einsatzbereit. Bei aktiver AD-Anmeldung wird sie gar nicht gespeichert.
- Deshalb kann libldap im Betrieb nie mit einer ungeprüften CA arbeiten.
- Dass libldap im langlebigen PHP-Prozess die neue CA-Datei verwendet und die
  alte nicht weiterlebt, beweist der hermetische Test
  `DirectoryLdapFixtureTest` (CA-Rotation) für dasselbe Image.
- N1 und N2 belegen vor Ort die Prüfkette, das Zurückwechseln ohne Neustart und
  parallele Tests.

### N1 Fremde CA
- **Voraussetzung:** K2 grün.
- **Handlung:** Fremd-CA erzeugen (ohne Schlüsseldatei):
  ```bash
  openssl req -x509 -newkey rsa:2048 -nodes -keyout /dev/null -out /home/BETRIEBSKONTO/gate0b/fremd-ca.pem -days 2 -subj /CN=Gate0B-Fremd-CA
  ```
  ```bash
  cat /home/BETRIEBSKONTO/gate0b/fremd-ca.pem
  ```
  Im Portal **nur** das CA-Bundle durch `fremd-ca.pem` ersetzen (Passwortfeld
  leer lassen = bleibt), speichern, dc1 testen.
- **Soll:** `tls_failed`. Kein Suchkonto-Bind am DC: Der Breaker bleibt zu, und
  das Suchkonto hat am PDC keinen neuen Fehlversuch (siehe N4-Befehl).
- **Bei Abweichung:** Ergibt der Test `ok`, vertraut der Container einer
  anderen Kette. Das ist ein Sicherheitsbefund: abbrechen und melden.
- [ ] Ergebnis:

### N2 Zurück zur richtigen CA ohne FPM-Neustart
- **Voraussetzung:** N1.
- **Handlung:** CA-Bundle wieder auf `ca.pem` setzen, speichern, alle DCs
  testen. Dann prüfen:
  ```bash
  docker inspect -f '{{.State.StartedAt}}' virtusphere-v2-webapp-php-1
  ```
  Zum Abschluss in zwei Browser-Tabs gleichzeitig **Verbindung testen** für
  dc1 und dc2 auslösen.
- **Soll:** Alle Tests `ok`, die Startzeit ist gleich wie in P6. N1 und N2
  belegen zusammen, dass FPM die neue CA ohne Neustart verwendet und die alte
  nicht weiterlebt. Die parallelen Tests ergeben beide `ok`.
- **Bei Abweichung:** Hilft erst ein FPM-Neustart, ist das ein Befund. Dann
  gilt die Betriebsregel „nach CA-Wechsel `docker compose restart php
  webserver`“; im Ergebnis vermerken.
- [ ] Ergebnis:

### N3 Falscher Name bei vertrauter Kette (optional, braucht DNS-Eintrag)
- **Voraussetzung:** Zustimmung AD-Betrieb für einen befristeten DNS-Eintrag.
- **Handlung (DC):**
  ```powershell
  Add-DnsServerResourceRecordA -ZoneName example.test -Name gate0b-alias -IPv4Address 192.0.2.11
  ```
  **Portal:** T2 mit `gate0b-alias.example.test` ausführen, dann im Portal
  einen Controller `gate0b-alias.example.test` anlegen und testen. Danach den
  Controller löschen.

  **DC:**
  ```powershell
  Remove-DnsServerResourceRecord -ZoneName example.test -RRType A -Name gate0b-alias -Force
  ```
- **Soll:** Probe `tls-fehlgeschlagen`, Portal `tls_failed`.
- **Bei Abweichung:** Ergibt sich `ok`, steht der Alias im Zertifikat. Das ist
  kein Portalfehler, aber zu notieren. Ohne DNS-Eintrag diese Zeile als „durch
  Fixture belegt (`DirectoryLdapFixtureTest`, wrongname)“ markieren.
- [ ] Ergebnis:

### N4 Falsches Suchkonto-Passwort: genau ein Bind, Breaker, kein Fanout
- **Voraussetzung:** K2 grün für mindestens zwei DCs.
- **Handlung (DC, vorher):**
  ```powershell
  Get-ADUser -Identity SUCHKONTO -Server pdc.example.test -Properties badPwdCount, LockedOut | Format-List badPwdCount, LockedOut
  ```
  **Portal:** Suchkonto-Passwort auf einen falschen Wert setzen, speichern,
  dc1 testen. Danach unter **Benutzer > Active Directory** die Benutzersuche
  einmal ausführen.

  **DC, nachher:** derselbe Befehl.

  **Portal:** richtiges Passwort speichern, dc1 und dc2 testen.
- **Soll:**
  - Der Test ergibt `service_bind_rejected`.
  - Die Suche danach startet keinen weiteren Bind; der Systemstatus zeigt
    „Suchkonto abgewiesen“ (Breaker) mit dem Satz zum Grund.
  - `badPwdCount` am PDC ist um genau 1 gestiegen, `LockedOut` ist False.
  - Nach dem richtigen Passwort sind beide Tests `ok` und der Breaker ist frei.
- **Bei Abweichung:** Steigt der Zähler um mehr als 1, gibt es einen Fanout.
  Abbrechen und melden; das Suchkonto gegebenenfalls entsperren lassen
  (`Unlock-ADAccount -Identity SUCHKONTO`).
- [ ] Ergebnis:

### N5 RODC (nur wenn vorhanden)
- **Voraussetzung:** D2 nennt einen RODC.
- **Handlung:** Controller mit dem RODC-FQDN anlegen und testen, danach
  löschen.
- **Soll:** `read_only_controller_unsupported`.
- **Bei Abweichung:** Melden. Ohne RODC: „entfällt“.
- [ ] Ergebnis:

### N6 Abgelaufenes Zertifikat, fehlende EKU, TLS 1.1, stummer DC, andere Domäne
- **Voraussetzung:** keine.
- **Handlung:** Nicht vor Ort erzeugen, weil das Zertifikate, TLS-Einstellungen
  oder AD ändern würde.
- **Soll:** Als „durch Fixture belegt“ markieren:
  - abgelaufen: `DirectoryLdapFixtureTest`, expired;
  - fehlende Server-Authentication-EKU: `DirectoryLdapFixtureTest`, noeku
    (Probe und libldap lehnen ab);
  - nur TLS 1.1: `DirectoryLdapFixtureTest`, tls11 (libldap lehnt ab, die
    Positivkontrolle zeigt, dass der Dienst TLS 1.1 wirklich spricht);
  - DC antwortet nach TLS nicht: `DirectoryLdapFixtureTest` und
    `DirectoryLdapFixtureFailoverTest`, silent (Ende nach der Antwortfrist,
    Wechsel zu dc2 im Budget);
  - andere Domäne: Code `domain_mismatch`.
- **Bei Abweichung:** —
- [ ] Ergebnis:

---

## Block F: Anmeldungen mit Testkonten (Portal und AD-Betrieb)

### F0 Import und Aktivierung nur für Testkonten
- **Voraussetzung:** K2 grün für alle DCs, N4 abgeschlossen.
- **Handlung:** Unter **Benutzer > Active Directory** nach „testkonto“ suchen
  und TESTKONTO1, 3, 4 und 5 mit Rolle **Benutzer** importieren. TESTKONTO2
  ebenfalls versuchen. Danach **AD-Anmeldung aktivieren**.
- **Soll:** Der Import von TESTKONTO2 wird als deaktiviert abgelehnt, die
  anderen sind importiert. Die Aktivierung gelingt ohne Blocker. Keine echten
  Nutzer importieren.
- **Bei Abweichung:** Den Blockertext notieren. Häufig fehlt HTTPS (P4) oder
  ein lokaler Admin (P5). Meldet die Suche `invalid_response`, kann das
  Suchkonto `userAccountControl` oder `pwdLastSet` nicht lesen (D8).
- [ ] Ergebnis:

### F1 Richtige Anmeldung
- **Voraussetzung:** F0. Privates Browserfenster.
- **Handlung:** Quelle **Active Directory** wählen. Unter dem Namensfeld steht
  der Hinweis „Active Directory: Anmeldung mit name@domäne“. Dann
  `testkonto1@example.test` mit richtigem Passwort anmelden.
- **Soll:** Das Portal öffnet, das Konto ist als AD-Konto erkennbar. Das
  Sicherheitsprotokoll zeigt Login `success`, Quelle AD, mit Controller. Im
  Systemstatus stimmt der Zertifikatsablauf je benutztem Controller mit T2
  überein (das Portal liest ihn nach einem Verzeichniskontakt höchstens einmal
  am Tag neu).
- **Bei Abweichung:** Typisierten Code aus dem Protokoll notieren.
  `invalid_response` heißt: Das Suchkonto liest `userAccountControl` oder
  `pwdLastSet` nicht (D8).
- [ ] Ergebnis:

### F2 Schreibweise und Namensform
- **Voraussetzung:** F1, abgemeldet.
- **Handlung (DC, vorher):**
  ```powershell
  Get-ADUser -Identity TESTKONTO1 -Server pdc.example.test -Properties badPwdCount | Format-List badPwdCount
  ```
  **Portal:** `TESTKONTO1@EXAMPLE.TEST` (alles groß) mit richtigem Passwort
  anmelden, abmelden. Dann `TESTKONTO1` (ohne `@`) mit falschem Passwort
  anmelden.

  **DC, nachher:** derselbe Befehl.
- **Soll:** Die Anmeldung in Großbuchstaben klappt (AD vergleicht ohne
  Beachtung der Schreibweise). Der Name ohne `@` ergibt „ungültig“ und
  `badPwdCount` steigt nicht: Das Portal lehnt einen Namen ohne UPN-Form vor
  jedem DC-Kontakt ab.
- **Bei Abweichung:** Großschreibung scheitert: notieren, ein Hinweis für die
  Hilfe. Steigt `badPwdCount` beim Namen ohne `@`, ist das ein Befund.
- [ ] Ergebnis:

### F3 Falsches Passwort: ein Bind, kein zweiter DC, kein Rückfall
- **Voraussetzung:** F1.
- **Handlung (DC, vorher):**
  ```powershell
  Get-ADUser -Identity TESTKONTO1 -Server pdc.example.test -Properties badPwdCount | Format-List badPwdCount
  ```
  **Portal:** `testkonto1@example.test` mit falschem Passwort anmelden
  (einmal).

  **DC:** derselbe Befehl.
- **Soll:** Die Meldung lautet „Benutzername oder Passwort ungültig.“ und keine
  Sitzung entsteht. `badPwdCount` ist um genau 1 gestiegen. Das
  Sicherheitsprotokoll nennt den Grund „Anmeldedaten ungültig“. Eine Anmeldung
  desselben Kontos mit Quelle **Lokal** ergibt ebenfalls „ungültig“.
- **Bei Abweichung:** Ein Anstieg um mehr als 1 bedeutet Fanout: abbrechen und
  melden.
- [ ] Ergebnis:

### F4 Nicht importiertes Konto
- **Voraussetzung:** F0. Ein vorhandenes AD-Konto, das nicht importiert ist
  (TESTKONTO2 ist dafür ungeeignet, weil deaktiviert; ein weiteres Testkonto
  oder ein mit dem AD-Betrieb abgestimmtes Konto verwenden).
- **Handlung:** Mit richtigem Passwort anmelden.
- **Soll:** „ungültig“, keine Sitzung. Am PDC steigt `badPwdCount` nicht, weil
  das Portal ohne Import nicht bindet.
- **Bei Abweichung:** Eine Sitzung ist ein Sicherheitsbefund: abbrechen und
  melden.
- [ ] Ergebnis:

### F5 Lokal deaktiviertes, importiertes Konto
- **Voraussetzung:** F1. TESTKONTO1 ist in einem zweiten privaten Fenster
  angemeldet.
- **Handlung:** Als Admin TESTKONTO1 unter **Benutzer** deaktivieren. Im
  angemeldeten Fenster eine Seite neu laden. Dann mit richtigem Passwort neu
  anmelden. Danach das Konto wieder aktivieren und im ersten Fenster erneut
  neu laden.
- **Soll:** Das Neuladen führt sofort zur Anmeldeseite; im Protokoll steht
  „Sitzung beendet: Portalkonto deaktiviert“. Die neue Anmeldung ergibt
  „ungültig“, keine Sitzung trotz richtigem AD-Passwort. Nach der
  Reaktivierung bleibt die alte Sitzung beendet.
- **Bei Abweichung:** Eine Sitzung ist ein Sicherheitsbefund.
- [ ] Ergebnis:

### F6 Kontozustände (richtiges Passwort)
- **Voraussetzung:** D9, F0.
- **Handlung:** Je mit richtigem Passwort anmelden: TESTKONTO4 (muss Passwort
  ändern) und TESTKONTO5 (abgelaufen).
- **Soll:** Beide „ungültig“, keine Sitzung. Das Sicherheitsprotokoll nennt den
  typisierten Grund: TESTKONTO4 „Passwort muss geändert werden“, TESTKONTO5
  „Konto abgelaufen“. Damit ist die Zuordnung der AD-Unterschlüssel 773 und
  701 am echten DC belegt.
- **Bei Abweichung:** Eine Sitzung ist ein Sicherheitsbefund. Steht nur
  „Anmeldedaten ungültig“, liefert der DC den Unterschlüssel nicht wie
  dokumentiert: notieren und melden, kein Abbruch.
- [ ] Ergebnis:

### F7 Sitzungsende nach AD-Deaktivierung (Revalidierung)
- **Voraussetzung:** F1. TESTKONTO1 ist angemeldet.
- **Handlung (DC):**
  ```powershell
  Disable-ADAccount -Identity TESTKONTO1 -Server dc1.example.test
  ```
  **Portal:** Im angemeldeten Fenster jede Minute eine Seite neu laden,
  höchstens 10 Minuten lang. Nach dem Sitzungsende einmal mit richtigem
  Passwort neu anmelden.

  **DC, danach:**
  ```powershell
  Enable-ADAccount -Identity TESTKONTO1 -Server dc1.example.test
  ```
- **Soll:** Spätestens nach etwa 5 Minuten plus Replikationszeit endet die
  Sitzung und die Anmeldeseite erscheint. Im Protokoll steht „Sitzung beendet:
  Konto im Active Directory deaktiviert“. Die neue Anmeldung ergibt
  „ungültig“, ohne Sitzung. Uhrzeiten notieren.
- **Bei Abweichung:** Läuft die Sitzung nach 10 Minuten weiter, ist das ein
  Befund: Replikation prüfen, dann melden.
- [ ] Ergebnis:

### F8 Sitzungsende nach Passwortänderung im AD
- **Voraussetzung:** TESTKONTO1 wieder aktiv und angemeldet.
- **Handlung (DC):** Passwort von TESTKONTO1 zurücksetzen lassen (neues
  Passwort nur ins Ticket). **Portal:** 10 Minuten wie F7 neu laden.
- **Soll:** Spätestens nach etwa 5 Minuten plus Replikationszeit endet die
  Sitzung. Im Protokoll steht „Sitzung beendet: Passwort im Active Directory
  nach der Anmeldung geändert“. Grundlage ist `pwdLastSet`, das das Portal bei
  der Anmeldung merkt und bei jeder Nachprüfung vergleicht.
- **Bei Abweichung:** Läuft die Sitzung nach 10 Minuten weiter, ist das ein
  Befund (vor N1a: erwartet, nur notieren).
- [ ] Ergebnis:

### F9 Drossel gegen AD-Sperre
- **Voraussetzung:** D7 notiert, TESTKONTO3 importiert.
- **Handlung:** **Portal:** sechsmal hintereinander `testkonto3@example.test`
  mit falschem Passwort anmelden.

  **DC, danach:**
  ```powershell
  Get-ADUser -Identity TESTKONTO3 -Server pdc.example.test -Properties badPwdCount, LockedOut | Format-List badPwdCount, LockedOut
  ```
- **Soll:** Versuche 1 bis 5 ergeben „ungültig“, ab Versuch 6 kommt die
  Drosselmeldung. `badPwdCount` ist 5, nicht 6. `LockedOut` ist False, wenn D7
  passt.
- **Bei Abweichung:** `LockedOut` True heißt: Das Portal kann Konten sperren,
  Befund melden. `badPwdCount` 6 heißt: Die Drossel greift zu spät, ebenfalls
  Befund.
- [ ] Ergebnis:

### F10 Gesperrtes Konto (nur wenn D7 eine Sperre auslöst)
- **Voraussetzung:** F9. `LockedOut` ist True (die Sperrschwelle liegt bei 5
  oder darunter) oder der AD-Betrieb sperrt TESTKONTO3 auf anderem Weg.
- **Handlung:** Nach Ablauf der Portal-Drossel (15 min) TESTKONTO3 mit
  richtigem Passwort anmelden. **Am Ende (DC):**
  ```powershell
  Unlock-ADAccount -Identity TESTKONTO3
  ```
- **Soll:** „ungültig“, keine Sitzung. Das Sicherheitsprotokoll nennt „Konto
  gesperrt“ (AD-Unterschlüssel 775). Eine bestehende Sitzung eines gesperrten
  Kontos endet nach der Nachprüfung mit „Konto im Active Directory gesperrt“;
  das belegt die Fixture (carol), vor Ort nur bei Gelegenheit.
- **Bei Abweichung:** Ohne Sperre: „entfällt (D7 passt)“, durch Fixture
  belegt. Eine Sitzung ist ein Sicherheitsbefund.
- [ ] Ergebnis:

---

## Block G: Ergänzungen seit N1a (Portal)

### G1 Systemstatus nennt typisierte Gründe
- **Voraussetzung:** N4 gelaufen.
- **Handlung:** Unter **Systemstatus** den Bereich Active Directory während N4
  (falsches Suchkonto-Passwort) und danach ansehen.
- **Soll:** Während N4 steht beim Zustand der Satz zum Grund (Suchkonto
  abgewiesen) mit dem Hinweis, was zu tun ist. Nach dem richtigen Passwort ist
  der Satz weg.
- **Bei Abweichung:** Notieren, kein Abbruch.
- [ ] Ergebnis:

### G2 Zertifikatsdaten nach Erneuerung (nur wenn eine Erneuerung ansteht)
- **Voraussetzung:** Der AD-Betrieb erneuert im Prüfzeitraum ein
  DC-Zertifikat, sonst „entfällt“.
- **Handlung:** Nach der Erneuerung T2 für diesen DC wiederholen, dann mit
  TESTKONTO1 anmelden. Am nächsten Tag den Systemstatus ansehen.
- **Soll:** Spätestens einen Tag nach der Erneuerung zeigt der Systemstatus den
  neuen Ablauf wie T2. Bis dahin steht der Controller höchstens auf „veraltet“,
  nicht auf „Gefahr“, solange Anmeldungen klappen. **Verbindung testen** zeigt
  den neuen Wert sofort.
- **Bei Abweichung:** Notieren, kein Abbruch.
- [ ] Ergebnis:

### G3 FPM-Wanduhr im Log
- **Voraussetzung:** P2 zeigt die beiden FPM-Zeilen.
- **Handlung:**
  ```bash
  cd /opt/VirtuSphere-v2-WebApp && docker compose logs --since 6h php | grep -cE "executing too slow|execution timed out"
  ```
- **Soll:** Die Zahl notieren. 0 ist der Normalfall. Jede Meldung „executing
  too slow“ nennt nur das Skript, keinen Inhalt; sie zeigt eine Anfrage über
  30 s.
- **Bei Abweichung:** Meldungen im AD-Prüfzeitraum mit dem Skript `login.php`
  sind ein Befund zur Antwortzeit der DCs.
- [ ] Ergebnis:

---

## Block A: Ausfall und Failover (optional, braucht befristete Firewall-Regel am DC)

### A1 Erster DC nicht erreichbar
- **Voraussetzung:** Zustimmung AD-Betrieb. dc1 hat Priorität 1, dc2
  Priorität 2.
- **Handlung (dc1):**
  ```powershell
  New-NetFirewallRule -DisplayName Gate0B-Block -Direction Inbound -Protocol TCP -LocalPort 636 -RemoteAddress 192.0.2.10 -Action Block
  ```
  **Portal:** `testkonto1@example.test` richtig anmelden und die Zeit stoppen.

  **dc1, danach:**
  ```powershell
  Remove-NetFirewallRule -DisplayName Gate0B-Block
  ```
- **Soll:** Die Anmeldung klappt über dc2 nach höchstens etwa 8 Sekunden. Der
  Systemstatus zeigt dc1 gestört und dc2 ok, das Protokoll nennt dc2 als
  Controller. Eine zweite Anmeldung innerhalb einer Minute geht ohne Wartezeit
  (Cooldown).
- **Bei Abweichung:** Kein Wechsel oder eine Wartezeit über 10 s sind ein
  Befund.
- [ ] Ergebnis:

### A2 Alle DCs nicht erreichbar: Notfall-Admin bleibt nutzbar
- **Voraussetzung:** A1 verstanden, TESTKONTO1 angemeldet.
- **Handlung:** Die Regel aus A1 auf allen DCs anlegen. Im AD-Fenster weiter
  jede Minute neu laden. Parallel als lokaler Notfall-Admin anmelden und eine
  Seite laden. Nach 16 Minuten die Regeln überall entfernen.
- **Soll:**
  - Der Notfall-Admin arbeitet ohne Verzögerung.
  - Die AD-Sitzung läuft in der Schonfrist weiter und endet spätestens nach
    etwa 15 Minuten mit „Active Directory nicht erreichbar“.
  - Eine neue AD-Anmeldung ergibt „Verzeichnisdienst nicht erreichbar“.
  - Nach dem Entfernen der Regeln klappt die AD-Anmeldung wieder.
- **Bei Abweichung:** Hängt der Notfall-Admin, ist das ein Befund nach
  ADR-0039.
- [ ] Ergebnis:

---

## Block E: Abschlussprüfung Logs

### E1 DC-Ereignisse (je DC)
- **Voraussetzung:** D5 aktiv, Blöcke K bis F gelaufen.
- **Handlung:**
  ```powershell
  Get-WinEvent -FilterHashtable @{LogName='Directory Service'; Id=2886,2887,2888,2889,3039,3040,3041,3074,3075; StartTime=(Get-Date).AddHours(-6)} -ErrorAction SilentlyContinue | Format-List TimeCreated, Id, Message
  ```
- **Soll:** Kein Ereignis 2889, 3039, 3074 oder 3075 nennt `192.0.2.10`. 2886
  und 3040/3041 sind allgemeine Hinweise und nicht dem Portal zuzuordnen. Nur
  Ereignis-IDs und Anzahl notieren, keine Meldungstexte.
- **Bei Abweichung:** Ein Ereignis 2889 mit der Portal-IP bedeutet einen
  unsicheren Bind. Das ist ein Sicherheitsbefund: abbrechen und melden.
- [ ] dc1:
- [ ] dc2:

### E2 Diagnose-Logging zurücksetzen (je DC)
- **Voraussetzung:** D5 wurde gesetzt.
- **Handlung:** Den alten Wert aus D5 einsetzen. Im Beispiel 0:
  ```powershell
  Set-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Services\NTDS\Diagnostics' -Name '16 LDAP Interface Events' -Value 0 -Type DWord
  ```
- **Soll:** Der Wert ist wie vor D5.
- [ ] dc1:
- [ ] dc2:

### E3 Keine Geheimnisse in Portal-Logs
- **Voraussetzung:** Blöcke K bis F gelaufen.
- **Handlung:** Das Testpasswort von TESTKONTO1 verdeckt eingeben. Der Befehl
  zeigt nur Dateinamen mit Treffern:
  ```bash
  read -rs PW && grep -rlF -- "$PW" /opt/VirtuSphere-v2-WebApp/Docker/WebAPI/logs /opt/VirtuSphere-v2-WebApp/Docker/logs/nginx; echo "Treffer-Ende"; unset PW
  ```
  Dasselbe mit dem Suchkonto-Passwort.
- **Soll:** Vor `Treffer-Ende` steht keine Datei.
- **Bei Abweichung:** Eine Datei mit Treffer ist ein Sicherheitsbefund. Datei
  nicht weitergeben, melden, und das betroffene Passwort im AD ändern lassen.
- [ ] Ergebnis:

### E4 Protokoll im Portal
- **Voraussetzung:** keine.
- **Handlung:** **Logs > Sicherheit**, Kategorie `directory` und `auth` für
  den Prüfzeitraum ansehen.
- **Soll:** Nur typisierte Ergebnisse und Gründe, kein DN, kein LDAP-Rohtext,
  keine Passwörter. Fehlgeschlagene Anmeldungen nennen den Namen nur bei
  importierten Konten.
- [ ] Ergebnis:

---

## Block R: Aufräumen und Freigabe

### R1 Aufräumen
- [ ] AD-Anmeldung im Portal **deaktivieren**, bis die Freigabe vorliegt.
  Importierte Testkonten im Portal deaktivieren oder löschen.
- [ ] AD-Betrieb deaktiviert oder löscht TESTKONTO1 bis TESTKONTO5 und hebt
  eine Sperre von TESTKONTO3 auf (F9, F10).
- [ ] Firewall-Regeln aus Block A sind entfernt:
  ```powershell
  Get-NetFirewallRule -DisplayName Gate0B-Block -ErrorAction SilentlyContinue
  ```
  Soll: keine Ausgabe.
- [ ] DNS-Eintrag aus N3 ist entfernt.
- [ ] Fremd-CA und Container-Kopie gelöscht:
  ```bash
  rm -f /home/BETRIEBSKONTO/gate0b/fremd-ca.pem
  ```
  ```bash
  cd /opt/VirtuSphere-v2-WebApp && docker compose exec -T php rm -f /tmp/gate0b-ca.pem
  ```
- [ ] `C:\Windows\Temp\gate0b-gpresult.html` auf jedem DC gelöscht.

### R2 DC-Matrix

| DC (nur Kürzel) | Build/Patch (D1) | schreibbar (D2) | Signing (D3) | CBT (D4) | Thumbprint / Ablauf (D6, T2) | TLS-Version (T2) | K2 | Ergebnis |
|---|---|---|---|---|---|---|---|---|
| dc1 | | | | | | | | |
| dc2 | | | | | | | | |

### R3 Freigabe

Gate 0B ist erfüllt, wenn alle diese Bedingungen gelten:
- [ ] P0 bis P7, D1 bis D4, D6, D8, T1, T2, K1, K2, N1, N2, N4, F0 bis F9, G1,
  E1, E3 und E4 sind wie erwartet positiv beziehungsweise wie erwartet
  negativ.
- [ ] Optionale Zeilen (D5, N3, N5, F10, G2, G3, A1, A2) sind erledigt oder
  als „entfällt“ oder „durch Fixture belegt“ vermerkt.
- [ ] Keine Richtlinie und kein Zertifikat wurde zur Umgehung geändert.
- [ ] Der CA-Wechsel im laufenden FPM ist vor Ort belegt (N1, N2, Startzeit
  unverändert) und hermetisch belegt (Hinweis vor N1). Sonst ist die
  Betriebsregel „Neustart nach CA-Wechsel“ vermerkt.
- [ ] Offene Befunde sind an den Nutzer gemeldet, mindestens D7 (Sperrschwelle
  gegen Drossel) und P3 (TLS-Bibliothek).

| Rolle | Name (nur im Ticket) | Datum | Freigabe ja/nein |
|---|---|---|---|
| AD-Betrieb | | | |
| VirtuSphere-Verantwortlicher | | | |

Ergebnis: **OFFEN** bis zur Unterschrift beider Rollen. Danach folgen die
AD-Aktivierung für echte Nutzer und der Pilot.

## Quellen (nur zur Information, vor Ort nicht nötig)

- Microsoft [LDAP channel binding](https://learn.microsoft.com/en-us/windows-server/identity/ad-ds/ldap-channel-binding)
- Microsoft [LDAP signing](https://learn.microsoft.com/en-us/windows-server/identity/ad-ds/ldap-signing)
- Microsoft [ADV190023 Einstellungen](https://learn.microsoft.com/en-us/troubleshoot/windows-server/active-directory/ldap-session-security-settings-requirements-adv190023)
- Microsoft [KB4520412](https://support.microsoft.com/en-us/topic/2020-2023-and-2024-ldap-channel-binding-and-ldap-signing-requirements-for-windows-kb4520412-ef185fb8-00f7-167d-744c-f299a66fc00a)
- Microsoft [LDAPS-Zertifikat](https://learn.microsoft.com/en-us/troubleshoot/windows-server/active-directory/enable-ldap-over-ssl-3rd-certification-authority)
- Microsoft [Get-ADDomain](https://learn.microsoft.com/en-us/powershell/module/activedirectory/get-addomain)
- Microsoft [Get-ADUserResultantPasswordPolicy](https://learn.microsoft.com/en-us/powershell/module/activedirectory/get-aduserresultantpasswordpolicy)
- Broadcom [Common Active Directory LDAP bind errors](https://knowledge.broadcom.com/external/article/51487/common-active-directory-ldap-bind-errors.html)
  (data-Unterschlüssel; eine vollständige Microsoft-Primärquelle gibt es
  nicht, deshalb belegt F6 die Zuordnung am echten DC)
- PHP [ldap_set_option](https://www.php.net/manual/en/function.ldap-set-option.php)
- PHP-FPM [Konfiguration](https://www.php.net/manual/en/install.fpm.configuration.php)
