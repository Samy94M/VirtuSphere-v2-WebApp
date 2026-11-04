// The deploy page's live blocker list (deploy_blockers.js) sits above the queue
// form and re-renders 250 ms after every change plus one fetch. A new verdict
// can add or remove blockers and warnings, so the queue form and the job filter
// below it move.
//
// A pointer click that straddles that re-render is lost without an error:
// Playwright judges the hit target on the first pointer event only, so a press
// on the checkbox and a release after the form moved never become a click on it
// ("Clicking the checkbox did not change its state", red once each on Firefox
// and WebKit in the 2026.11.3 release lane, FL1 2026-11-04).
//
// Every change sets data-preparation-state="checking" in its own event handler,
// before the action that caused it returns, and the render (or the failure
// render) replaces it. Wait for that before clicking anything below the list.

const { expect } = require('@playwright/test');

// The verdict is one server round trip, like a navigation, so it gets the
// suite's navigationTimeout (playwright.config.js) instead of the 7 s expect
// default: under host load the blocker GET took 4.6 to 7.2 s (FL1 run2).
const LIVE_VERDICT_TIMEOUT_MS = 15000;

async function waitForLiveVerdict(page) {
  await expect(page.locator('[data-deploy-blockers]'), 'the live verdict of the last change is rendered')
    .not.toHaveAttribute('data-preparation-state', 'checking', { timeout: LIVE_VERDICT_TIMEOUT_MS });
}

module.exports = { waitForLiveVerdict };
