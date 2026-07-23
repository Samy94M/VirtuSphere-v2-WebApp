// TESTPLAN 3.6: automated accessibility scan (axe-core, WCAG 2.1/2.2 A + AA)
// over every portal page in both themes. Automation only catches a part of what
// matters, but the part it catches is the part that is cheap to regress: a
// control without an accessible name, a contrast pair that fails at AA, a form
// field with no label, a broken heading or landmark structure.
//
// A violation is reported with the rule id, the impact and the offending
// selectors, so a failure names what to fix rather than just that something is
// wrong.

const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { PORTAL_PAGES } = require('../lib/pages');
const { ROLES } = require('../lib/auth');

const THEMES = ['light', 'dark'];

// The rule sets the portal commits to. Best-practice rules are deliberately out:
// they encode opinions (e.g. one <main> per page) rather than the AA bar.
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

function describeViolations(violations) {
  return violations
    .map((v) => {
      const where = v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join('\n      ');
      return `  [${v.impact}] ${v.id}: ${v.help}\n      ${where}`;
    })
    .join('\n');
}

// Admin sees every page; the denied-page behaviour is already covered by the
// health matrix, and axe has nothing to say about a 403.
test.use({ storageState: ROLES.admin.storageState });

for (const pageDef of PORTAL_PAGES) {
  for (const theme of THEMES) {
    test(`a11y: ${pageDef.path} (${theme})`, async ({ page }) => {
      await page.addInitScript((t) => {
        try {
          window.localStorage.setItem('virtusphere.theme', t);
        } catch (e) {
          /* no storage origin on the very first navigation */
        }
      }, theme);

      await page.goto(pageDef.path, { waitUntil: 'domcontentloaded' });

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

      const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();

      expect(
        results.violations,
        `accessibility violations on ${pageDef.path} (${theme}):\n${describeViolations(results.violations)}`
      ).toEqual([]);
    });
  }
}
