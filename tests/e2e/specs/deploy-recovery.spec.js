// Etappe 13R: the two per-job recovery actions on a job log.
//
// The remote execution path ships disabled (8R-O), so no job reaches this block
// on its own. The row is therefore seeded directly: the point of the proof is
// that the block renders the stored state, that the cleanup retry is REFUSED
// when the evidence moved between looking and clicking, and that documenting an
// external check appends rather than replaces. None of that depends on the
// remote path being enabled, and all of it would silently rot without a proof.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const MARK = 'e2erecovery';

function cleanup() {
  runPhp(`
$db = db();
$db->query("DELETE FROM deploy_recovery_resolutions WHERE job_id IN (SELECT id FROM deploy_jobs WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE '${MARK}%'))");
$db->query("DELETE FROM deploy_remote_executions WHERE job_id IN (SELECT id FROM deploy_jobs WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE '${MARK}%'))");
$db->query("DELETE FROM deploy_job_logs WHERE job_id IN (SELECT id FROM deploy_jobs WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE '${MARK}%'))");
$db->query("DELETE FROM deploy_logs WHERE event_code IN ('deploy.remote_cleanup_retried','deploy.recovery_documented')");
$db->query("DELETE FROM deploy_jobs WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE '${MARK}%')");
$db->query("DELETE FROM deploy_missions WHERE mission_name LIKE '${MARK}%'");
echo 'CLEANED';
`);
}

test.beforeAll(() => cleanup());
test.afterAll(() => cleanup());

