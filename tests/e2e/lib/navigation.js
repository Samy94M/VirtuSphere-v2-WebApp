// Portal form actions use POST/Redirect/GET. Waiting only for a URL regex is
// unsafe when the redirect returns to the same page: Playwright may satisfy it
// from the current URL before the click has submitted anything. Pair the POST
// response with a real main-frame navigation, then wait for its load event.

async function submitAndWaitForNavigation(page, trigger, endpoint) {
  const endpointPath = '/' + String(endpoint).replace(/^\/+/, '');
  const [response] = await Promise.all([
    page.waitForResponse((candidate) => {
      const path = new URL(candidate.url()).pathname;
      return path.endsWith(endpointPath) && candidate.request().method() === 'POST';
    }),
    page.waitForEvent('framenavigated', (frame) => frame === page.mainFrame()),
    trigger.click(),
  ]);
  await page.waitForLoadState('load');
  return response;
}

module.exports = { submitAndWaitForNavigation };
