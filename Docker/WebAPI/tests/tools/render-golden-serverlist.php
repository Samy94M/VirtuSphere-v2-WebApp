<?php

declare(strict_types=1);

/**
 * CLI half of the yaml-roundtrip gate (Plan v2, AP5): renders the golden
 * mission fixture through the REAL generators (ansible_serverlist_yml /
 * ansible_accounts_yml) into serverlist.yml and accounts.yml. The other half,
 * Ansible/tests/roundtrip_verify.py, loads both files with PyYAML (Ansible's
 * loader) and deep-compares them against the fixture's `expected` block.
 *
 * Usage: php render-golden-serverlist.php <golden-mission.json> <out-dir>
 *
 * Exit codes: 0 rendered; 1 generator failure (a real finding);
 * 2 unusable environment (fixture/out dir missing or unreadable).
 */

require_once dirname(__DIR__, 2) . '/lib/ansible.php';

if ($argc !== 3) {
    fwrite(STDERR, "usage: render-golden-serverlist.php <fixture.json> <out-dir>\n");
    exit(2);
}

[, $fixturePath, $outDir] = $argv;

if (!is_file($fixturePath)) {
    fwrite(STDERR, "fixture missing: {$fixturePath}\n");
    exit(2);
}
if (!is_dir($outDir) || !is_writable($outDir)) {
    fwrite(STDERR, "out dir missing or not writable: {$outDir}\n");
    exit(2);
}

$raw = file_get_contents($fixturePath);
if ($raw === false) {
    fwrite(STDERR, "fixture unreadable: {$fixturePath}\n");
    exit(2);
}

try {
    $fixture = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, 'fixture is not valid JSON: ' . $e->getMessage() . "\n");
    exit(2);
}

foreach (['mission', 'vms', 'esxi_credential', 'esxi_secret', 'ansible_credential', 'api_base_url', 'expected'] as $key) {
    if (!array_key_exists($key, $fixture)) {
        fwrite(STDERR, "fixture lacks key: {$key}\n");
        exit(2);
    }
}

try {
    $serverlist = ansible_serverlist_yml(
        $fixture['mission'],
        $fixture['vms'],
        (int) ($fixture['power_cycle_wait'] ?? VIRTUSPHERE_POWERCYCLE_WAIT_DEFAULT),
        (string) ($fixture['host_datacenter'] ?? ''),
        (string) ($fixture['esxi_host_name'] ?? '')
    );
    $accounts = ansible_accounts_yml(
        $fixture['esxi_credential'],
        (string) $fixture['esxi_secret'],
        $fixture['ansible_credential'],
        (string) $fixture['api_base_url']
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'generator failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (file_put_contents($outDir . DIRECTORY_SEPARATOR . 'serverlist.yml', $serverlist) === false
    || file_put_contents($outDir . DIRECTORY_SEPARATOR . 'accounts.yml', $accounts) === false
) {
    fwrite(STDERR, "cannot write YAML artifacts to {$outDir}\n");
    exit(2);
}

fwrite(STDOUT, "rendered serverlist.yml and accounts.yml to {$outDir}\n");
exit(0);
