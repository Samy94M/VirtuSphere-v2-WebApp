// TESTPLAN 3.5 / E6: the mission transfer and template flows. Export must hand
// the browser a real JSON download (header contract, parseable payload); the
// import goes upload -> preview -> confirm with DB proof, plus the rejection
// path for a non-JSON file. Template clone and save-as-template prove that the
// copy exists and carries the VMs.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const PREFIX = 'e2etransfer-';

// VM names are unique across missions (the import/clone guards enforce it),
// so every seed carries its own, or test 2 collides with what test 1 imported.
function seedMissionWithVm(name, vmName) {
  return phpJson(`
$db = db();
$id = repo_create_mission($db, ['mission_name' => '${name}', 'hypervisor_datastorage' => 'ds1', 'hypervisor_datacenter' => 'DC1', 'domain' => 'seed.example.local'], false, null);
$stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os) VALUES (?, '${vmName}', '${vmName}', 'Win11')");
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'JSON' . json_encode(['id' => $id]) . 'JSON';
`, ['lib/repo/missions.php']);
}

function seedTemplate(baseName, vmName) {
  return phpJson(`
$db = db();
$name = VIRTUSPHERE_TEMPLATE_PREFIX . '${baseName}';
$id = repo_create_mission($db, ['mission_name' => $name, 'hypervisor_datastorage' => 'ds1', 'hypervisor_datacenter' => 'DC1', 'domain' => 'seed.example.local'], true, null);
$stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os) VALUES (?, '${vmName}', '${vmName}', 'Win11')");
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'JSON' . json_encode(['id' => $id, 'name' => $name]) . 'JSON';
`, ['lib/repo/missions.php']);
}

function missionByName(name) {
  return phpJson(`
$db = db();
$stmt = $db->prepare('SELECT m.id, (SELECT COUNT(*) FROM deploy_vms v WHERE v.mission_id = m.id) AS vm_count FROM deploy_missions m WHERE m.mission_name = ? ORDER BY m.id DESC LIMIT 1');
$n = '${name}';
$stmt->bind_param('s', $n);
$stmt->execute();
echo 'JSON' . json_encode($stmt->get_result()->fetch_assoc() ?: null) . 'JSON';
`);
}

function cleanup() {
  runPhp(`
$db = db();
foreach (['${PREFIX}%', VIRTUSPHERE_TEMPLATE_PREFIX . '${PREFIX}%'] as $like) {
    $stmt = $db->prepare('DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)');
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $stmt = $db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
    $stmt->bind_param('s', $like);
    $stmt->execute();
}
$stmt = $db->prepare("DELETE FROM deploy_logs WHERE category = 'missions' AND log_message LIKE ?");
$like = '%${PREFIX}%';
$stmt->bind_param('s', $like);
$stmt->execute();
echo 'CLEANED';
`);
}

// The size limits come from the PHP constants and the running container's own
// ini, never from a number typed into this spec: the app limit is the product's
// SSoT and the two PHP buffers are what the operator's browser actually meets.
function uploadLimits() {
  const raw = phpJson(`
echo 'JSON' . json_encode([
    'app' => VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES,
    'version' => VIRTUSPHERE_MISSION_EXPORT_VERSION,
    'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
    'post_max_size' => (string) ini_get('post_max_size'),
]) . 'JSON';
`, ['lib/mission_transfer.php']);
  const bytes = (shorthand) => {
    const value = String(shorthand).trim();
    const number = parseInt(value, 10);
    const factor = { k: 1024, m: 1048576, g: 1073741824 }[value.slice(-1).toLowerCase()] || 1;
    return number * factor;
  };
  return {
    app: Number(raw.app),
    version: Number(raw.version),
    upload: bytes(raw.upload_max_filesize),
    post: bytes(raw.post_max_size),
  };
}

/**
 * The rendered sentence for a catalog key, in both locales, cut before the first
 * placeholder. The browser negotiates its own locale, so an assertion that names
 * one of them would pass or fail for the wrong reason.
 */
function messageVariants(key) {
  const texts = phpJson(`
$out = [];
foreach (Lang::LOCALES as $locale) { Lang::load($locale); $out[$locale] = __t('${key}'); }
Lang::load(Lang::DEFAULT_LOCALE);
echo 'JSON' . json_encode($out) . 'JSON';
`);
  return Object.values(texts).map((text) => text.split(/:[a-z_]+/)[0].trim()).filter((text) => text !== '');
}

