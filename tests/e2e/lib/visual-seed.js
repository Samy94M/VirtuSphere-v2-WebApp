'use strict';

const { phpJson, runPhp } = require('./php');

const MARK = 'visuale11fixture';

function assertVisualQaIsolation(env = process.env) {
  const expected = {
    VIRTUSPHERE_VISUAL_QA_ALLOWED: '1',
    VIRTUSPHERE_QA_PROJECT: 'virtusphere-qa',
    VIRTUSPHERE_BASE_URL: 'http://127.0.0.1:8031/portal/',
    VIRTUSPHERE_PHP_CONTAINER: 'virtusphere-qa-php-1',
    VIRTUSPHERE_MYSQL_CONTAINER: 'virtusphere-qa-mysql-1',
    DB_NAME: 'deploymentcenter',
  };
  for (const [name, value] of Object.entries(expected)) {
    if (env[name] !== value) throw new Error(`visual seed isolation refused: ${name} must equal ${value}`);
  }
  return true;
}

function cleanupVisualFixtures() {
  assertVisualQaIsolation();
  runPhp(`
$db = db();
$prefix = '${MARK}%';
$stmt = $db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
$stmt->bind_param('s', $prefix);
$stmt->execute();
`);
}

function seedVisualFixtures() {
  assertVisualQaIsolation();
  cleanupVisualFixtures();
  return phpJson(`
$db = db();
$missionId = repo_create_mission($db, [
    'mission_name' => '${MARK}',
    'hypervisor_datastorage' => 'QA-Datastore',
    'hypervisor_datacenter' => 'QA-Datacenter',
    'domain' => 'visual.qa.invalid',
], false, null);
$fixed = '2026-08-27 08:00:00';
$stmt = $db->prepare('UPDATE deploy_missions SET created_at = ?, updated_at = ? WHERE id = ?');
$stmt->bind_param('ssi', $fixed, $fixed, $missionId);
$stmt->execute();
echo 'JSON' . json_encode(['missionId' => $missionId, 'missionName' => '${MARK}']) . 'JSON';
`, ['lib/repo/missions.php']);
}

module.exports = { MARK, assertVisualQaIsolation, cleanupVisualFixtures, seedVisualFixtures };
