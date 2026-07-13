// TESTPLAN 3.4: field round-trip through the DB and back to every render
// context. The value under test is mission_name, because one field renders in
// three escaping contexts: the missions list cell (HTML text), the mission
// detail heading (HTML text) and the rename input (HTML attribute), and it
// accepts hostile input (the only restriction is "no spaces"). vm_name was the
// obvious candidate but is charset-restricted to a NetBIOS name, so it rejects
// the matrix rather than round-tripping it.
//
// The POST response renders sticky values from form_remember() and proves
// nothing about persistence, so every check reads the *stored* row: MySQL
// directly for bytes, a fresh GET for each render context.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { execFileSync } = require('node:child_process');

test.use({ storageState: ROLES.admin.storageState });

const PHP_CONTAINER = process.env.VIRTUSPHERE_PHP_CONTAINER || 'virtusphere-v2-webapp-php-1';
const PREFIX = 'e2ert-'; // no spaces: mission_name forbids them

// Each probe carries a distinct escaping/encoding concern and no spaces. The
// prefix keeps them recognizable and cleanable.
const PROBES = [
  { label: 'umlauts + ß (utf8mb4)', value: PREFIX + 'Übermäßig-Straße-ß' },
  { label: 'HTML metachars, no double-escape', value: PREFIX + 'Tom&<b>fett</b>' },
  { label: 'XSS payload rendered as text', value: PREFIX + '<script>alert(1)</script>' },
  { label: 'attribute breakout attempt', value: PREFIX + '"><img/src=x>' },
  { label: 'SQL metachars', value: PREFIX + "';DROP-TABLE-vms;--" },
  { label: '4-byte UTF-8 emoji', value: PREFIX + '🚀🖥️ok' },
  { label: 'YAML metachars', value: PREFIX + 'x:y#{a}&*b' },
];

const HTML_ENTITY = /&(?:lt|gt|amp|quot|#0?39|#x27);/;

function runPhp(body) {
  const php = '<?php\nrequire_once "/var/www/html/lib/bootstrap.php";\n' + body;
  return execFileSync('docker', ['exec', '-i', PHP_CONTAINER, 'php'], {
    input: php,
    encoding: 'utf8',
    timeout: 15000,
  });
}

function missionColumn(missionId, column) {
  const out = runPhp(`
$db = db();
$stmt = $db->prepare('SELECT \`${column}\` AS v FROM deploy_missions WHERE id = ? LIMIT 1');
$id = ${Number(missionId)};
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
echo 'V_START' . ($row ? $row['v'] : '') . 'V_END';
`);
  const m = out.match(/V_START([\s\S]*)V_END/);
  return m ? m[1] : null;
}

function cleanup() {
  runPhp(`
$db = db();
$like = '${PREFIX}%';
$stmt = $db->prepare('DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)');
$stmt->bind_param('s', $like);
$stmt->execute();
$stmt = $db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
$stmt->bind_param('s', $like);
$stmt->execute();
echo 'CLEANED';
`);
}

test.beforeAll(() => cleanup());
test.afterAll(() => cleanup());

// The create button, scoped to the create form. Not `.first()` submit on the
// page: the layout header carries a logout form whose submit comes first.
function createButton(page) {
  return page.locator('form:has(input[name="action"][value="create"]) button[type="submit"]');
}

async function createMissionViaUi(page, name) {
  await page.goto('missions.php?type=missions');
  await page.locator('form:has(input[name="action"][value="create"]) input[name="mission_name"]').fill(name);
  await Promise.all([page.waitForURL(/missions\.php/), createButton(page).click()]);
  // The redirect lands back on the list; resolve the id of the row we just made.
  return idByName(name);
}

function idByName(name) {
  const out = runPhp(`
$db = db();
$stmt = $db->prepare('SELECT id FROM deploy_missions WHERE mission_name = ? ORDER BY id DESC LIMIT 1');
$n = ${phpString(name)};
$stmt->bind_param('s', $n);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
echo 'MID=' . ($row ? (int) $row['id'] : 0);
`);
  const m = out.match(/MID=(\d+)/);
  return m ? Number(m[1]) : 0;
}

function phpString(value) {
  return "'" + String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
}

for (const probe of PROBES) {
  test(`mission_name round-trip: ${probe.label}`, async ({ page }) => {
    page.on('dialog', async (d) => {
      await d.dismiss();
      throw new Error(`XSS: a ${d.type()} dialog fired for ${JSON.stringify(probe.value)}`);
    });

    const missionId = await createMissionViaUi(page, probe.value);
    expect(missionId, 'the mission was created').toBeGreaterThan(0);

    // 1) Backend: byte-exact and stored raw, never as HTML entities.
    const stored = missionColumn(missionId, 'mission_name');
    expect(stored, 'stored value is byte-exact').toBe(probe.value);
    expect(stored, 'no HTML entity leaked into storage (double-escape)').not.toMatch(HTML_ENTITY);

    // 2) Render context A: the missions list cell (HTML text). The name is plain
    // text in the first cell; find the row by its detail link, then read that
    // cell. A `<script>` in the value proves it renders as literal text.
    await page.goto('missions.php?type=missions');
    const row = page.locator('tr', {
      has: page.locator(`a[href*="mission_details.php?id=${missionId}"]`),
    });
    await expect(row, 'the mission appears in the list').toHaveCount(1);
    expect((await row.locator('td').first().textContent()).trim(), 'list cell renders the value verbatim')
      .toBe(probe.value);

    // 3) Render context B + C: the detail heading (HTML text) and the rename
    // input (HTML attribute). The attribute context is the one a breakout probe
    // targets: `"><img>` would end the value attribute early.
    await page.goto(`mission_details.php?id=${missionId}`);
    expect((await page.locator('h2').first().textContent()).trim(), 'detail heading renders the value')
      .toBe(probe.value);
    expect(await page.locator('input[name="mission_name"]').inputValue(), 'rename input round-trips the value')
      .toBe(probe.value);
  });
}

test('no-op re-save keeps the value unchanged (idempotency)', async ({ page }) => {
  const value = PREFIX + 'Idem&<x>';
  const missionId = await createMissionViaUi(page, value);
  expect(missionColumn(missionId, 'mission_name')).toBe(value);

  // Re-save the detail form without changing anything.
  await page.goto(`mission_details.php?id=${missionId}`);
  await Promise.all([
    page.waitForURL(/mission_details\.php/),
    page.locator('form:has(input[name="action"][value="update"]) button[type="submit"]').first().click(),
  ]);
  expect(missionColumn(missionId, 'mission_name'), 'value unchanged after a no-op re-save').toBe(value);
});
