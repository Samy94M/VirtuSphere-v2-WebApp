// U13 / SC-008: the long live-blocker read releases the PHP session after
// locale, expiry, directory revalidation and RBAC have completed. The DB table
// lock below holds the interface relation read; a same-PHPSESSID session_ping must be
// able to acquire the session and answer the CSRF-protected ping successfully.

const { test, expect } = require('../lib/test');
const { spawn } = require('node:child_process');
const http = require('node:http');
const { ROLES } = require('../lib/auth');
const { phpJson, runPhp, PHP_CONTAINER } = require('../lib/php');

const PREFIX = 'e2e_u13_session_';

// The lock helper holds the relation at most this long: through the blocker
// read, the peer ping and the release.
const LOCK_HOLD_SECONDS = 45;
const BLOCKER_TIMEOUT_MS = 40000;
// The bound only ends a request that never gets there. How long the read took
// to reach the lock is reported as the `blocker-wait` annotation.
const BLOCKER_WAIT_OBSERVATION_MS = 10000;

function firstLine(text) {
  return String(text).split('\n')[0].slice(0, 300);
}

// KB1 (31.10.2026), cause of BK-N12: every Playwright API request context of a
// worker shares one keep-alive agent, and nginx closes an idle keep-alive
// connection after 65 s without announcing it. In the full suite the last API
// request before this spec (deploy-actions.spec.js) left a socket in that pool,
// and the blocker GET reused it at the moment nginx closed it: "socket hang up"
// with no status, exactly the race Node documents for request.reusedSocket. The
// two requests this spec asserts on therefore open their own connection
// (agent: false) and carry the proven PHPSESSID explicitly. No earlier socket
// can take part, and every transport failure stays red.
function sessionRequest(baseURL, sessionId, { method = 'GET', path, form = null, timeoutMs }) {
  const url = new URL(path, baseURL);
  if (url.protocol !== 'http:') {
    throw new Error(`sessionRequest speaks plain HTTP only, got ${url.protocol}`);
  }
  const body = form === null ? null : new URLSearchParams(form).toString();
  const headers = { Cookie: `PHPSESSID=${sessionId}`, Accept: 'application/json', Connection: 'close' };
  if (body !== null) {
    headers['Content-Type'] = 'application/x-www-form-urlencoded';
    headers['Content-Length'] = Buffer.byteLength(body);
  }
  return new Promise((resolve, reject) => {
    const request = http.request(url, { method, headers, agent: false }, (response) => {
      const chunks = [];
      response.on('data', (chunk) => { chunks.push(chunk); });
      response.on('error', reject);
      response.on('end', () => {
        resolve({ status: response.statusCode, text: Buffer.concat(chunks).toString('utf8') });
      });
    });
    request.setTimeout(timeoutMs, () => {
      request.destroy(new Error(`${method} ${url.pathname} got no answer within ${timeoutMs} ms`));
    });
    request.on('error', reject);
    if (body !== null) {
      request.write(body);
    }
    request.end();
  });
}

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

