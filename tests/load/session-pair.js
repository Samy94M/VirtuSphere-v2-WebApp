// U13 SC-008 paired session probe. Run MODE=same and MODE=independent against
// the same already-warm S/T/L fixture. It performs no writes.
import http from 'k6/http';
import { check, fail, sleep } from 'k6';
import { parseHTML } from 'k6/html';
import { Counter, Trend } from 'k6/metrics';

const BASE = (__ENV.BASE || 'http://localhost:8080/portal').replace(/\/+$/, '');
const USER = __ENV.VS_USER || '';
const PASS = __ENV.VS_PASS || '';
const MODE = String(__ENV.MODE || '').toLowerCase();
const PROFILE = String(__ENV.U13_PROFILE || '').toUpperCase();
const MISSION_TEXT = __ENV.TARGET_MISSION_ID || '';
const MISSION_NAME = __ENV.TARGET_MISSION_NAME || '';
const VM_COUNT_TEXT = __ENV.TARGET_VM_COUNT || '';
const VM_MARKER = __ENV.TARGET_VM_MARKER || '';
const RUN_ID = __ENV.U13_RUN_ID || '';
const PROFILE_VM_COUNTS = { S: 10, T: 40, L: 1000 };

if (!USER || !PASS) {
  throw new Error('VS_USER and VS_PASS are required; pass them at runtime and keep them out of run artifacts');
}
if (!['same', 'independent'].includes(MODE)) {
  throw new Error('MODE must be same or independent');
}
if (!Object.prototype.hasOwnProperty.call(PROFILE_VM_COUNTS, PROFILE)) {
  throw new Error('U13_PROFILE must be S, T or L');
}
if (!/^[a-z0-9][a-z0-9_.-]{0,63}$/.test(RUN_ID)) {
  throw new Error('U13_RUN_ID must be a non-secret lowercase run identifier');
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
const BLOCKER_URL = `${BASE}/deploy_blockers.php?mission_id=${MISSION_TEXT}&mode=export`;
const PEER_URL = `${BASE}/dashboard.php`;
const blockerDuration = new Trend('paired_blocker_duration', true);
const peerDuration = new Trend('paired_peer_duration', true);
const pairDuration = new Trend('paired_pair_duration', true);
const acceptedPairs = new Counter('accepted_session_pairs');

export const options = {
  tags: {
    u13_profile: PROFILE,
    u13_session_mode: MODE,
    u13_thermal_state: 'warm',
    u13_run_id: RUN_ID,
  },
  scenarios: {
    pairs: {
      executor: 'constant-vus',
      vus: 1,
      duration: '60s',
      gracefulStop: '30s',
    },
  },
  thresholds: {
    checks: ['rate>0.99'],
    http_req_failed: ['rate<0.01'],
    accepted_session_pairs: ['count>0'],
  },
};

function isSignedIn(res) {
  return res.status === 200 && res.body.includes('logout.php');
}

function login() {
  const jar = new http.CookieJar();
  const loginPage = http.get(`${BASE}/login.php`, {
    jar,
    redirects: 0,
    tags: { page: 'pair_login' },
  });
  const csrf = parseHTML(loginPage.body).find('input[name=_csrf]').first().attr('value');
  if (loginPage.status !== 200 || loginPage.url !== `${BASE}/login.php` || !csrf) {
    fail('pair login did not reach the exact login form with a CSRF token');
  }
  const response = http.post(
    `${BASE}/login.php`,
    { username: USER, password: PASS, _csrf: csrf },
    { jar, tags: { page: 'pair_login' } },
  );
  if (!isSignedIn(response) || response.url !== `${BASE}/dashboard.php`) {
    fail(`pair login did not reach the authenticated dashboard (status ${response.status}, url ${response.url})`);
  }
  const cookies = jar.cookiesForURL(`${BASE}/dashboard.php`);
  if (!cookies.PHPSESSID || cookies.PHPSESSID.length !== 1 || !cookies.PHPSESSID[0]) {
    fail('pair login yielded no unique PHPSESSID');
  }
  return { jar, id: cookies.PHPSESSID[0] };
}

function proveTarget(session) {
  const response = http.get(VM_URL, {
    jar: session.jar,
    redirects: 0,
    tags: { page: 'pair_target_proof' },
  });
  const document = parseHTML(response.body);
  return isSignedIn(response)
    && response.url === VM_URL
    && String(response.headers['Content-Type'] || '').toLowerCase().includes('text/html')
    && document.find('title').first().text().includes(MISSION_NAME)
    && document.find('table.table-sticky-actions tbody tr').size() === Number(VM_COUNT_TEXT)
    && document.find('input[data-bulk-item][name="vm_ids[]"]').size() === Number(VM_COUNT_TEXT)
    && response.body.includes(VM_MARKER);
}

function blockerIsValid(response) {
  if (response.status !== 200 || response.url !== BLOCKER_URL
    || !String(response.headers['Content-Type'] || '').toLowerCase().includes('application/json')) {
    return false;
  }
  try {
    const payload = response.json();
    return payload.ok === true
      && Number.isInteger(payload.count)
      && Number.isInteger(payload.total)
      && Number.isInteger(payload.warning_total)
      && Array.isArray(payload.blockers)
      && Array.isArray(payload.warnings);
  } catch (_) {
    return false;
  }
}

let sessions;

export default function () {
  if (!sessions) {
    const first = login();
    const second = MODE === 'same' ? first : login();
    const equal = first.id === second.id;
    const identityValid = MODE === 'same' ? equal : !equal;
    const targetValid = proveTarget(first) && proveTarget(second);
    check(identityValid, { 'session identities match the requested A/B mode': (valid) => valid });
    check(targetValid, { 'both A/B sessions reach the exact target mission and VM content': (valid) => valid });
    if (!identityValid || !targetValid) {
      fail('session identity or target-content precondition failed');
    }
    console.log(JSON.stringify({
      evidence: 'u13_session_identity',
      run_id: RUN_ID,
      mode: MODE,
      relation: equal ? 'same' : 'different',
      expected_relation: MODE === 'same' ? 'same' : 'different',
      identity_valid: identityValid,
      raw_session_ids_recorded: false,
    }));
    sessions = { first, second };
  }

  const started = Date.now();
  const responses = http.batch([
    ['GET', BLOCKER_URL, null, {
      jar: sessions.first.jar,
      redirects: 0,
      headers: { Accept: 'application/json' },
      tags: { page: 'paired_blocker_candidate', session_mode: MODE },
      timeout: '120s',
    }],
    ['GET', PEER_URL, null, {
      jar: sessions.second.jar,
      redirects: 0,
      tags: { page: 'paired_peer_candidate', session_mode: MODE },
      timeout: '120s',
    }],
  ]);

  const blockerValid = blockerIsValid(responses[0]);
  const peerValid = isSignedIn(responses[1])
    && responses[1].url === PEER_URL
    && String(responses[1].headers['Content-Type'] || '').toLowerCase().includes('text/html');
  check(blockerValid, { 'paired blocker is the authenticated decision JSON': (valid) => valid });
  check(peerValid, { 'paired peer is the exact authenticated dashboard': (valid) => valid });
  if (!blockerValid || !peerValid) {
    fail('paired response validation failed; candidate timings were not accepted');
  }

  blockerDuration.add(responses[0].timings.duration);
  peerDuration.add(responses[1].timings.duration);
  pairDuration.add(Date.now() - started);
  acceptedPairs.add(1);
  sleep(0.2);
}