// e2e-covers: deploy.php:remote_cleanup_retry
// e2e-covers: deploy.php:remote_review_document
// e2e-covers: deploy_log.php:remote_cleanup_retry
// e2e-covers: deploy_log.php:remote_review_document
test('recovery block: a failed cleanup can be requeued, a moved evidence hash is refused, a check is appended', async ({ page }) => {
  const seed = phpJson(`
$db = db();
$admin = (int) ($db->query("SELECT id FROM deploy_users WHERE role='admin' LIMIT 1")->fetch_assoc()['id'] ?? 1);
$missionId = repo_create_mission($db, [
    'mission_name' => '${MARK}-mission',
    'hypervisor_datastorage' => 'ds',
    'hypervisor_datacenter' => 'dc',
    'domain' => 'recovery.qa.invalid',
], false, $admin);
$db->query("INSERT INTO deploy_jobs (mission_id, user_id, status, payload_json, created_at, updated_at)
    VALUES ({$missionId}, {$admin}, 'failed', '{\\"mode\\":\\"full\\"}', NOW(), NOW())");
$jobId = (int) $db->insert_id;
// A durable remote execution whose cleanup failed. Everything else is the
// neutral state the foundation writes; only cleanup_state is the case here.
$db->query("INSERT INTO deploy_remote_executions
    (job_id, job_attempt, step_key, protocol_version, run_token, unit_name, remote_dir, instance_id, generation_id,
     controller_state, effect_state, reconciliation_state, cleanup_state, cleanup_attempts, cleanup_auto_attempts,
     cleanup_last_error, result_sha256)
    VALUES ({$jobId}, 1, 'create', 1, REPLACE(UUID(),'-',''), '${MARK}-unit-{$jobId}', '/tmp/${MARK}-{$jobId}',
     RANDOM_BYTES(16), RANDOM_BYTES(16), 'exited_nonzero', 'unknown', 'manual_required', 'failed', 3, 3,
     'rm: cannot remove', REPEAT('a', 64))");
$executionId = (int) $db->insert_id;
echo 'JSON' . json_encode(['job' => $jobId, 'execution' => $executionId]) . 'JSON';
`, ['lib/repo/missions.php']);

  await page.goto(`deploy_log.php?id=${seed.job}`);
  const block = page.locator('section.panel', { hasText: 'Recovery' }).first();
  await expect(block, 'the block renders because a remote execution exists').toBeVisible();
  await expect(block, 'the stored cleanup state is shown, not guessed').toContainText('failed');

  // The evidence moves under the operator between rendering and clicking. The
  // retry must be refused, not silently applied to a case they never saw.
  runPhp(`
$db = db();
$db->query("UPDATE deploy_remote_executions SET result_sha256 = REPEAT('b', 64) WHERE id = ${seed.execution}");
echo 'MOVED';
`);
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('deploy.php') && r.request().method() === 'POST'),
    block.locator('form:has(input[name="action"][value="remote_cleanup_retry"]) button').click(),
  ]);
  let state = phpJson(`
$db = db();
$row = $db->query("SELECT cleanup_state, cleanup_auto_attempts FROM deploy_remote_executions WHERE id = ${seed.execution}")->fetch_assoc();
$audit = (int) $db->query("SELECT COUNT(*) AS c FROM deploy_logs WHERE event_code = 'deploy.remote_cleanup_retried' AND result = 'failure'")->fetch_assoc()['c'];
echo 'JSON' . json_encode(['cleanup' => $row['cleanup_state'], 'auto' => (int) $row['cleanup_auto_attempts'], 'audit' => $audit]) . 'JSON';
`);
  expect(state.cleanup, 'a moved evidence hash leaves the state alone').toBe('failed');
  expect(state.auto, 'and does not reset the automatic counter').toBe(3);
  expect(state.audit, 'the refusal is itself recorded').toBe(1);

  // Now the honest path: reload so the form carries the current hash.
  await page.reload();
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('deploy.php') && r.request().method() === 'POST'),
    block.locator('form:has(input[name="action"][value="remote_cleanup_retry"]) button').click(),
  ]);
  state = phpJson(`
$db = db();
$row = $db->query("SELECT cleanup_state, cleanup_attempts, cleanup_auto_attempts FROM deploy_remote_executions WHERE id = ${seed.execution}")->fetch_assoc();
echo 'JSON' . json_encode(['cleanup' => $row['cleanup_state'], 'attempts' => (int) $row['cleanup_attempts'], 'auto' => (int) $row['cleanup_auto_attempts']]) . 'JSON';
`);
  expect(state.cleanup, 'with unchanged evidence the cleanup is queued again').toBe('eligible');
  expect(state.auto, 'the automatic counter restarts').toBe(0);
  expect(state.attempts, 'the cumulative count is kept: eleven failures stay eleven').toBe(3);

  // Documenting an external check appends one row and keeps the operator's own
  // words out of the audit trail.
  await page.reload();
  await block.locator('#recovery-resolution').selectOption('confirmed_not_applied');
  await block.locator('#recovery-reason').fill('Checked on the ESXi host: no VM of this mission exists.');
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('deploy.php') && r.request().method() === 'POST'),
    block.locator('form:has(input[name="action"][value="remote_review_document"]) button').click(),
  ]);
  const documented = phpJson(`
$db = db();
$row = $db->query("SELECT resolution_code, resolution_scope, reason, LENGTH(evidence_fingerprint) AS fp FROM deploy_recovery_resolutions WHERE job_id = ${seed.job} ORDER BY id DESC LIMIT 1")->fetch_assoc();
$count = (int) $db->query("SELECT COUNT(*) AS c FROM deploy_recovery_resolutions WHERE job_id = ${seed.job}")->fetch_assoc()['c'];
$audit = $db->query("SELECT context_json FROM deploy_logs WHERE event_code = 'deploy.recovery_documented' ORDER BY id DESC LIMIT 1")->fetch_assoc();
echo 'JSON' . json_encode([
    'code' => $row['resolution_code'],
    'scope' => $row['resolution_scope'],
    'reason' => $row['reason'],
    'fingerprint' => (int) $row['fp'],
    'count' => $count,
    'context' => (string) ($audit['context_json'] ?? ''),
]) . 'JSON';
`);
  expect(documented.code).toBe('confirmed_not_applied');
  expect(documented.scope).toBe('remote_execution');
  expect(documented.reason).toContain('no VM of this mission exists');
  expect(documented.fingerprint, 'the entry is bound to the state it was written about').toBe(64);
  expect(documented.count, 'exactly one appended entry').toBe(1);
  expect(documented.context, 'the free text stays out of the audit row').not.toContain('no VM of this mission exists');
  expect(documented.context).toContain('confirmed_not_applied');
});
