// TESTPLAN 3.1: basic health of every portal page. For each page and theme the
// test proves it loads with the right access outcome, carries no PHP
// error/notice, raises no console error or CSP violation, reaches no host but
// 127.0.0.1 (the air-gap proof), and shows no missing-translation marker or raw
// `module.key`. Runs under both the admin and the user storageState.

const { test, expect } = require('@playwright/test');
const { PORTAL_PAGES } = require('../lib/pages');
const { ROLES } = require('../lib/auth');

const THEMES = ['light', 'dark'];

// Signals that mean PHP rendered an error into the HTML instead of a page.
const PHP_ERROR_MARKERS = [
  'Fatal error',
  'Parse error',
  'Warning:',
  'Notice:',
  'Deprecated:',
  'Uncaught',
  'Stack trace',
  'mysqli',
  '/var/www/html',
];

// A missing translation renders the bare key as an element's ENTIRE text (a
// label, heading or button that is exactly `help.foo`). Matching a substring
// anywhere would false-positive on legitimate content: the audit log on logs.php
// shows real permission literals like `users.manage`, and filenames/help text
// mention `settings.php`. So the check looks for a *leaf element whose whole
// trimmed text is a key-shaped token*, which is what an untranslated key is and
// what embedded data never is.
const KEY_PREFIXES = 'common|portal|missions|vms|deploy|os|packages|vlans|credentials|integrations|users|settings|logs|help|account|dashboard|validate|login';
const WHOLE_KEY = new RegExp('^(?:' + KEY_PREFIXES + ')\\.[a-z0-9_]{3,}$');

for (const [roleName, role] of Object.entries(ROLES)) {
  test.describe(`health matrix [${roleName}]`, () => {
    test.use({ storageState: role.storageState });

    for (const pageDef of PORTAL_PAGES) {
      for (const theme of THEMES) {
        const denied = pageDef.access === 'admin' && roleName === 'user';

        test(`${pageDef.path} (${theme})${denied ? ' [denied]' : ''}`, async ({ page, baseURL }) => {
          const consoleErrors = [];
          const offHostRequests = [];

          page.on('console', (msg) => {
            if (msg.type() === 'error') {
              consoleErrors.push(msg.text());
            }
          });
          page.on('pageerror', (err) => consoleErrors.push('pageerror: ' + err.message));

          // Air-gap: any request to a host other than 127.0.0.1 fails the test.
          await page.route('**/*', (route) => {
            const url = new URL(route.request().url());
            if (url.hostname !== '127.0.0.1' && url.hostname !== 'localhost') {
              offHostRequests.push(url.href);
              return route.abort();
            }
            return route.continue();
          });

          // Theme is read from localStorage on load, so set it before navigating.
          await page.addInitScript((t) => {
            try {
              window.localStorage.setItem('virtusphere.theme', t);
            } catch (e) {
              /* first navigation may not have a storage origin yet */
            }
          }, theme);

          const response = await page.goto(pageDef.path, { waitUntil: 'domcontentloaded' });
          const status = response ? response.status() : 0;
          const body = await page.content();

          // The air-gap holds on every page, denied or not.
          expect(offHostRequests, `off-host requests from ${pageDef.path}`).toEqual([]);

          if (denied) {
            // A denied page must not render its content: the portal answers 403
            // with a localized refusal, never the page body. The 403 for the
            // main document legitimately shows up as a console "Failed to load
            // resource" error, so the console assertion does not apply here.
            expect(status, `denied page should not be 200: ${pageDef.path}`).not.toBe(200);
            return;
          }

          expect(status, `page should load: ${pageDef.path}`).toBeLessThan(400);

          // No PHP error text leaked into the HTML.
          for (const marker of PHP_ERROR_MARKERS) {
            expect(body, `PHP error marker "${marker}" in ${pageDef.path}`).not.toContain(marker);
          }

          expect(consoleErrors, `console errors on ${pageDef.path}`).toEqual([]);

          // Missing-translation guard: any leaf element whose entire text is a
          // key-shaped token is an untranslated `__t()` key rendered verbatim.
          const rawKeyLeaves = await page.evaluate((pattern) => {
            const re = new RegExp(pattern);
            const hits = [];
            for (const el of document.querySelectorAll('body *')) {
              if (el.children.length > 0) {
                continue; // leaves only; a container's text includes its children
              }
              const text = (el.textContent || '').trim();
              if (re.test(text)) {
                hits.push(text);
              }
            }
            return hits;
          }, WHOLE_KEY.source);
          expect(rawKeyLeaves, `untranslated i18n keys on ${pageDef.path}`).toEqual([]);
        });
      }
    }
  });
}
