#!/bin/sh
# scripts/check-doc-semantics.sh — semantischer Doku-Check (AP9).
#
# check-doc-hygiene prueft Form (Marker, Budgets); dieser Check prueft Aussagen:
# Betriebsdoku darf keinen Ist-Stand behaupten, der beim naechsten Commit still
# veraltet. Die Fehlerklasse ist dieselbe wie bei check-bounds-sync: der Code
# laeuft weiter, nur die Doku luegt, und kein Test merkt es.
#
#   1. PRE-SHIP-CHECKLIST.md bleibt leere Vorlage: kein abgehakter Punkt,
#      kein datierter Nachweis. Nachweise gehoeren ins QA-Artefakt des Laufs.
#   2. Keine hartcodierten Test-/Spec-/Assertion-Zahlen in aktiver Doku.
#   3. Keine Migrations-Stands-/Anzahl-Behauptung (konkrete Migrationsnamen
#      als fachliche Referenz, z. B. "migration 0020", bleiben erlaubt).
#   4. Keine Lastmesswerte (ms/Requests/VUs) ausserhalb von tests/load/.
#   5. PHPStan-Level-Nennungen == level aus phpstan.neon.dist.
#   6. "MySQL x.y"-Nennungen == mysql-Image in docker-compose.yml.
#   7. "Node NN"-Nennungen == node-version in ci.yml.
#   8. Stillgelegte Backup-Pfade (Docker/scripts/backup|restore.sh) nur mit
#      Stilllegungs-Marker in derselben Zeile.
#   9. Aktive Doku und PowerShell verwenden nur die MECM-Terminologie.
#  10. "`VIRTUSPHERE_X` ... aktuell N"-Nennungen == numerischer Wert der
#      Konstante in lib/constants.php bzw. lib/deploy_constants.php.
#  11. Ein von aktiver Doku behaupteter Dateipfad braucht einen Erzeuger
#      ausserhalb von docs/ (die Erstpasswort-Datei, die nichts je schrieb).
#  12. Jeder .env.example-Schluessel, den compose ohne Vorgabewert interpoliert,
#      kommt im Go-Live-Runbook namentlich vor (APP_BIND_IP fehlte dort).
#
# Bewusst NICHT geprueft (datierte Dokumente, die einen Stand beschreiben
# sollen): docs/audits/, docs/CHANGELOG.md, docs/adr/. Nicht maschinell
# pruefbar und daher Review-Sache bleiben: widerspruchsfreie Exitkriterien
# und die CI-Lane-Beschreibung gegen ci.yml.
#
# Aufrufer: .claude/hooks/session-start.sh (--quiet), scripts/check.ps1
# (Gate doc-semantics), scripts/test-guards.ps1 (Fixtures), manuell.
# VIRTUSPHERE_CHECK_ROOT uebersteuert das Repo-Root; die [doc-semantics.*]-IDs
# sind der stabile Diagnose-Vertrag.
set -eu
# Das echte Repo, unabhaengig von VIRTUSPHERE_CHECK_ROOT. Regel 11 braucht es:
# sie vergleicht, was die (in einer Fixture ggf. mutierte) Doku behauptet, gegen
# das, was der Baum wirklich enthaelt. Eine Doku-Fixture enthaelt keinen Code, und
# gegen sie waere jeder Pfad scheinbar verwaist.
real_root=$(cd "$(dirname "$0")/.." && pwd)
cd "${VIRTUSPHERE_CHECK_ROOT:-$(dirname "$0")/..}"

quiet=0
case "${1:-}" in
  --quiet|-q) quiet=1 ;;
  --ci|'') ;;
  --help|-h) echo "Usage: scripts/check-doc-semantics.sh [--quiet|--ci]"; exit 0 ;;
  *) echo "Unknown argument: $1" >&2; exit 2 ;;
esac

errors=0
fail() { # fail <id> <text>
  echo "FEHLER: [doc-semantics.$1] $2" >&2
  errors=$((errors + 1))
}

