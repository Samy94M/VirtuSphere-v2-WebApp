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
wieder her. Deshalb referenziert Compose die gehärteten MySQL- und
phpMyAdmin-Child-Images über feste lokale Tags, die `docker save`/`docker load`
erhält. Ihre Upstream-Basen bleiben in den Dockerfiles per Digest gepinnt; auf
dem Air-Gap-Host hängt die Integrität der fertigen Images an der Prüfsumme aus
Schritt 1. Der Bundle-Build beweist vor der Ausgabe, dass jeder von Compose
aufgelöste Tag tatsächlich in `images.txt` und einem Image-Archiv liegt.

## Schritt 3: Quellcode und Abhängigkeiten entpacken

```bash
mkdir -p virtusphere && tar xzf source.tar.gz -C virtusphere
tar xzf deps/vendor.tar.gz -C virtusphere/Docker/WebAPI
```

`vendor/` ist bewusst nicht im Git-Archiv: es kommt aus dem Bundle, weil
`composer install` hier keinen Packagist erreicht. Ohne diesen Schritt startet
PHP mit „vendor/autoload.php missing".

Auf dem **Ansible-Host** zuerst die Python-Voraussetzungen aus dem Wheelhouse
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

## Schritt 4: deaktivierten Durable Runner installieren und prüfen

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
Standortevidenz. `create` und `full` bleiben unabhängig davon bis Etappe 14B
gesperrt.

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
| `DB_PASS`, `MYSQL_ROOT_PASSWORD` | dito |
| `APP_BIND_IP` | die LAN-Adresse des Hosts (oder `0.0.0.0`). Der Vorlagenwert `127.0.0.1` bindet nur an Loopback: der Stack ist gesund, jede hostlokale Probe antwortet, und aus dem LAN ist das Portal trotzdem nicht erreichbar |
| `APP_PUBLIC_BASE_URL` | die Rückrufadresse, die der Ansible-Host für die MAC-Meldung benutzt. Ohne auflösbaren Namen die IP eintragen, sonst läuft der Deploy sauber durch und die MACs kommen nie an |

`openssl` steht auf dem Host meist zur Verfügung (`openssl rand -base64 32`);
falls nicht, die Werte auf der Build-Maschine erzeugen und mit übertragen.

`Docker/scripts/setup.sh` wird hier **nicht** ausgeführt: es baut.

## Schritt 6: Log-Verzeichnisse und Host-Eigenheiten

Vor dem ersten Start (siehe `docs/operations/go-live.md`, Schritt 1a, dort mit
Begründung):

```bash
chmod 0777 virtusphere/Docker/WebAPI/logs virtusphere/Docker/logs/nginx
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
docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/migrate.php --check
docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/migrate.php
```

`--wait` kehrt erst zurück, wenn jeder Healthcheck grün ist. Kommt der Stack
nicht hoch, zuerst Schritt 6 prüfen.

## Schritt 8: Weiter im Go-Live-Runbook

Ab hier ist nichts mehr offline-spezifisch. `docs/operations/go-live.md` führt
weiter: Backup einrichten (Schritt 3), **erstes Admin-Konto anlegen und das
Portal im Browser öffnen** (Schritt 4.1 und 4.2), IP-Freigaben füllen, MECM
anbinden.

## Releases nachziehen

Ein Update ist derselbe Weg ohne die erstmalige Runner-, `.env`- und
Hosteinrichtung aus Schritt 4 bis 6: neues Bundle prüfen, Images
laden, Quellcode über den bestehenden Baum entpacken (die `.env` und die
Override-Datei liegen außerhalb des Archivs und bleiben), dann
`docker compose up -d --wait` und die Migrationen. Vorher ein Backup
(`sh scripts/backup.sh`), denn Migrationen laufen nur vorwärts.

Der Runner-Installer ist bewusst kein In-place-Updater. Solange 8R-S offen ist,
nutzt kein Produktivjob diese Installation. Ein späterer Runnerwechsel gehört
in dasselbe kontrollierte, revisionsgebundene 8R-S-Fenster wie Claim-Pause,
Drain, Backup, Austausch und Rückbauprobe; dieses Runbook löscht oder
überschreibt dafür keinen laufzeitrelevanten Bestand auf Verdacht.
