#!/bin/sh
# Fuellt das QA-Volume qa-repo mit genau dem Pruefstand (OV1, 03.11.2026).
# Laeuft nur als Dienst qa-source aus Docker/qa/docker-compose.qa.yml, gestartet
# vom Gate qa-stack (scripts/lib/check/runtime.ps1, Sync-QaSourceVolume). Die
# Auswahl der Dateien trifft der Runner, nicht dieses Skript: es kopiert die
# NUL-getrennte Liste und meldet, was danach im Volume liegt. Ob das dem
# Checkout entspricht, entscheidet der Runner aus seiner eigenen Pruefsumme.
#
# Aufbau des Volumes:
#   tree/  der Pruefstand (subpath tree/Docker/WebAPI und tree/Ansible fuer die
#          App-Dienste, volume-subpath tree als /repo fuer phpunit-full)
#   meta/  SHA-256-Liste von tree/ und die Herkunft (Commit, geaenderte
#          Dateien), damit ein spaeterer Lauf gegen einen stehenden Stack
#          pruefen kann, ob der Arbeitsbaum noch derselbe ist
set -eu

evidence=/qa-evidence
list=$evidence/qa-source.files
origin=$evidence/qa-source.origin.json
out=$evidence/qa-source.volume.sha256

if [ ! -s "$list" ] || [ ! -s "$origin" ]; then
    echo "qa-source: Dateiliste oder Herkunft fehlt unter $evidence" >&2
    exit 2
fi
if [ ! -d /qa-repo ] || [ ! -d /qa-source ]; then
    echo "qa-source: /qa-repo oder /qa-source ist nicht gemountet" >&2
    exit 2
fi

# Ein Rest eines frueheren Laufs (etwa nach -KeepArtifacts) darf nie
# mitgeprueft werden: erst leeren, dann fuellen.
find /qa-repo -mindepth 1 -delete
mkdir /qa-repo/tree /qa-repo/meta

# --no-recursion: die Liste nennt Ordner (fuer ihren Modus) und Dateien
# einzeln; ein Ordnereintrag darf nicht seinen ganzen Inhalt nachziehen.
cd /qa-source
tar --create --file=- --null --verbatim-files-from --no-recursion --files-from="$list" \
    | tar --extract --file=- --directory=/qa-repo/tree

cd /qa-repo/tree
find . -type f -print0 | sort -z | xargs -0 -r sha256sum > /qa-repo/meta/qa-source.sha256
cp "$origin" /qa-repo/meta/qa-source.origin.json
cp /qa-repo/meta/qa-source.sha256 "$out.tmp"
mv "$out.tmp" "$out"
echo "qa-source: $(wc -l < "$out") Dateien in qa-repo/tree"
