#!/usr/bin/env python3
"""Beweis, dass Playbookausgabe ohne PYTHONUNBUFFERED gesammelt ankommt.

Laeuft IM QA-Ansible-Image mit dem Repository unter /repo:ro:

    docker run --rm -v "$PWD:/repo:ro" -w /repo virtusphere-qa-ansible:latest \
        python3 /repo/Docker/qa-ansible/output-buffering-probe.py

Warum es dieses Werkzeug gibt: Der Create-Auftrag vom 13.08.2026 endete mit
"Remote command produced no output for 1800 seconds (idle timeout)", waehrend
vierzehn von fuenfzehn VMs auf ESXi entstanden. Der Fehler beweist einen
ausbleibenden Ausgabestrom, nicht das Ende des ESXi-Tasks. Python puffert
seinen stdout blockweise, sobald er kein Terminal ist, und der Worker liest
ueber eine SSH-Pipe: die Zeilen aller Schleifen-Items stehen dann bis zum Ende
des Prozesses im Puffer.

Gemessen werden BEIDE Faelle, und beide sind Teil der Aussage:

  * Kontrollfall ohne PYTHONUNBUFFERED. Er muss das Puffern zeigen. Faellt er
    weg, ist der zweite Fall wertlos: eine Laufzeit, die ohnehin nie puffert,
    laesst jede Unbuffered-Behauptung durchgehen, und das Gate bewachte
    nichts mehr. Wird dieser Fall rot, hat sich die Laufzeit geaendert und die
    Annahme des Plans gehoert neu geprueft, nicht die Schwelle.
  * Produktionsfall mit PYTHONUNBUFFERED=1. Er muss die Zeilen einzeln und
    waehrend des Laufs liefern.

Die Schwellen sind Anteile der jeweils gemessenen Gesamtdauer, keine
Sekundenwerte: eine langsamere Maschine verschiebt beide Zahlen gemeinsam. Auf
dem gepinnten Image (ansible-core 2.19.11, Python 3.13) kamen die drei
Item-Zeilen gepuffert bei 9,69 s von 9,83 s an und ungebuffert bei 3,88 s,
6,50 s und 9,09 s von 9,26 s.

Exitcodes: 0 Vertrag erfuellt, 1 Vertrag verletzt, 2 Umgebung unbrauchbar.
"""

from __future__ import annotations

import os
import subprocess
import sys
import time

PLAYBOOK = os.path.join(os.path.dirname(os.path.abspath(__file__)), "output-buffering-probe.yml")

# Das Label aus loop_control der Fixture. Es steht in der Ergebniszeile jedes
# Items, und nur diese Zeilen sind Messpunkte.
ITEM_MARK = "vs-probe-"
EXPECTED_ITEMS = 3

# Gepuffert: die erste Item-Zeile darf erst am Ende erscheinen. 0.85 laesst der
# Messung Luft nach unten, ohne den Unterschied zum zweiten Fall aufzuweichen.
BUFFERED_FIRST_MIN_RATIO = 0.85
# Ungebuffert: die erste Item-Zeile ist deutlich vor dem Ende da, und die drei
# Zeilen liegen sichtbar auseinander statt gemeinsam am Schluss.
UNBUFFERED_FIRST_MAX_RATIO = 0.70
UNBUFFERED_SPREAD_MIN_RATIO = 0.30


def run_case(unbuffered: bool) -> tuple[float, list[float], int]:
    """Startet das Playbook und gibt (Gesamtdauer, Ankunftszeiten, Exitcode)."""
    env = dict(os.environ)
    env.setdefault("HOME", "/tmp")
    env.setdefault("ANSIBLE_LOCAL_TEMP", "/tmp/.ansible-tmp")
    # Farbcodes und Callback-Plugins wuerden die Zeilenform aendern, nicht ihre
    # Ankunftszeit; der Standard-Callback bleibt bewusst der gemessene.
    env["ANSIBLE_FORCE_COLOR"] = "0"
    env.pop("PYTHONUNBUFFERED", None)
    if unbuffered:
        env["PYTHONUNBUFFERED"] = "1"

    started = time.monotonic()
    process = subprocess.Popen(
        ["ansible-playbook", PLAYBOOK],
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        env=env,
        bufsize=0,
    )
    marks: list[float] = []
    assert process.stdout is not None
    for raw in iter(process.stdout.readline, b""):
        if ITEM_MARK in raw.decode("utf-8", "replace"):
            marks.append(time.monotonic() - started)
    process.stdout.close()
    code = process.wait()
    return time.monotonic() - started, marks, code