# --- Scope: aktive Doku (Kernliste muss existieren, sonst Zero-Match-Luege) ---
core_docs='README.md AGENTS.md GROK.md CLAUDE.md PRE-SHIP-CHECKLIST.md docs/QA.md docs/QUALITY-GATES.md docs/TESTPLAN.md docs/DEPLOYMENT.md docs/INSTALLATION-ANLEITUNG.md'
active_docs=''
for f in $core_docs; do
  if [ -f "$f" ]; then
    active_docs="$active_docs $f"
  else
    fail missing-file "$f nicht gefunden; der Check kann seinen Scope nicht abdecken."
  fi
done
for f in docs/operations/*.md docs/security/*.md; do
  [ -f "$f" ] && active_docs="$active_docs $f"
done

# --- 1. PRE-SHIP-CHECKLIST bleibt leere Vorlage --------------------------------
if [ -f PRE-SHIP-CHECKLIST.md ]; then
  if grep -nE '^\s*[-*] \[[xX]\]' PRE-SHIP-CHECKLIST.md >&2; then
    fail pre-ship-checked "PRE-SHIP-CHECKLIST.md enthaelt abgehakte Punkte; Nachweise gehoeren ins QA-Artefakt (check.ps1 -Json), die Checkliste bleibt Vorlage."
  fi
  if grep -nE '20[0-9]{2}-[0-9]{2}-[0-9]{2}' PRE-SHIP-CHECKLIST.md >&2; then
    fail pre-ship-metric "PRE-SHIP-CHECKLIST.md enthaelt datierte Nachweise; Datum und Messwert gehoeren ins QA-Artefakt des Laufs."
  fi
fi

# --- 2.-4. Zahlen, die still veralten ------------------------------------------
for f in $active_docs; do
  if grep -nE '\b[0-9]+ ?(PHPUnit-|Pester-|E2E-|Playwright-)?([Tt]ests?|[Tt]estdateien|[Ss]pecs?|[Aa]ssertions?)\b' "$f" >&2; then
    fail test-count "$f nennt eine Testanzahl; Testzahlen gehoeren ausschliesslich in erzeugte QA-Artefakte."
  fi
  if grep -nE '\bMigrationsstand [0-9]{4}|\b[0-9]+\+? (incremental |delta )*[Mm]igration(s|en)\b' "$f" >&2; then
    fail migration-count "$f behauptet einen Migrationsstand/-umfang; der veraltet mit der naechsten Migration (konkrete Namen wie 'migration 0020' sind als fachliche Referenz erlaubt, ebenso die Anweisung, den Stand zu pruefen)."
  fi
  if grep -nE '\b[0-9]+ ?ms\b|\b[0-9]+ [Rr]equests\b|\b[0-9]+ ?VUs\b' "$f" >&2; then
    fail load-metric "$f enthaelt Lastmesswerte; Baselines und Schwellen leben ausschliesslich bei den Lasttests (tests/load/README.md)."
  fi
done

# --- 5. PHPStan-Level gegen phpstan.neon.dist -----------------------------------
stan_ssot='Docker/WebAPI/phpstan.neon.dist'
stan_level=''
[ -f "$stan_ssot" ] && stan_level=$(sed -n 's/^[[:space:]]*level:[[:space:]]*\([0-9][0-9]*\).*/\1/p' "$stan_ssot" | head -n 1)
if [ -z "$stan_level" ]; then
  fail no-ssot "PHPStan-Level nicht aus $stan_ssot lesbar; ohne SSoT kann keine Level-Nennung geprueft werden."
else
  for f in $active_docs; do
    if grep -inE 'phpstan' "$f" 2>/dev/null | grep -iE 'level [0-9]+' | grep -viE "level $stan_level\b" >&2; then
      fail phpstan-level "$f nennt ein anderes PHPStan-Level als $stan_ssot (level $stan_level); an der Nennung fixen, nicht am SSoT."
    fi
  done
