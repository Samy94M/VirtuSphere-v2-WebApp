#!/bin/sh
# Offline-Release-Bundle (AP8, Plan v2): baut aus dem lokalen Stand ein
# vollstaendig offline verifizier- und installierbares Artefakt:
#
#   images/         docker save der Runtime-Images (per Image-ID dedupliziert)
#   images.txt      Ref -> Tag -> Image-ID -> RepoDigest je gespeichertem Image
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
# Der Build-Host braucht Netz (composer, Galaxy, trivy-DB, PyPI) und Docker;
# das Zielsystem braucht fuer die Verifikation nur sha256sum.
#
# Aufruf: sh scripts/build-offline-bundle.sh [--release] [zielverzeichnis]
#   ohne Argument: <repo>/dist/virtusphere-offline-<commit12>
#   --release: ein dirty Worktree ist ein Abbruch statt einer Warnung
#              (Images/vendor entstehen aus dem lokalen Stand, waehrend
#              source.tar.gz HEAD archiviert; ein Release-Artefakt darf diese
#              Luecke nicht tragen)
#
# Gebaut wird in einem frischen Staging neben dem Ziel und erst nach der
# Selbstverifikation atomar umbenannt; ein nichtleeres Ziel ist ein Abbruch,
# weil Altdateien sonst mit ins SHA256SUMS-Manifest gehasht wuerden.
#
# Exitcodes: 0 Bundle gebaut und verifiziert | 1 Qualitaet (CVE, dirty bei
#            --release, Roundtrip-Bruch) | 2 Umgebung unvollstaendig.
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

RELEASE=false
if [ "${1:-}" = "--release" ]; then
    RELEASE=true
    shift
fi

COMMIT=$(git -C "$ROOT" rev-parse HEAD)
COMMIT_SHORT=$(git -C "$ROOT" rev-parse --short=12 HEAD)
DIRTY=false
if [ -n "$(git -C "$ROOT" status --porcelain)" ]; then
    DIRTY=true
    if [ "$RELEASE" = true ]; then
        echo "offline-bundle: [bundle.dirty] ABBRUCH: --release verlangt einen sauberen Worktree. Images und vendor entstehen aus dem lokalen Stand, waehrend source.tar.gz HEAD archiviert; ein Release-Artefakt darf diese Luecke nicht tragen." >&2
        exit 1
    fi
    echo "offline-bundle: WARNUNG: Worktree ist nicht sauber; Provenance traegt dirty=true (Release verlangt Clean Checkout; --release macht daraus einen Abbruch)."
fi

OUT=${1:-"$ROOT/dist/virtusphere-offline-$COMMIT_SHORT"}
mkdir -p "$(dirname "$OUT")"
OUT_PARENT=$(CDPATH='' cd -- "$(dirname "$OUT")" && pwd)
OUT="$OUT_PARENT/$(basename "$OUT")"
if [ -e "$OUT" ] && [ -n "$(ls -A "$OUT" 2>/dev/null)" ]; then
    echo "offline-bundle: [bundle.dest-not-empty] ABBRUCH: Zielverzeichnis $OUT ist nicht leer. Altdateien wuerden mit ins SHA256SUMS-Manifest gehasht und als Bundle-Inhalt ausgeliefert; Verzeichnis leeren oder anderes Ziel waehlen." >&2
    exit 2
fi
# Frisches Staging neben dem Ziel (gleiches Dateisystem, das mv am Ende ist
# atomar): ein abgebrochener Build hinterlaesst nie ein halbes Bundle unter dem
# Zielnamen, und kein Altbestand kann ins Manifest wandern.
STAGE="$OUT_PARENT/.$(basename "$OUT").stage-$$"
rm -rf "$STAGE"
mkdir -p "$STAGE/images" "$STAGE/deps" "$STAGE/collections" "$STAGE/sbom" "$STAGE/reports"
cleanup_stage() { rm -rf "$STAGE"; }
trap cleanup_stage EXIT INT TERM
ROOT_M=$(docker_path "$ROOT")
OUT_M=$(docker_path "$STAGE")

