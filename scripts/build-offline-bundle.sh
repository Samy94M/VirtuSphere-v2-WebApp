#!/bin/sh
# Offline-Release-Bundle (AP8, Plan v2): baut aus dem lokalen Stand ein
# vollstaendig offline verifizier- und installierbares Artefakt:
#
#   images/         docker save der Runtime-Images (per Image-ID dedupliziert)
#   images.txt      Ref -> Tag -> Image-ID -> RepoDigest je gespeichertem Image
#   .env.offline-images  MYSQL_IMAGE/PMA_IMAGE auf die geladenen Tags (docker load
#                   stellt keinen RepoDigest her, also ist der Digest-Pin auf dem
#                   Zielhost nicht aufloesbar)
#   deps/           vendor.tar.gz (composer install --no-dev, im PHP-Image)
#   collections/    ansible-galaxy collection download (Air-Gap-Ansible-Host)
#   sbom/           SPDX-SBOM je Image (trivy, Digest-Pin aus tool-lock.json)
#   reports/        CVE-Bericht je Image; offene Critical/High brechen den
#                   Build (.trivyignore.yaml ist der befristete Ausnahme-Vertrag)
#   source.tar.gz   git archive HEAD
#   provenance.json Commit, Dirty-Flag, Zeit, Toolversionen (keine Secrets)
#   INSTALL.md      Offline-Installationsschritte
#   verify.sh       prueft SHA256SUMS vollstaendig offline
#   SHA256SUMS      Manifest ueber jede Datei des Bundles
#
# Der Build-Host braucht Netz (composer, Galaxy, trivy-DB) und Docker; das
# Zielsystem braucht fuer die Verifikation nur sha256sum.
#
# Aufruf: sh scripts/build-offline-bundle.sh [zielverzeichnis]
#   ohne Argument: <repo>/dist/virtusphere-offline-<commit12>
#
# Exitcodes: 0 Bundle gebaut und verifiziert | 1 Qualitaet (CVE) |
#            2 Umgebung unvollstaendig.
set -eu

# Git-Bash/MSYS wandelt absolute POSIX-Argumente (-w /tmp/app, --ignorefile
# /repo/...) in Windows-Pfade um; Container-Pfade muessen unangetastet bleiben.
# Nur je docker-run-Aufruf setzen (dkr), nie global: native Programme wie
# git.exe brauchen die automatische Konvertierung weiterhin. Auf Linux sind
# beide Variablen wirkungslos.
dkr() {
    MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*' docker "$@"
}

# Host-Seite eines -v-Mounts in Windows-Form bringen. cygpath -m loest dabei
# auch die MSYS-Mount-Aliase auf: Git-Bash-pwd meldet fuer das User-Temp
# z. B. /tmp/..., und dieser Pfad ginge unkonvertiert an Docker Desktop, das
# ihn still im Linux-VM-Dateisystem anlegt - der Container schreibt dann ins
# Leere und kein Werkzeug meldet einen Fehler. Auf Linux gibt es kein cygpath
# und der Pfad bleibt unveraendert.
docker_path() {
    if command -v cygpath >/dev/null 2>&1; then
        cygpath -m "$1"
    else
        printf '%s\n' "$1"
    fi
}

SCRIPT_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH='' cd -- "$SCRIPT_DIR/.." && pwd)

command -v docker >/dev/null 2>&1 || { echo "offline-bundle: docker fehlt"; exit 2; }
command -v git >/dev/null 2>&1 || { echo "offline-bundle: git fehlt"; exit 2; }
command -v sha256sum >/dev/null 2>&1 || { echo "offline-bundle: sha256sum fehlt"; exit 2; }

COMMIT=$(git -C "$ROOT" rev-parse HEAD)
COMMIT_SHORT=$(git -C "$ROOT" rev-parse --short=12 HEAD)
DIRTY=false
if [ -n "$(git -C "$ROOT" status --porcelain)" ]; then
    DIRTY=true
    echo "offline-bundle: WARNUNG: Worktree ist nicht sauber; Provenance traegt dirty=true (Release verlangt Clean Checkout)."
fi

OUT=${1:-"$ROOT/dist/virtusphere-offline-$COMMIT_SHORT"}
mkdir -p "$OUT"
OUT=$(CDPATH='' cd -- "$OUT" && pwd)
mkdir -p "$OUT/images" "$OUT/deps" "$OUT/collections" "$OUT/sbom" "$OUT/reports"
ROOT_M=$(docker_path "$ROOT")
OUT_M=$(docker_path "$OUT")

