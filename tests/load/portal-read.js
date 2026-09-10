// Read-heavy VirtuSphere portal profile (TESTPLAN 4.7 / U13 SC-021).
//
// Every VU owns one authenticated operator session. The VM request is accepted
// into the VM latency metric only after it reaches the exact mission URL and
// proves the expected mission, VM row count and a known VM marker. A followed
// redirect, login page or error response therefore cannot become a successful
// VM sample.
import http from 'k6/http';
import { check, sleep, fail } from 'k6';
import { parseHTML } from 'k6/html';
import { Counter, Trend } from 'k6/metrics';

const BASE = (__ENV.BASE || 'http://localhost:8080/portal').replace(/\/+$/, '');
const USER = __ENV.VS_USER || '';
const PASS = __ENV.VS_PASS || '';
const PROFILE = String(__ENV.U13_PROFILE || '').toUpperCase();
const THERMAL_STATE = String(__ENV.U13_THERMAL_STATE || '').toLowerCase();
const MISSION_TEXT = __ENV.TARGET_MISSION_ID || '';
const MISSION_NAME = __ENV.TARGET_MISSION_NAME || '';
const VM_COUNT_TEXT = __ENV.TARGET_VM_COUNT || '';
const VM_MARKER = __ENV.TARGET_VM_MARKER || '';

const PROFILE_VM_COUNTS = { S: 10, T: 40, L: 1000 };

if (!USER || !PASS) {
  throw new Error('VS_USER and VS_PASS are required; pass them at runtime and keep them out of run artifacts');
}
if (!Object.prototype.hasOwnProperty.call(PROFILE_VM_COUNTS, PROFILE)) {
  throw new Error('U13_PROFILE must be S, T or L');
}
if (!['warmup', 'warm'].includes(THERMAL_STATE)) {
  throw new Error('U13_THERMAL_STATE must be warmup or warm; use portal-read-cold.js for one cold target sample');
}
if (!/^[1-9][0-9]*$/.test(MISSION_TEXT)) {
  throw new Error('TARGET_MISSION_ID must be a positive fixture mission ID');
}
if (!MISSION_NAME || !VM_MARKER) {
  throw new Error('TARGET_MISSION_NAME and TARGET_VM_MARKER are required content proofs');
}
if (!/^[1-9][0-9]*$/.test(VM_COUNT_TEXT)) {
  throw new Error('TARGET_VM_COUNT must be a positive integer');
}

const TARGET_VM_COUNT = Number(VM_COUNT_TEXT);
if (TARGET_VM_COUNT !== PROFILE_VM_COUNTS[PROFILE]) {
  throw new Error(`TARGET_VM_COUNT must be ${PROFILE_VM_COUNTS[PROFILE]} for profile ${PROFILE}`);
}

const VM_URL = `${BASE}/vms.php?mission_id=${MISSION_TEXT}`;
const vmsDuration = new Trend('target_vms_duration', true);
const acceptedVmSamples = new Counter('accepted_vm_samples');

export const options = {
  tags: {
    u13_profile: PROFILE,
    u13_thermal_state: THERMAL_STATE,
  },
  scenarios: {
    operators: {
      executor: 'ramping-vus',
      startVUs: 1,
      stages: [
        { duration: '10s', target: 10 },
        { duration: '20s', target: 10 },
        { duration: '20s', target: 30 },
        { duration: '10s', target: 0 },
      ],
      gracefulRampDown: '5s',
    },
    monitor: {
      executor: 'constant-arrival-rate',
      exec: 'monitor',
      rate: 5,
      timeUnit: '1s',
      duration: '60s',
      preAllocatedVUs: 5,
      maxVUs: 10,
    },
  },
  thresholds: {
    checks: ['rate>0.99'],
    http_req_failed: ['rate<0.01'],
    'http_req_duration{page:dashboard}': ['p(95)<800'],
    'http_req_duration{page:missions}': ['p(95)<800'],
    target_vms_duration: ['p(95)<800'],
    accepted_vm_samples: ['count>0'],
    'http_req_duration{page:health}': ['p(95)<300'],
    'dropped_iterations{scenario:monitor}': ['count<1'],
  },
};

// Module scope is per VU. k6 clears the per-VU cookie jar between iterations,
// so the session id is restored into the jar instead of sent as a hand-written
// Cookie header (which would be lost across a redirect).
let sessionId = null;