# Digest-gepinnte Tool-Images aus der Lockdatei (AP4-SSoT); ohne Pin kein Lauf.
TRIVY_REF=$(sed -n 's/.*"ref": "\(aquasec\/trivy@sha256:[0-9a-f]*\)".*/\1/p' "$ROOT/scripts/tool-lock.json")
[ -n "$TRIVY_REF" ] || { echo "offline-bundle: kein trivy-Pin in scripts/tool-lock.json"; exit 2; }
ANSIBLE_IMAGE=virtusphere-qa-ansible:latest
PHP_IMAGE=virtusphere-v2-webapp-php
docker image inspect "$ANSIBLE_IMAGE" >/dev/null 2>&1 || { echo "offline-bundle: $ANSIBLE_IMAGE fehlt (docker build -f Docker/qa-ansible/Dockerfile -t $ANSIBLE_IMAGE .)"; exit 2; }
docker image inspect "$PHP_IMAGE" >/dev/null 2>&1 || { echo "offline-bundle: $PHP_IMAGE fehlt (docker compose build php)"; exit 2; }

# --- 1) Runtime-Images: dedupe per Image-ID, save+gzip, Digest-Manifest -------
echo "==> Images speichern"
: > "$STAGE/images.txt"
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
    docker save "$tag" | gzip > "$STAGE/images/$safe.tar.gz"

    # Das Archiv muss sich selbst beweisen: verify.sh hasht nur Dateien, und ein
    # leeres RepoTags faellt erst auf dem Zielhost auf, wo niemand mehr etwas
    # reparieren kann.
    if ! gunzip -c "$STAGE/images/$safe.tar.gz" | tar -xOf - manifest.json 2>/dev/null | grep -q "\"$tag\""; then
        echo "offline-bundle: [bundle.image-tag] images/$safe.tar.gz traegt den Tag $tag nicht; ein docker load daraus ergaebe ein namenloses Image." >&2
        exit 1
    fi
    printf '%s\t%s\t%s\t%s\t%s\n' "$ref" "$tag" "$id" "${digest:-lokal-gebaut}" "images/$safe.tar.gz" >> "$STAGE/images.txt"
done

# --- 1b) Roundtrip: loesen die Offline-Referenzen wirklich auf? -----------------
#
# Die docker-load-Haelfte laesst sich auf dem Build-Host nicht ehrlicher beweisen
# als durch den manifest.json-Tag-Check oben (die Images sind hier ohnehin
# vorhanden; nur ein Wegwerf-Docker-Daemon koennte mehr zeigen). Die lokal
# gehaerteten Runtime-Images haben feste Tags, und jede von Compose aufgeloeste
# Referenz muss unter genau diesem Tag im Bundle liegen. Ein Digest waere nach
# docker load nicht aufloesbar und bleibt deshalb ein harter Befund.
echo "==> Compose-Roundtrip mit Bundle-Tags"
resolved=$(docker compose --project-directory "$ROOT" --profile "*" config --images | LC_ALL=C sort -u)
if printf '%s\n' "$resolved" | grep -q '@sha256:'; then
    echo "offline-bundle: [bundle.roundtrip-digest] compose loest eine Digest-Referenz auf; der Zielhost koennte sie nach docker load nicht finden:" >&2
    printf '%s\n' "$resolved" | grep '@sha256:' >&2
    exit 1
