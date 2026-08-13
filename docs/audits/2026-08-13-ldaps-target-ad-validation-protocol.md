# LDAPS-/AD-Zielabnahmeprotokoll

Stand: 2026-08-13
Status: verpflichtendes, noch offenes Gate 0B für die Zielumgebung

Dieses Protokoll entscheidet nur, ob die vorgesehene VirtuSphere-
Authentisierung mit der konkreten AD-DS-Umgebung kompatibel ist. Es ändert
keine Gruppenrichtlinie, kein Zertifikat und kein Benutzerkonto. Ohne ein
vollständig positives Ergebnis bleiben AD-Aktivierung, Pilotlogin und Release
gesperrt. Lokale Portalbenutzer bleiben davon unberührt.

## Beweisregeln

- Keine Kennwörter, privaten Schlüssel, LDAP-Diagnosetexte, vollständigen DNs
  oder exportierten Registry-Inhalte in dieses Dokument kopieren.
- Erlaubte Evidenz sind Hostname, Betriebssystem-Build, Policybezeichnung und
  effektiver Wert, Zertifikatsseriennummer/-SHA-256/-Ablaufdatum, Zeitstempel,
  typisiertes Ergebnis und freigegebene Ticket-/Screenshot-Referenzen.
- Jeder vorgesehene schreibbare DC wird einzeln geprüft. Ein Erfolg über einen
  Load Balancer oder nur einen DC genügt nicht.
- Die Prüfung erfolgt aus dem tatsächlich vorgesehenen PHP-FPM-Container und
  Netzwerksegment. Ein Test nur von einem Administrator-PC beweist den
  Produktpfad nicht.
- Fehler führen nicht zu einer automatischen Policyabschwächung. Ursache und
  Zielkonfiguration werden zuerst mit dem AD-Betrieb geklärt.

## Stammdaten

| Feld | Nachweis |
|---|---|
| Umgebung/Ticket | offen |
| Prüfer und Vier-Augen-Freigabe | offen |
| PHP-Image-Digest und PHP-Version | offen |
| OpenLDAP-Version im Image | offen |
| AD-DNS-Domäne | offen |
| Geplanter Search Base | offen |
| AD-Suchkonto (nur Bezeichner, kein DN/Secret) | offen |
| Portal-HTTPS geprüft | offen |
| Lokaler Notfalladmin geprüft | offen |

## DC-Matrix

Für jeden DC eine Zeile anlegen.

| DC-FQDN | Windows-Build/Patchstand | schreibbar | Signing effektiv | CBT effektiv | Zertifikat SHA-256/Ablauf | Ergebnis |
|---|---|---:|---|---|---|---|
| offen | offen | offen | offen | offen | offen | offen |

Zertifikatskriterien je DC:

- vollständige Vertrauenskette aus dem importierten CA-Bundle;
- Server-Authentication-EKU;
- DC-FQDN in SAN beziehungsweise im von AD DS unterstützten Namensfeld;
- aktuell gültig, nicht selbstsigniert als ungeprüfte Ausnahme;
- Port 636 liefert genau den erwarteten Endpunkt;
- RootDSE-`dnsHostName` entspricht dem konfigurierten FQDN;
- identischer `defaultNamingContext` auf allen DCs;
- `msDS-isRODC=FALSE`.

## Policy- und Bind-Prüfung

Der AD-Betrieb erfasst den effektiven, nicht nur den konfigurierten Wert für:

1. Domain controller: LDAP server signing requirements;
2. Domain controller: LDAP server channel binding token requirements;
3. relevante Ereignisse 2886 bis 2889 und CBT-Ereignisse 3039 bis 3041 im
   vereinbarten Prüfzeitfenster;
4. `AvoidPdcOnWan`, falls die Topologie/WAN-Nutzung betroffen ist.

Microsoft dokumentiert für TLS-geschützte Simple Binds einerseits fehlende
CBT-Information und andererseits ältere Empfehlungen, solche Clients bei
`Always` abzulehnen. Diese Dokumentations-/Produktgrenze wird nicht aus dem
Plan abgeleitet, sondern mit dem gepatchten Ziel-AD praktisch bewiesen. Ein
erfolgreicher Search-Account-Bind und Benutzer-Bind ist für jede vorgesehene
effektive Policykombination erforderlich.

