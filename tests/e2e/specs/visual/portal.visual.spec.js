'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('../../lib/test');
const { ROLES } = require('../../lib/auth');
const { cleanupVisualFixtures, seedVisualFixtures } = require('../../lib/visual-seed');
const contract = require('../../visual/runner-contract.json');

const theme = process.env.VIRTUSPHERE_VISUAL_THEME;
const outputDir = process.env.VIRTUSPHERE_VISUAL_OUTPUT_DIR;
if (!contract.themes.includes(theme)) throw new Error(`invalid visual theme: ${theme}`);
if (!outputDir) throw new Error('VIRTUSPHERE_VISUAL_OUTPUT_DIR is required');

test.describe.configure({ mode: 'serial' });
test.use({ storageState: ROLES.admin.storageState });

let seeded;

test.beforeAll(() => {
  fs.mkdirSync(outputDir, { recursive: true });
  seeded = seedVisualFixtures();
});

test.afterAll(() => cleanupVisualFixtures());

test('captures only deterministic synthetic portal states', async ({ page }) => {
  await page.clock.setFixedTime(new Date(contract.clock));
  await page.addInitScript(({ fixedTheme, randomSeed, sessionRemainingSeconds }) => {
    window.localStorage.setItem('virtusphere.theme', fixedTheme);
    let state = randomSeed >>> 0;
    Math.random = () => {
      state = (1664525 * state + 1013904223) >>> 0;
      return state / 0x100000000;
    };

    const pinSessionRemainingSeconds = () => {
      const timer = document.querySelector('[data-session-timer]');
      if (!timer) return false;
      timer.setAttribute('data-expires-in', String(sessionRemainingSeconds));
      return true;
    };
    const pinBeforeDeferredScripts = () => {
      if (document.readyState === 'loading' || !pinSessionRemainingSeconds()) return;
      document.removeEventListener('readystatechange', pinBeforeDeferredScripts);
    };
    document.addEventListener('readystatechange', pinBeforeDeferredScripts);
    pinBeforeDeferredScripts();
  }, {
    fixedTheme: theme,
    randomSeed: contract.randomSeed,
    sessionRemainingSeconds: contract.sessionRemainingSeconds,
  });
  // The badge fixture is applied while the document is parsed, never after the
  // page has painted. Changing a painted badge makes Chromium re-raster only the
  // invalidated area, and that partial raster drew the round badge caps (and
  // sometimes text edges) in byte-different ways between identical runs (BK1,
  // 02.11.2026: 30 replays per theme with the old post-load change gave three
  // variants each, 40 per theme with this observer gave one, and so did 10 with
  // --disable-partial-raster). A MutationObserver callback runs as a microtask
  // at the end of the parser task that inserted the node, before the event
  // loop's next rendering step, so the badge is painted only in its fixture
  // state; a text node appended later is corrected the same way.
  await page.addInitScript((overviewBadges) => {
    // A badge is its decorative icon span plus its word (SY2). The fixture owns
    // both, and the MECM card holds two signal rows, addressed by `signal`.
    const badgeSelector = (fixture) => `.status-overview-card[href="${fixture.href}"]`
      + (fixture.signal ? ` .signal-row:nth-child(${fixture.signal})` : '') + ' .badge';
    const applyOverviewBadges = () => {
      for (const fixture of overviewBadges) {
        const node = document.querySelector(badgeSelector(fixture));
        if (!node) continue;
        if (node.className !== fixture.className) node.className = fixture.className;
        const iconClass = `badge-icon badge-icon-${fixture.icon}`;
        const icon = node.firstElementChild;
        if (!icon || icon.className !== iconClass || node.childNodes.length !== 2 || node.textContent !== fixture.text) {
          const span = document.createElement('span');
          span.className = iconClass;
          span.setAttribute('aria-hidden', 'true');
          node.replaceChildren(span, fixture.text);
        }
      }
    };
    new MutationObserver(applyOverviewBadges).observe(document, { childList: true, subtree: true, characterData: true });
  }, contract.pages.flatMap((entry) => entry.fixture?.overviewBadges || []));
  await page.emulateMedia({ colorScheme: theme, reducedMotion: contract.reducedMotion });

  const sessionClockText = [
    Math.floor(contract.sessionRemainingSeconds / 60),
    contract.sessionRemainingSeconds % 60,
  ].map((part) => String(part).padStart(2, '0')).join(':');

  const pages = contract.pages.map((entry) => ({
    ...entry,
    url: entry.url.replace(':missionId', String(seeded.missionId)),
  }));
  // A mask covers a control the portal neither styles nor can regress. It must
  // still be observed at least once: a selector that has stopped matching is a
  // dead exemption, and a dead exemption is how a masked area silently grows to
  // cover something we DO own.
  const maskHits = new Map(contract.masks.map((entry) => [entry.selector, 0]));

  for (const viewport of contract.viewports) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    for (const target of pages) {
      const response = await page.goto(target.url, { waitUntil: 'networkidle' });
      expect(response && response.ok()).toBeTruthy();
      for (const badgeFixture of target.fixture?.overviewBadges || []) {
        const badge = page.locator(`.status-overview-card[href="${badgeFixture.href}"]`
          + (badgeFixture.signal ? ` .signal-row:nth-child(${badgeFixture.signal})` : '') + ' .badge');
        const count = await badge.count();
        if (badgeFixture.optional && count === 0) continue;
        expect(count, `visual fixture target ${badgeFixture.href}`).toBe(1);
        // Verified, not set: the init-script observer above owns the change.
        await expect(badge).toHaveClass(badgeFixture.className);
        await expect(badge).toHaveText(badgeFixture.text);
        await expect(badge.locator(`.badge-icon.badge-icon-${badgeFixture.icon}[aria-hidden="true"]`)).toHaveCount(1);
      }
      await expect(page.locator('[data-session-timer]')).toHaveAttribute(
        'data-expires-in',
        String(contract.sessionRemainingSeconds)
      );
      await expect(page.locator('[data-session-clock]')).toHaveText(sessionClockText);
      await page.evaluate(() => document.fonts.ready);
      const family = await page.locator('body').evaluate((body) => getComputedStyle(body).fontFamily);
      expect(family).toContain('Segoe UI');
      // Chromium can report network/font readiness one compositor frame before
      // translucent panels and their backdrop blur have settled. Two explicit
      // frame boundaries keep the strict zero-pixel determinism contract without
      // weakening its threshold or changing the rendered product state.
      await page.evaluate(() => new Promise((resolve) => {
        requestAnimationFrame(() => requestAnimationFrame(resolve));
      }));
      const mask = [];
      for (const entry of contract.masks) {
        const locator = page.locator(entry.selector);
        const count = await locator.count();
        maskHits.set(entry.selector, maskHits.get(entry.selector) + count);
        if (count > 0) mask.push(locator);
      }
      const captureTarget = target.captureSelector ? page.locator(target.captureSelector) : page;
      if (target.captureSelector) await expect(captureTarget).toBeVisible();
      await captureTarget.screenshot({
        path: path.join(outputDir, `${target.name}-${viewport.name}.png`),
        ...(target.captureSelector ? {} : { fullPage: true }),
        animations: contract.animations,
        caret: contract.caret,
        scale: contract.screenshotScale,
        mask,
        maskColor: contract.maskColor,
      });
    }
  }

  for (const [selector, hits] of maskHits) {
    expect(hits, `the declared visual mask ${selector} matched nothing in this capture`).toBeGreaterThan(0);
  }
});
