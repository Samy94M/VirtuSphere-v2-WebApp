# Offline-Installation auf einem luftspaltgetrennten Host

Dieses Runbook ist der **reale** Installationsweg auf dem Produktionshost: er hat
keinen Internetzugang, also auch keinen Zugriff auf Docker Hub, Packagist, die
Ansible Galaxy oder GitHub. Alles kommt aus dem Offline-Bundle
(`scripts/build-offline-bundle.sh`, auf einer Maschine **mit** Netz gebaut).

**Warum es dieses Dokument gibt:** `docs/INSTALLATION-ANLEITUNG.md` beschreibt
`Docker/scripts/setup.sh`, und das ruft `docker compose build` auf. Die
Basis-Images liegen nicht im Bundle, also kann dieser Aufruf hier nicht
durchlaufen. Der Weg unten baut nichts, er lädt die bereits gehärteten Images.

Verwandte Dokumente: `docs/operations/go-live.md` ist ab Schritt 2 (Migrationen)
identisch und bleibt maßgeblich; dieses Dokument ersetzt nur, wie Code und Images
auf den Host kommen und wie der Stack startet.

Zwei Hosts: Schritt 1 bis 3 (erster Teil) und 5 bis 7 laufen auf dem
**Docker-Host**, Schritt 3 („Auf dem Ansible-Host“) und 4 auf dem
**Ansible-Host**. Ist das eine eigene Maschine, das Bundle dorthin ebenfalls
übertragen und dort zuerst Schritt 1 ausführen. Die Gesamtreihenfolge beider
Hosts steht im Wegweiser von
[go-live.md](go-live.md#wegweiser-docker-host-und-ansible-host).

## Voraussetzungen auf dem Host

| Braucht | Prüfen mit |
|---|---|
| Docker Engine mit Compose-V2-Plugin | `docker compose version` |
| `sha256sum`, `tar`, `gunzip` | Basiswerkzeuge, überall vorhanden |
| Bundle-Verzeichnis übertragen (USB, internes Gitea) | `ls` |

Kein `git`, kein `composer`, kein `pip` und kein `ansible-galaxy` mit Netz.

## Schritt 1: Bundle prüfen

```bash
cd <bundle-verzeichnis>
sh verify.sh
```

Prüft jede Datei gegen `SHA256SUMS`. Bricht das ab, ist die Übertragung
unvollständig: **nicht** weitermachen, neu übertragen. `provenance.json` nennt
den Commit, aus dem das Bundle gebaut wurde; dieser Wert gehört ins
Abnahmeprotokoll, denn er ist die einzige Angabe, welcher Stand hier läuft.

## Schritt 2: Images laden (nicht bauen)

```bash
for f in images/*.tar.gz; do gunzip -c "$f" | docker load; done
docker images
```

Danach prüfen: `docker images` muss **jedes** geladene Image mit Namen **und** Tag
zeigen. Ein namenloses Image (`<none>:<none>`) ist hier kein normaler Nebeneffekt,
sondern ein Befund - das Bundle bricht seinen Build bereits ab, wenn ein Archiv
keinen Tag trägt.

**Kein `docker image prune`.** Die eben geladenen Images sind noch von keinem
Container referenziert; `prune` würde genau sie entfernen, und auf diesem Host
gibt es keinen Weg, sie zurückzuholen.

Warum das ein eigener Absatz ist: `docker load` stellt **keinen RepoDigest**
wieder her. Deshalb referenziert Compose die gehärteten PHP-, MySQL- und
phpMyAdmin-Child-Images über feste lokale Tags, die `docker save`/`docker load`
erhält. Ihre Upstream-Basen bleiben in den Dockerfiles per Digest gepinnt; auf
dem Air-Gap-Host hängt die Integrität der fertigen Images an der Prüfsumme aus
Schritt 1. Der Bundle-Build beweist vor der Ausgabe, dass jeder Kern-Tag in
`images.txt` liegt. Das optionale phpMyAdmin-Image steht ausschließlich in
`tools/images.txt` und wird durch den Kernweg weder geladen noch gestartet.

## Schritt 3: Quellcode und Abhängigkeiten entpacken

```bash
mkdir -p virtusphere && tar xzf source.tar.gz -C virtusphere
tar xzf deps/vendor.tar.gz -C virtusphere/Docker/WebAPI
```

Beide Befehle als das Konto ausführen, das den Projektordner später betreibt,
**nicht mit `sudo`**: Als `root` entpackte Dateien gehören `root`, und jedes
spätere Update scheitert dann an genau diesen Ordnern.

`source.tar.gz` enthält zwar das versionierte `vendor/`, aber mit den
Entwicklungspaketen. `deps/vendor.tar.gz` ist der Produktionsstand
(`composer install --no-dev`) und wird darüber entpackt, weil `composer install`
hier keinen Packagist erreicht. Das gilt nur für eine Installation aus dem
Archiv. Kommt der Code als Git-Checkout auf den Host, bringt der Checkout
`vendor/` mit, und `deps/vendor.tar.gz` wird nicht darüber entpackt: Es würde
versionierte Dateien verändern und das nächste `git pull` blockieren.

### Auf dem Ansible-Host

Zuerst die Python-Voraussetzungen aus dem Wheelhouse
des Bundles installieren (ansible-core, pyvmomi, requests; die Versionen pinnt
`deps/requirements-ansible-host.txt` auf den QA-geprüften Stand). Das
Wheelhouse zielt auf glibc/x86_64 mit Python 3.12, also Ubuntu 24.04;
ansible-core in der gepinnten Version verlangt ohnehin Python 3.11 oder neuer.
Danach die Collections, ebenfalls offline:

```bash
python3 -m pip install --no-index --find-links deps/wheels -r deps/requirements-ansible-host.txt
ansible-galaxy collection install collections/*.tar.gz
```

Vor dem Wheelhouse hieß „BEFORE the network is air-gapped" in
`Ansible/requirements.yml` faktisch „vorher im Netz installieren“; für einen
frisch aufzusetzenden Air-Gap-Host war das ein Widerspruch in sich.

Danach den Aufräum-Timer einmal als root installieren. Er liegt im Bundle unter
`virtusphere/Ansible/host-cleanup/`; die Installation braucht weder Netz noch Paket:

```bash
sudo sh virtusphere/Ansible/host-cleanup/install-secret-cleanup.sh <ansible-konto>
```

`<ansible-konto>` ist das Linux-Konto des Ansible-Zugangs, also der
Benutzername, den der Ansible-Zugang im Portal unter **Zugangsdaten** trägt,
nicht das eigene Anmeldekonto. Was der Timer tut und warum er empfohlen ist, steht in
[Go-Live, Abschnitt Ansible-Host vorbereiten](go-live.md#ansible-host-vorbereiten-vor-schritt-4).

Der Host-Schlüssel des Ansible-Hosts wird im Portal bestätigt. Dafür liegt im Bundle unter `virtusphere/Ansible/host-identity/` das Werkzeug `virtusphere-host-identity.sh`; es liest nur die öffentlichen Host-Schlüssel dieses Hosts, schreibt nichts und braucht kein Netz. Es gibt die Zeile je Schlüssel aus, die das Portal akzeptiert; Ablauf und Grenzen stehen in [Verbindungen und Vertrauensanker](trust-flows.md#betrieb).

## Schritt 4: deaktivierten Durable Runner installieren und prüfen (Ansible-Host)

Das Bundle enthält unter `runner/` den in Etappe 8R-O entwickelten Runner. Der
Installer prüft dessen eigenes `SHA256SUMS`, kopiert den geschlossenen Dateisatz
nach `~/.local/libexec/virtusphere` und legt den Zustands-Root mit Modus `0700`
an. Er überschreibt keine bestehende Installation, ändert keine Privilegien und
aktiviert insbesondere kein Linger:

```bash
python3 runner/virtusphere_remote_install.py runner
```

Der anschließende Preflight ist rein lesend. `<standortfreigabe-bytes>` ist
kein Produktdefault: Der Wert muss aus der freigegebenen Kapazitätsmessung des
echten Hosts stammen.

```bash
python3 ~/.local/libexec/virtusphere/virtusphere_remote_preflight.py \
  --required-free-bytes <standortfreigabe-bytes> > 8r-site-preflight.json
```

Ein Exitcode ungleich null oder `"ready": false` ist ein Abbruch der
Standortabnahme. Das JSON enthält einen gehashten Hostfingerprint und keine
Credentials. Es aktiviert aus sich selbst keinen Modus; ohne den noch offenen,
revisions- und bundlegebundenen 8R-S-Import bleibt jeder neue Remote-Modus
`disabled`. Auf Entwicklungs- oder Ersatzhosts erzeugte Ergebnisse sind keine
Standortevidenz.

`create` und `full` laufen unabhängig davon weiterhin über den bestehenden
SSH-Weg, seit Etappe 14B aber mit einem Aufruf je VM. Dafür gilt offline dasselbe
wie sonst: Es wird zur Laufzeit nichts nachgeladen. `ansible-core`, `pyvmomi`,
`requests` und der vollständige Collectionstand kommen als gepinnte Wheels
beziehungsweise Collectionarchive aus `deps/wheels` und `collections/` des
Bundles. `Ansible/requirements.yml` besitzt direkte und transitive Pins
gemeinsam; das QA-Gate vergleicht deren rekursive `MANIFEST.json`-Abhängigkeiten
mit dem Lock. Der Host-Preflight vergleicht anschließend jede installierte
Collection mit ihrem Pin und den installierten Kern mit den Anforderungen aller
Collections. Eine fehlende, zusätzliche ungepinnte Abhängigkeit oder abweichende
Version wird abgelehnt, nicht stillschweigend verwendet.

Ein kurzer Selbsttest der Async-Naht ohne ESXi lohnt sich hier, weil sie das
einzige Stück des Create-Wegs ist, das vom Host und nicht von VirtuSphere abhängt:
Ein `command`-Aufruf mit `async` und `poll: 0` muss eine Job-ID hinterlassen, die
eine spätere, eigene Abfrage im selben Verzeichnis wiederfindet. Findet sie nichts,
fehlt dem Konto das Schreibrecht im Heimatverzeichnis oder `/tmp` wird je Sitzung
neu eingehängt; beides macht einen unterbrochenen Create-Auftrag unauflösbar.

## Schritt 5: `.env` anlegen

Vorlage ist `virtusphere/.env.example`:

```bash
cp virtusphere/.env.example virtusphere/.env
```

Die geladenen Runtime-Tags stehen bereits fest in `docker-compose.yml`; es gibt
keinen Image-Override, der auf dem Air-Gap-Host versehentlich einen Pull auslösen
kann. In `.env` dann zwingend selbst setzen:

| Schlüssel | Warum |
|---|---|
| `APP_KEY` | EnvBoot bricht ohne oder mit schwachem Wert hart ab |
| `DB_PASS` | EnvBoot und der MySQL-Bootstrap lehnen einen fehlenden oder schwachen Wert ab |
| `MYSQL_ROOT_PASSWORD` | ausschließlich MySQL/Host-Backup; der MySQL-Bootstrap lehnt einen fehlenden oder schwachen Wert vor dem Serverstart ab, PHP und Worker erhalten ihn nicht |
| `APP_BIND_IP` | die LAN-Adresse des Hosts (oder `0.0.0.0`). Der Vorlagenwert `127.0.0.1` bindet nur an Loopback: der Stack ist gesund, jede hostlokale Probe antwortet, und aus dem LAN ist das Portal trotzdem nicht erreichbar |
| `APP_PUBLIC_BASE_URL` | die Rückrufadresse, die der Ansible-Host für die MAC-Meldung benutzt. Ohne auflösbaren Namen die IP eintragen, sonst läuft der Deploy sauber durch und die MACs kommen nie an |

`openssl` steht auf dem Host meist zur Verfügung (`openssl rand -base64 32`);
falls nicht, die Werte auf der Build-Maschine erzeugen und mit übertragen.

`Docker/scripts/setup.sh` wird hier **nicht** ausgeführt: es baut.

## Schritt 6: Log-Verzeichnisse und Host-Eigenheiten

Vor dem ersten Start (siehe `docs/operations/go-live.md`, Schritt 1a, dort mit
Begründung):

```bash
chmod 0777 virtusphere/Docker/WebAPI/logs
```

Auf einem echten Linux-Host außerdem eine lokale `docker-compose.override.yml`
für das Netz-Subnetz und die Proxy-Variablen (Begründung und Inhalt in
`docs/operations/go-live.md`, Schritt 1a). Diese Datei ist gitignored und liegt
deshalb **nicht** im Bundle: sie beschreibt diesen einen Host, nicht das Produkt.
Sobald sie einmal existiert, nimmt `scripts/backup.sh` sie ins Config-Archiv auf,
und der Restore-Drill prüft, dass der archivierte Compose-Satz auflösbar ist. Beim
**ersten** Aufbau eines Hosts gibt es sie noch nicht, also hier von Hand anlegen.

## Schritt 7: Stack starten und Migrationen

```bash
cd virtusphere
docker compose config --quiet
docker compose up -d --wait
docker compose exec -T php php /var/www/html/lib/migrate.php --check
docker compose exec -T php php /var/www/html/lib/migrate.php
```

`--wait` kehrt erst zurück, wenn jeder Healthcheck grün ist. Kommt der Stack
nicht hoch, zuerst Schritt 6 prüfen.

## Schritt 8: Weiter im Go-Live-Runbook

Ab hier ist nichts mehr offline-spezifisch. `docs/operations/go-live.md` führt
weiter: Backup einrichten (Schritt 3), **erstes Admin-Konto anlegen und das
Portal im Browser öffnen** (Schritt 4.1 und 4.2), IP-Freigaben füllen, MECM
anbinden.

## Optionales phpMyAdmin-Werkzeug

Der normale Installationsweg oben ist vollständig ohne phpMyAdmin. Erst ein
bewusster Werkzeugaufruf prüft das eigene Teilmanifest, lädt dessen einziges
Image und startet das weiterhin loopbackgebundene `tools`-Profil:

```bash
cd <bundle-verzeichnis>
sh tools/install.sh
```

Bei einem neuen Bundle denselben Befehl erneut ausführen; das neue Teilmanifest
und Image werden vor dem Ersetzen geprüft. Entfernen stoppt und löscht nur den
Werkzeugcontainer, nicht MySQL oder das Portal:

```bash
cd virtusphere
docker compose --profile tools rm -sf phpmyadmin
```

## Releases nachziehen

Vor jedem Update gilt zusätzlich die ausführbare Matrix und Wartungsfolge in
[`upgrade-recovery.md`](upgrade-recovery.md). Ein verifiziertes Bundle allein
autorisiert noch keine gemischten Portal-/Schema-/Workerstände.

Ein Update folgt dem [Update-Runbook](update.md), nicht den Schritten dieses
Dokuments. Es unterscheidet sich an mehreren Stellen von der Erstinstallation:
Backup und Restore-Drill kommen zuerst; die laufenden Images werden vor dem
Laden gesichert, weil `docker load` ihre Namen auf die neuen Images umhängt;
Worker und Webserver werden vor dem Quellwechsel gestoppt; Dateien, die das neue
Release entfernt hat, müssen nach dem Entpacken über den alten Baum gelöscht
werden; migriert wird vor dem Start der Worker. Ein `docker compose up -d --wait`
vor der Migration, wie in Schritt 7 der Erstinstallation, würde die neuen Worker
gegen das alte Schema starten.

Der Runner-Installer ist bewusst kein In-place-Updater. Solange 8R-S offen ist,
nutzt kein Produktivjob diese Installation. Ein späterer Runnerwechsel gehört
in dasselbe kontrollierte, revisionsgebundene 8R-S-Fenster wie Claim-Pause,
Drain, Backup, Austausch und Rückbauprobe; dieses Runbook löscht oder
überschreibt dafür keinen laufzeitrelevanten Bestand auf Verdacht.
