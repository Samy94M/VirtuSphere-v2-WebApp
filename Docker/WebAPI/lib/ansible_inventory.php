<?php

declare(strict_types=1);

/**
 * ESXi inventory transport and parsing (ADR-0023): compatibility facade.
 *
 * Split out of ansible.php, which had grown past its size budget (ADR-0006) and
 * was carrying two unrelated jobs: generating deploy artifacts, and running plus
 * decoding the read-only inventory pull. Everything here belongs to the second.
 *
 * The playbook is a dumb transport: it forwards raw module output inside one
 * base64-JSON marker line. Every field path is resolved on this side, because
 * PHP is unit-testable without an ESXi host and Jinja is not.
 *
 * The split below is structural only (ADR-0006, Etappe 7 of
 * docs/audits/2026-08-11-deploy-reliability-master-plan.md): every function
 * kept its name, signature, marker and log wording, and this path stays the
 * single public require, so no caller changed. Static scanners read the owner
 * registry in lib/ansible_inventory_modules.php rather than one filename - a
 * contract that scans a single file stops guarding the moment that file is
 * split, without turning red.
 *
 * Ownership map:
 * - ansible_inventory_artifacts.php   job artifact prep, remote command
 * - ansible_inventory_parse.php       marker decode, core normalization,
 *                                     playbook-failure categorization
 * - ansible_inventory_datastore.php   datastore health, per-query outcome
 * - ansible_inventory_capability.php  ESXi capability facts, host facts
 */

require_once __DIR__ . '/ansible.php';
require_once __DIR__ . '/esxi_datastore_health.php';
require_once __DIR__ . '/ansible_inventory_modules.php';
require_once __DIR__ . '/ansible_inventory_artifacts.php';
require_once __DIR__ . '/ansible_inventory_parse.php';
require_once __DIR__ . '/ansible_inventory_datastore.php';
require_once __DIR__ . '/ansible_inventory_capability.php';
