// Etappe 15.8/15.9: following one request through the audit log.
//
// The chain under test is the operator's: an error message hands out a
// correlation id, the audit row shows the same id with a copy button, the id
// filters the log to that one request, and the jobs it enqueued are listed
// beside it with a link into their logs. Every step of that is browser
// behaviour - the clipboard write, the confirmation, the field error on a
// partial id - and none of it is visible to a source scan.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const IP = '203.0.113.44';
// Sixteen lowercase hex characters, the shape virtusphere_correlation_id()
// mints. A memorable-but-not-hex id would simply be refused by the filter.
const TRACE = 'e2ecc0de00000001';

function seed() {
  return phpJson(`
$db = db();
$stmt = $db->prepare("INSERT INTO deploy_logs (ip, category, log_message, correlation_id, created_at, updated_at) VALUES (?, 'auth', 'e2e correlation fixture', ?, NOW(), NOW())");
$ip = '${IP}';
$trace = '${TRACE}';
$stmt->bind_param('ss', $ip, $trace);
$stmt->execute();

// A second row of the SAME request: a trace is only useful if it gathers more
// than one line.
$stmt = $db->prepare("INSERT INTO deploy_logs (ip, category, log_message, correlation_id, created_at, updated_at) VALUES (?, 'auth', 'e2e correlation fixture two', ?, NOW(), NOW())");
$stmt->bind_param('ss', $ip, $trace);
$stmt->execute();

// A row of a DIFFERENT request, so an exact filter has something to exclude.
$other = '${TRACE}ff';
$stmt = $db->prepare("INSERT INTO deploy_logs (ip, category, log_message, correlation_id, created_at, updated_at) VALUES (?, 'auth', 'e2e correlation foreign row', ?, NOW(), NOW())");
$stmt->bind_param('ss', $ip, $other);
$stmt->execute();

// A system job (no mission) carrying the same trace.
$stmt = $db->prepare("INSERT INTO deploy_jobs (mission_id, status, correlation_id, created_at, updated_at) VALUES (NULL, 'succeeded', ?, NOW(), NOW())");
$stmt->bind_param('s', $trace);
$stmt->execute();
$jobId = (int) $db->insert_id;

echo 'JSON' . json_encode(['jobId' => $jobId]) . 'JSON';
`);
}

function cleanup() {
  runPhp(`
$db = db();
$stmt = $db->prepare('DELETE FROM deploy_jobs WHERE correlation_id LIKE ?');
$like = '${TRACE}%';
$stmt->bind_param('s', $like);
$stmt->execute();
$stmt = $db->prepare('DELETE FROM deploy_logs WHERE ip = ?');
$ip = '${IP}';
$stmt->bind_param('s', $ip);
$stmt->execute();
echo 'CLEANED';
`);
}

test.beforeEach(() => cleanup());
test.afterAll(() => cleanup());

test('the audit table shows the correlation id and copies it on demand', async ({ page, context, browserName }) => {
  seed();
  // `clipboard-read`/`clipboard-write` are Chromium permission names; Firefox
  // and WebKit refuse them outright ("Unknown permission"), which failed this
  // test on both engines for a reason that had nothing to do with the portal.
  //
  // The split is deliberate rather than a skip. What every engine must show is
  // the contract from the portal rules: the copy control is a real button, it
  // is keyboard-operable, and it announces its outcome either way, because a
  // plain-HTTP LAN portal has no `navigator.clipboard` at all and the failure
  // is a normal branch. Reading the clipboard BACK is the one assertion that
  // needs the Chromium permission model, so only that part is conditional.
  const canReadClipboard = browserName === 'chromium';
  if (canReadClipboard) {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
  }
  await page.goto(`logs.php?tab=security&ip=${IP}`);

  const row = page.locator('tbody tr', { hasText: 'e2e correlation fixture two' }).first();
  await expect(row.locator('.correlation-id code')).toHaveText(TRACE);

  // Keyboard-operable because it is a real button: focus it and press Enter,
  // rather than clicking, so the assertion is about the control and not about
  // a mouse handler that happens to work.
  const copy = row.locator('.correlation-id .copy-button');
  await copy.focus();
  await expect(copy).toBeFocused();
  await copy.press('Enter');

  // Announced on every engine: either the copy worked or it did not, and the
  // operator is told which. An empty status would be the silent catch the
  // portal rules forbid.
  await expect(row.locator('[data-copy-status]'), 'the copy is confirmed visibly').toBeVisible();
  await expect(row.locator('[data-copy-status]')).not.toBeEmpty();

  // The value itself is verifiable only where the clipboard can be read back.
  if (canReadClipboard) {
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(TRACE);
  }

  // And the id stays selectable text regardless, which is the fallback the
  // help promises for a browser without clipboard access.
  await expect(row.locator('.correlation-id code')).toHaveText(TRACE);
});

