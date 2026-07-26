# Offline-Installation auf einem luftspaltgetrennten Host

Dieses Runbook ist der **reale** Installationsweg auf dem Produktionshost: er hat
keinen Internetzugang, also auch keinen Zugriff auf Docker Hub, Packagist, die
Ansible Galaxy oder GitHub. Alles kommt aus dem Offline-Bundle
(`scripts/build-offline-bundle.sh`, auf einer Maschine **mit** Netz gebaut).

**Warum es dieses Dokument gibt:** `docs/INSTALLATION-ANLEITUNG.md` beschreibt
`Docker/scripts/setup.sh`, und das ruft `docker compose build` auf. Die
Basis-Images sind Digest-gepinnt und liegen nicht im Bundle, also kann dieser
Aufruf hier nicht durchlaufen. Der Weg unten baut nichts, er lädt fertige Images.

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

`docker load` bringt die Images mit genau den Tags mit, die `docker-compose.yml`
erwartet, deshalb muss anschließend nichts gebaut und nichts gezogen werden.

Das Laden kann pro Image ein zusätzliches namenloses Image (`<none>:<none>`)
hinterlassen, wenn eine Zwischenschicht keinen Tag trägt. Das ist normal und
kostet nur Plattenplatz; `docker image prune` räumt es auf, **nachdem** der Stack
läuft (vorher nicht: es entfernt auch, was noch nicht referenziert ist).

## Schritt 3: Quellcode und Abhängigkeiten entpacken

```bash
mkdir -p virtusphere && tar xzf source.tar.gz -C virtusphere
tar xzf deps/vendor.tar.gz -C virtusphere/Docker/WebAPI
```

`vendor/` ist bewusst nicht im Git-Archiv: es kommt aus dem Bundle, weil
`composer install` hier keinen Packagist erreicht. Ohne diesen Schritt startet
PHP mit „vendor/autoload.php missing".

Auf dem **Ansible-Host** zusätzlich die Collections offline installieren:

```bash
ansible-galaxy collection install collections/*.tar.gz
```

## Schritt 4: `.env` anlegen

Vorlage ist `virtusphere/.env.example`. Zwingend selbst setzen:

| Schlüssel | Warum |
|---|---|
| `APP_KEY` | EnvBoot bricht ohne oder mit schwachem Wert hart ab |
| `DB_PASS`, `MYSQL_ROOT_PASSWORD` | dito |
| `APP_BIND_IP` | die LAN-Adresse des Hosts (oder `0.0.0.0`). Der Vorlagenwert `127.0.0.1` bindet nur an Loopback: der Stack ist gesund, jede hostlokale Probe antwortet, und aus dem LAN ist das Portal trotzdem nicht erreichbar |
| `APP_PUBLIC_BASE_URL` | die Rückrufadresse, die der Ansible-Host für die MAC-Meldung benutzt. Ohne auflösbaren Namen die IP eintragen, sonst läuft der Deploy sauber durch und die MACs kommen nie an |

`openssl` steht auf dem Host meist zur Verfügung (`openssl rand -base64 32`);
falls nicht, die Werte auf der Build-Maschine erzeugen und mit übertragen.

`Docker/scripts/setup.sh` wird hier **nicht** ausgeführt: es baut.

## Schritt 5: Log-Verzeichnisse und Host-Eigenheiten

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

## Schritt 6: Stack starten und Migrationen

```bash
cd virtusphere
docker compose config --quiet
docker compose up -d --wait
docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/migrate.php --check
docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/migrate.php
```

`--wait` kehrt erst zurück, wenn jeder Healthcheck grün ist. Kommt der Stack
nicht hoch, zuerst Schritt 5 prüfen.

## Schritt 7: Weiter im Go-Live-Runbook

Ab hier ist nichts mehr offline-spezifisch. `docs/operations/go-live.md` führt
weiter: Backup einrichten (Schritt 3), **erstes Admin-Konto anlegen und das
Portal im Browser öffnen** (Schritt 4.1 und 4.2), IP-Freigaben füllen, MECM
anbinden.

## Releases nachziehen

Ein Update ist derselbe Weg ohne Schritt 4 und 5: neues Bundle prüfen, Images
laden, Quellcode über den bestehenden Baum entpacken (die `.env` und die
Override-Datei liegen außerhalb des Archivs und bleiben), dann
`docker compose up -d --wait` und die Migrationen. Vorher ein Backup
(`sh scripts/backup.sh`), denn Migrationen laufen nur vorwärts.
