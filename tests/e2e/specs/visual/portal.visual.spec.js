'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');
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
  await page.emulateMedia({ colorScheme: theme, reducedMotion: contract.reducedMotion });

  const sessionClockText = [
    Math.floor(contract.sessionRemainingSeconds / 60),
    contract.sessionRemainingSeconds % 60,
  ].map((part) => String(part).padStart(2, '0')).join(':');

  const pages = [
    { name: 'missions', url: 'missions.php' },
    { name: 'deploy', url: `deploy.php?mission_id=${seeded.missionId}` },
  ];
  for (const viewport of contract.viewports) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    for (const target of pages) {
      const response = await page.goto(target.url, { waitUntil: 'networkidle' });
      expect(response && response.ok()).toBeTruthy();
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
      await page.screenshot({
        path: path.join(outputDir, `${target.name}-${viewport.name}.png`),
        fullPage: true,
        animations: contract.animations,
        caret: contract.caret,
        scale: contract.screenshotScale,
      });
    }
  }
});
