#!/usr/bin/env python3
"""Beweis der zwei Tatsachen, auf denen das Loeschen von accounts.yml ruht (K21).

Laeuft IM QA-Ansible-Image mit dem Repository unter /repo:ro:

    docker run --rm -v "$PWD:/repo:ro" -w /repo virtusphere-qa-ansible:latest \
        python3 /repo/Docker/qa-ansible/vars-files-lifetime-probe.py

Warum es dieses Werkzeug gibt: K21 (VT-04) loescht die Zugangsdatei
accounts.yml mit dem ESXi-Passwort an jedem Auftragsende, auch wenn ein Schritt
nicht zurueckgemeldet hat. Die erste Begruendung lautete, ein laufendes
Playbook habe seine vars_files beim Play-Start gelesen. Die Astra-Pruefung hat
das fuer ansible-core 2.19.11 widerlegt: vars/manager.py laedt jede
vars_files-Datei mit cache='vaulted', parsing/dataloader.py cached eine
unverschluesselte Datei dann nicht, und get_vars() laeuft je Task. Das Loeschen
ist trotzdem zulaessig, aber aus einem anderen Grund: ein Playbook ohne
lesenden Kanal endet an seiner naechsten Ausgabe, und nach ADR-0042 laeuft nur
ein Worker. Beide Tatsachen gehoeren der Laufzeit, nicht dem Code dieses
Repositories; ein Ansible-Update koennte sie lautlos kippen.

Gemessen werden zwei Faelle, jeder mit Kontrollfall:

  * vars_files je Task. Mit -e vs_delete=true loescht Task 2 die Datei; Task 3
    im block und Task 4 im always muessen an "'esxi_password' is undefined"
    scheitern. Der Kontrollfall ohne Loeschen muss in beiden den Wert lesen,
    sonst beweist der Prueffall nichts. Wird der Prueffall gruen (Werte noch
    da), cached Ansible wieder: dann ist die Begruendung im Amendment
    veraltet, nicht diese Probe.
  * Leser weg. Mit PYTHONUNBUFFERED=1 (wie der Produktionsbefehl) und
    stderr auf stdout (wie 2>&1) schliesst der Treiber den Lesekanal nach der
    ersten Ausgabe. Das Playbook muss enden, ohne den Marker-Task zu
    erreichen. Im Kontrollfall liest der Treiber bis zum Ende, und der Marker
    muss entstehen. Faengt eine Laufzeit BrokenPipeError ab und laeuft weiter,
    wird dieser Fall rot, und das Loeschen unter einem nicht
    zurueckgekehrten Schritt verliert seine Grundlage.

Nicht gemessen: ein echter sshd. Dass sshd beim Kanalende die stdout-Pipe des
Kindes schliesst und kein HUP sendet, ist Lesart (FM-03), hier steht eine
lokale Pipe.

Exitcodes: 0 Vertrag erfuellt, 1 Vertrag verletzt, 2 Umgebung unbrauchbar.
"""

from __future__ import annotations

import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
LIFETIME_PLAYBOOK = os.path.join(HERE, "vars-files-lifetime-probe.yml")
READER_PLAYBOOK = os.path.join(HERE, "reader-gone-probe.yml")

SECRET = "vs-probe-secret"
UNDEFINED = "'esxi_password' is undefined"
FIRST_OUTPUT = "vs-first"
# Ohne Leser endet das Playbook an seiner naechsten Ausgabe nach der Pause von
# drei Sekunden; die Grenze faengt nur einen haengenden Lauf ab.
RUN_TIMEOUT_SECONDS = 120


def base_env() -> dict[str, str]:
    env = dict(os.environ)
    env.setdefault("HOME", "/tmp")
    env.setdefault("ANSIBLE_LOCAL_TEMP", "/tmp/.ansible-tmp")
    env["ANSIBLE_FORCE_COLOR"] = "0"
    env["ANSIBLE_NOCOLOR"] = "1"
    # Wie der Produktionsbefehl (ansible_remote_steps): die Zeilen gehen sofort
    # in die Pipe. Gepuffert kaeme die erste Zeile erst am Ende, und der Fall
    # "Leser weg" wuerde etwas anderes messen.
    env["PYTHONUNBUFFERED"] = "1"
    return env


def lifetime_case(workdir: str, delete: bool) -> tuple[int, str]:
    """Spielt das vars_files-Playbook in workdir ab und gibt (Exitcode, Ausgabe)."""
    for name in os.listdir(workdir):
        os.remove(os.path.join(workdir, name))
    playbook = os.path.join(workdir, "probe.yml")
    shutil.copyfile(LIFETIME_PLAYBOOK, playbook)
    with open(os.path.join(workdir, "accounts.yml"), "w", encoding="utf-8") as handle:
        handle.write("esxi_password: %s\n" % SECRET)
    command = ["ansible-playbook", playbook]
    if delete:
        command += ["-e", "vs_delete=true"]
    completed = subprocess.run(
        command,
        cwd=workdir,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        env=base_env(),
        timeout=RUN_TIMEOUT_SECONDS,
        check=False,
    )
    return completed.returncode, completed.stdout.decode("utf-8", "replace")


