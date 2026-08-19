<?php

declare(strict_types=1);

/**
 * Deploy-mode planning and the remote shell commands the worker runs:
 * compatibility facade.
 *
 * This is still the only require path callers need (ADR-0006 refactoring
 * contract, Etappe 8 of docs/audits/2026-08-11-deploy-reliability-master-plan.md):
 * it loads the domain modules in a deterministic order, so a caller that
 * required this file before the split keeps every function it had. The split
 * is structural only; every function kept its name, signature, marker wording
 * and quoting.
 *
 * Static scanners read the owner registry in lib/ansible_command_modules.php
 * rather than this filename - a contract that scans a single file stops
 * guarding the moment that file is split, without turning red.
 *
 * Ownership map:
 * - ansible_command_shell.php      the one shell-quoting primitive
 * - ansible_command_modes.php      payload/VM filter, mode-to-playbook SSoT,
 *                                  step markers, mission remote command
 * - ansible_command_preflight.php  preflight components and command, embedded
 *                                  probe sources, output readers
 */

require_once __DIR__ . '/ansible_command_shell.php';
require_once __DIR__ . '/ansible_command_modes.php';
require_once __DIR__ . '/ansible_command_preflight.php';
