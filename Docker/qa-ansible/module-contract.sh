#!/bin/sh
# Modulvertrag der gepinnten community.vmware-Version. Laeuft IM QA-Ansible-Image
# (das die Collection installiert hat) mit dem Repository unter /repo:ro.
#
#   docker run --rm -v "$PWD:/repo:ro" virtusphere-qa-ansible:latest \
#       sh /repo/Docker/qa-ansible/module-contract.sh
#
# Zwei Fragen, die nur hier beantwortbar sind, weil nur hier die Collection liegt:
#
#   1. Laedt jedes benutzte Modul? module-probe.yml ruft jedes mit gueltigen
#      Argumenten gegen 127.0.0.1:443. Der Verbindungsfehler ist der Gutfall; eine
#      fehlende Python-Bibliothek, ein Argumentfehler oder ein verschwundenes
#      Modul sind es nicht. Anlass war `requests`: ohne diese Bibliothek scheitern
#      ALLE zehn Module vor der Argumentpruefung, und sie stand in keiner
#      dokumentierten Voraussetzung.
#   2. Ist ein benutztes Modul upstream als deprecated/tombstoned markiert?
#      Gelesen aus meta/runtime.yml der installierten Collection, gehalten gegen
#      module-deprecations.txt. Damit steht die Frist im Repo, ohne dass eine
#      Prosa-Notiz die Wahrheit spiegeln muesste.
#
#   3. Sind "benutzte Module" und "geprobte Module" dieselbe Menge? Sonst waechst
#      ein Modul in ein Playbook hinein, das nie geprobt wird, und die Probe
#      meldet weiter gruen fuer neun von zehn.
#
# Punkt 3 waere als PHP-Static-Test in der schnellen Lane besser aufgehoben, geht
# dort aber nicht: der PHP-Container mountet nur Docker/WebAPI und Ansible/, nicht
# Docker/qa-ansible/. Ein Glob auf die Probe waere dort dauerhaft leer und der
# Test dauerhaft still gruen - dieselbe Falle wie bei scripts/ (siehe CLAUDE.md).
set -eu

REPO="${REPO:-/repo}"
PROBE="$REPO/Docker/qa-ansible/module-probe.yml"
ALLOWLIST="$REPO/Docker/qa-ansible/module-deprecations.txt"
COLLECTION_ROOT="${COLLECTION_ROOT:-/usr/share/ansible/collections/ansible_collections/community/vmware}"
RUNTIME="$COLLECTION_ROOT/meta/runtime.yml"

errors=0
fail() {
  echo "FEHLER: [ansible-module-contract.$1] $2" >&2
  errors=$((errors + 1))
}

for required in "$PROBE" "$ALLOWLIST" "$RUNTIME"; do
  [ -f "$required" ] || { echo "FEHLER: [ansible-module-contract.no-ssot] $required fehlt." >&2; exit 2; }
done

# --- Die benutzten Module ------------------------------------------------------
# Aus den Playbooks, nicht aus einer Liste: ein neu benutztes Modul ist sofort im
# Scope. Zero-Match ist ein Fehler, kein leerer Gutfall - eine Suche, die nichts
# findet, macht jede Pruefung darunter dauerhaft und still gruen.
used=$(grep -rhoE 'community\.vmware\.[a-z0-9_]+' "$REPO/Ansible/"*.yml 2>/dev/null \
  | sed 's/^community\.vmware\.//' | LC_ALL=C sort -u)
if [ -z "$used" ]; then
  echo "FEHLER: [ansible-module-contract.zero-match] kein community.vmware-Modul unter $REPO/Ansible/ gefunden." >&2
  exit 2
fi
used_count=$(printf '%s\n' "$used" | wc -l | tr -d ' ')

# --- Die geprobten Module, in beide Richtungen ---------------------------------
probed=$(grep -oE '^      community\.vmware\.[a-z0-9_]+' "$PROBE" 2>/dev/null \
  | sed 's/^ *community\.vmware\.//' | LC_ALL=C sort -u)
if [ -z "$probed" ]; then
  echo "FEHLER: [ansible-module-contract.zero-match] $PROBE probt kein einziges Modul; die Probe darunter wuerde nichts pruefen und trotzdem gruen melden." >&2
  exit 2
fi
for module in $used; do
  printf '%s\n' "$probed" | grep -qx "$module" || \
    fail probe-incomplete "ein Playbook benutzt 'community.vmware.$module', aber $PROBE probt es nicht; fuer dieses Modul beweist der Vertrag nichts."
