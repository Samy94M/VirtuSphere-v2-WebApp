#!/usr/bin/env python3
"""PyYAML half of the yaml-roundtrip gate (Plan v2, AP5).

Loads the serverlist.yml/accounts.yml that render-golden-serverlist.php
produced from the golden mission fixture, using yaml.safe_load: the same
YAML-1.1 semantics Ansible applies when it reads the files on the control
node. Then deep-compares both documents against the fixture's ``expected``
block. This is the semantic proof the substring pins in PHPUnit cannot give:
a Norway token stays a string, a control byte survives as the same byte, an
int stays an int, WaitingTime stays the string the desktop client expects.

Deliberately NOT named test_*.py: the python-client-tests gate discovers
unittest files in this directory with the stdlib-only python image, which has
no PyYAML. This script runs inside the QA ansible image (yaml-roundtrip gate
in scripts/check.ps1), where PyYAML is pinned via ansible-core.

Usage: roundtrip_verify.py <golden-mission.json> <dir-with-yml>
Exit codes: 0 semantically identical; 1 mismatch (real finding);
2 unusable environment (missing files, no PyYAML).
"""

import json
import sys


def fail_env(message):
    print("ENV: %s" % message, file=sys.stderr)
    sys.exit(2)


try:
    import yaml
except ImportError:
    fail_env("PyYAML missing; run inside the QA ansible image")


def diff(expected, actual, path, findings):
    """Collect readable type/value differences between two loaded trees."""
    if type(expected) is not type(actual):
        findings.append(
            "%s: type %s != %s (expected %r, got %r)"
            % (path, type(expected).__name__, type(actual).__name__, expected, actual)
        )
        return
    if isinstance(expected, dict):
        for key in sorted(set(expected) | set(actual)):
            if key not in actual:
                findings.append("%s.%s: missing" % (path, key))
            elif key not in expected:
                findings.append("%s.%s: unexpected key" % (path, key))
            else:
                diff(expected[key], actual[key], "%s.%s" % (path, key), findings)
        return
    if isinstance(expected, list):
        if len(expected) != len(actual):
            findings.append("%s: length %d != %d" % (path, len(expected), len(actual)))
            return
        for index, (exp_item, act_item) in enumerate(zip(expected, actual)):
            diff(exp_item, act_item, "%s[%d]" % (path, index), findings)
        return
    if expected != actual:
        findings.append("%s: expected %r, got %r" % (path, expected, actual))


def main():
    if len(sys.argv) != 3:
        fail_env("usage: roundtrip_verify.py <fixture.json> <dir>")

    fixture_path, artifact_dir = sys.argv[1], sys.argv[2]

    try:
        with open(fixture_path, encoding="utf-8") as handle:
            fixture = json.load(handle)
    except (OSError, ValueError) as error:
        fail_env("fixture unreadable: %s" % error)

    expected = fixture.get("expected")
    if not isinstance(expected, dict) or "serverlist" not in expected or "accounts" not in expected:
        fail_env("fixture lacks the expected.serverlist/expected.accounts contract")

    loaded = {}
    for name in ("serverlist", "accounts"):
        path = "%s/%s.yml" % (artifact_dir, name)
        try:
            with open(path, encoding="utf-8") as handle:
                loaded[name] = yaml.safe_load(handle)
        except OSError as error:
            fail_env("rendered artifact missing: %s" % error)
        except yaml.YAMLError as error:
            # A generator emitting YAML the real loader rejects IS the finding.
            print("FAIL %s.yml does not parse under PyYAML: %s" % (name, error), file=sys.stderr)
            sys.exit(1)

    findings = []
    for name in ("serverlist", "accounts"):
        diff(expected[name], loaded[name], name, findings)

    if findings:
        print("FAIL yaml-roundtrip: %d semantic difference(s)" % len(findings), file=sys.stderr)
        for finding in findings:
            print("  " + finding, file=sys.stderr)
        sys.exit(1)

    print("OK yaml-roundtrip: serverlist.yml and accounts.yml survive PyYAML semantically")
    sys.exit(0)


if __name__ == "__main__":
    main()
