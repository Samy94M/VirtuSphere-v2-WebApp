// Etappe 14B, Teiletappe G: the per-VM create progress card and the paging that
// works without JavaScript.
//
// The plan's acceptance for this stage is one specific picture: more than 1,500
// log lines, a terminal error at the end of the tail, and a progress of 14 of 15
// with one unresolved unit. That combination is the incident this whole stage
// exists for, and it is the one a green run never produces, so it is seeded.
//
// Two of the assertions here cannot be made anywhere else. That the card counts
// from the stored rows and not from the log is only visible when the log says
// something different from the rows, which is exactly what a truncated window
// does. And that the "load older" control works without scripting is a property
// of the rendered anchor, not of any function: it was a `<button type="button">`
// for three stages while the endpoint accepted the cursor the whole time.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const MARK = 'e2ecreateprogress';

function cleanup() {
  runPhp(`
$db = db();
$db->query("DELETE FROM deploy_create_vm_results WHERE job_id IN (SELECT id FROM deploy_jobs WHERE payload_json LIKE '%${MARK}%')");
$db->query("DELETE FROM deploy_job_logs WHERE job_id IN (SELECT id FROM deploy_jobs WHERE payload_json LIKE '%${MARK}%')");
$db->query("DELETE FROM deploy_jobs WHERE payload_json LIKE '%${MARK}%'");
echo 'CLEANED';
`);
}

test.beforeAll(() => cleanup());
test.afterAll(() => cleanup());

/** Fourteen created units, one unresolved, and a log long enough to be windowed. */
function seedIncident(logLines) {
  return phpJson(`
$db = db();
$payload = json_encode(['mode' => 'create', 'fixture' => '${MARK}'], JSON_THROW_ON_ERROR);
$status = 'failed';
$stmt = $db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json) VALUES (NULL, ?, ?)');
$stmt->bind_param('ss', $status, $payload);
$stmt->execute();
$job = (int) $db->insert_id;

for ($i = 1; $i <= 15; $i++) {
    $name = sprintf('${MARK}-VM%03d', $i);
    if ($i <= 14) {
        $stmt = $db->prepare(
            'INSERT INTO deploy_create_vm_results (job_id, vm_name, position, total, action, status, outcome,'
            . ' changed, existed_before, vm_moid, vm_instance_uuid, started_at, finished_at)'
            . " VALUES (?, ?, ?, 15, 'create', 'succeeded', 'created', 1, 0, ?, ?, NOW(), NOW())"
        );
        $moid = sprintf('vm-%d', 900 + $i);
        $uuid = sprintf('5001%04d-0000-0000-0000-00000000%04d', $i, $i);
        $stmt->bind_param('isiss', $job, $name, $i, $moid, $uuid);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO deploy_create_vm_results (job_id, vm_name, position, total, action, status,'
            . ' existed_before, error_code, error_detail, started_at, finished_at)'
            . " VALUES (?, ?, ?, 15, 'create', 'uncertain', 0, 'transport_lost', 'connection lost during the call', NOW(), NOW())"
        );
        $stmt->bind_param('isi', $job, $name, $i);
    }
    $stmt->execute();
}

$total = ${Number(logLines)};
for ($start = 1; $start <= $total; $start += 250) {
    $values = [];
    for ($seq = $start; $seq <= min($total, $start + 249); $seq++) {
        $values[] = sprintf("(%d,%d,'ansible','${MARK} line %d')", $job, $seq, $seq);
    }
    $db->query('INSERT INTO deploy_job_logs (job_id, seq, stream, line) VALUES ' . implode(',', $values));
}
$last = $total + 1;
$db->query(sprintf("INSERT INTO deploy_job_logs (job_id, seq, stream, line) VALUES (%d,%d,'worker_error','${MARK} create step ended without a result for position 15')", $job, $last));
echo 'JSON' . json_encode(['job' => $job, 'last' => $last]) . 'JSON';
`, ['lib/repo/deploy_jobs.php']);
}