fi

# --- 6. MySQL-Version gegen docker-compose.yml ----------------------------------
# Die Referenz laeuft ueber eine Indirektion (${MYSQL_IMAGE:-mysql:8.4@sha256:...}),
# weil docker load keinen RepoDigest wiederherstellt und ein digest-gepinntes
# image: auf einem luftspaltgetrennten Host nicht aufloesbar ist. Die Version steht
# im STANDARD der Indirektion, also matcht das Muster `mysql:` an beliebiger Stelle
# der image-Zeile statt direkt dahinter. Diese Regel hat den Umbau gefangen, was
# genau ihr Zweck ist: der Zero-Match-Zweig unten ist kein leerer Gutfall.
mysql_ssot=$(sed -n 's/.*image:.*mysql:\([0-9][0-9]*\.[0-9][0-9]*\).*/\1/p' docker-compose.yml 2>/dev/null | head -n 1)
if [ -z "$mysql_ssot" ]; then
  fail no-ssot "MySQL-Version nicht aus docker-compose.yml lesbar; ohne SSoT kann keine Versionsnennung geprueft werden."
else
  for f in $active_docs; do
    if grep -nE "MySQL [0-9]+\.[0-9]+" "$f" 2>/dev/null | grep -vE "MySQL $mysql_ssot\b" >&2; then
      fail mysql-version "$f nennt eine andere MySQL-Version als docker-compose.yml ($mysql_ssot)."
    fi
  done
fi

# --- 7. Node-Version gegen ci.yml ------------------------------------------------
node_ssot=$(sed -n "s/.*node-version:[[:space:]]*'\{0,1\}\([0-9][0-9]*\)'\{0,1\}.*/\1/p" .github/workflows/ci.yml 2>/dev/null | head -n 1)
if [ -z "$node_ssot" ]; then
  fail no-ssot "Node-Version nicht aus .github/workflows/ci.yml lesbar; ohne SSoT kann keine Versionsnennung geprueft werden."
else
  for f in $active_docs; do
    if grep -nE '\bNode(\.js)? [0-9]+\b' "$f" 2>/dev/null | grep -vE "\bNode(\.js)? $node_ssot\b" >&2; then
      fail node-version "$f nennt eine andere Node-Version als ci.yml ($node_ssot)."
    fi
  done
fi

# --- 8. Stillgelegte Backup-Pfade nur mit Marker --------------------------------
for f in $active_docs; do
  if grep -nE 'Docker/scripts/(backup|restore)\.sh' "$f" 2>/dev/null | grep -viE 'stillgelegt|retired|hard-fail|brechen|bricht' >&2; then
    fail backup-path "$f referenziert Docker/scripts/backup.sh|restore.sh ohne Stilllegungs-Marker; kanonisch sind scripts/backup.sh + scripts/restore_test.sh (ADR-0017/E5)."
  fi
done