async function expectAlertSays(page, key) {
  const variants = messageVariants(key);
  expect(variants.length, `the catalog has no text for ${key}`).toBeGreaterThan(0);
  // Wait for the alert before reading it: the POST answers with a redirect, so
  // reading straight after the response races the navigation (WebKit destroys
  // the execution context under it).
  await expect(page.locator('.alert-error').first()).toBeVisible();
  const alerts = (await page.locator('.alert-error').allTextContents()).join('\n');
  expect(
    variants.some((variant) => alerts.includes(variant)),
    `no alert matched ${key}; the page said: ${alerts}`,
  ).toBe(true);
}

function importAuditCount(name) {
  return phpJson(`
$db = db();
$stmt = $db->prepare("SELECT COUNT(*) AS c FROM deploy_logs WHERE category = 'missions' AND log_message LIKE ?");
$like = 'imported mission ${name}%';
$stmt->bind_param('s', $like);
$stmt->execute();
echo 'JSON' . json_encode($stmt->get_result()->fetch_assoc()) . 'JSON';
`).c;
}

/** A readable export document, so a spec can post a deliberately broken shape. */
function transferDocument(version, vms) {
  return {
    format_version: version,
    exported_at: new Date().toISOString(),
    mission: {
      mission_name: PREFIX + 'shapefile',
      mission_status: '',
      mission_notes: '',
      wds_vlan: '',
      hypervisor_datastorage: 'ds1',
      hypervisor_datacenter: 'DC1',
      domain: 'seed.example.local',
    },
    vms,
  };
}

function jsonUpload(name, document) {
  return { name, mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(document)) };
}

async function uploadFile(page, file) {
  await page.goto('missions.php?type=missions');
  const form = page.locator('form:has(input[name="action"][value="import_preview"])');
  await form.locator('input[type="file"]').setInputFiles(file);
  // Wait for the redirect TARGET, not for the POST: the POST answers 302, so
  // the POST response alone races the navigation, and the landing URL is not
  // always different from the current one (a rejected upload comes back to the
  // same address), which rules out waiting on the URL.
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'GET'
      && r.request().resourceType() === 'document'
      && r.url().includes('missions.php')),
    form.locator('button[type="submit"]').click(),
  ]);
  await page.waitForLoadState('domcontentloaded');
}

test.beforeAll(() => cleanup());
test.afterAll(() => cleanup());

// e2e-covers: mission_details.php:export
// e2e-covers: missions.php:import_preview
// e2e-covers: missions.php:import_confirm
test('export -> import preview -> confirm round-trips a mission with its VMs', async ({ page }) => {
  const sourceName = PREFIX + 'src';
  const importedName = PREFIX + 'imported';
  const seed = seedMissionWithVm(sourceName, 'E2ETVMSRC');

  // Export: the browser receives a real JSON download.
  await page.goto(`mission_details.php?id=${seed.id}`);
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.locator('form:has(input[name="action"][value="export"]) button').click(),
  ]);
  expect(download.suggestedFilename(), 'the download is named as JSON').toMatch(/\.json$/);
  const exportPath = await download.path();
  const payload = JSON.parse(require('node:fs').readFileSync(exportPath, 'utf8'));
  expect(payload.mission.mission_name, 'the export names the mission').toBe(sourceName);
  expect(payload.format_version, 'the export carries its format version').toBeGreaterThan(0);

  // Rejection path first: a non-JSON file is refused with a sentence.
  await page.goto('missions.php?type=missions');
  const uploadForm = page.locator('form:has(input[name="action"][value="import_preview"])');
  await uploadForm.locator('input[type="file"]').setInputFiles({
    name: 'not-json.txt',
    mimeType: 'text/plain',
    buffer: Buffer.from('this is not an export'),
  });
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('missions.php') && r.request().method() === 'POST'),
    uploadForm.locator('button[type="submit"]').click(),
  ]);
  await expect(page.locator('.alert-error').first(), 'the broken file is refused, localized').toBeVisible();
  expect(missionByName(importedName), 'nothing was imported').toBeNull();

  // The preview blocks structurally while the source mission still holds the
  // same VM names (they are unique across missions), so the source goes away
  // first: the real-world flow is export here, import on another instance.
  runPhp(`
$db = db();
$id = ${Number(seed.id)};
$stmt = $db->prepare('DELETE FROM deploy_vms WHERE mission_id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt = $db->prepare('DELETE FROM deploy_missions WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'REMOVED';
`);

  // Preview: the real export is accepted and the confirm step appears.
  const uploadForm2 = page.locator('form:has(input[name="action"][value="import_preview"])');
  await uploadForm2.locator('input[type="file"]').setInputFiles(exportPath);
  await Promise.all([
    page.waitForURL(/missions\.php\?type=missions&import=/),
    uploadForm2.locator('button[type="submit"]').click(),
  ]);
  const confirmForm = page.locator('form:has(input[name="action"][value="import_confirm"])');
  await expect(confirmForm, 'the preview offers the confirm step').toBeVisible();

  // Confirm under a new name: the mission and its VM exist in the DB.
  await confirmForm.locator('input[name="mission_name"]').fill(importedName);
  await Promise.all([
    page.waitForURL(/mission_details\.php\?id=/),
    confirmForm.locator('button[type="submit"]').click(),
  ]);
  const imported = missionByName(importedName);
  expect(imported, 'the imported mission exists').not.toBeNull();
  expect(Number(imported.vm_count), 'the VM came along').toBe(1);
});

