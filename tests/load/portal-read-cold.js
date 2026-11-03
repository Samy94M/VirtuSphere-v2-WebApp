// U13 single-sample cold target probe. This script does not make the host cold.
// Its result is admissible only when an external run manifest proves the exact
// reset/start state and that this is the first VM-target request afterwards.
import http from 'k6/http';
import { check, fail } from 'k6';
import { parseHTML } from 'k6/html';
import { Counter, Trend } from 'k6/metrics';

const BASE = (__ENV.BASE || 'http://localhost:8080/portal').replace(/\/+$/, '');
const USER = __ENV.VS_USER || '';
const PASS = __ENV.VS_PASS || '';
const PROFILE = String(__ENV.U13_PROFILE || '').toUpperCase();
const MISSION_TEXT = __ENV.TARGET_MISSION_ID || '';
const MISSION_NAME = __ENV.TARGET_MISSION_NAME || '';
const VM_COUNT_TEXT = __ENV.TARGET_VM_COUNT || '';
const VM_MARKER = __ENV.TARGET_VM_MARKER || '';
const COLD_PRECONDITION = __ENV.U13_COLD_PRECONDITION || '';
const PROFILE_VM_COUNTS = { S: 10, T: 40, L: 1000 };

if (!USER || !PASS) {
  throw new Error('VS_USER and VS_PASS are required; keep them outside run artifacts');
}
if (!Object.prototype.hasOwnProperty.call(PROFILE_VM_COUNTS, PROFILE)) {
  throw new Error('U13_PROFILE must be S, T or L');
}
if (COLD_PRECONDITION !== 'verified-target-route-reset') {
  throw new Error('U13_COLD_PRECONDITION must confirm the externally recorded target-route reset state');
}
if (!/^[1-9][0-9]*$/.test(MISSION_TEXT) || !/^[1-9][0-9]*$/.test(VM_COUNT_TEXT)) {
  throw new Error('TARGET_MISSION_ID and TARGET_VM_COUNT must be positive integers');
}
if (!MISSION_NAME || !VM_MARKER) {
  throw new Error('TARGET_MISSION_NAME and TARGET_VM_MARKER are required content proofs');
}
if (Number(VM_COUNT_TEXT) !== PROFILE_VM_COUNTS[PROFILE]) {
  throw new Error(`TARGET_VM_COUNT must be ${PROFILE_VM_COUNTS[PROFILE]} for profile ${PROFILE}`);
}

const VM_URL = `${BASE}/vms.php?mission_id=${MISSION_TEXT}`;
const coldVmDuration = new Trend('target_vms_cold_duration', true);
const acceptedColdVmSamples = new Counter('accepted_cold_vm_samples');

export const options = {
  tags: {
    u13_profile: PROFILE,
    u13_thermal_state: 'cold-target-route',
  },
  scenarios: {
    cold_target: {
      executor: 'per-vu-iterations',
      vus: 1,
      iterations: 1,
      maxDuration: '120s',
    },
  },
  thresholds: {
    checks: ['rate==1'],
    http_req_failed: ['rate==0'],
    accepted_cold_vm_samples: ['count>0'],
  },
};

function isSignedIn(response) {
  return response.status === 200 && response.body.includes('logout.php');
}

function login() {
  const loginPage = http.get(`${BASE}/login.php`, {
    redirects: 0,
    tags: { page: 'cold_login' },
  });
  const csrf = parseHTML(loginPage.body).find('input[name=_csrf]').first().attr('value');
  if (loginPage.status !== 200 || loginPage.url !== `${BASE}/login.php` || !csrf) {
    fail('cold probe login form precondition failed');
  }
  const response = http.post(
    `${BASE}/login.php`,
    { username: USER, password: PASS, _csrf: csrf },
    { tags: { page: 'cold_login' } },
  );
  if (!isSignedIn(response) || response.url !== `${BASE}/dashboard.php`) {
    fail('cold probe authentication failed');
  }
}

export default function () {
  // Authentication necessarily warms bootstrap/auth code. The run manifest may
  // call only the following target-route request cold; it may not claim a cold
  // host, cold database or cold authenticated portal process from this sample.
  login();
  const response = http.get(VM_URL, {
    redirects: 0,
    tags: { page: 'vms_cold_candidate' },
  });
  const document = parseHTML(response.body);
  const valid = isSignedIn(response)
    && response.url === VM_URL
    && String(response.headers['Content-Type'] || '').toLowerCase().includes('text/html')
    && document.find('title').first().text().includes(MISSION_NAME)
    && document.find('table.table-sticky-actions tbody tr').size() === Number(VM_COUNT_TEXT)
    && document.find('input[data-bulk-item][name="vm_ids[]"]').size() === Number(VM_COUNT_TEXT)
    && response.body.includes(VM_MARKER);
  check(valid, { 'cold candidate reached the exact target mission and VM content': (value) => value });
  if (!valid) {
    fail(`cold VM target validation failed (status ${response.status}, url ${response.url})`);
  }
  coldVmDuration.add(response.timings.duration);
  acceptedColdVmSamples.add(1);
}