# --- 10. "`KONSTANTE` (aktuell N)" gegen den PHP-Wert ---------------------------
# Betriebsdoku nennt einen Schwellwert gern als "`VIRTUSPHERE_X` (aktuell 3)".
# Das ist die Doku-Variante der check-bounds-sync-Fehlerklasse: die Konstante
# wandert, der Satz laeuft weiter und liegt ab da falsch. Geprueft wird nur die
# Form, die den Namen mitliefert, also genau die Stellen, die einen Vergleich
# ueberhaupt zulassen; eine Zahl ohne Konstantennamen bleibt Review-Sache.
const_ssot_files='Docker/WebAPI/lib/constants.php Docker/WebAPI/lib/deploy_constants.php'
for f in $active_docs; do
  # grep -o statt sed s///: eine Zeile nennt oft zwei Konstanten ("... STALE_FACTOR
  # ..., aktuell 2x); danger ab ... FAILURE_STREAK_DANGER (aktuell 3)"), und eine
  # Substitution pro Zeile haette die erste stillschweigend uebergangen. Das
  # `[^`]*` zwischen Name und Zahl kann keinen zweiten Namen ueberspringen, so
  # dass jede Nennung an ihre eigene Zahl gebunden bleibt, mit oder ohne Klammer.
  grep -oE '`VIRTUSPHERE_[A-Z0-9_]+`[^`]*aktuell [0-9]+' "$f" 2>/dev/null \
    | sed -E 's/^`([A-Z0-9_]+)`.*aktuell ([0-9]+)$/\1 \2/' | while read -r name claimed; do
    actual=''
    for ssot in $const_ssot_files; do
      [ -f "$ssot" ] || continue
      value=$(sed -n "s/^const $name = \([0-9][0-9]*\);.*/\1/p" "$ssot" | head -n 1)
      [ -n "$value" ] && actual="$value"
    done
    if [ -z "$actual" ]; then
      echo "$f nennt \`$name\` mit einem Ist-Wert, aber die Konstante ist in keinem SSoT-File numerisch auffindbar." >&2
      echo "MISMATCH"
    elif [ "$actual" != "$claimed" ]; then
      echo "$f behauptet $name = $claimed, tatsaechlich $actual." >&2
      echo "MISMATCH"
    fi
  done | grep -q MISMATCH && fail const-mirror "$f nennt einen anderen Konstantenwert als der Code; an der Nennung fixen, nicht am SSoT."
done

# --- 11. Ein von aktiver Doku behaupteter Dateipfad braucht einen Erzeuger ------
#
# Das Go-Live-Runbook schickte den neuen Admin zu einer Erstpasswort-Datei unter
# Docker/WebAPI/logs/. Eine Suche ueber den ganzen Baum fand diesen Dateinamen
# ausschliesslich in der Doku: nichts im Repository schreibt die Datei je, eine
# frische Datenbank enthaelt keinen Benutzer, und das Portal legt keinen an. Der
# Admin am ersten Tag konnte sich nicht anmelden.
#
# Geprueft wird die Form, die eine Behauptung ueberhaupt nachpruefbar macht: ein
# Pfad mit Dateiendung in Backticks. Nennt ihn die Doku, muss ihn irgendetwas
# ausserhalb von docs/ erzeugen oder lesen (Code, Skript, Compose, Migration).
# Ein Pfad, der nur in Dokumentation existiert, IST der Befund.
doc_path_scope=''
for f in $active_docs; do
  [ -f "$f" ] && doc_path_scope="$doc_path_scope $f"
done
# Zeilen mit Entfernungs-Marker fallen vorher heraus (dieselbe Ausnahme wie bei
# Regel 8): "Phase D entfernt `portal/vorgaben.txt`" ist eine Loeschungsnotiz,
# keine Anweisung, und ein Erzeuger waere dort genau falsch.
# shellcheck disable=SC2086
path_pattern='`[A-Za-z0-9_./-]+/[A-Za-z0-9_-]+\.(txt|json|log|pem|pfx|sql|key|crt)`'
claimed_paths=$(grep -hE "$path_pattern" $doc_path_scope 2>/dev/null \
  | grep -viE 'entfern|removes|removed|geloescht|gel.scht|retired|stillgelegt|nicht mehr' \
  | grep -oE "$path_pattern" \
  | tr -d '`' | LC_ALL=C sort -u || true)