done
for module in $probed; do
  printf '%s\n' "$used" | grep -qx "$module" || \
    fail probe-stale "$PROBE probt 'community.vmware.$module', das kein Playbook mehr benutzt; die Zeile bindet den Vertrag an ein Modul, das uns nicht mehr betrifft."
done

# --- 1. Laedt jedes Modul? -----------------------------------------------------
probe_output=$(ansible-playbook "$PROBE" 2>&1 || true)
if [ -z "$probe_output" ]; then
  echo "FEHLER: [ansible-module-contract.zero-match] die Modulprobe hat nichts ausgegeben." >&2
  exit 2
fi

# Verbotene Meldungen statt einer erwarteten: eine neue Formulierung des
# Verbindungsfehlers ist kein Befund, ein neuer Argumentname schon.
if printf '%s\n' "$probe_output" | grep -q 'Failed to import the required Python library'; then
  lib=$(printf '%s\n' "$probe_output" | grep -oE 'required Python library \([a-zA-Z0-9_.-]+\)' | head -n 1)
  fail missing-library "die gepinnte Collection braucht eine Python-Bibliothek, die im Image fehlt ($lib). Sie fehlt dann auch auf jedem Ansible-Host, der nur die dokumentierten Voraussetzungen erfuellt: Docker/qa-ansible/requirements.in und die Host-Liste in docs/DEPLOYMENT.md nachziehen."
fi
if printf '%s\n' "$probe_output" | grep -qE 'Unsupported parameters|unsupported parameter|is required|are required'; then
  detail=$(printf '%s\n' "$probe_output" | grep -oE '"msg": "[^"]*(Unsupported parameters|unsupported parameter|is required|are required)[^"]*"' | head -n 2)
  fail argument-spec "ein Modul lehnt die Argumente ab, die die Probe uebergibt; die argument_spec der gepinnten Version passt nicht mehr zu Docker/qa-ansible/module-probe.yml (und damit womoeglich nicht mehr zu einem Playbook). $detail"
fi
if printf '%s\n' "$probe_output" | grep -qE "couldn't resolve module/action|could not be found"; then
  fail module-gone "ein benutztes Modul existiert in der gepinnten Version nicht (mehr)."
fi

# --- 2. Deprecations der installierten Collection ------------------------------
# awk statt eines YAML-Parsers: runtime.yml ist hier flach genug (zwei Ebenen
# Einrueckung unter plugin_routing), und das Image bringt kein PyYAML-CLI mit.
routing=$(awk '
  /^[[:space:]]{4}[a-z0-9_]+:[[:space:]]*$/ { name = $1; sub(/:$/, "", name); next }
  /^[[:space:]]{6}(deprecation|tombstone):[[:space:]]*$/ { if (name != "") { kind = $1; sub(/:$/, "", kind); print name, kind } }
' "$RUNTIME")
if [ -z "$routing" ]; then
  echo "FEHLER: [ansible-module-contract.zero-match] $RUNTIME nennt keine einzige deprecation/tombstone; die Ableitung trifft nicht mehr und wuerde jedes Modul als sauber melden." >&2
  exit 2
fi

allow=$(grep -vE '^[[:space:]]*(#|$)' "$ALLOWLIST" | awk '{ print $1 }' | LC_ALL=C sort -u)

for module in $used; do
  entry=$(printf '%s\n' "$routing" | awk -v m="$module" '$1 == m { print $2 }' | head -n 1)
  [ -n "$entry" ] || continue
  if ! printf '%s\n' "$allow" | grep -qx "$module"; then
    fail deprecation-unrecorded "die gepinnte Collection markiert das benutzte Modul '$module' als $entry, und Docker/qa-ansible/module-deprecations.txt kennt es nicht. Nachfolger und removal_version dort eintragen (aus $RUNTIME) oder das Modul portieren."
  fi
done

# Gegenrichtung: eine Ausnahme, die kein Playbook mehr benutzt, deckt kuenftig das
# falsche Modul.
for module in $allow; do
  if ! printf '%s\n' "$used" | grep -qx "$module"; then
    fail deprecation-stale "Docker/qa-ansible/module-deprecations.txt nennt '$module', aber kein Playbook benutzt es mehr; Zeile entfernen."
  fi
done

if [ "$errors" -gt 0 ]; then
  echo "ansible-module-contract: $errors Fehler." >&2
  exit 1
fi
echo "ansible-module-contract: $used_count Modul(e) laden gegen die gepinnte Collection, Deprecations sind erfasst."
