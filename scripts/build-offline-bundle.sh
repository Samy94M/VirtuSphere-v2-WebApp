#!/bin/sh
# Offline-Release-Bundle (AP8, Plan v2): baut aus dem lokalen Stand ein
# vollstaendig offline verifizier- und installierbares Artefakt:
#
#   images/         docker save der Runtime-Images (per Image-ID dedupliziert)
#   images.txt      Ref -> Image-ID -> RepoDigest je gespeichertem Image
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
    docker save "$ref" | gzip > "$OUT/images/$safe.tar.gz"
    printf '%s\t%s\t%s\t%s\n' "$ref" "$id" "${digest:-lokal-gebaut}" "images/$safe.tar.gz" >> "$OUT/images.txt"
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

# --- 4) SBOM + CVE-Bericht je gespeichertem Image ------------------------------
echo "==> SBOM und CVE-Scan (trivy)"
CVE_FAILED=0
while IFS="$(printf '\t')" read -r ref _id _digest _file; do
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
3. Quellcode entpacken: `mkdir virtusphere && tar xzf source.tar.gz -C virtusphere`
4. PHP-Abhaengigkeiten: `tar xzf deps/vendor.tar.gz -C virtusphere/Docker/WebAPI`
5. Ansible-Collections auf dem Ansible-Host:
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