for path in $claimed_paths; do
  base=$(basename "$path")
  # Erzeuger/Leser: irgendeine Nennung ausserhalb von docs/. Auf dem Basisnamen,
  # weil Code den Pfad selten als ganzen String traegt (er baut ihn aus einem
  # Verzeichnis plus Dateinamen zusammen).
  # --exclude=*.log: eine Logzeile, die den Namen zufaellig enthaelt, ist kein
  # Erzeuger. Laufzeitlogs liegen im Arbeitsbaum (gitignored) und wuerden die
  # Regel abhaengig davon machen, was der Stack heute geschrieben hat.
  if ! grep -rqI --exclude-dir=docs --exclude-dir=.git --exclude-dir=vendor \
       --exclude-dir=node_modules --exclude-dir=dist --exclude='*.log' \
       -- "$base" "$real_root" 2>/dev/null; then
    fail phantom-path "aktive Doku nennt '$path', aber nichts ausserhalb von docs/ erzeugt oder liest '$base'; ein Pfad, der nur in der Doku existiert, schickt den Leser ins Leere."
  fi
done

# --- 12. Jeder .env.example-Schluessel, den compose braucht, steht im Runbook ---
#
# `APP_BIND_IP` fehlte im .env-Schritt des Runbooks, und sein Vorlagenwert
# 127.0.0.1 macht das Portal im LAN unerreichbar, waehrend der Stack vollstaendig
# gesund ist und jede dokumentierte Probe hostlokal laeuft. EnvBoot prueft diesen
# Wert als einzigen nicht, weil jeder Wert technisch gueltig ist.
#
# Geprueft wird genau die Klasse, die den Start kaputt machen kann: ein Schluessel,
# den docker-compose.yml als ${NAME} OHNE Vorgabewert interpoliert. Fehlt er im
# Go-Live-Runbook, kann niemand ihn setzen, ohne die Compose-Datei zu lesen.
env_example='.env.example'
compose_file='docker-compose.yml'
runbook='docs/operations/go-live.md'
if [ -f "$env_example" ] && [ -f "$compose_file" ] && [ -f "$runbook" ]; then
  # ${NAME} ja, ${NAME:-default} und ${NAME-default} nein: mit Vorgabewert
  # startet der Stack auch ohne den Schluessel.
  interpolated=$(grep -ohE '\$\{[A-Z][A-Z0-9_]*\}' "$compose_file" | tr -d '${}' | LC_ALL=C sort -u)
  for key in $interpolated; do
    grep -qE "^#? *$key=" "$env_example" || continue
    if ! grep -q "$key" "$runbook"; then
      fail env-key-unnamed "$key wird von $compose_file ohne Vorgabewert interpoliert und steht in $env_example, kommt aber in $runbook nicht vor; wer das Runbook abarbeitet, kann ihn nicht setzen."
    fi
  done
else
  fail no-ssot "$env_example, $compose_file oder $runbook fehlt; ohne alle drei ist die .env-Abdeckung nicht pruefbar."
fi

# --- 13. Migrationsstand auch in der Bereichsform -------------------------------
#
# Regel 4 faengt "Migrationsstand 0020" und "28 Migrationen", nicht aber die Form,
# in der ein Dokument den Umfang als Spanne behauptet ("Migrationen 0001-0028",
# "migrations 0001 bis 0028"). Die veraltet genauso mit der naechsten Migration,
# sieht aber wie eine fachliche Referenz aus, weil beide Enden konkrete Namen sind.
# Eine einzelne Nennung bleibt erlaubt, eine Spanne nicht.
for f in $active_docs; do
  if grep -nE '\b0[0-9]{3} ?(-|–|bis|to|\.\.\.?) ?0[0-9]{3}\b' "$f" >&2; then
    fail migration-range "$f behauptet einen Migrationsumfang als Spanne; die veraltet mit der naechsten Migration. Eine einzelne Migration namentlich zu nennen bleibt erlaubt."
  fi
done

