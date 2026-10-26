// The `test` every spec imports (M1). It is Playwright's own test plus one
// automatic fixture that renews the role logins.
//
// Why: the setup project logs in once per run, and the portal ends a session
// 3600 s after login no matter how active it is (lib/auth.php). One
// `test:matrix` run takes Firefox, WebKit and Edge one after another, well over
// an hour under load, so the last engine met the session-expiry dialog
// (`data-session-modal`) instead of the page under test.
//
// What: before a test, the fixture signs both roles in again with that
// project's browser and rewrites the storageState files when the last renewal
// belongs to another browser project or is older than REFRESH_AFTER_MS.
// Automatic fixtures are set up before the test's own fixtures, so the
// `context` a spec creates from the file already carries the new session.
//
// Three choices keep the renewal from hurting the suite it protects (first
// version, 2026-10-26: twelve red tests and 79 not run under load):
// - It runs before tests only, not before beforeAll/afterAll hooks. Those seed
//   fixtures through the PHP container under their own 30 s timeout; a login
//   in front of them ate that budget.
// - It has its own timeout, so a slow login is not charged to the test.
// - The last renewal is kept in a marker file, not in the worker. Playwright
//   replaces the worker after every failed test; a per-worker flag made each
//   replacement log in again, so one slow login turned into a chain.
// A failed renewal keeps the previous storageState files, records the failure
// in the marker and retries after RETRY_AFTER_MS; the test gets an annotation.

const fs = require('node:fs');
const path = require('node:path');
const base = require('@playwright/test');
const { ROLES, AUTH_DIR, refreshStorageState } = require('./auth');

// Far below the 3600 s session lifetime.
const REFRESH_AFTER_MS = 20 * 60 * 1000;
const RETRY_AFTER_MS = 2 * 60 * 1000;
// Two logins with a 15 s navigation and a 15 s dashboard wait each.
const RENEWAL_TIMEOUT_MS = 90 * 1000;
const MARKER = path.join(AUTH_DIR, 'renewal.json');

function readMarker() {
  try {
    return JSON.parse(fs.readFileSync(MARKER, 'utf8'));
  } catch {
    return null;
  }
}

function renewalDue(marker, project, now) {
  if (!marker || marker.project !== project) return true;
  const age = now - Number(marker.at || 0);
  return age >= (marker.ok ? REFRESH_AFTER_MS : RETRY_AFTER_MS);
}

const test = base.test.extend({
  roleLogins: [async ({ browser }, use, testInfo) => {
    const project = testInfo.project.name;
    if (renewalDue(readMarker(), project, Date.now())) {
      const failures = [];
      for (const role of Object.values(ROLES)) {
        try {
          await refreshStorageState(browser, testInfo.project.use.baseURL, role);
        } catch (error) {
          failures.push(`${role.username}: ${String(error.message).split('\n')[0]}`);
        }
      }
      fs.mkdirSync(AUTH_DIR, { recursive: true });
      fs.writeFileSync(MARKER, JSON.stringify({ project, at: Date.now(), ok: failures.length === 0 }));
      if (failures.length > 0) {
        testInfo.annotations.push({
          type: 'role-login-renewal-failed',
          description: failures.join('; '),
        });
      }
    }
    await use();
  }, { auto: true, timeout: RENEWAL_TIMEOUT_MS }],
});

module.exports = { ...base, test };
