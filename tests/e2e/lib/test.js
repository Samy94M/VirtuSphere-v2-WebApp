// The `test` every spec imports (M1). It is Playwright's own test plus one
// automatic fixture that renews the role logins.
//
// Why: the setup project logs in once per run, and the portal ends a session
// 3600 s after login no matter how active it is (lib/auth.php). One
// `test:matrix` run takes Firefox, WebKit and Edge one after another, well over
// an hour under load, so the last engine met the session-expiry dialog
// (`data-session-modal`) instead of the page under test.
//
// What: each worker serves exactly one project, so the first test of a worker
// signs both roles in again with that project's browser and rewrites the
// storageState files. A worker that lives longer than REFRESH_AFTER_MS renews
// them before its next test. Automatic fixtures are set up before the test's
// own fixtures, so the `context` a spec creates from the file already carries
// the new session. A failed renewal keeps the previous files and leaves an
// annotation; the tests then show whatever the old session still allows.

const base = require('@playwright/test');
const { ROLES, refreshStorageState } = require('./auth');

// Far below the 3600 s session lifetime, so no test starts on a session older
// than this plus one test.
const REFRESH_AFTER_MS = 20 * 60 * 1000;

// Per worker process. A worker never switches projects.
let renewedAt = 0;

const test = base.test.extend({
  roleLogins: [async ({ browser }, use, testInfo) => {
    if (Date.now() - renewedAt >= REFRESH_AFTER_MS) {
      const failures = [];
      for (const role of Object.values(ROLES)) {
        try {
          await refreshStorageState(browser, testInfo.project.use.baseURL, role);
        } catch (error) {
          failures.push(`${role.username}: ${String(error.message).split('\n')[0]}`);
        }
      }
      renewedAt = Date.now();
      if (failures.length > 0) {
        testInfo.annotations.push({
          type: 'role-login-renewal-failed',
          description: failures.join('; '),
        });
      }
    }
    await use();
  }, { auto: 'all-hooks-included' }],
});

module.exports = { ...base, test };