def reader_case(workdir: str, read_to_end: bool) -> tuple[int, bool, str]:
    """Startet das Leser-Playbook; gibt (Exitcode, Marker vorhanden, gelesene Ausgabe)."""
    marker = os.path.join(workdir, "marker-%s" % ("control" if read_to_end else "gone"))
    process = subprocess.Popen(
        ["ansible-playbook", READER_PLAYBOOK, "-e", "vs_marker=%s" % marker],
        cwd=workdir,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        env=base_env(),
        bufsize=0,
    )
    assert process.stdout is not None
    seen = []
    for raw in iter(process.stdout.readline, b""):
        line = raw.decode("utf-8", "replace")
        seen.append(line)
        if not read_to_end and FIRST_OUTPUT in line:
            break
    # Der Leser geht: das Kanalende, das ein abgebrochener SSH-Kanal dem
    # entfernten Playbook hinterlaesst.
    process.stdout.close()
    try:
        code = process.wait(timeout=RUN_TIMEOUT_SECONDS)
    except subprocess.TimeoutExpired:
        process.kill()
        process.wait()
        code = None
    return (-999 if code is None else code), os.path.exists(marker), "".join(seen)


def main() -> int:
    for path in (LIFETIME_PLAYBOOK, READER_PLAYBOOK):
        if not os.path.exists(path):
            print("INFRA: Fixture %s fehlt" % path, file=sys.stderr)
            return 2
    if shutil.which("ansible-playbook") is None:
        print("INFRA: ansible-playbook nicht im PATH (falsches Image?)", file=sys.stderr)
        return 2

    failures: list[str] = []
    workdir = tempfile.mkdtemp(prefix="vs-vars-files-")
    try:
        code, output = lifetime_case(workdir, delete=False)
        if code != 0 or "vs-block=%s" % SECRET not in output or "vs-always=%s" % SECRET not in output:
            print(output, file=sys.stderr)
            print(
                "INFRA: Kontrollfall ohne Loeschen liest den Wert nicht in block und always "
                "(Exitcode %d); ohne ihn beweist der Prueffall nichts." % code,
                file=sys.stderr,
            )
            return 2
        print("vars_files Kontrollfall: Wert in block und always gelesen, Exitcode 0.")

        code, output = lifetime_case(workdir, delete=True)
        if "vs-before=%s" % SECRET not in output:
            print(output, file=sys.stderr)
            print("INFRA: Prueffall liest den Wert schon vor dem Loeschen nicht.", file=sys.stderr)
            return 2
        still_there = [label for label in ("vs-block=", "vs-always=") if label + SECRET in output]
        undefined = output.count(UNDEFINED)
        print(
            "vars_files Prueffall: Exitcode %d, %d-mal \"%s\", danach noch gelesen: %s"
            % (code, undefined, UNDEFINED, ", ".join(still_there) or "nichts")
        )
        if still_there or undefined < 2 or code == 0:
            failures.append(
                "Nach dem Loeschen von accounts.yml lasen block und always den Wert weiter (%s) oder "
                "scheiterten nicht beide an '%s'. Ansible cached vars_files wieder: Amendment ADR-0041 "
                "und webapi R15 neu begruenden, nicht diese Probe anpassen."
                % (", ".join(still_there) or "Exitcode %d" % code, UNDEFINED)
            )

        code, marker, output = reader_case(workdir, read_to_end=True)
        if code != 0 or not marker:
            print(output, file=sys.stderr)
            print(
                "INFRA: Kontrollfall mit Leser erreicht den Marker nicht (Exitcode %d); "
                "ohne ihn beweist der Fall ohne Leser nichts." % code,
                file=sys.stderr,
            )
            return 2
        print("Leser Kontrollfall: Marker erreicht, Exitcode 0.")

        code, marker, output = reader_case(workdir, read_to_end=False)
        if FIRST_OUTPUT not in output:
            print(output, file=sys.stderr)
            print("INFRA: die erste Ausgabe kam nie an; der Fall misst nichts.", file=sys.stderr)
            return 2
        print(
            "Leser weg: Exitcode %s, Marker %s."
            % ("Zeitgrenze" if code == -999 else str(code), "erreicht" if marker else "nicht erreicht")
        )
        if marker or code in (0, -999):
            failures.append(
                "Ein Playbook ohne lesenden Kanal lief weiter (Marker %s, Exitcode %s). Damit kann es nach "
                "dem Loeschen von accounts.yml weiterlaufen und seine Werte verlieren; die Begruendung im "
                "Amendment ADR-0041 traegt nicht mehr."
                % ("erreicht" if marker else "nicht erreicht", "Zeitgrenze" if code == -999 else str(code))
            )
    finally:
        shutil.rmtree(workdir, ignore_errors=True)

    if failures:
        for line in failures:
            print("FAIL: " + line, file=sys.stderr)
        return 1

    print(
        "OK: vars_files wird je Task gelesen (geloescht heisst ab dem naechsten Task undefiniert, "
        "auch im always), und ein Playbook ohne Leser endet vor seinem naechsten Task."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
