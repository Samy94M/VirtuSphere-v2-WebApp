#!/bin/sh
# scripts/check-compose-network-pins.sh: belegt vor einem `up`, dass jedes Netz
# der aufgeloesten Compose-Konfiguration ein gepinntes Subnetz hat (C-07).
#
# Ein Compose-Netz ohne ipam.config.subnet bekommt ein Subnetz aus Dockers
# Standardpool (172.x). Liegt die Adresse, ueber die der Betreiber per SSH
# verbunden ist, darin, friert die Sitzung ein (docs/operations/go-live.md,
# Schritt 1a.2). Seit C-07 hat der Stack zwei Netze, `default` und `front`, und
# die Override-Datei eines solchen Hosts muss beide pinnen.
#
# Aufruf im Projektordner, als Betriebskonto:
#   sh scripts/check-compose-network-pins.sh
#   sh scripts/check-compose-network-pins.sh -f docker-compose.yml -f docker-compose.override.yml -f docker-compose.supervisor.yml
# Ohne Argumente gilt die Dateiwahl von Compose selbst (docker-compose.yml plus
# docker-compose.override.yml, falls vorhanden). Argumente gehen unveraendert
# vor `config` an `docker compose`.
#
# Ausgabe: je Netz nur der Name und gepinnt=ja/nein. Weder ein Subnetz noch die
# aufgeloeste Konfiguration erscheinen; diese enthaelt die Geheimnisse aus .env
# und bleibt in einer Shellvariablen.
#
# Exitcodes: 0 alle Netze gepinnt; 1 mindestens ein Netz ohne Pin; 2 Umgebung
# unvollstaendig (docker fehlt, Konfiguration nicht aufloesbar, kein Netz).
set -eu
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'

root="${VIRTUSPHERE_CHECK_ROOT:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$root" 2>/dev/null || { echo "check-compose-network-pins: Projektordner $root nicht erreichbar"; exit 2; }
command -v docker >/dev/null 2>&1 || { echo "check-compose-network-pins: docker nicht gefunden"; exit 2; }

# Alle Profile: ein Netz, das nur ein Werkzeugdienst nutzt, entsteht beim
# `--profile tools up` genauso aus dem Standardpool. Stderr bleibt stumm, weil
# eine Compose-Meldung Werte aus der Konfiguration zitieren koennte.
if ! config=$(docker compose "$@" --profile '*' config 2>/dev/null); then
  echo "check-compose-network-pins: docker compose config fehlgeschlagen; Ursache mit 'docker compose config --quiet' ansehen"
  exit 2
fi

# Kanonische Compose-Ausgabe: `networks:` auf Spalte 0, je Netz eine Zeile mit
# zwei Leerzeichen Einzug, darunter ipam.config mit `subnet:`. Gezaehlt wird
# nur, ob ein nichtleeres `subnet:` im Block des Netzes steht.
result=$(printf '%s\n' "$config" | awk '
  /^[^[:space:]#]/ { innet = ($0 ~ /^networks:[[:space:]]*$/); cur = ""; next }
  !innet { next }
  /^  [^[:space:]#-][^:]*:[[:space:]]*(\{\})?[[:space:]]*$/ {
    cur = $0
    sub(/^  /, "", cur)
    sub(/:[[:space:]]*(\{\})?[[:space:]]*$/, "", cur)
    order[++n] = cur
    pinned[cur] = "nein"
    next
  }
  cur != "" && /^[[:space:]]+(-[[:space:]]+)?subnet:[[:space:]]*[^[:space:]]/ { pinned[cur] = "ja" }
  END { for (i = 1; i <= n; i++) print order[i] " " pinned[order[i]] }
')
config=""

total=$(printf '%s\n' "$result" | grep -c . || true)
if [ "$total" -eq 0 ]; then
  echo "check-compose-network-pins: keine Netze in der aufgeloesten Konfiguration (Zero-Match)"
  exit 2
fi

echo "[0/$total] Netze der aufgeloesten Compose-Konfiguration"
position=0
unpinned=0
while read -r net flag; do
  [ -n "$net" ] || continue
  position=$((position + 1))
  echo "[$position/$total] RUN $net"
  echo "[$position/$total] gepinnt=$flag $net"
  [ "$flag" = "ja" ] || unpinned=$((unpinned + 1))
done <<EOF
$result
EOF

if [ "$unpinned" -gt 0 ]; then
  echo "check-compose-network-pins: $unpinned von $total Netzen ohne gepinntes Subnetz; vor dem naechsten up in der Override networks.<netz>.ipam.config setzen (go-live.md, Schritt 1a.2)"
  exit 1
fi
echo "check-compose-network-pins: OK, alle $total Netze gepinnt"
