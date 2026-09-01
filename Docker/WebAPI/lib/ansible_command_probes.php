<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command_shell.php';

/**
 * The sources VirtuSphere embeds into remote preflight commands.
 *
 * Split out of ansible_command_preflight.php when that file passed the ADR-0006
 * budget: the check map, the command assembly and the output readers are one
 * domain, and the shell/Python programs they carry are another. Nothing here
 * knows what a component is called or how a failure is reported; each function
 * returns one self-contained probe.
 *
 * Two rules hold for every source below. It runs on an air-gapped host, so it
 * uses nothing but the interpreter it is handed; and it is normalized to LF
 * before it is quoted, because this repository is checked out with CRLF on
 * Windows and a carriage return inside a shell line makes `TERM` an invalid
 * signal and `exit 1` an illegal number.
 */

/**
 * Probe for the async state directory. Deliberately not a fixed path: the real
 * directory belongs to a job's remote handle and does not exist yet at preflight
 * time. What is checked is the permission to make one.
 */
function ansible_async_workspace_probe_command(): string
{
    $source = <<<'SH'
d=$(mktemp -d) || exit 1
trap 'rm -rf -- "$d"' EXIT INT TERM
mkdir -p -- "$d/async" || exit 1
chmod 0700 -- "$d/async" || exit 1
: > "$d/async/probe" || exit 1
chmod 0600 -- "$d/async/probe" || exit 1
[ -r "$d/async/probe" ] || exit 1
rm -f -- "$d/async/probe" || exit 1
rmdir -- "$d/async" || exit 1
SH;

    return 'sh -c ' . ansible_sh_quote(ansible_embedded_script_source($source)) . ' 2>&1';
}

/**
 * Normalizes an embedded script to LF before it is quoted into a remote command.
 *
 * This file is checked out with CRLF on a Windows developer machine and with LF
 * on the build host, so a heredoc carries whatever the checkout had. A carriage
 * return survives the quoting and reaches the remote shell inside every line:
 * `trap ... TERM\r` is an invalid signal specification and `exit 1\r` is an
 * illegal number, so the probe failed with two errors on Alpine and would have
 * behaved differently depending on where the image was built. Python tolerates
 * the same bytes, which is exactly why this must not be left to chance for the
 * shell sources next to it.
 */
function ansible_embedded_script_source(string $source): string
{
    return str_replace(["\r\n", "\r"], "\n", $source);
}

/**
 * The collection version pinned in Ansible/requirements.yml, which is the SSoT
 * (ADR-0025). Parsed rather than duplicated: a second literal here would keep
 * passing while it quietly stopped meaning the same thing.
 */
function ansible_pinned_collection_version(): string
{
    static $version = null;
    if ($version !== null) {
        return $version;
    }
    $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'Ansible' . DIRECTORY_SEPARATOR . 'requirements.yml';
    $source = is_readable($path) ? (string) file_get_contents($path) : '';
    if (preg_match('/name:\s*community\.vmware\s*\R\s*version:\s*"?([0-9][0-9A-Za-z.\-]*)"?/', $source, $matches) !== 1) {
        // No guessed default: a pin that cannot be read must not silently
        // become "any version is fine".
        throw new RuntimeException('The community.vmware pin could not be read from Ansible/requirements.yml.');
    }

    return $version = $matches[1];
}

/**
 * Probe that compares the host's installed runtime against what the pinned
 * collection itself demands.
 *
 * Two questions, one component, both answered from artifacts on the host:
 *
 *  1. Is the installed community.vmware the pinned one? A host that silently
 *     carries another version runs different modules than every test in this
 *     repository.
 *  2. Does the installed ansible-core satisfy the `requires_ansible` of that
 *     installed collection? The floor is read out of the collection's own
 *     meta/runtime.yml, so no number is repeated in PHP and a collection bump
 *     brings its new floor with it.
 *
 * The probe prints a one-line reason on failure and nothing on success.
 */
function ansible_runtime_version_probe_command(string $pinnedVersion): string
{
    $source = <<<'PY'
import json, re, subprocess, sys

pinned = sys.argv[1]

listing = subprocess.run(
    ["ansible-galaxy", "collection", "list", "--format", "json"],
    capture_output=True, text=True,
)
try:
    paths = json.loads(listing.stdout)
except (TypeError, ValueError):
    paths = {}

found = None
for root, collections in (paths.items() if isinstance(paths, dict) else []):
    entry = collections.get("community.vmware") if isinstance(collections, dict) else None
    if isinstance(entry, dict) and entry.get("version"):
        found = (root, str(entry["version"]))
        break

if found is None:
    sys.stderr.write("community.vmware is not installed for this ansible-galaxy\n")
    raise SystemExit(1)

root, installed = found
if installed != pinned:
    sys.stderr.write(
        "community.vmware %s is installed, but this VirtuSphere pins %s\n" % (installed, pinned)
    )
    raise SystemExit(1)

def version_tuple(text):
    return tuple(int(part) for part in re.findall(r"\d+", text)[:3])

try:
    with open("%s/community/vmware/meta/runtime.yml" % root, "r", encoding="utf-8") as handle:
        runtime = handle.read()
except OSError:
    sys.stderr.write("the installed community.vmware carries no readable meta/runtime.yml\n")
    raise SystemExit(1)

match = re.search(r"requires_ansible:\s*['\"]?>=\s*([0-9][0-9.]*)", runtime)
if match is None:
    sys.stderr.write("the installed community.vmware declares no ansible-core floor\n")
    raise SystemExit(1)
floor = match.group(1)

core = subprocess.run(["ansible", "--version"], capture_output=True, text=True)
core_match = re.search(r"core\s+([0-9][0-9.]*)", core.stdout or "")
if core_match is None:
    sys.stderr.write("the installed ansible-core version could not be read\n")
    raise SystemExit(1)

if version_tuple(core_match.group(1)) < version_tuple(floor):
    sys.stderr.write(
        "ansible-core %s is installed, but community.vmware %s requires %s or newer\n"
        % (core_match.group(1), installed, floor)
    )
    raise SystemExit(1)
PY;

    return 'command -v ansible-galaxy >/dev/null 2>&1 && python3 -c '
        . ansible_sh_quote(ansible_embedded_script_source($source)) . ' ' . ansible_sh_quote($pinnedVersion) . ' 2>&1';
}

function ansible_collection_probe_command(string $module): string
{
    $source = <<<'PY'
import json, subprocess, sys
module = sys.argv[1]
result = subprocess.run(
    ["ansible-doc", "-t", "module", "--json", module],
    capture_output=True,
    text=True,
)
try:
    documents = json.loads(result.stdout)
except (TypeError, ValueError):
    documents = {}
if result.returncode != 0 or not isinstance(documents, dict) or module not in documents:
    sys.stdout.write(result.stdout)
    sys.stderr.write(result.stderr)
    raise SystemExit(result.returncode or 1)
PY;

    return 'command -v ansible-doc >/dev/null 2>&1 && python3 -c '
        . ansible_sh_quote(ansible_embedded_script_source($source)) . ' ' . ansible_sh_quote($module) . ' 2>&1';
}
