# HTTPS: Betrieb und Wiederherstellung

Zuständigkeit: Portal-Settings, Tab "HTTPS" (Berechtigung `system.config`).
Entscheidungen: ADR-0012 (Mechanik), ADR-0027 (Schalter, Upload, HSTS).

## Einrichtung

1. Zertifikat bei der Windows-Domänen-CA (AD CS) anfordern, Vorlage
   "Webserver": der FQDN des Portals muss als **SAN** im Zertifikat stehen
   (der CN allein reicht modernen Browsern nicht). Wird das Portal zusätzlich
   per Kurzname oder IP aufgerufen, diese ebenfalls als SAN aufnehmen.
2. Export als PFX/PKCS#12 mit privatem Schlüssel und Passwort; alternativ ein
   PEM-Paar (Zertifikat + Schlüssel).
3. Portal, Tab HTTPS: Datei hochladen (bei PFX das Passwort mitgeben). Die
   Metadaten-Karte zeigt CN, SANs, Aussteller, Gültigkeit und den
   SHA-256-Fingerabdruck; unter 30 Resttagen erscheint ein Warn-Badge.
4. "HTTPS aktivieren": Der Webserver übernimmt das Zertifikat innerhalb
   weniger Sekunden (Watcher-Log im Container: `docker logs
   virtusphere-v2-webapp-webserver-1 | grep init.sh`). Im Browser prüfen:
   `https://<host>:<WEB_HTTPS_PORT>`.
5. Erst danach "Umleitung aktivieren"; HTTP-Portalaufrufe werden nun mit
   301/308 auf HTTPS geleitet. Die Maschinen-Schnittstelle (MECM,
   PowerShell, `db_importMAC.php`, `mecm_report.php`) und `health.php`
   bleiben grundsätzlich auf HTTP erreichbar.
6. HSTS erst aktivieren, wenn HTTPS dauerhaft bleiben soll: Browser merken
   sich die Vorgabe 180 Tage, auch über ein späteres Abschalten hinaus.

Einmalige Host-Voraussetzung (bei Bestandsinstallationen nach dem
WP7-Update): `WEB_HTTPS_PORT` in `.env` setzen und `docker compose up -d`
ausführen (neue Mounts + Portmapping). `Docker/nginx/ssl` und
`Docker/nginx/conf.d` müssen für uid 33 (`www-data`, den PHP-FPM-Worker)
schreibbar sein, z. B. `chown 33:33` auf dem Docker-Host.
Bei den von Compose verwalteten Named Volumes initialisieren PHP- und
nginx-Image diese Verzeichnisse automatisch als `33:0` mit Modus `0770`.
Nur bei Bind-Mounts muss der Betreiber die Host-Rechte selbst passend setzen.

## Erneuerung

Neues Zertifikat einfach über denselben Upload installieren (Überschreiben
wird bestätigt). Ein aktiver HTTPS-Listener übernimmt es beim nächsten
Watcher-Durchlauf; kein Container-Neustart nötig.

## Störungsbilder

- **Portal wirkt nach Redirect-Aktivierung unerreichbar** (Browser vertraut
  dem Zertifikat nicht): HTTP läuft immer weiter. Rückweg über einen anderen
  Browser/Client auf HTTP, oder direkt in der Datenbank:
  `UPDATE deploy_settings SET setting_value='0' WHERE
  setting_key='https_redirect_enabled';`
- **Watcher verweigert eine Änderung**: Container-Log zeigt
  `config change REJECTED by nginx -t` samt nginx-Fehler; es wird weiter die
  letzte gültige Konfiguration ausgeliefert. Ursache beheben (in der Regel
  defektes Material), erneut speichern.
- **`*.conf.bad` in `Docker/nginx/conf.d`**: Boot-Quarantäne; eine beim
  Containerstart defekte generierte Konfiguration wurde beiseitegelegt, HTTP
  kam trotzdem hoch. Die HTTP-zu-HTTPS-Umleitung setzt sich in diesem Zustand
  selbst aus (die generierte Konfiguration ist der Beleg, dass ein Listener
  existiert), und die HTTPS-Karte in den Einstellungen zeigt eine Warnung.
  Nach Behebung die `.bad`-Datei löschen und HTTPS im Portal erneut aktivieren
  (schreibt die Konfiguration frisch); die Umleitung springt von selbst wieder
  an.
- **Quarantäne trifft auf HSTS**: Ein Browser, der die HSTS-Vorgabe schon
  gesehen hat, verweigert HTTP 180 Tage lang von sich aus, egal was der Server
  sendet. Die ausgesetzte Umleitung hilft diesem Browser also nicht; für die
  Wiederherstellung einen frischen Browser, ein privates Fenster oder das
  Löschen der HSTS-Richtlinie nutzen (Chrome: `chrome://net-internals/#hsts`).