fi
for res in $resolved; do
    restag="$res"
    case "$restag" in */*:*|*:*) : ;; *) restag="$restag:latest" ;; esac
    if ! grep -q "$(printf '\t%s\t' "$restag")" "$STAGE/images.txt"; then
        echo "offline-bundle: [bundle.roundtrip-missing] compose referenziert $res, aber das Bundle traegt keinen Tag $restag." >&2
        exit 1
    fi
done

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

# --- 3b) Python-Wheels fuer den Air-Gap-Ansible-Host ---------------------------
#
# requirements.yml verlangt ansible-core/pyvmomi/requests "BEFORE the network is
# air-gapped"; ohne Wheels im Bundle hiess das fuer einen frisch aufzusetzenden
# Air-Gap-Host "vorher im Netz installieren" - ein Widerspruch in sich. Die
# Versionen kommen aus der QA-Lockdatei (SSoT Docker/qa-ansible/requirements.txt,
# pip-compile mit Hashes); die Integritaet der Dateien haengt am
# SHA256SUMS-Manifest des Bundles.
#
# Die Zielplattform ist EXPLIZIT gesetzt, nicht geerbt: das QA-Image ist Alpine
# (musl), und ein nacktes pip download lud dort musllinux-Wheels, die auf dem
# glibc-Ubuntu-Ansible-Host nicht installierbar sind (beim ersten Probelauf
# passiert). manylinux + Python 3.12 deckt Ubuntu 24.04; ansible-core 2.19
# verlangt ohnehin Python >= 3.11. --only-binary haelt sdists heraus, die im
# Air-Gap nicht baubar waeren; setuptools/wheel kommen trotzdem mit, damit
# pip-Werkzeug auf dem Host vollstaendig ist.
echo "==> Python-Wheels fuer den Ansible-Host (manylinux/cp312)"
: > "$STAGE/deps/requirements-ansible-host.txt"
HOST_PKGS=""
for pkg in ansible-core pyvmomi requests; do
    pin=$(sed -n "s/^\($pkg==[0-9][^ \\\\]*\).*$/\1/p" "$ROOT/Docker/qa-ansible/requirements.txt" | head -n 1)
    [ -n "$pin" ] || { echo "offline-bundle: [bundle.wheel-pin] $pkg fehlt in Docker/qa-ansible/requirements.txt; der Wheelhouse-Pin waere ungedeckt." >&2; exit 2; }
    HOST_PKGS="$HOST_PKGS $pin"
    printf '%s\n' "$pin" >> "$STAGE/deps/requirements-ansible-host.txt"
done
# shellcheck disable=SC2086
dkr run --rm \
    -v "$OUT_M/deps:/bundle-out" \
    "$ANSIBLE_IMAGE" \
    pip download --no-cache-dir --dest /bundle-out/wheels \
    --only-binary=:all: --python-version 312 \
    --platform manylinux_2_28_x86_64 --platform manylinux2014_x86_64 --platform manylinux_2_17_x86_64 \
    $HOST_PKGS setuptools wheel
ls "$STAGE/deps/wheels"/ansible_core-*.whl >/dev/null 2>&1 \
    || { echo "offline-bundle: [bundle.wheelhouse] kein ansible-core-Wheel im Wheelhouse gelandet." >&2; exit 1; }
if ls "$STAGE/deps/wheels"/*musllinux*.whl >/dev/null 2>&1; then
    echo "offline-bundle: [bundle.wheel-platform] musllinux-Wheels im Wheelhouse; der glibc-Ansible-Host koennte sie nicht installieren." >&2
    exit 1
fi

# --- 4) SBOM + CVE-Bericht je gespeichertem Image ------------------------------
echo "==> SBOM und CVE-Scan (trivy)"
CVE_FAILED=0
while IFS="$(printf '\t')" read -r ref _tag _id _digest _file; do
    safe=$(printf '%s' "$ref" | tr -c 'A-Za-z0-9._-' '_')
    dkr run --rm \
        -v //var/run/docker.sock:/var/run/docker.sock \
        -v virtusphere-trivy-cache://root/.cache \
        "$TRIVY_REF" image --quiet --format spdx-json "$ref" > "$STAGE/sbom/$safe.spdx.json"
    # Voller Bericht (inkl. unfixed) als Bundle-Artefakt; blockiert wird nur,
    # was fixbar ist (--ignore-unfixed) - dieselbe Politik wie das
    # image-cve-Gate, dokumentiert in .trivyignore.yaml.
    dkr run --rm \
        -v //var/run/docker.sock:/var/run/docker.sock \
        -v virtusphere-trivy-cache://root/.cache \
        -v "$ROOT_M/.trivyignore.yaml:/repo/.trivyignore.yaml:ro" \
        "$TRIVY_REF" image --quiet --scanners vuln --severity CRITICAL,HIGH \
        --ignorefile /repo/.trivyignore.yaml "$ref" > "$STAGE/reports/cve-$safe.txt"
    if ! dkr run --rm \
        -v //var/run/docker.sock:/var/run/docker.sock \
        -v virtusphere-trivy-cache://root/.cache \
        -v "$ROOT_M/.trivyignore.yaml:/repo/.trivyignore.yaml:ro" \
        "$TRIVY_REF" image --quiet --scanners vuln --severity CRITICAL,HIGH \
        --ignore-unfixed --ignorefile /repo/.trivyignore.yaml --exit-code 1 "$ref" >/dev/null; then
        echo "offline-bundle: fixbare Critical/High-CVEs in $ref (reports/cve-$safe.txt)"
        CVE_FAILED=1
    fi
done < "$STAGE/images.txt"
if [ "$CVE_FAILED" -ne 0 ]; then
    echo "offline-bundle: ABBRUCH: ein Release-Artefakt mit fixbaren Critical/High-CVEs entsteht nicht (Ausnahmen nur befristet via .trivyignore.yaml)."
    exit 1
fi

# --- 5) Quellcode-Snapshot ------------------------------------------------------
echo "==> git archive HEAD"
git -C "$ROOT" archive --format=tar.gz -o "$STAGE/source.tar.gz" HEAD

# --- 6) Provenance (keine Secrets, keine .env-Inhalte) -------------------------
DOCKER_VERSION=$(docker version --format '{{.Server.Version}}' 2>/dev/null || echo unbekannt)
cat > "$STAGE/provenance.json" <<EOF
{
    "schema": 1,
    "bundle": "virtusphere-offline",
    "commit": "$COMMIT",
    "dirty": $DIRTY,
    "release": $RELEASE,
    "builtAt": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "builder": "$(uname -s)/$(uname -m)",
    "dockerServer": "$DOCKER_VERSION",
    "trivy": "$TRIVY_REF",
    "imageManifest": "images.txt"
}
EOF

# --- 7) INSTALL.md + verify.sh --------------------------------------------------
cat > "$STAGE/INSTALL.md" <<'EOF'
# VirtuSphere Offline-Bundle

Alle Schritte laufen ohne Internetzugang.

1. Verifikation: `sh verify.sh` (prueft jede Datei gegen SHA256SUMS).
2. Images laden: `for f in images/*.tar.gz; do gunzip -c "$f" | docker load; done`
   Danach muss `docker images` jedes Image MIT Namen und Tag zeigen. Kein
   `docker image prune`: es wuerde die eben geladenen Images entfernen.
3. Quellcode entpacken: `mkdir virtusphere && tar xzf source.tar.gz -C virtusphere`
4. PHP-Abhaengigkeiten: `tar xzf deps/vendor.tar.gz -C virtusphere/Docker/WebAPI`
5. Python-Pakete auf dem Ansible-Host (vor den Collections; ansible-core,
   pyvmomi und requests sind die dokumentierten Host-Voraussetzungen):
   `python3 -m pip install --no-index --find-links deps/wheels -r deps/requirements-ansible-host.txt`
   Danach die Ansible-Collections:
   `ansible-galaxy collection install collections/*.tar.gz` (offline).
6. Weiter mit `virtusphere/docs/operations/offline-install.md` (.env anlegen,
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

cat > "$STAGE/verify.sh" <<'EOF'
#!/bin/sh
# Offline-Verifikation des VirtuSphere-Bundles: prueft jede Datei gegen
# SHA256SUMS. Braucht nur sha256sum, kein Netz, kein Docker.
set -eu
cd "$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
command -v sha256sum >/dev/null 2>&1 || { echo "verify: sha256sum fehlt"; exit 2; }
sha256sum -c SHA256SUMS
echo "OK: Bundle vollstaendig offline verifiziert."
EOF
chmod +x "$STAGE/verify.sh"

# --- 8) Manifest zuletzt: jede Datei ausser dem Manifest selbst ----------------
echo "==> SHA256SUMS schreiben"
(
    cd "$STAGE"
    find . -type f ! -name SHA256SUMS | sed 's|^\./||' | LC_ALL=C sort | xargs -d '\n' sha256sum > SHA256SUMS
)

# --- 9) Selbstverifikation, dann atomar veroeffentlichen ------------------------
echo "==> Offline-Selbstverifikation"
sh "$STAGE/verify.sh"

# Erst ein vollstaendig verifiziertes Staging bekommt den Zielnamen; der Trap
# entfaellt danach, denn ab hier gibt es nichts Halbes mehr aufzuraeumen.
mv "$STAGE" "$OUT"
trap - EXIT INT TERM

echo "offline-bundle: fertig unter $OUT"
du -sh "$OUT" 2>/dev/null || true
