// Dev-host load probe for the VirtuSphere portal (TESTPLAN 4.7). Read-heavy and
// session-based: every VU signs in as its own operator, then polls the pages an
// operator actually sits on - dashboard, the mission and VM lists, and the health
// endpoint the deploy page long-polls. LAN reality is a handful of operators, so
// this asks "does it stay quick and error-free under a realistic-plus concurrency"
// (not a stress-to-break run). Per-endpoint thresholds are tagged so a slow page
// is named, not hidden in an aggregate.
//
// Writes stay out of scope on purpose: a load test must not mutate real rows.
//
// One VU = one session, and that is load-bearing.
// -----------------------------------------------
// The first version of this script logged in once in setup() and handed the SAME
// PHPSESSID to all 30 VUs. PHP's file session handler holds an exclusive lock on
// the session file for the whole request (no first-party code calls
// session_write_close()), so those 30 VUs queued up behind one lock: the measured
// p95 was mostly time spent waiting for each other, not time the portal spent
// rendering. It also modelled something that cannot happen - thirty operators do
// not share one browser session. Baselines taken that way describe the test, not
// the portal.
import http from 'k6/http';
import { check, sleep, fail } from 'k6';
import { parseHTML } from 'k6/html';

const BASE = __ENV.BASE || 'http://localhost:8080/portal';
const USER = __ENV.VS_USER || 'admin';
const PASS = __ENV.VS_PASS || 'admin12345678';

export const options = {
  scenarios: {
    operators: {
      executor: 'ramping-vus',
      startVUs: 1,
      stages: [
        { duration: '10s', target: 10 }, // ramp to ten concurrent operators
        { duration: '20s', target: 10 }, // hold
        { duration: '20s', target: 30 }, // push past a realistic LAN peak
        { duration: '10s', target: 0 },  // ramp down
      ],
      gracefulRampDown: '5s',
    },
    // The monitor is not an operator: it polls health.php at a fixed rate no
    // matter how slow the portal gets. constant-arrival-rate models exactly
    // that, and dropped_iterations is its truth metric: if k6 cannot keep the
    // rate up, the run must fail instead of quietly measuring a slower one.
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
    // Checks without a threshold cannot fail a run: k6 reports them and exits 0.
    // Every check in this script is a false-green detector (signed-in marker,
    // health body), so a failing check must fail the run.
    checks: ['rate>0.99'],
    // No request may error, and the pages an operator waits on stay snappy.
    http_req_failed: ['rate<0.01'],
    'http_req_duration{page:dashboard}': ['p(95)<800'],
    'http_req_duration{page:missions}': ['p(95)<800'],
    'http_req_duration{page:vms}': ['p(95)<800'],
    'http_req_duration{page:health}': ['p(95)<300'],
    // The monitor keeps its rate or the run is red (see the scenario comment).
    'dropped_iterations{scenario:monitor}': ['count<1'],
  },
};

// Module scope is per-VU in k6 (every VU runs its own JS context), so this holds
// exactly one session per virtual operator - which is the point of the script.
//
// It has to be carried by hand rather than left to the cookie jar: k6 RESETS the
// per-VU jar at the start of every iteration. Signing in on iteration 0 and
// relying on the jar afterwards therefore logs in once and then browses
// anonymously, and since the portal answers an anonymous request with a redirect
// to the login form - which is also a 200, and k6 follows redirects - the run
// looks perfectly healthy while measuring nothing but the login page.
let sessionId = null;

// Signs this VU in as its own operator and returns its session id.
function login() {
  const loginPage = http.get(`${BASE}/login.php`, { tags: { page: 'login' } });
  const csrf = parseHTML(loginPage.body).find('input[name=_csrf]').first().attr('value');
  if (!csrf) {
    fail('login page carried no CSRF token; is BASE right?');
  }

  const res = http.post(
    `${BASE}/login.php`,
    { username: USER, password: PASS, _csrf: csrf },
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

// A signed-in page carries the layout's logout form; the login page does not.
// Checking for that beats checking the status code, which is 200 either way -
// including on the redirect back to the login form.
function isSignedIn(res) {
  return res.status === 200 && res.body.includes('logout.php');
}

export default function () {
  // Put the session back into this iteration's (freshly emptied) cookie jar
  // rather than passing it as a Cookie header. A header would look equivalent and
  // is not: vms.php answers with a 302, and k6 does not carry a hand-set header
  // across a redirect, so the redirected request would go out anonymous, land on
  // the login form, and still be a 200. That is precisely the false-green this
  // script exists to avoid. The jar survives the redirect the way a browser's does.
  const jar = http.cookieJar();
  if (sessionId === null) {
    sessionId = login(); // login() leaves the cookie in this iteration's jar
  } else {
    jar.set(`${BASE}/`, 'PHPSESSID', sessionId);
  }

  const dash = http.get(`${BASE}/dashboard.php`, { tags: { page: 'dashboard' } });
  check(dash, { 'dashboard rendered for a signed-in operator': (r) => isSignedIn(r) });

  const missions = http.get(`${BASE}/missions.php?type=missions`, { tags: { page: 'missions' } });
  check(missions, { 'missions rendered for a signed-in operator': (r) => isSignedIn(r) });

  // vms.php redirects (302) before it renders; the check follows it through.
  const vms = http.get(`${BASE}/vms.php`, { tags: { page: 'vms' } });
  check(vms, { 'vms rendered for a signed-in operator': (r) => isSignedIn(r) });

  // health.php is what a monitor and the deploy page poll most often. It is a
  // JSON endpoint and carries no layout, so it gets its own check.
  const health = http.get(`${BASE}/health.php`, { tags: { page: 'health' } });
  check(health, { 'health reports ok': (r) => r.status === 200 && r.body.includes('ok') });

  sleep(1); // an operator reads between clicks
}

// The unauthenticated monitor probe (health.php needs no session by design:
// it is what LAN monitoring polls). Own exec so the arrival-rate scenario
// never pays the login cost and never touches the operator sessions.
export function monitor() {
  const health = http.get(`${BASE}/health.php`, { tags: { page: 'health' } });
  check(health, { 'monitor sees a healthy portal': (r) => r.status === 200 && r.body.includes('ok') });
}
