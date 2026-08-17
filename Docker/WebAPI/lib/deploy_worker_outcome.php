<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible_command.php';
require_once __DIR__ . '/audit_events.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/integration_health.php';
require_once __DIR__ . '/mac_import.php';
require_once __DIR__ . '/connection_errors.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/heartbeats.php';
require_once __DIR__ . '/repo/status_events.php';
require_once __DIR__ . '/ssh_transport_exceptions.php';
require_once __DIR__ . '/worker_heartbeat.php';

/**
 * The deploy worker's job-outcome state machine: compatibility facade over the
 * domain modules. Everything that turns a finished (or failed, cancelled,
 * reaped) playbook sequence into durable job and VM states lives behind this
 * require. It sits outside lib/deploy_worker.php because that file is the CLI
 * entrypoint and runs its main loop on require; this module keeps the status
 * decisions requireable, so the integration tests can drive them against a real
 * database without an SSH transport.
 *
 * The split is structural only (ADR-0006 amendment 2026-08-11): every function
 * kept its name, signature, SQL, transaction boundaries, exceptions and log
 * wording, and this path stays the single public require, so no caller changed.
 * Static scanners read the owner registry in lib/deploy_worker_modules.php
 * rather than one filename - a contract that scans a single file stops guarding
 * the moment that file is split, without turning red.
 *
 * Ownership map:
 * - deploy_worker_runtime.php   cancellation signal, phases, redaction,
 *                               service status row, heartbeat, ownership check
 * - deploy_worker_reaper.php    observer window and the stale-job reaper
 * - deploy_worker_vm_state.php  deploy_vms transitions and the MAC verdict
 * - deploy_worker_finish.php    conclude/cancel/fail, terminal write, audit
 */

require_once __DIR__ . '/deploy_worker_modules.php';
require_once __DIR__ . '/deploy_worker_runtime.php';
require_once __DIR__ . '/deploy_worker_vm_state.php';
require_once __DIR__ . '/deploy_worker_reaper.php';
require_once __DIR__ . '/deploy_worker_finish.php';