def describe(label: str, total: float, marks: list[float]) -> str:
    arrivals = ", ".join("%.2f s (%.0f%%)" % (m, 100.0 * m / total) for m in marks)
    return "%s: Gesamtdauer %.2f s, Item-Zeilen bei %s" % (label, total, arrivals)


def main() -> int:
    if not os.path.exists(PLAYBOOK):
        print("INFRA: Fixture %s fehlt" % PLAYBOOK, file=sys.stderr)
        return 2

    results = {}
    for label, unbuffered in (("gepuffert", False), ("ungebuffert", True)):
        try:
            total, marks, code = run_case(unbuffered)
        except FileNotFoundError:
            print("INFRA: ansible-playbook nicht im PATH (falsches Image?)", file=sys.stderr)
            return 2
        if code != 0:
            print("INFRA: Fixture-Playbook endete im Fall %s mit Exitcode %d" % (label, code), file=sys.stderr)
            return 2
        if len(marks) != EXPECTED_ITEMS:
            # Kein Treffer heisst hier nicht "sauber": ohne Messpunkte kann der
            # Vergleich nichts mehr aussagen und darf nicht still gruen sein.
            print(
                "INFRA: Fall %s lieferte %d statt %d Item-Zeilen; das Label '%s' "
                "der Fixture und die Callback-Ausgabe passen nicht mehr zusammen"
                % (label, len(marks), EXPECTED_ITEMS, ITEM_MARK),
                file=sys.stderr,
            )
            return 2
        results[label] = (total, marks)
        print(describe(label, total, marks))

    failures = []

    total, marks = results["gepuffert"]
    first_ratio = marks[0] / total
    if first_ratio < BUFFERED_FIRST_MIN_RATIO:
        failures.append(
            "Der Kontrollfall puffert nicht mehr: die erste Item-Zeile kam nach %.0f%% der "
            "Laufzeit statt fruehestens %.0f%%. Damit beweist der zweite Fall nichts mehr. "
            "Pruefen, ob die Laufzeit ihr Pufferverhalten geaendert hat, bevor eine Schwelle "
            "angefasst wird." % (100.0 * first_ratio, 100.0 * BUFFERED_FIRST_MIN_RATIO)
        )

    total, marks = results["ungebuffert"]
    first_ratio = marks[0] / total
    spread_ratio = (marks[-1] - marks[0]) / total
    if first_ratio > UNBUFFERED_FIRST_MAX_RATIO:
        failures.append(
            "Mit PYTHONUNBUFFERED=1 kam die erste Item-Zeile erst nach %.0f%% der Laufzeit "
            "(erlaubt bis %.0f%%)." % (100.0 * first_ratio, 100.0 * UNBUFFERED_FIRST_MAX_RATIO)
        )
    if spread_ratio < UNBUFFERED_SPREAD_MIN_RATIO:
        failures.append(
            "Mit PYTHONUNBUFFERED=1 lagen erste und letzte Item-Zeile nur %.0f%% der Laufzeit "
            "auseinander (erwartet mindestens %.0f%%); die Zeilen kommen weiterhin gemeinsam."
            % (100.0 * spread_ratio, 100.0 * UNBUFFERED_SPREAD_MIN_RATIO)
        )

    if failures:
        for line in failures:
            print("FAIL: " + line, file=sys.stderr)
        return 1

    print("OK: ohne PYTHONUNBUFFERED kommt die Ausgabe gesammelt am Ende, mit ihr laufend.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
