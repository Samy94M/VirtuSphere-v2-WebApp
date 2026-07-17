// TESTPLAN 2.4 / 3.5: CSV formula-injection guard, proven in the DOWNLOADED
// artifact. PortalCsvExportTest already pins portal_csv_guard() in isolation;
// this closes the loop end-to-end: a value that begins with a formula
// character, seeded straight into the DB (this tests the export guard, not the
// input validators), must arrive in the actual downloaded CSV bytes with a
// leading apostrophe so a spreadsheet cannot execute it on open.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const PREFIX = 'e2ecsv-';
// One payload per formula lead character the guard must neutralize.
const PAYLOADS = {
  os: '=1+2',
  hostname: '+SUM(A1)',
  cpu: '-2+3',
  ram: '@cmd|calc',
};

function seedMission() {
  return phpJson(`
$db = db();
$id = repo_create_mission($db, ['mission_name' => '${PREFIX}m', 'hypervisor_datastorage' => 'ds1', 'hypervisor_datacenter' => 'DC1', 'domain' => 'seed.example.local'], false, null);
$os = ${JSON.stringify(PAYLOADS.os)};
$host = ${JSON.stringify(PAYLOADS.hostname)};
$cpu = ${JSON.stringify(PAYLOADS.cpu)};
$ram = ${JSON.stringify(PAYLOADS.ram)};
$stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os, vm_cpu, vm_ram) VALUES (?, 'E2ECSVVM', ?, ?, ?, ?)");
$stmt->bind_param('issss', $id, $host, $os, $cpu, $ram);
$stmt->execute();
echo 'JSON' . json_encode(['id' => $id]) . 'JSON';
`, ['lib/repo/missions.php']);
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

// Not an e2e-covers marker: the CSV export is a GET link, not one of the
// name="action" POST actions the coverage contract inventories.
test('CSV export neutralizes every formula-injection cell in the downloaded file', async ({ context }) => {
  const seed = seedMission();

  // The export is a GET link; fetch the real bytes through the authenticated
  // session so the assertion runs against the downloaded artifact, not the page.
  const response = await context.request.get(`vms.php?mission_id=${seed.id}&export=csv`);
  expect(response.status(), 'the export responds').toBe(200);
  expect(String(response.headers()['content-type'] || ''), 'it is served as CSV').toContain('text/csv');
  const body = await response.text();

  // Every payload survives as text, and none of them is left in a
  // formula-executable lead position: the guard prefixes an apostrophe.
  for (const [field, payload] of Object.entries(PAYLOADS)) {
    expect(body, `${field} value is present in the export`).toContain(payload);
    expect(body, `${field} value is not left executable`).toContain(`'${payload}`);
    // And it never appears bare at a cell boundary (start of file, after a
    // delimiter or a quote) with its formula character still leading.
    const bareLead = new RegExp(`(^|[;",\\r\\n])\\${payload[0]}`.replace(/\\\$/, '\\' + payload[0]));
    // Simpler, robust check: the raw formula char must not directly follow a
    // cell opener without the guarding quote. Scan for `;=` / `"=` / `\n=` etc.
    for (const opener of [';', '"', '\n']) {
      expect(body.includes(opener + payload[0] + payload.slice(1)), `${field} appears unguarded after "${opener === '\n' ? '\\n' : opener}"`).toBe(false);
    }
    void bareLead;
  }
});