test('an exact correlation filter shows one request and the jobs it enqueued', async ({ page }) => {
  const { jobId } = seed();

  await page.goto(`logs.php?tab=security&ip=${IP}`);
  await page.locator('tbody tr', { hasText: 'e2e correlation fixture two' }).first()
    .locator('.correlation-id a').click();
  await expect(page).toHaveURL(new RegExp(`correlation=${TRACE}`));

  // The trace gathers this request's rows and nothing else. The link drops the
  // IP filter on purpose: a trace is the whole request, not the part of it that
  // matched what was on screen before.
  const messages = page.locator('tbody td.log-message');
  await expect(messages).toHaveCount(2);
  await expect(messages.first()).toContainText('e2e correlation fixture');
  await expect(page.locator('tbody tr', { hasText: 'foreign row' })).toHaveCount(0);

  // Inside the trace the id no longer links: a link to the page you are on
  // reads as a different page.
  await expect(page.locator('tbody .correlation-id a')).toHaveCount(0);
  await expect(page.locator('tbody .correlation-id .copy-button').first()).toBeVisible();

  const jobs = page.locator('section.panel', { hasText: 'Deploy jobs of this request' });
  await expect(jobs, 'the jobs of the traced request are listed beside its rows').toBeVisible();
  await expect(jobs.locator('tbody tr')).toHaveCount(1);
  await expect(jobs.locator('tbody tr')).toContainText(String(jobId));
  await expect(jobs.locator('tbody tr'), 'a job without a mission is named, not blank').toContainText('System job');
  await expect(jobs.locator(`a[href="deploy_log.php?id=${jobId}"]`), 'admin holds deploy.run').toHaveCount(1);
  // The retention windows differ, so the panel says so: an old trace with audit
  // rows and no jobs must not read as "this request enqueued nothing".
  await expect(jobs).toContainText('job logs only for');
});

test('a partial correlation id is refused and nothing is queried', async ({ page }) => {
  seed();
  await page.goto(`logs.php?tab=security&correlation=${TRACE.slice(0, 6)}`);

  const field = page.locator('input[name="correlation"]');
  await expect(field, 'the rejected value stays visible').toHaveValue(TRACE.slice(0, 6));
  await expect(field).toHaveAttribute('aria-invalid', 'true');
  await expect(page.locator('.field-error')).toBeVisible();

  // Not "nothing found": the filter was never run, and saying otherwise reports
  // a result the page did not obtain.
  await expect(page.locator('td.table-empty')).toContainText('was not run');
  await expect(page.locator('tbody td.log-message')).toHaveCount(0);
  // A refused filter is not exportable either.
  await expect(page.locator('a[href*="export=csv"]')).toHaveCount(0);
  // And the jobs panel does not appear for a filter that was not run.
  await expect(page.locator('section.panel', { hasText: 'Deploy jobs of this request' })).toHaveCount(0);
});

test('the deploy job log names its own correlation id', async ({ page }) => {
  const { jobId } = seed();
  await page.goto(`deploy_log.php?id=${jobId}`);

  const card = page.locator('article.kpi', { hasText: 'Correlation ID' });
  await expect(card.locator('code')).toHaveText(TRACE);
  await expect(card.locator(`a[href*="correlation=${TRACE}"]`), 'the id leads to its audit trace').toHaveCount(1);
  await expect(card.locator('.copy-button')).toBeVisible();
});
