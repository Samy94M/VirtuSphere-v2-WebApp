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
mysql_ssot=$(sed -n 's/.*image:[[:space:]]*mysql:\([0-9][0-9]*\.[0-9][0-9]*\).*/\1/p' docker-compose.yml 2>/dev/null | head -n 1)
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
