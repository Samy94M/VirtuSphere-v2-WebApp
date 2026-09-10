// U13 / SC-008: the long live-blocker read releases the PHP session after
// locale, expiry, directory revalidation and RBAC have completed. The DB table
// lock below holds the interface relation read; a same-PHPSESSID session_ping must be
// able to acquire the session and answer the CSRF-protected ping successfully.

const { test, expect } = require('@playwright/test');
const { spawn } = require('node:child_process');
const { ROLES } = require('../lib/auth');
const { phpJson, runPhp, PHP_CONTAINER } = require('../lib/php');

const PREFIX = 'e2e_u13_session_';

function seedMission() {
  return phpJson(`
$prefix = '${PREFIX}';
$like = $prefix . '%';
$stmt = db()->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
$stmt->bind_param('s', $like);
$stmt->execute();
$name = $prefix . 'mission';
$status = VIRTUSPHERE_MISSION_STATUS_DEFAULT;
$stmt = db()->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
$stmt->bind_param('ss', $name, $status);
$stmt->execute();
$missionId = (int) db()->insert_id;
$vmName = 'E2E-U13-SESSION-VM';
$stmt = db()->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)');
$stmt->bind_param('iss', $missionId, $vmName, $vmName);
$stmt->execute();
echo 'JSON' . json_encode(['mission_id' => $missionId, 'vm_id' => (int) db()->insert_id]) . 'JSON';
`);
}

function cleanupMission() {
  runPhp(`
$like = '${PREFIX}%';
$stmt = db()->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
$stmt->bind_param('s', $like);
$stmt->execute();
echo 'CLEAN';
`);
}

async function signInFresh(page) {
  await page.goto('login.php');
  await page.locator('#username').fill(ROLES.admin.username);
  await page.locator('#password').fill(ROLES.admin.password);
  await Promise.all([
    page.waitForURL(/dashboard\.php/, { timeout: 15000 }),
    page.locator('button[type="submit"]').click(),
  ]);
}

function blockerVmQueryIsWaiting(vmId) {
  return phpJson(`
$waiting = false;
$candidates = [];
$vmId = ${Number(vmId)};
foreach (db()->query('SHOW FULL PROCESSLIST')->fetch_all(MYSQLI_ASSOC) as $process) {
    $info = (string) ($process['Info'] ?? '');
    $state = strtolower((string) ($process['State'] ?? ''));
    if (str_contains($info, 'deploy_interfaces') && str_contains($state, 'wait')) {
        $candidates[] = ['state' => $state, 'info' => $info];
    }
    $isFixtureRead = $info === 'SELECT * FROM deploy_interfaces WHERE vm_id IN (?) ORDER BY vm_id, id'
        || $info === 'SELECT * FROM deploy_interfaces WHERE vm_id IN (' . $vmId . ') ORDER BY vm_id, id';
    if ($isFixtureRead
        && str_contains($state, 'wait')) {
        $waiting = true;
        break;
    }
}
echo 'JSON' . json_encode(['waiting' => $waiting, 'candidates' => $candidates]) . 'JSON';
`);
}