// Polls until the blocker read waits on the lock, and stops at once when the
// request settles instead: a settled request will never start waiting.
async function observeBlockerWait(vmId, isSettled) {
  const deadline = Date.now() + BLOCKER_WAIT_OBSERVATION_MS;
  let observation;
  for (;;) {
    observation = blockerVmQueryIsWaiting(vmId);
    if (observation.waiting) {
      return { waiting: true, observation };
    }
    if (isSettled() || Date.now() >= deadline) {
      return { waiting: false, observation };
    }
    await new Promise((resolve) => { setTimeout(resolve, 250); });
  }
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
    // The hold limit only bounds a forgotten helper; release() ends it normally.
    // Running into the limit is reported, never mistaken for a release.
    `if (stream_select($read, $write, $except, ${LOCK_HOLD_SECONDS}) > 0) { fgets(STDIN); echo "RELEASED\\n"; } else { echo "EXPIRED\\n"; }`,
    "$db->query('UNLOCK TABLES');",
  ].join(' ');
  const child = spawn('docker', ['exec', '-i', PHP_CONTAINER, 'php', '-r', code], {
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  let stdout = '';
  let stderr = '';
  let readinessFinished = false;
  let releaseRequested = false;
  let endedEarly = null;
  child.stdout.on('data', (chunk) => { stdout += chunk.toString(); });
  child.stderr.on('data', (chunk) => { stderr += chunk.toString(); });
  child.stdin.on('error', (error) => { stderr += `stdin: ${error.message}`; });

  const exited = new Promise((resolve, reject) => {
    child.once('error', reject);
    child.once('exit', (codeValue) => {
      // An end after readiness that release() did not ask for means the lock is
      // gone while the test still relies on it: its own failure, not a pass.
      if (readinessFinished && !releaseRequested) {
        endedEarly = new Error(
          `VM table-lock helper ended before release() (exit ${codeValue}, `
          + `${stdout.includes('EXPIRED') ? `hold limit of ${LOCK_HOLD_SECONDS} s reached` : 'no expiry reported'}): ${stderr || stdout}`,
        );
      }
      if (codeValue === 0) {
        resolve();
      } else {
        reject(new Error(`VM table-lock helper exited ${codeValue}: ${stderr || stdout}`));
      }
    });
  });

  // Throws when the lock ended before release(); called before each assertion
  // that relies on the relation still being locked.
  const assertHeld = () => {
    if (endedEarly) {
      throw endedEarly;
    }
  };

  let releasePromise;
  const release = () => {
    releaseRequested = true;
    if (!releasePromise) {
      if (!child.stdin.destroyed && !child.stdin.writableEnded) {
        child.stdin.end("release\n");
      }
      releasePromise = new Promise((resolveExit, rejectExit) => {
        const exitTimeout = setTimeout(() => rejectExit(new Error(
          'VM table-lock helper exit was not confirmed; stop this QA run and resolve the helper before reusing the stack',
        )), 10000);
        exited.then(
          () => { clearTimeout(exitTimeout); if (endedEarly) { rejectExit(endedEarly); } else { resolveExit(); } },
          (error) => { clearTimeout(exitTimeout); rejectExit(endedEarly || error); },
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
        resolve({ release, assertHeld });
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

test('same-session peer proceeds while live blockers wait and the locale persists', async ({ browser }, testInfo) => {
  // Lock hold, the blocker read, the peer ping and the release fit in this
  // budget, not in the global 30 s.
  test.setTimeout(90000);
  const fixture = seedMission();
  const baseURL = testInfo.project.use.baseURL;
  const browserContext = await browser.newContext({ baseURL, locale: 'de-DE' });
  const page = await browserContext.newPage();
  let tableLock;
  let blockerPromise;

  try {
    await signInFresh(page);
    await expect(page.locator('html')).toHaveAttribute('lang', 'de');
    const csrf = await page.getByRole('banner').locator('form[action="logout.php"] input[name="_csrf"]').inputValue();
    const sessionIds = (await browserContext.storageState()).cookies
      .filter((cookie) => cookie.name === 'PHPSESSID').map((cookie) => cookie.value);
    expect(sessionIds.length, 'the pair starts with exactly one proven PHP session id').toBe(1);
    const sessionId = sessionIds[0];

    tableLock = await startVmTableLock();
    const blockerPath = `deploy_blockers.php?mission_id=${Number(fixture.mission_id)}&mode=export&lang=en`;
    const startedAt = Date.now();
    const blockerState = { settled: false };
    blockerPromise = sessionRequest(baseURL, sessionId, { path: blockerPath, timeoutMs: BLOCKER_TIMEOUT_MS })
      .finally(() => { blockerState.settled = true; });
    // Observe early rejection while the test is still waiting on the peer; the
    // promise is awaited below.
    void blockerPromise.catch(() => {});

    const { waiting, observation } = await observeBlockerWait(fixture.vm_id, () => blockerState.settled);
    const elapsed = Date.now() - startedAt;
    if (!waiting) {
      tableLock.assertHeld();
      // A blocker request that settled without ever waiting on the lock is
      // explained by its answer: a 401/403 points at the session, a 200 at a
      // read path that skipped the locked relation, a transport error at the
      // connection (no retry: this spec owns its connection).
      let answer = '';
      if (blockerState.settled) {
        const outcome = await blockerPromise.then((response) => ({ response }), (error) => ({ error }));
        answer = outcome.response
          ? `; blocker answered HTTP ${outcome.response.status}: ${outcome.response.text.slice(0, 400)}`
          : `; blocker request failed: ${firstLine(outcome.error && outcome.error.message ? outcome.error.message : outcome.error)}`;
      }
      throw new Error(
        `blocker request after ${elapsed} ms did not reach the locked VM relation read: `
        + `VM wait observation: ${JSON.stringify(observation)}; blocker settled: ${blockerState.settled}${answer}`,
      );
    }
    testInfo.annotations.push({ type: 'blocker-wait', description: `the blocker read waited on the lock after ${elapsed} ms` });
    tableLock.assertHeld();

    const ping = await sessionRequest(baseURL, sessionId, {
      method: 'POST',
      path: 'session_ping.php',
      form: { _csrf: csrf },
      timeoutMs: 5000,
    });
    expect(ping.status, `peer ping answer: ${ping.text.slice(0, 400)}`).toBe(200);
    expect(JSON.parse(ping.text)).toMatchObject({ ok: true });
    expect(blockerVmQueryIsWaiting(fixture.vm_id).waiting,
      'the same scoped DB read is still waiting after the peer write').toBe(true);
    expect(blockerState.settled, 'the DB read must still be in flight when the same-session write completes').toBe(false);

    await tableLock.release();
    tableLock = null;
    const blockerResponse = await blockerPromise;
    expect(blockerResponse.status, `blocker answer: ${blockerResponse.text.slice(0, 400)}`).toBe(200);
    expect(JSON.parse(blockerResponse.text)).toMatchObject({ ok: true });

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
    await blockerPromise?.catch(() => {});
    try { await browserContext.close(); } catch (error) { cleanupErrors.push(error); }
    try { cleanupMission(); } catch (error) { cleanupErrors.push(error); }
    if (cleanupErrors.length > 0) {
      throw cleanupErrors[0];
    }
  }
});
