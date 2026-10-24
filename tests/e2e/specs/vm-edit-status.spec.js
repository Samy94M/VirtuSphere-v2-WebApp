// ST-2 VM editor: the status panel shows the derived phase and its findings,
// the technical block keeps the raw values, and the status history is open
// without a click. There is no action here, so no e2e-covers marker. The suite
// renders EN; the checks accept the DE wording as well.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const PREFIX = 'e2evmedit-';
const UUID = '5f1c9a3e-2b7d-4c1e-9a8f-0e2d3c4b5a69';
const MOID = 'vm-e2e-4711';
const HOST = 'esxi-e2e.example.test';

/**
 * A mission with one VM that is handed to MECM: registered, OS installing, with
 * a previous MECM device, a bound ESXi placement and two status events, one of
 * them the technical note 'mecm update id', and one client event.
 */
function seedVm(name) {
  return phpJson(
    `
$db = db();
$missionId = repo_create_mission($db, ['mission_name' => '${name}', 'hypervisor_datastorage' => 'ds1', 'hypervisor_datacenter' => 'DC1', 'domain' => 'seed.example.local'], false, null);
$vmHost = 'E2EV' . $missionId . 'E';
$mecmId = 'E2E-RES-4711';
$oldId = 'E2E-OLD-R1';
$uuid = '${UUID}';
$moid = '${MOID}';
$esxiHost = '${HOST}';
$stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, mecm_rollout_hostname, mecm_rollout_revision, vm_os, lifecycle_state, mecm_sync_state, vm_status, mecm_id, mecm_previous_id, vm_instance_uuid, vm_moid, vm_bound_esxi_host, updated) VALUES (?, 'E2EVMEDIT1', ?, ?, 3, 'Win11', 'os_installing', 'registered', '4/5 OS Installing', ?, ?, ?, ?, ?, 0)");
$stmt->bind_param('isssssss', $missionId, $vmHost, $vmHost, $mecmId, $oldId, $uuid, $moid, $esxiHost);
$stmt->execute();
$vmId = (int) $db->insert_id;
$claimKey = strtolower($vmHost);
$stmt = $db->prepare("INSERT INTO deploy_vm_hostname_claims (hostname_key, vm_id, desired_claim, rollout_claim) VALUES (?, ?, 1, 1)");
$stmt->bind_param('si', $claimKey, $vmId);
$stmt->execute();
$mac = '00:50:56:EE:00:01';
$stmt = $db->prepare("INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac, mode) VALUES (?, '', '', '', 'WDS', ?, 'dhcp')");
$stmt->bind_param('is', $vmId, $mac);
$stmt->execute();
repo_record_vm_status_event($db, $vmId, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY, VIRTUSPHERE_STATUS_REGISTERED, 'created from portal', null);
repo_record_vm_status_event($db, $vmId, VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, VIRTUSPHERE_STATUS_OS_INSTALLING, 'mecm update id', null);
repo_record_client_event($db, $vmId, $mac, VIRTUSPHERE_CLIENT_PHASE_HOSTNAME, VIRTUSPHERE_CLIENT_EVENT_FINISHED, 'e2e detail', '127.0.0.1');
echo 'JSON' . json_encode([
    'missionId' => $missionId,
    'vmId' => $vmId,
    'noteDe' => virtusphere_vm_status_note_text('mecm update id', 'de'),
    'noteEn' => virtusphere_vm_status_note_text('mecm update id', 'en'),
]) . 'JSON';
`,
    ['lib/repo/missions.php', 'lib/repo/status_events.php', 'lib/repo/client_events.php', 'lib/vm_timeline.php']
  );
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

test('VM editor status: the phase, the findings, the technical block and the timeline are visible without a click', async ({ page }) => {
  const seed = seedVm(PREFIX + 'status');

  await page.goto(`vm_edit.php?mission_id=${seed.missionId}&vm_id=${seed.vmId}`);

  // Header: the derived phase, never the raw x/5 stage.
  await expect(
    page.locator('.vm-status-head .badge'),
    'the phase is the header badge'
  ).toHaveText(/Handed to MECM|An MECM übergeben/);

  // Findings: the previous MECM device and the missing PXE evidence, each linked to where it is resolved.
  const findings = page.locator('.vm-finding-list');
  await expect(
    findings.locator('a[href*="system_status.php#mecm"]', { hasText: /old MECM device|Altes MECM-Gerät/i }),
    'the old MECM device links to the MECM status'
  ).toHaveCount(1);
  await expect(
    findings.locator('a[href="#vm-progress-watch"]', { hasText: /PXE evidence missing|PXE-Beleg fehlt/ }),
    'the missing PXE evidence links to the observation on this page'
  ).toHaveCount(1);
  await expect(page.locator('#vm-progress-watch'), 'the observation link lands on its section').toHaveCount(1);

  // Technical block: the stage value and the identity of the VM in ESXi and in MECM.
  const technical = page.locator('section', { has: page.locator('#vm-technical') });
  await expect(technical, 'the raw stage is in the technical block').toContainText('4/5 OS Installing');
  await expect(technical, 'the instance UUID is shown').toContainText(UUID);
  await expect(technical, 'the MOID is shown').toContainText(MOID);
  await expect(technical, 'the ESXi host is shown').toContainText(HOST);

  // Timeline: open without a click, the note in words and the raw note nowhere.
  const timeline = page.locator('section', { has: page.locator('#vm-timeline-heading') });
  await expect(page.locator('#vm-timeline-heading'), 'the timeline heading is visible without a click').toBeVisible();
  await expect(timeline.locator('details'), 'the timeline is not collapsed').toHaveCount(0);
  await expect(timeline.locator('table'), 'the timeline is a table').toBeVisible();
  const history = await timeline.innerText();
  expect(history, 'the raw note is replaced by its wording').not.toContain('mecm update id');
  expect(
    history.includes(seed.noteDe) || history.includes(seed.noteEn),
    'the translated note is shown'
  ).toBe(true);
  expect(history, 'no raw lifecycle or power token is shown').not.toMatch(/os_installing|poweredOff/);
  await expect(timeline, 'the client event is listed with its detail').toContainText('e2e detail');
});
