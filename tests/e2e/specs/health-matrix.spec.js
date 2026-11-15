// TESTPLAN 3.1 and 3.6 in one pass per page, role and theme (QL4, B5: one
// goto, one seed; until then accessibility.spec.js loaded the same pages a
// second time).
//
// 3.1, basic health of every portal page: for each page and theme the test
// proves it loads with the right access outcome, carries no PHP error/notice,
// raises no console error or CSP violation, reaches no host but 127.0.0.1 (the
// air-gap proof), and shows no missing-translation marker or raw `module.key`.
// Runs under both the admin and the user storageState.
//
// 3.6, automated accessibility scan (axe-core, WCAG 2.1/2.2 A + AA) over every
// page in both themes, under the admin storageState only: admin sees every
// page, and axe has nothing to say about a 403. Automation only catches a part
// of what matters, but the part it catches is the part that is cheap to
// regress: a control without an accessible name, a contrast pair that fails at
// AA, a form field with no label, a broken heading or landmark structure. A
// violation is reported with the rule id, the impact and the offending
// selectors, so a failure names what to fix rather than just that something is
// wrong. The scan runs as its own step after every health assertion, because
// it opens every <details> first and the health checks read the page as loaded.

const fs = require('node:fs');
const path = require('node:path');
const AxeBuilder = require('@axe-core/playwright').default;
const { test, expect } = require('../lib/test');
const { PORTAL_PAGES, pageUrl, pageLabel } = require('../lib/pages');
const { ROLES } = require('../lib/auth');
const { seedMatrixFixtures, cleanupMatrixFixtures } = require('../lib/matrix-seed');
const { formReferenceProblems } = require('../lib/form-accessibility');
const { ERROR_PAGE_SELECTOR } = require('../lib/error-page');

const THEMES = ['light', 'dark'];
const MARK = 'e2ehealth';

// The rule sets the portal commits to. Best-practice rules are deliberately out:
// they encode opinions (e.g. one <main> per page) rather than the AA bar.
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const AXE_ROLE = 'admin';

function describeViolations(violations) {
  return violations
    .map((v) => {
      const where = v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join('\n      ');
      return `  [${v.impact}] ${v.id}: ${v.help}\n      ${where}`;
    })
    .join('\n');
}

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
// The portal's own error renderer (lib/errors.php) hides every PHP marker
// above. After a partial render it is appended below the page, which still
// answers 200 (VE1: "VM hinzufügen" ended like that). It is found by its
// attribute (lib/error-page.js), not by its title text (C5).

// A missing translation renders the bare key as an element's ENTIRE text (a
// label, heading or button that is exactly `help.foo`). Matching a substring
// anywhere would false-positive on legitimate content: the audit log on logs.php
// shows real permission literals like `users.manage`, and filenames/help text
// mention `settings.php`. So the check looks for a *leaf element whose whole
// trimmed text is a key-shaped token*, which is what an untranslated key is and
// what embedded data never is.
//
// The prefixes are the catalog filenames, read from disk rather than listed:
// the module of a key IS its catalog name (ADR-0014), and a hand-kept list goes
// stale silently in the direction that matters. It had already lost four
// catalogs, among them help_system_status, whose keys help.php renders.
// Same rule the i18n rules file states for the PHPUnit catalog scanners: glob,
// never list.
//
// One locale is scanned, not both: the browser negotiates it (Chromium asks for
// en, so this runs against the EN catalog), and lang-audit --ci already pins
// that DE and EN hold the same key set. A key missing from one is missing from
// both, so the second pass would double the matrix for no new failure. Proven
// the hard way while mutation-testing this check: a key broken in DE alone
// stays green here and goes red in lang-audit instead.
const CATALOG_DIR = path.resolve(__dirname, '../../../Docker/WebAPI/lang/de');
const KEY_MODULES = fs
  .readdirSync(CATALOG_DIR)
  .filter((name) => name.endsWith('.php'))
  .map((name) => name.slice(0, -4))
  .sort();

// An empty derivation makes the regex unsatisfiable and the check silently,
// permanently green: exactly the failure class it exists to catch.
if (KEY_MODULES.length === 0) {
  throw new Error(`no language catalogs found under ${CATALOG_DIR}; the i18n check would pass vacuously`);
}