// e2e-covers: mission_details.php:clone_template
test('clone_template: a template copy becomes a new mission with the VMs', async ({ page }) => {
  const template = seedTemplate(PREFIX + 'tpl', 'E2ETVMTPL');
  const cloneName = PREFIX + 'clone';

  await page.goto(`mission_details.php?id=${template.id}`);
  const form = page.locator('form:has(input[name="action"][value="clone_template"])');
  await form.locator('input[name="target_mission_name"]').fill(cloneName);
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('mission_details.php') && r.request().method() === 'POST'),
    form.locator('button[type="submit"]').click(),
  ]);

  const clone = missionByName(cloneName);
  expect(clone, 'the clone exists as a mission').not.toBeNull();
  expect(Number(clone.vm_count), 'the template VM was copied').toBe(1);
});

// e2e-covers: mission_details.php:save_as_template
test('save_as_template: a mission becomes a prefixed template with the VMs', async ({ page }) => {
  const missionName = PREFIX + 'to-tpl';
  const seed = seedMissionWithVm(missionName, 'E2ETVMSAVE');
  const templateName = phpJson(`echo 'JSON' . json_encode(['v' => VIRTUSPHERE_TEMPLATE_PREFIX . '${missionName}']) . 'JSON';`).v;

  await page.goto(`mission_details.php?id=${seed.id}`);
  const form = page.locator('form:has(input[name="action"][value="save_as_template"])');
  // The field pre-fills with prefix + mission name; keep it.
  await expect(form.locator('input[name="target_template_name"]')).toHaveValue(templateName);
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('mission_details.php') && r.request().method() === 'POST'),
    form.locator('button[type="submit"]').click(),
  ]);

  const template = missionByName(templateName);
  expect(template, 'the template exists').not.toBeNull();
  expect(Number(template.vm_count), 'the VM was copied into the template').toBe(1);
});

// The originally reported round trip: export a TEMPLATE, upload it again. The
// export does not distinguish a template from a mission, so the name suggestion
// carries the reserved prefix. That is a name problem, and a name problem is
// fixed in the field of this very form, so Confirm must stay usable.
// e2e-covers: missions.php:import_preview
test('a re-imported template reports the prefix at the field and still lets the operator confirm', async ({ page }) => {
  const template = seedTemplate(PREFIX + 'reimport', 'E2ETVMREIMP');
  const importedName = PREFIX + 'reimported';

  await page.goto(`mission_details.php?id=${template.id}`);
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.locator('form:has(input[name="action"][value="export"]) button').click(),
  ]);
  const exportPath = await download.path();

  await uploadFile(page, exportPath);
  await page.waitForURL(/import=/);
  const previewLink = page.url();

  const confirmForm = page.locator('form:has(input[name="action"][value="import_confirm"])');
  await expect(confirmForm, 'the preview rendered').toBeVisible();
  await expect(confirmForm.locator('input[name="mission_name"]'), 'the field offers the name from the file')
    .toHaveValue(template.name);
  // Two independent name findings stack here on purpose: the file carries the
  // reserved template prefix AND that exact name already exists (it is the
  // template this export came from). The prefix rule is the one under test.
  const fieldErrors = confirmForm.locator('.field-error');
  await expect(fieldErrors.first(), 'the name problem is reported at the field it is fixed in').toBeVisible();
  const fieldErrorText = (await fieldErrors.allTextContents()).join('\n');
  const prefixVariants = messageVariants('validate.mission_import_no_template');
  expect(
    prefixVariants.some((variant) => fieldErrorText.includes(variant)),
    `the template prefix rule is not named at the field; it said: ${fieldErrorText}`,
  ).toBe(true);
  await expect(
    confirmForm.locator('button[type="submit"]'),
    'a name problem must not disable the button that submits the corrected name',
  ).toBeEnabled();

  expect(importAuditCount(importedName), 'nothing is audited before the import').toBe(0);
  await confirmForm.locator('input[name="mission_name"]').fill(importedName);
  await Promise.all([
    page.waitForURL(/mission_details\.php\?id=/),
    confirmForm.locator('button[type="submit"]').click(),
  ]);

  const imported = missionByName(importedName);
  expect(imported, 'the corrected name imported').not.toBeNull();
  expect(Number(imported.vm_count), 'the template VM came along').toBe(1);
  expect(importAuditCount(importedName), 'a successful import writes exactly one missions audit row').toBe(1);

  // Back after a success: nothing expired here, the import consumed the
  // hand-off. Saying "expired" would assert a cause this path never checked.
  await page.goto(previewLink);
  await expectAlertSays(page, 'missions.import_err_gone');
});

