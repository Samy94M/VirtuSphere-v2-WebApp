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

## Ausblick: Maschinen-API auf HTTPS (nach E3)

Bewusst nicht Teil von WP7 (ADR-0019, Kandidat 5). Wenn die Umstellung bei
E3 entschieden wird, ist sie rein clientseitig:

- **PowerShell/MECM**: Registry-URL (`HKLM:\SOFTWARE\VirtuSphere\MECM`) auf
  `https://` ändern und einmalig `[Net.ServicePointManager]::SecurityProtocol
  = [Net.SecurityProtocolType]::Tls12` ergänzen (PowerShell-5.1-Pflicht).
  Domänengebundene Clients vertrauen der Domänen-CA automatisch; es wird
  kein Zertifikat pro Client verteilt.
- **Ubuntu-Ansible-Host**: Root-CA der Domäne in den System-Truststore
  (`/usr/local/share/ca-certificates/` + `update-ca-certificates`) und die
  URL für `upload_mac_list.py` umstellen; die Python-Standardbibliothek
  prüft Zertifikate standardmäßig korrekt.
- **Server**: nichts; der 8443-Listener bedient die API-Pfade bereits mit.
  Am Ende der Migration entfällt nur der HTTP-Port.

Unberührt bleiben in jedem Fall: Worker→Ansible (SSH/SFTP, kein TLS-Bezug)
und Ansible→ESXi (ESXis eigenes API-Zertifikat).