- **HSTS-Rollback**: Nach dem Abschalten senden wir den Header nicht mehr,
  Browser halten die Vorgabe aber bis zu 180 Tage. Solange muss HTTPS
  funktionsfähig bleiben oder betroffene Browser müssen die Richtlinie
  manuell löschen (Chrome: `chrome://net-internals/#hsts`).

## Maschinen-API auf HTTPS: was ausgeliefert ist

Dieser Abschnitt beschrieb die Maschinenkette lange als „Ausblick nach E3" und
schickte den Administrator dabei zum falschen Registry-Wert. Beides war unwahr:
die Kette kann TLS, und die Umstellung ist konfigurierbar.

**Was schon vorher da war:** `Get-VsConfig` liest den Registry-Wert `Scheme`, der
Installer bietet `-Scheme https`, und die Client-Skripte haben mit
`Get-VsApiScheme` und ihrer eigenen TLS-Initialisierung genau das richtige Muster.

**Was gefehlt hat und jetzt da ist:**

- **TLS 1.2 in jedem Aufgabenprozess.** Es setzte ausschließlich der *Installer*,
  in seinem eigenen Prozess. Die vier Aufgaben laufen in eigenen Prozessen, und
  unter Windows PowerShell 5.1 ist der Vorgabewert von `SecurityProtocol` je nach
  Windows-Version zu alt. Ein TLS-Portal hätte die vier Aufgaben also mit einem
  Handshake-Fehler stillgelegt, während der Installer grün meldete: sein Probelauf
  bewies etwas, das seine Aufgaben nicht konnten. `Initialize-VsTls` in
  `mecm\VirtuSphere-Common.ps1` (Zwilling der Client-Funktion, ADR-0029) wird
  jetzt von allen vier Aufgaben und vom Installer über dieselbe Funktion gerufen.
- **Der MAC-Rückruf kann TLS.** `Ansible/upload_mac_list.py` hatte kein
  `import ssl` und ein nacktes `urlopen`. Gegen ein selbstsigniertes Zertifikat
  wäre also genau der Kanal gescheitert, der allein über Erfolg oder Misserfolg
  eines Deploys entscheidet, und zwar mit der Meldung „Netzwerkfehler".
- **Ein selbstsigniertes Zertifikat wird über den hinterlegten Fingerabdruck
  vertraut, nicht durch abgeschaltete Prüfung.** Niemand hatte entschieden, wie
  ihm zu vertrauen ist, und „Prüfung aus" auf dem Kanal, der die MAC-Adressen
  trägt, ist schlechter als ehrliches HTTP: es sieht verschlüsselt aus und ist
  von jedem im Netz beantwortbar. Ein Zertifikatswechsel bleibt damit eine
  bewusste Handlung, weil der Abruf fehlschlägt, bis der neue Abdruck eingetragen
  ist.

### Umstellen

1. HTTPS im Portal einschalten (Abschnitte oben).
2. Auf dem MECM-Server:
   `install-VirtuSphere-MECM.ps1 -Scheme https -CertThumbprint <SHA-1 ohne Trennzeichen> ...`.
   Der Fingerabdruck ist der, den `certlm.msc` beim Portal-Zertifikat anzeigt;
   Leerzeichen und Doppelpunkte dürfen mitkopiert werden. Bei einem Zertifikat aus
   einer PKI, der der Server schon vertraut, den Parameter weglassen: dann gilt
   die normale Kettenprüfung, und die ist die stärkere Antwort. Ein Re-Run des
   Installers ohne diese Parameter behält beide Werte.
3. Auf dem Ansible-Host nichts. Der Deploy-Worker trägt den SHA-256-Fingerabdruck
   des Portal-Zertifikats bei jedem Auftrag selbst in `upload_mac_list.py` ein
   (`cert_sha256`), sofern die API-Basis-URL `https://` ist und ein Zertifikat
   installiert ist. Wer der Domänen-CA vertrauen will, legt sie statt dessen in
   den System-Truststore (`/usr/local/share/ca-certificates/` +
   `update-ca-certificates`); dann bleibt der Wert leer und die normale Prüfung
   greift.
4. **API-Basis-URL im Portal auf `https://...` umstellen** (Einstellungen →
   Bereitstellung). Ohne diesen Schritt schreibt der Worker weiterhin eine
   http-Rückrufadresse in jedes Deploy-Artefakt.

Unberührt bleiben in jedem Fall: Worker→Ansible (SSH/SFTP, kein TLS-Bezug)
und Ansible→ESXi (ESXis eigenes API-Zertifikat).