// The name the operator just typed has to survive a refused confirm, together
// with the finding about it: coming back with the file's suggestion instead
// would explain a problem the form no longer shows.
// e2e-covers: missions.php:import_confirm
test('a refused confirm comes back with the name the operator typed', async ({ page }) => {
  const limits = uploadLimits();
  await uploadFile(page, jsonUpload('retype.json', transferDocument(limits.version, [{
    vm_name: 'E2ETRETYPE', vm_hostname: 'E2ETRETYPE', vm_os: 'Windows Server 2019',
    vm_domain: 'seed.example.local', interfaces: [], disks: [], packages: [],
  }])));

  const confirmForm = page.locator('form:has(input[name="action"][value="import_confirm"])');
  await expect(confirmForm).toBeVisible();

  // A template-prefixed target name: the browser pattern accepts it, the server
  // refuses it, and the hand-off has to survive.
  const rejected = phpJson("echo 'JSON' . json_encode(['v' => VIRTUSPHERE_TEMPLATE_PREFIX . '" + PREFIX + "retyped']) . 'JSON';").v;
  await confirmForm.locator('input[name="mission_name"]').fill(rejected);
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'GET'
      && r.request().resourceType() === 'document'
      && r.url().includes('missions.php')),
    confirmForm.locator('button[type="submit"]').click(),
  ]);

  const retryForm = page.locator('form:has(input[name="action"][value="import_confirm"])');
  await expect(retryForm, 'the hand-off survives a refused confirm').toBeVisible();
  await expect(retryForm.locator('input[name="mission_name"]'), 'the typed name came back with the finding')
    .toHaveValue(rejected);
  await expect(retryForm.locator('.field-error').first()).toBeVisible();
  expect(missionByName(rejected), 'nothing was written').toBeNull();
});

// e2e-covers: missions.php:import_preview
test('an oversize file is answered with the size sentence, not with a missing-file message', async ({ page }) => {
  const limits = uploadLimits();

  // Compared against the count BEFORE, not against zero: other specs in this
  // file import successfully, and the point here is that this rejection adds
  // nothing.
  const auditedBefore = importAuditCount(PREFIX);

  // Just past the app limit: the form hint and the server check both refuse it.
  await uploadFile(page, { name: 'too-big.json', mimeType: 'application/json', buffer: Buffer.alloc(limits.app + 1, 'x') });
  await expectAlertSays(page, 'missions.import_err_too_large');
  await expect(page.locator('form:has(input[name="action"][value="import_confirm"])')).toHaveCount(0);
  expect(importAuditCount(PREFIX), 'a rejected upload writes an import audit row').toBe(auditedBefore);

  // Past PHP's own upload_max_filesize but below post_max_size: PHP rejects the
  // file itself (UPLOAD_ERR_INI_SIZE) and the page still has to say "too large".
  // Folding that code into the missing-file sentence is what made an operator
  // pick the same file three times in a row.
  const overIni = limits.upload + 1024;
  expect(overIni, 'the probe has to stay under post_max_size or PHP sees no request at all').toBeLessThan(limits.post);
  await uploadFile(page, { name: 'over-ini.json', mimeType: 'application/json', buffer: Buffer.alloc(overIni, 'x') });
  await expectAlertSays(page, 'missions.import_err_too_large');
});