# --- 14. Deutsche Dokumente schreiben echte Umlaute -----------------------------
#
# Dieselbe Regel, die fuer den Portalkatalog schon gilt (ADR-0014): ue/ae/oe statt
# ü/ä/ö liest sich wie eine Kodierungsstoerung und ist in einem Dokument, das ein
# wechselnder Administrator unter Druck liest, genau die falsche Stelle fuer Zweifel
# am Text. Geprueft wird eine Wortliste statt eines Musters, weil "neue" und
# "Steuerung" legitim sind: nur Schreibweisen, die im Deutschen nie richtig sind.
#
# Code faellt vorher heraus (Zeilen gezaehlt, nicht geloescht, damit die
# Zeilennummer stimmt): ein Bezeichner in Backticks oder in einem Codeblock ist ein
# zitierter Wert, kein Prosatext. `client_hostname erkennt \`Uebersprungen\`` nennt
# ein String-Literal, das ein PowerShell-Skript wirklich so vergleicht; dort einen
# Umlaut zu erzwingen wuerde die Doku falsch machen.
umlaut_words='fuer|ueber|Ueber|waehrend|naechst|moeglich|Moeglich|koenn|Koenn|wuerde|Wuerde|gehoert|loesch|Loesch|oeffn|Oeffn|aender|Aender|Groesse|laeng|Laeng|pruef|Pruef|ausfuehr|Ausfuehr|zurueck|Zurueck|muess|Muess|Loesung|Schluessel|Verzoeger|maessig|gemaess|hoechst|spaeter|gueltig|Gueltig|erfuellt|Uebersicht|bestaetig|Bestaetig|zusaetzlich|Zusaetzlich|taeglich|urspruenglich|beruecksichtig|verfuegbar|Verfuegbar|noetig|Noetig|stoer|Stoer'
for f in $active_docs; do
  prose=$(awk '/^[[:space:]]*```/ { fence = !fence; print ""; next } { print (fence ? "" : $0) }' "$f" \
    | sed 's/`[^`]*`//g')
  if printf '%s\n' "$prose" | grep -nE "\b($umlaut_words)" >&2; then
    fail doc-ascii-umlaut "$f schreibt Umlaute als ue/ae/oe (Zeilennummern oben, Code ausgenommen); deutsche Dokumente benutzen echte Umlaute wie der Portalkatalog."
  fi
done

# --- 15. Hardware-Version des createVMs-Playbooks vs. Support-Matrix ------------
#
# Das Playbook verdrahtet `version: 21`. vmx-21 ist laut Broadcom "not compatible
# with versions of ESXi prior to 8.0 Update 2", die Support-Matrix in
# docs/DEPLOYMENT.md verspricht aber ESXi 7.0 und 8.0. Dass der Altclient dieselbe
# 21 ausliefert und im Feld funktioniert, beweist nur, dass die Hosts im Feld schon
# 8.0 U2 oder neuer sind: ein erster Kunde auf 7.0 laeuft in einen harten
# Fehlschlag bei der VM-Erstellung, und unsere eigene Zusage ist die Ursache.
#
# Geprueft wird deshalb das Paar, nicht die Zahl: das Playbook ist die SSoT, und die
# Matrix muss die Untergrenze nennen, die diese Zahl verlangt.
create_playbook='Ansible/createVMs-ESXi_playbook.yml'
matrix_doc='docs/DEPLOYMENT.md'
if [ -f "$create_playbook" ] && [ -f "$matrix_doc" ]; then
  hw_version=$(sed -n 's/^[[:space:]]*version:[[:space:]]*\([0-9][0-9]*\).*/\1/p' "$create_playbook" | head -n 1)
  if [ -z "$hw_version" ]; then
    fail no-ssot "$create_playbook nennt keine hardware version mehr; Regel 15 kann die Support-Matrix nicht mehr dagegen halten."
  else
    # Die Untergrenze je vmx-Version. Waechst die Liste, waechst die Doku mit.
    case "$hw_version" in
      21) esxi_floor='8.0 Update 2' ;;
      20) esxi_floor='8.0' ;;
      19) esxi_floor='7.0 Update 2' ;;
      *)  esxi_floor='' ;;
    esac
    if [ -z "$esxi_floor" ]; then
      fail hw-version-unknown "$create_playbook setzt hardware version $hw_version, fuer die Regel 15 keine ESXi-Untergrenze kennt; Untergrenze belegen und hier eintragen."
    else
      # Nur die Zeilen, die von der Erstellung reden. Ein "8.0" irgendwo in der
      # Matrix ist kein Beleg: die Zeile ueber der Erstellungsgrenze nennt die
      # allgemein unterstuetzten Versionen, und die ist genau die Aussage, die zu
      # weit ist.
      create_rows=$(grep -iE 'creating|hardware version' "$matrix_doc" || true)
      if ! printf '%s\n' "$create_rows" | grep -qF "$esxi_floor"; then
        fail hw-version-matrix "$create_playbook erzeugt VMs mit hardware version $hw_version (verlangt ESXi $esxi_floor), aber $matrix_doc nennt diese Untergrenze nicht dort, wo es um die Erstellung geht; die Support-Matrix verspricht damit Hosts, auf denen die VM-Erstellung hart fehlschlaegt."
      fi
    fi
  fi