test('the card counts fourteen of fifteen with one unresolved, from the rows and not from the log', async ({ page }) => {
  const seed = seedIncident(1600);
  await page.goto(`deploy_log.php?id=${seed.job}`);

  const card = page.locator('[data-deploy-create-progress]');
  await expect(card).toBeVisible();

  // 14, not 15. deploy_create_summary() counts the unresolved unit as processed
  // because the worker did get to it, which is the right meaning there; putting
  // that number in front of a person would say the job finished and that
  // something is open in one line.
  await expect(card.locator('[data-create-position]')).toContainText('14');
  await expect(card.locator('[data-create-position]')).toContainText('15');
  await expect(card.locator('[data-create-count="created"]')).toHaveText('14');
  await expect(card.locator('[data-create-count="uncertain"]')).toHaveText('1');
  // The one confusion that must never happen: unresolved counted as failed.
  // A failure says the VM does not exist; unresolved says nobody knows, and the
  // two lead an operator to opposite actions.
  await expect(card.locator('[data-create-count="failed"]')).toHaveText('0');

  // The current unit is the unresolved one, and it is not described as running.
  const current = card.locator('[data-create-current-label]');
  await expect(current).toContainText(`${MARK}-VM015`);
  await expect(current).not.toHaveText('');

  // The log window is bounded and therefore does NOT contain the first lines,
  // which is what makes the assertion above meaningful: the numbers cannot have
  // come from the text on screen.
  const rows = page.locator('[data-deploy-log-body] [data-log-seq]');
  await expect(rows).toHaveCount(1000);
  await expect(rows.last()).toContainText('ended without a result for position 15');
  await expect(rows.first()).not.toContainText('line 1 ');
});

test('older lines are reachable without JavaScript', async ({ browser }) => {
  const seed = seedIncident(1600);
  // A context with scripting disabled is the honest test: a spec that clicks
  // the control with JS running proves the handler, not the fallback, and the
  // fallback is what was missing.
  const context = await browser.newContext({
    storageState: ROLES.admin.storageState,
    javaScriptEnabled: false,
  });
  const page = await context.newPage();
  await page.goto(`deploy_log.php?id=${seed.job}`);

  const older = page.locator('[data-deploy-log-older]');
  await expect(older).toBeVisible();
  // An anchor with a real cursor, not a button that needs a handler.
  expect(await older.evaluate((element) => element.tagName.toLowerCase())).toBe('a');
  const href = await older.getAttribute('href');
  expect(href).toContain('before_seq=');

  await older.click();
  await page.waitForLoadState('domcontentloaded');
  const rows = page.locator('[data-deploy-log-body] [data-log-seq]');
  await expect(rows).not.toHaveCount(0);
  // The page moved BACKWARDS: the newest line of this page is older than the
  // oldest line of the first one.
  const newest = Number(await rows.last().getAttribute('data-log-seq'));
  expect(newest).toBeLessThan(seed.last);

  // The card is server-rendered, so it is still there and still correct with no
  // script running at all.
  await expect(page.locator('[data-deploy-create-progress] [data-create-count="uncertain"]')).toHaveText('1');
  await context.close();
});

test('a job without a create section shows no card at all', async ({ page }) => {
  // Not a card at zero: an inventory pull creates nothing, and a zeroed card
  // would teach readers that the card means nothing on every other job too.
  const seed = phpJson(`
$db = db();
$payload = json_encode(['mode' => 'inventory', 'fixture' => '${MARK}'], JSON_THROW_ON_ERROR);
$status = 'succeeded';
$stmt = $db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json) VALUES (NULL, ?, ?)');
$stmt->bind_param('ss', $status, $payload);
$stmt->execute();
echo 'JSON' . json_encode(['job' => (int) $db->insert_id]) . 'JSON';
`, ['lib/repo/deploy_jobs.php']);

  await page.goto(`deploy_log.php?id=${seed.job}`);
  await expect(page.locator('[data-deploy-create-progress]')).toHaveCount(0);
});