// e2e-covers: missions.php:import_preview
test('a scalar interfaces/disks list is a positioned finding with canonical counts', async ({ page }) => {
  const limits = uploadLimits();
  const document = transferDocument(limits.version, [{
    vm_name: 'E2ETSHAPE1',
    vm_hostname: 'E2ETSHAPE1',
    vm_os: 'Windows Server 2019',
    vm_domain: 'seed.example.local',
    interfaces: 'oops',
    disks: 'oops',
    packages: [],
  }]);

  await uploadFile(page, jsonUpload('broken-shape.json', document));

  const confirmForm = page.locator('form:has(input[name="action"][value="import_confirm"])');
  await expect(confirmForm, 'a document with broken sub-lists still renders its preview').toBeVisible();
  await expect(
    confirmForm.locator('button[type="submit"]'),
    'a finding inside the file has to disable the button, because this form cannot fix it',
  ).toBeDisabled();

  const findings = page.locator('.alert-error');
  await expect(findings.first()).toBeVisible();
  const text = (await findings.allTextContents()).join('\n');
  expect(text, 'the finding names the container it is about').toContain('interfaces');
  expect(text, 'the finding names the container it is about').toContain('disks');

  // The counts are canonical: a string is zero entries, not one. The order is
  // VMs, interfaces, disks, packages, so the whole row set is asserted rather
  // than "a zero appears somewhere".
  const previewPanel = page.locator('section.panel:has(form:has(input[name="action"][value="import_confirm"]))');
  const values = await previewPanel.locator('table tbody tr td').allTextContents();
  expect(values.slice(0, 4), 'a scalar sub-list must count as nothing, not as one entry').toEqual(['1', '0', '0', '0']);
  expect(missionByName(PREFIX + 'shapefile'), 'nothing was written').toBeNull();
});

// e2e-covers: missions.php:import_preview
test('a newer upload survives opening the older preview link', async ({ page }) => {
  const limits = uploadLimits();
  const vm = (name) => ({
    vm_name: name, vm_hostname: name, vm_os: 'Windows Server 2019',
    vm_domain: 'seed.example.local', interfaces: [], disks: [], packages: [],
  });

  // waitForURL rather than a bare page.url() assertion: WebKit commits the
  // redirect target a beat after the document response, so reading the URL
  // straight away can still return the address the POST was sent from.
  await uploadFile(page, jsonUpload('a.json', transferDocument(limits.version, [vm('E2ETHANDA')])));
  await page.waitForURL(/import=/);
  const linkA = page.url();

  await uploadFile(page, jsonUpload('b.json', transferDocument(limits.version, [vm('E2ETHANDB')])));
  await page.waitForURL(/import=/);
  const linkB = page.url();
  expect(linkB, 'the second upload has to get its own token').not.toBe(linkA);

  // The stale link of A must say only that there is nothing behind it ...
  await page.goto(linkA);
  await expectAlertSays(page, 'missions.import_err_gone');

  // ... and must not have taken B down with it. That unset() was the defect.
  await page.goto(linkB);
  await expect(
    page.locator('form:has(input[name="action"][value="import_confirm"])'),
    'opening the stale link of the earlier upload destroyed the newer preview',
  ).toBeVisible();
});

// Cancel deliberately leaves the hand-off in place; the help and the runbook say
// so, and this is where that promise is either true or not.
// e2e-covers: missions.php:import_preview
test('cancel leaves the view and browser back shows the same preview again', async ({ page }) => {
  const limits = uploadLimits();
  await uploadFile(page, jsonUpload('cancel.json', transferDocument(limits.version, [{
    vm_name: 'E2ETCANCEL', vm_hostname: 'E2ETCANCEL', vm_os: 'Windows Server 2019',
    vm_domain: 'seed.example.local', interfaces: [], disks: [], packages: [],
  }])));

  const confirmForm = page.locator('form:has(input[name="action"][value="import_confirm"])');
  await expect(confirmForm).toBeVisible();
  await confirmForm.locator('a.button-secondary').click();
  await expect(page.locator('form:has(input[name="action"][value="import_preview"])'), 'cancel returns to the upload form').toBeVisible();

  await page.goBack();
  await expect(
    page.locator('form:has(input[name="action"][value="import_confirm"])'),
    'the hand-off was discarded on cancel, which no text promises',
  ).toBeVisible();
});