const WHOLE_KEY = new RegExp('^(?:' + KEY_MODULES.join('|') + ')\\.[a-z0-9_]{3,}$');

// One seed for the whole file: the pages that need a concrete object share it,
// and re-seeding per test would multiply the docker exec round trips by the
// role and theme fan-out for no added coverage.
let seeded = null;

test.beforeAll(() => {
  cleanupMatrixFixtures(MARK);
  seeded = seedMatrixFixtures(MARK);
});

test.afterAll(() => {
  cleanupMatrixFixtures(MARK);
});

for (const [roleName, role] of Object.entries(ROLES)) {
  test.describe(`health matrix [${roleName}]`, () => {
    test.use({ storageState: role.storageState });

    for (const pageDef of PORTAL_PAGES) {
      for (const theme of THEMES) {
        const denied = pageDef.access === 'admin' && roleName === 'user';

        test(`${pageLabel(pageDef)} (${theme})${denied ? ' [denied]' : ''}`, async ({ page, baseURL }) => {
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

          const response = await page.goto(pageUrl(pageDef, seeded), { waitUntil: 'domcontentloaded' });
          const status = response ? response.status() : 0;
          const body = await page.content();

          // The air-gap holds on every page, denied or not.
          expect(offHostRequests, `off-host requests from ${pageLabel(pageDef)}`).toEqual([]);

          // No page ends in the portal error renderer, denied or not: a denied
          // page that crashes answers 500, which the status check below accepts.
          await expect(page.locator(ERROR_PAGE_SELECTOR), `portal error page on ${pageLabel(pageDef)}`).toHaveCount(0);

          if (denied) {
            // A denied page must not render its content: the portal answers 403
            // with a localized refusal, never the page body. The 403 for the
            // main document legitimately shows up as a console "Failed to load
            // resource" error, so the console assertion does not apply here.
            expect(status, `denied page should not be 200: ${pageLabel(pageDef)}`).not.toBe(200);
            return;
          }

          expect(status, `page should load: ${pageLabel(pageDef)}`).toBeLessThan(400);

          // A redirect answers 200 from wherever it lands, so every other
          // assertion below would then run against a different page under this
          // page's name. That is how vm_edit.php can be "covered" by scanning
          // the mission list: it redirects on a missing or misnamed id. Pages
          // that redirect by design declare it.
          if (!pageDef.redirects) {
            const landed = new URL(page.url()).pathname.split('/').pop();
            expect(landed, `${pageLabel(pageDef)} redirected away instead of rendering`).toBe(
              pageDef.path.split('?')[0],
            );
          }

          // No PHP error text leaked into the HTML.
          for (const marker of PHP_ERROR_MARKERS) {
            expect(body, `PHP error marker "${marker}" in ${pageLabel(pageDef)}`).not.toContain(marker);
          }

          expect(consoleErrors, `console errors on ${pageLabel(pageDef)}`).toEqual([]);

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
          expect(rawKeyLeaves, `untranslated i18n keys on ${pageLabel(pageDef)}`).toEqual([]);

          if (roleName !== AXE_ROLE) {
            return;
          }

          await test.step(`a11y: ${pageLabel(pageDef)} (${theme})`, async () => {
            // The error-page check above already ran on this very page: a page
            // that ended in the portal error renderer would be scanned as the
            // error page (or as half a page with it appended) and could pass,
            // because axe finds little to fault on a heading and a paragraph (C5).

            // A closed <details> is not in the accessibility tree, so axe skips its
            // content entirely: legends, technical-detail blocks and repair forms were
            // never scanned even though a keyboard user reaches all of them with one
            // Enter. Everything the page can show is opened before the scan, which is
            // strictly more coverage than the collapsed state (a hidden subtree can
            // only lose findings, never gain them).
            await page.evaluate(() => {
              document.querySelectorAll('details').forEach((element) => {
                element.open = true;
              });
            });

            const formProblems = await formReferenceProblems(page);
            expect(
              formProblems,
              `form accessibility references on ${pageLabel(pageDef)} (${theme})\n${formProblems.join('\n')}`
            ).toEqual([]);

            const results = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();

            expect(
              results.violations,
              `accessibility violations on ${pageLabel(pageDef)} (${theme}):\n${describeViolations(results.violations)}`
            ).toEqual([]);
          });
        });
      }
    }
  });
}