# Digest-gepinnte Tool-Images aus der Lockdatei (AP4-SSoT); ohne Pin kein Lauf.
TRIVY_REF=$(sed -n 's/.*"ref": "\(aquasec\/trivy@sha256:[0-9a-f]*\)".*/\1/p' "$ROOT/scripts/tool-lock.json")
[ -n "$TRIVY_REF" ] || { echo "offline-bundle: kein trivy-Pin in scripts/tool-lock.json"; exit 2; }
ANSIBLE_IMAGE=virtusphere-qa-ansible:latest
PHP_IMAGE=virtusphere-v2-webapp-php
docker image inspect "$ANSIBLE_IMAGE" >/dev/null 2>&1 || { echo "offline-bundle: $ANSIBLE_IMAGE fehlt (docker build -f Docker/qa-ansible/Dockerfile -t $ANSIBLE_IMAGE .)"; exit 2; }
docker image inspect "$PHP_IMAGE" >/dev/null 2>&1 || { echo "offline-bundle: $PHP_IMAGE fehlt (docker compose build php)"; exit 2; }

# --- 1) Runtime-Images: dedupe per Image-ID, save+gzip, Digest-Manifest -------
echo "==> Images speichern"
: > "$OUT/images.txt"
SEEN_IDS=""
docker compose --project-directory "$ROOT" --profile "*" config --images | LC_ALL=C sort -u | while IFS= read -r ref; do
    [ -n "$ref" ] || continue
    id=$(docker image inspect --format '{{.Id}}' "$ref" 2>/dev/null) || { echo "offline-bundle: Image fehlt lokal: $ref (erst docker compose build/pull)"; exit 2; }
    case " $SEEN_IDS " in *" $id "*) echo "    skip (buildgleich): $ref"; continue;; esac
    SEEN_IDS="$SEEN_IDS $id"
    digest=$(docker image inspect --format '{{if .RepoDigests}}{{index .RepoDigests 0}}{{end}}' "$ref")
    safe=$(printf '%s' "$ref" | tr -c 'A-Za-z0-9._-' '_')
    echo "    $ref"

    # `docker save name:tag@sha256:...` schreibt "RepoTags": null ins Manifest,
    # weil eine Referenz mit Digest keinen Tag traegt. Nach `docker load` ist das
    # Image dann namenlos (<none>:<none>) - der gemeldete "dangling image". Und
    # schlimmer: `docker load` stellt NIE einen RepoDigest wieder her, ein
    # save-Archiv enthaelt gar keinen. Ein digest-gepinntes `image:` ist auf dem
    # Zielhost damit ueberhaupt nicht aufloesbar ("No such image"), also versucht
    # compose zu ziehen - auf einem luftspaltgetrennten Host das Ende.
    #
    # Gespeichert wird deshalb der TAG. Die Integritaet der Kette haengt nicht
    # mehr am Registry-Digest (den der Zielhost nie pruefen koennte), sondern an
    # der Pruefsumme des Bundles - plus dieser Zusicherung hier: das lokale Image
    # muss den gepinnten Digest wirklich tragen, sonst waere der ausgelieferte Tag
    # nicht beweisbar derselbe Inhalt.
    tag="${ref%@*}"
    want="${ref#*@}"
    # Eine Referenz ohne Tag (die lokal gebauten Images heissen bei
    # `compose config --images` nur "virtusphere-v2-webapp-php") normalisiert
    # Docker auf :latest, und genau das steht dann in RepoTags. Ohne diese
    # Normalisierung sucht die Zusicherung unten einen Tag, den es so nie gibt.
    case "$tag" in
        */*:*|*:*) : ;;
        *) tag="$tag:latest" ;;
    esac
    if [ "$want" != "$ref" ]; then
        if ! docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$ref" \
            | grep -qx "${tag%%:*}@$want"; then
            echo "offline-bundle: [bundle.digest-mismatch] $ref traegt den gepinnten Digest lokal nicht; erst 'docker compose pull' laufen lassen." >&2
            exit 2
        fi
    fi
    docker save "$tag" | gzip > "$OUT/images/$safe.tar.gz"

    # Das Archiv muss sich selbst beweisen: verify.sh hasht nur Dateien, und ein
    # leeres RepoTags faellt erst auf dem Zielhost auf, wo niemand mehr etwas
    # reparieren kann.
    if ! gunzip -c "$OUT/images/$safe.tar.gz" | tar -xOf - manifest.json 2>/dev/null | grep -q "\"$tag\""; then
        echo "offline-bundle: [bundle.image-tag] $OUT/images/$safe.tar.gz traegt den Tag $tag nicht; ein docker load daraus ergaebe ein namenloses Image." >&2
        exit 1
    fi
    printf '%s\t%s\t%s\t%s\t%s\n' "$ref" "$tag" "$id" "${digest:-lokal-gebaut}" "images/$safe.tar.gz" >> "$OUT/images.txt"
done

# --- 1b) Referenzen, die der Zielhost wirklich aufloesen kann ------------------
#
# docker load stellt keinen RepoDigest wieder her, also ist die digest-gepinnte
# Referenz aus docker-compose.yml auf dem Zielhost nicht aufloesbar. Compose
# referenziert die beiden Registry-Images deshalb ueber ${MYSQL_IMAGE:-<pin>} bzw.
# ${PMA_IMAGE:-<pin>}; diese Datei setzt die Variablen auf die reinen Tags, die
# Schritt 2 der Installation geladen hat. Auf einem vernetzten Host existiert sie
# nicht, und der Digest gilt.
echo "==> .env.offline-images schreiben"
{
    echo "# Vom Offline-Bundle erzeugt. An .env anhaengen, BEVOR der Stack startet."
    echo "# Grund: docker load stellt keinen RepoDigest wieder her, also kann der Host"
    echo "# die digest-gepinnte Referenz nicht aufloesen und wuerde ziehen wollen."
    while IFS="$(printf '\t')" read -r _ref tag _id _digest _file; do
        case "$tag" in
            mysql:*)      echo "MYSQL_IMAGE=$tag" ;;
            phpmyadmin:*) echo "PMA_IMAGE=$tag" ;;
        esac
    done < "$OUT/images.txt"
} > "$OUT/.env.offline-images"
grep -q '^MYSQL_IMAGE=' "$OUT/.env.offline-images" \
    || { echo "offline-bundle: [bundle.image-indirection] kein MYSQL_IMAGE in .env.offline-images; der Zielhost koennte das Image nicht aufloesen." >&2; exit 1; }

# --- 2) PHP-Abhaengigkeiten: vendor.tar.gz ohne Dev-Pakete --------------------
echo "==> composer vendor (--no-dev) bauen"
dkr run --rm \
    -v "$ROOT_M/Docker/WebAPI/composer.json:/tmp/app/composer.json:ro" \
    -v "$ROOT_M/Docker/WebAPI/composer.lock:/tmp/app/composer.lock:ro" \
    -v "$OUT_M/deps:/bundle-out" \
    -e COMPOSER_CACHE_DIR=/tmp/composer-cache -e COMPOSER_ALLOW_SUPERUSER=1 \
    -w /tmp/app "$PHP_IMAGE" \
    sh -c 'composer install --no-dev --no-interaction --no-progress --no-scripts --quiet && tar czf /bundle-out/vendor.tar.gz vendor'

# --- 3) Ansible-Collections fuer den Air-Gap-Control-Node ---------------------
echo "==> Ansible-Collections herunterladen"
dkr run --rm \
    -v "$ROOT_M/Ansible/requirements.yml:/tmp/requirements.yml:ro" \
    -v "$OUT_M/collections:/bundle-out" \
    "$ANSIBLE_IMAGE" \
    ansible-galaxy collection download -r /tmp/requirements.yml -p /bundle-out

# --- 4) SBOM + CVE-Bericht je gespeichertem Image ------------------------------
echo "==> SBOM und CVE-Scan (trivy)"
CVE_FAILED=0
while IFS="$(printf '\t')" read -r ref _tag _id _digest _file; do
    safe=$(printf '%s' "$ref" | tr -c 'A-Za-z0-9._-' '_')
    dkr run --rm \
        -v //var/run/docker.sock:/var/run/docker.sock \
        -v virtusphere-trivy-cache://root/.cache \
        "$TRIVY_REF" image --quiet --format spdx-json "$ref" > "$OUT/sbom/$safe.spdx.json"
    # Voller Bericht (inkl. unfixed) als Bundle-Artefakt; blockiert wird nur,
    # was fixbar ist (--ignore-unfixed) - dieselbe Politik wie das
    # image-cve-Gate, dokumentiert in .trivyignore.yaml.
    dkr run --rm \
        -v //var/run/docker.sock:/var/run/docker.sock \
        -v virtusphere-trivy-cache://root/.cache \
        -v "$ROOT_M/.trivyignore.yaml:/repo/.trivyignore.yaml:ro" \
        "$TRIVY_REF" image --quiet --scanners vuln --severity CRITICAL,HIGH \
        --ignorefile /repo/.trivyignore.yaml "$ref" > "$OUT/reports/cve-$safe.txt"
    if ! dkr run --rm \
        -v //var/run/docker.sock:/var/run/docker.sock \
        -v virtusphere-trivy-cache://root/.cache \
        -v "$ROOT_M/.trivyignore.yaml:/repo/.trivyignore.yaml:ro" \
        "$TRIVY_REF" image --quiet --scanners vuln --severity CRITICAL,HIGH \
        --ignore-unfixed --ignorefile /repo/.trivyignore.yaml --exit-code 1 "$ref" >/dev/null; then
        echo "offline-bundle: fixbare Critical/High-CVEs in $ref (reports/cve-$safe.txt)"
        CVE_FAILED=1
    fi
done < "$OUT/images.txt"
if [ "$CVE_FAILED" -ne 0 ]; then
    echo "offline-bundle: ABBRUCH: ein Release-Artefakt mit fixbaren Critical/High-CVEs entsteht nicht (Ausnahmen nur befristet via .trivyignore.yaml)."
    exit 1
fi

# --- 5) Quellcode-Snapshot ------------------------------------------------------
echo "==> git archive HEAD"
git -C "$ROOT" archive --format=tar.gz -o "$OUT/source.tar.gz" HEAD

# --- 6) Provenance (keine Secrets, keine .env-Inhalte) -------------------------
DOCKER_VERSION=$(docker version --format '{{.Server.Version}}' 2>/dev/null || echo unbekannt)
cat > "$OUT/provenance.json" <<EOF
{
    "bundle": "virtusphere-offline",
    "commit": "$COMMIT",
    "dirty": $DIRTY,
    "builtAt": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "builder": "$(uname -s)/$(uname -m)",
    "dockerServer": "$DOCKER_VERSION",
    "trivy": "$TRIVY_REF",
    "imageManifest": "images.txt"
}
EOF

# --- 7) INSTALL.md + verify.sh --------------------------------------------------
cat > "$OUT/INSTALL.md" <<'EOF'
# VirtuSphere Offline-Bundle

Alle Schritte laufen ohne Internetzugang.

1. Verifikation: `sh verify.sh` (prueft jede Datei gegen SHA256SUMS).
2. Images laden: `for f in images/*.tar.gz; do gunzip -c "$f" | docker load; done`
   Danach muss `docker images` jedes Image MIT Namen und Tag zeigen. Kein
   `docker image prune`: es wuerde die eben geladenen Images entfernen.
3. Quellcode entpacken: `mkdir virtusphere && tar xzf source.tar.gz -C virtusphere`
4. PHP-Abhaengigkeiten: `tar xzf deps/vendor.tar.gz -C virtusphere/Docker/WebAPI`
5. Ansible-Collections auf dem Ansible-Host:
   `ansible-galaxy collection install collections/*.tar.gz` (offline).
6. Beim Anlegen der `.env` im naechsten Schritt den Inhalt von
   `.env.offline-images` anhaengen. Ohne diese zwei Zeilen versucht Compose die
   digest-gepinnten Referenzen aufzuloesen, die ein `docker load` nicht
   wiederherstellt, und will ziehen - auf diesem Host das Ende.
7. Weiter mit `virtusphere/docs/operations/offline-install.md` (.env anlegen,
   Log-Rechte, `docker compose up -d --wait`, Migrationen), danach ab Schritt 3
   mit `virtusphere/docs/operations/go-live.md`. phpMyAdmin ist optional:
   `docker compose --profile tools up -d phpmyadmin`.

NICHT `Docker/scripts/setup.sh` benutzen: es ruft `docker compose build` auf, und
die Basis-Images sind digest-gepinnt und liegen nicht im Bundle. Auf einem Host
ohne Netz kann dieser Aufruf nicht durchlaufen. Schritt 2 laedt fertige Images,
gebaut wird hier nichts.

`sbom/` und `reports/` dokumentieren den Auslieferungszustand (SPDX-SBOM und
CVE-Bericht je Image); `provenance.json` traegt Commit und Buildkontext.
EOF

cat > "$OUT/verify.sh" <<'EOF'
#!/bin/sh
# Offline-Verifikation des VirtuSphere-Bundles: prueft jede Datei gegen
# SHA256SUMS. Braucht nur sha256sum, kein Netz, kein Docker.
set -eu
cd "$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
command -v sha256sum >/dev/null 2>&1 || { echo "verify: sha256sum fehlt"; exit 2; }
sha256sum -c SHA256SUMS
echo "OK: Bundle vollstaendig offline verifiziert."
EOF
chmod +x "$OUT/verify.sh"

# --- 8) Manifest zuletzt: jede Datei ausser dem Manifest selbst ----------------
echo "==> SHA256SUMS schreiben"
(
    cd "$OUT"
    find . -type f ! -name SHA256SUMS | sed 's|^\./||' | LC_ALL=C sort | xargs -d '\n' sha256sum > SHA256SUMS
)

# --- 9) Selbstverifikation: das Bundle muss offline pruefbar sein --------------
echo "==> Offline-Selbstverifikation"
sh "$OUT/verify.sh"

echo "offline-bundle: fertig unter $OUT"
du -sh "$OUT" 2>/dev/null || true
