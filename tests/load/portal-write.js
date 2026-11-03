// Write-path load probe (TESTPLAN 4.7 / plan v2 AP7). Runs ONLY against a
// throwaway stack: every iteration creates, edits and deletes real rows, and a
// run against the dev or production database would shred real data. Two locks
// enforce that: BASE has no default here (the read profile may default to the
// dev stack; this one must be pointed somewhere on purpose), and the run
// refuses to start without VS_ALLOW_WRITES=1.
//
// What an iteration proves, per virtual operator with their own session:
//   create  - POST missions.php action=create lands on the new mission's detail
//             page (the id comes from the redirect URL, not from a body guess);
//   update  - POST mission_details.php action=update persists a domain change;
//   delete  - POST missions.php action=delete removes the row; the confirm
//             dialog is UX only (ADR-0013), so the direct POST is the honest
//             wire-level equivalent of the confirmed click;
//   idempotence - a second identical delete does not 500: the portal answers
//             a stale row with a localized refusal, never a crash.
//
// Deploy enqueue/cancel/retry stay in the Playwright deploy spec: they need
// seeded credentials and worker choreography, which a load iteration cannot
// carry without measuring its own setup instead of the portal.
import http from 'k6/http';
import { check, sleep, fail } from 'k6';
import { parseHTML } from 'k6/html';

const BASE = __ENV.BASE; // no default on purpose, see header
const USER = __ENV.VS_USER || 'admin';
const PASS = __ENV.VS_PASS || 'admin12345678';

export const options = {
  scenarios: {
    writers: {
      // Arrival-rate, not VU-looping: the write rate stays fixed even when the
      // portal slows down, and dropped_iterations tells on a rate k6 could not
      // sustain instead of silently measuring a gentler one.
      executor: 'constant-arrival-rate',
      rate: 2,
      timeUnit: '1s',
      duration: '60s',
      preAllocatedVUs: 10,
      maxVUs: 20,
    },
  },
  thresholds: {
    checks: ['rate>0.99'],
    http_req_failed: ['rate<0.01'],
    'http_req_duration{action:create}': ['p(95)<1000'],
    'http_req_duration{action:update}': ['p(95)<1000'],
    'http_req_duration{action:delete}': ['p(95)<1000'],
    dropped_iterations: ['count<1'],
  },
};

export function setup() {
  if (!BASE) {
    fail('BASE is required (no default): point it at a THROWAWAY stack, e.g. http://127.0.0.1:8031/portal');
  }
  if (__ENV.VS_ALLOW_WRITES !== '1') {
    fail('refusing to run: this profile mutates rows. Set VS_ALLOW_WRITES=1 against a throwaway stack only.');
  }
}

// One session and one CSRF token per VU (module scope is per-VU). The token is
// session-stable, but it must be read AFTER login: signing in regenerates the
// session, so a token scraped from the login page would be stale.
let sessionId = null;
let csrf = null;

function isSignedIn(res) {
  return res.status === 200 && res.body.includes('logout.php');
}

function login() {
  const loginPage = http.get(`${BASE}/login.php`, { tags: { page: 'login' } });
  const loginCsrf = parseHTML(loginPage.body).find('input[name=_csrf]').first().attr('value');
  if (!loginCsrf) {
    fail('login page carried no CSRF token; is BASE right?');
  }
  const res = http.post(
    `${BASE}/login.php`,
    { username: USER, password: PASS, _csrf: loginCsrf },
    { tags: { page: 'login' } },
  );
  if (!isSignedIn(res)) {
    fail(`login failed for "${USER}" (status ${res.status}, url ${res.url})`);
  }
  const cookies = http.cookieJar().cookiesForURL(`${BASE}/dashboard.php`);
  if (!cookies.PHPSESSID) {
    fail('login yielded no PHPSESSID; check credentials');
  }
  return cookies.PHPSESSID[0];
}

function postSessionCsrf() {
  const missionsPage = http.get(`${BASE}/missions.php?type=missions`, { tags: { page: 'missions' } });
  const token = parseHTML(missionsPage.body).find('input[name=_csrf]').first().attr('value');
  if (!token) {
    fail('signed-in missions page carried no CSRF token');
  }
  return token;
}

export default function () {
  const jar = http.cookieJar();
  if (sessionId === null) {
    sessionId = login();
    csrf = postSessionCsrf();
  } else {
    jar.set(`${BASE}/`, 'PHPSESSID', sessionId);
  }

  // Unique per VU and iteration; the mission name pattern forbids spaces.
  const name = `k6w-${__VU}-${__ITER}-${Date.now()}`;

  // Create: success is landing on the new mission's detail page.
  const created = http.post(
    `${BASE}/missions.php?type=missions`,
    { action: 'create', mission_name: name, _csrf: csrf },
    { tags: { action: 'create' } },
  );
  const idMatch = created.url.match(/mission_details\.php\?id=(\d+)/);
  check(created, { 'create landed on the detail page': () => idMatch !== null });
  if (!idMatch) {
    return; // nothing to update or delete; the failed check already counted
  }
  const missionId = idMatch[1];

  // Update: the detail form requires its non-template fields, so send them.
  const updated = http.post(
    `${BASE}/mission_details.php?id=${missionId}`,
    {
      action: 'update',
      mission_name: name,
      domain: 'k6.example.local',
      hypervisor_datastorage: 'k6-ds',
      hypervisor_datacenter: 'K6DC',
      _csrf: csrf,
    },
    { tags: { action: 'update' } },
  );
  check(updated, { 'update persisted (page shows the domain)': (r) => isSignedIn(r) && r.body.includes('k6.example.local') });

  // Delete: the wire-level equivalent of the confirmed dialog click.
  const deleted = http.post(
    `${BASE}/missions.php?type=missions`,
    { action: 'delete', mission_id: missionId, _csrf: csrf },
    { tags: { action: 'delete' } },
  );
  check(deleted, { 'delete removed the row from the list': (r) => isSignedIn(r) && !r.body.includes(`mission_details.php?id=${missionId}"`) });

  // Idempotence: deleting the same row again must be a refusal, never a 500.
  const again = http.post(
    `${BASE}/missions.php?type=missions`,
    { action: 'delete', mission_id: missionId, _csrf: csrf },
    { tags: { action: 'delete' } },
  );
  check(again, { 'double delete is refused, not crashed': (r) => r.status < 500 });

  sleep(0.2);
}