function startVmTableLock() {
  const code = [
    "require_once '/var/www/html/lib/db.php';",
    '$db = db();',
    // Bound acquisition too: an older metadata/table lock must not leave this
    // helper waiting after the test has already failed its readiness deadline.
    "$db->query('SET SESSION lock_wait_timeout = 5');",
    // Lock the relation, since locking deploy_vms stops getMissions() at its
    // earlier VM count before getVMs() ever reaches the intended scoped read.
    "$db->query('LOCK TABLES deploy_interfaces WRITE');",
    "echo \"READY\\n\";",
    'flush();',
    '$read = [STDIN]; $write = null; $except = null;',
    'if (stream_select($read, $write, $except, 15) > 0) { fgets(STDIN); }',
    "$db->query('UNLOCK TABLES');",
  ].join(' ');
  const child = spawn('docker', ['exec', '-i', PHP_CONTAINER, 'php', '-r', code], {
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  let stdout = '';
  let stderr = '';
  let readinessFinished = false;
  child.stdout.on('data', (chunk) => { stdout += chunk.toString(); });
  child.stderr.on('data', (chunk) => { stderr += chunk.toString(); });
  child.stdin.on('error', (error) => { stderr += `stdin: ${error.message}`; });

  const exited = new Promise((resolve, reject) => {
    child.once('error', reject);
    child.once('exit', (codeValue) => {
      if (codeValue === 0) {
        resolve();
      } else {
        reject(new Error(`VM table-lock helper exited ${codeValue}: ${stderr || stdout}`));
      }
    });
  });

  let releasePromise;
  const release = () => {
    if (!releasePromise) {
      if (!child.stdin.destroyed && !child.stdin.writableEnded) {
        child.stdin.end("release\n");
      }
      releasePromise = new Promise((resolveExit, rejectExit) => {
        const exitTimeout = setTimeout(() => rejectExit(new Error(
          'VM table-lock helper exit was not confirmed; stop this QA run and resolve the helper before reusing the stack',
        )), 10000);
        exited.then(
          () => { clearTimeout(exitTimeout); resolveExit(); },
          (error) => { clearTimeout(exitTimeout); rejectExit(error); },
        );
      });
    }
    return releasePromise;
  };

  return new Promise((resolve, reject) => {
    const rejectAfterExit = async (error) => {
      if (readinessFinished) return;
      readinessFinished = true;
      clearTimeout(timeout);
      try {
        // No handle reaches the caller on readiness failure. This branch owns
        // closing stdin and observing the process exit before it returns.
        await release();
      } catch (exitError) {
        reject(new Error(`${error.message}; ${exitError.message}`));
        return;
      }
      reject(error);
    };
    const timeout = setTimeout(() => {
      void rejectAfterExit(new Error(`VM table-lock helper did not become ready: ${stderr || stdout}`));
    }, 10000);
    const inspect = () => {
      if (!readinessFinished && stdout.includes('READY')) {
        readinessFinished = true;
        clearTimeout(timeout);
        resolve({ release });
      }
    };
    child.stdout.on('data', inspect);
    inspect();
    exited.then(
      () => { void rejectAfterExit(new Error(`VM table-lock helper exited before readiness: ${stderr || stdout}`)); },
      (error) => { void rejectAfterExit(error); },
    );
  });
}

test('same-session peer proceeds while live blockers wait and the locale persists', async ({ browser, playwright }, testInfo) => {
  const fixture = seedMission();
  const browserContext = await browser.newContext({
    baseURL: testInfo.project.use.baseURL,
    locale: 'de-DE',
  });
  const page = await browserContext.newPage();
  let blockerApi;
  let peerApi;
  let tableLock;
  let blockerPromise;

  try {
    await signInFresh(page);
    await expect(page.locator('html')).toHaveAttribute('lang', 'de');
    const csrf = await page.getByRole('banner').locator('form[action="logout.php"] input[name="_csrf"]').inputValue();
    const sharedState = await browserContext.storageState();
    const sessionIds = sharedState.cookies.filter((cookie) => cookie.name === 'PHPSESSID').map((cookie) => cookie.value);
    expect(sessionIds.length, 'the pair starts with exactly one proven PHP session id').toBe(1);

    blockerApi = await playwright.request.newContext({ baseURL: testInfo.project.use.baseURL, storageState: sharedState });
    peerApi = await playwright.request.newContext({ baseURL: testInfo.project.use.baseURL, storageState: sharedState });
    const blockerSessionIds = (await blockerApi.storageState()).cookies.filter((cookie) => cookie.name === 'PHPSESSID').map((cookie) => cookie.value);
    const peerSessionIds = (await peerApi.storageState()).cookies.filter((cookie) => cookie.name === 'PHPSESSID').map((cookie) => cookie.value);
    expect(blockerSessionIds.length).toBe(1);
    expect(peerSessionIds.length).toBe(1);
    expect(blockerSessionIds[0] === sessionIds[0], 'blocker request uses the fresh browser session').toBe(true);
    expect(peerSessionIds[0] === sessionIds[0], 'peer request uses the same fresh browser session').toBe(true);

    tableLock = await startVmTableLock();
    let blockerSettled = false;
    blockerPromise = blockerApi
      .get(`deploy_blockers.php?mission_id=${Number(fixture.mission_id)}&mode=export&lang=en`, { timeout: 20000 })
      .finally(() => { blockerSettled = true; });
    // Observe early rejection while the test is still waiting on the peer;
    // the original promise remains awaited below and still fails the test.
    void blockerPromise.catch(() => {});

    let observation;
    try {
      await expect.poll(() => {
        observation = blockerVmQueryIsWaiting(fixture.vm_id);
        return observation.waiting;
      }, {
        message: 'the live blocker request reached the deliberately blocked VM relation read',
        timeout: 10000,
      }).toBe(true);
    } catch (error) {
      throw new Error(`VM wait observation: ${JSON.stringify(observation)}; blocker settled: ${blockerSettled}`, { cause: error });
    }

    const ping = await peerApi.post('session_ping.php', {
      form: { _csrf: csrf },
      timeout: 5000,
    });
    expect(ping.status()).toBe(200);
    expect(await ping.json()).toMatchObject({ ok: true });
    expect(blockerVmQueryIsWaiting(fixture.vm_id).waiting,
      'the same scoped DB read is still waiting after the peer write').toBe(true);
    expect(blockerSettled, 'the DB read must still be in flight when the same-session write completes').toBe(false);

    await tableLock.release();
    tableLock = null;
    const blockerResponse = await blockerPromise;
    expect(blockerResponse.status()).toBe(200);
    expect(await blockerResponse.json()).toMatchObject({ ok: true });

    await page.goto('dashboard.php');
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    const afterIds = (await browserContext.storageState()).cookies.filter((cookie) => cookie.name === 'PHPSESSID').map((cookie) => cookie.value);
    expect(afterIds.length).toBe(1);
    expect(afterIds[0] === sessionIds[0], 'the close and peer write preserve the fresh session identity').toBe(true);
  } finally {
    const cleanupErrors = [];
    if (tableLock) {
      try { await tableLock.release(); } catch (error) { cleanupErrors.push(error); }
    }
    try { await blockerApi?.dispose(); } catch (error) { cleanupErrors.push(error); }
    try { await peerApi?.dispose(); } catch (error) { cleanupErrors.push(error); }
    await blockerPromise?.catch(() => {});
    try { await browserContext.close(); } catch (error) { cleanupErrors.push(error); }
    try { cleanupMission(); } catch (error) { cleanupErrors.push(error); }
    if (cleanupErrors.length > 0) {
      throw cleanupErrors[0];
    }
  }
});