function isSignedIn(res) {
  return res.status === 200 && res.body.includes('logout.php');
}

function isHtml(res) {
  return String(res.headers['Content-Type'] || '').toLowerCase().includes('text/html');
}

function login() {
  const loginPage = http.get(`${BASE}/login.php`, {
    redirects: 0,
    tags: { page: 'login' },
  });
  const csrf = parseHTML(loginPage.body).find('input[name=_csrf]').first().attr('value');
  if (loginPage.status !== 200 || loginPage.url !== `${BASE}/login.php` || !csrf) {
    fail('login page did not render at the exact expected URL with a CSRF token');
  }

  const res = http.post(
    `${BASE}/login.php`,
    { username: USER, password: PASS, _csrf: csrf },
    { tags: { page: 'login' } },
  );
  if (!isSignedIn(res) || res.url !== `${BASE}/dashboard.php`) {
    fail(`login did not reach the authenticated dashboard (status ${res.status}, url ${res.url})`);
  }

  const cookies = http.cookieJar().cookiesForURL(`${BASE}/dashboard.php`);
  if (!cookies.PHPSESSID || cookies.PHPSESSID.length !== 1 || !cookies.PHPSESSID[0]) {
    fail('login yielded no unique PHPSESSID');
  }
  return cookies.PHPSESSID[0];
}

function restoreSession() {
  const jar = http.cookieJar();
  if (sessionId === null) {
    sessionId = login();
  } else {
    jar.set(`${BASE}/`, 'PHPSESSID', sessionId);
  }
}

function targetVmPageIsValid(res) {
  if (!isSignedIn(res) || !isHtml(res) || res.url !== VM_URL) {
    return false;
  }
  const document = parseHTML(res.body);
  const title = document.find('title').first().text();
  const rows = document.find('table.table-sticky-actions tbody tr').size();
  const selectableRows = document.find('input[data-bulk-item][name="vm_ids[]"]').size();
  return title.includes(MISSION_NAME)
    && rows === TARGET_VM_COUNT
    && selectableRows === TARGET_VM_COUNT
    && res.body.includes(VM_MARKER);
}

export default function () {
  restoreSession();

  const dash = http.get(`${BASE}/dashboard.php`, { tags: { page: 'dashboard' } });
  check(dash, {
    'dashboard reached its exact authenticated HTML target': (r) =>
      isSignedIn(r) && isHtml(r) && r.url === `${BASE}/dashboard.php`,
  });

  const missions = http.get(`${BASE}/missions.php?type=missions`, { tags: { page: 'missions' } });
  check(missions, {
    'missions reached its exact authenticated HTML target': (r) =>
      isSignedIn(r) && isHtml(r) && r.url === `${BASE}/missions.php?type=missions`,
  });

  // The request is tagged as a candidate. Only a positively identified target
  // page is added to target_vms_duration, which owns the unchanged 800 ms p95.
  const vms = http.get(VM_URL, { redirects: 0, tags: { page: 'vms_candidate' } });
  const validVmPage = check(vms, {
    'VM request reached the exact mission and expected VM content': targetVmPageIsValid,
  });
  if (!validVmPage) {
    fail(`VM target validation failed (status ${vms.status}, url ${vms.url})`);
  }
  vmsDuration.add(vms.timings.duration);
  acceptedVmSamples.add(1);

  const health = http.get(`${BASE}/health.php`, { tags: { page: 'health' } });
  check(health, {
    'health returned the expected JSON success envelope': (r) => {
      if (r.status !== 200 || r.url !== `${BASE}/health.php`
        || !String(r.headers['Content-Type'] || '').toLowerCase().includes('application/json')) {
        return false;
      }
      try {
        return r.json().status === 'ok';
      } catch (_) {
        return false;
      }
    },
  });

  sleep(1);
}

export function monitor() {
  const health = http.get(`${BASE}/health.php`, { tags: { page: 'health' } });
  check(health, {
    'monitor sees the expected health JSON': (r) => {
      if (r.status !== 200 || r.url !== `${BASE}/health.php`
        || !String(r.headers['Content-Type'] || '').toLowerCase().includes('application/json')) {
        return false;
      }
      try {
        return r.json().status === 'ok';
      } catch (_) {
        return false;
      }
    },
  });
}