## Funktionale Negativ- und Failovertests

Alle Ergebnisse werden nur mit den typisierten VirtuSphere-Codes erfasst.

| Fall | Erwartung | Nachweis |
|---|---|---|
| richtige CA, richtiger FQDN, gültiges Suchkonto | erfolgreich | offen |
| falsche CA | TLS-Abbruch vor Bind | offen |
| falscher Hostname bei sonst vertrauter Kette | TLS-Abbruch | offen |
| abgelaufenes/nicht gültiges Zertifikat | TLS-Abbruch | offen |
| Zertifikat ohne Server-Authentication-EKU | TLS-Abbruch | offen |
| falsches Suchkonto-Kennwort | ein Bind, revisionsweiter Circuit Breaker, kein DC-Fanout | offen |
| falsches Benutzerkennwort | kein zweiter DC-Bind, keine lokale Fallback-Anmeldung | offen |
| nicht importierter Benutzer | keine Session | offen |
| deaktivierter AD-Benutzer | keine Session | offen |
| deaktivierter lokaler Autorisierungssatz | keine Session trotz erfolgreichem AD-Bind | offen |
| erster DC technisch nicht erreichbar | kontrollierter Wechsel zum nächsten validierten DC | offen |
| alle DCs im Cooldown | genau ein Recovery-Versuch | offen |
| RODC | als nicht unterstützt abgelehnt | offen |
| anderer `defaultNamingContext` | Domänenkonflikt, keine Aktivierung | offen |

## Langlebiger FPM- und CA-Rotationstest

1. PHP-FPM mit CA-Bundle A starten und beide Requests über denselben
   langlebigen Worker soweit steuerbar nachweisen.
2. LDAPS-Verbindung mit A erfolgreich öffnen.
3. Auf ein unabhängig erzeugtes CA-Bundle/Zertifikat B rotieren, ohne das alte
   Bundle als zusätzliche Vertrauenskette beizubehalten.
4. Einen neuen Request im laufenden FPM-Prozess ausführen. Er muss B verwenden;
   A darf nicht als stiller globaler OpenLDAP-Zustand fortleben.
5. Parallele Requests mit Revision A/B während des Wechsels ausführen. Kein
   Request darf CA-Dateiinhalt, Pfad oder TLS-Optionen des anderen vermischen.
6. Erst nach positivem Ergebnis das notwendige Betriebsverhalten festlegen:
   atomarer Laufzeitwechsel allein oder kontrollierter FPM-Reload.

## Freigabe

Gate 0B ist nur erfüllt, wenn alle Matrixzeilen positiv beziehungsweise wie
erwartet negativ sind, keine Richtlinie abgeschwächt wurde, die CA-Rotation im
langfristigen FPM bewiesen ist und AD-Betrieb plus VirtuSphere-Verantwortlicher
die Evidenz freigegeben haben.

Ergebnis: **OFFEN - AD-Aktivierung gesperrt**

Primärquellen:

- [Microsoft: LDAP signing verwalten](https://learn.microsoft.com/en-us/windows-server/identity/manage-ldap-signing-group-policy)
- [Microsoft: LDAPS-Zertifikate konfigurieren](https://learn.microsoft.com/en-us/windows-server/identity/ad-ds/configure-ldap-signing-certificates)
- [Microsoft: LDAP session security nach ADV190023](https://learn.microsoft.com/en-us/troubleshoot/windows-server/active-directory/ldap-session-security-settings-requirements-adv190023)
- [Microsoft: Channel-Binding-Policy](https://learn.microsoft.com/en-us/previous-versions/windows/it-pro/windows-10/security/threat-protection/security-policy-settings/domain-controller-ldap-server-channel-binding-token-requirements)
- [PHP: ldap_set_option](https://www.php.net/manual/en/function.ldap-set-option.php)
- [PHP: ldap_connect](https://www.php.net/manual/en/function.ldap-connect.php)