else
  fail no-ssot "$create_playbook oder $matrix_doc fehlt; die Hardware-Version ist nicht gegen die Support-Matrix pruefbar."
fi

# --- 16. vCenter bleibt Inventarquelle, nie Deploy-Ziel ------------------------
#
# Produktiv ist nur direktes Standalone-ESXi freigegeben. Ein vCenter liefert
# bewusst nur das partielle Read-only-Inventar (erstes Datacenter, VM-Suche im
# Root-Folder). Diese Supportgrenze darf weder zu "deploy yes" aufgeweicht noch
# durch das Entfernen der Matrixzeile still aus dem Handbuch verschwinden.
matrix_doc='docs/DEPLOYMENT.md'
if [ ! -f "$matrix_doc" ]; then
  fail no-ssot "$matrix_doc fehlt; die vCenter-Supportgrenze ist nicht pruefbar."
else
  vcenter_rows=$(grep -iE '^\|[^|]*vcenter[^|]*\|' "$matrix_doc" || true)
  if [ -z "$vcenter_rows" ]; then
    fail vcenter-deploy-boundary "$matrix_doc hat keine vCenter-Zeile in der Support-Matrix; deploy nein und die Read-only-Grenzen muessen ausdruecklich bleiben."
  else
    vcenter_contract_ok=1
    case "$vcenter_rows" in *'deploy **no**, partial read-only inventory'*) ;; *) vcenter_contract_ok=0 ;; esac
    case "$vcenter_rows" in *'first datacenter only'*) ;; *) vcenter_contract_ok=0 ;; esac
    case "$vcenter_rows" in *'folder `/`'*) ;; *) vcenter_contract_ok=0 ;; esac
    if [ "$vcenter_contract_ok" -ne 1 ]; then
      fail vcenter-deploy-boundary "$matrix_doc muss vCenter als deploy **no**, partielles Read-only-Inventar mit first-datacenter- und folder-/-Grenze ausweisen."
    fi
  fi
fi

# --- 9. Terminologie: SCCM ist ausgemustert, aktiver Text sagt MECM -------------
term_scope="$active_docs"
for p in Powershell-MECM tests/powershell PSScriptAnalyzerSettings.psd1 \
         scripts/run-pester.ps1 scripts/check-bounds-sync.php; do
  [ -e "$p" ] && term_scope="$term_scope $p"
done
# Word splitting is intentional: every scope path is repository-owned and has no spaces.
# shellcheck disable=SC2086
if grep -rinEI 'sccm|configmgr' $term_scope >&2; then
  fail sccm-terminology "aktiver Text verwendet den ausgemusterten Begriff SCCM/ConfigMgr; aktiver Text sagt MECM."
fi

if [ "$errors" -gt 0 ]; then
  echo "check-doc-semantics: $errors Fehler." >&2
  exit 1
fi
[ "$quiet" -eq 1 ] || echo "check-doc-semantics: Betriebsdoku behauptet keine veraltbaren Staende."
